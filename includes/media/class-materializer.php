<?php
/**
 * Materializer — materialização repo→uploads de binários de attachment.
 *
 *  - Passo 8 da cadeia §A.5.1: streaming blob→tmp (MediaStore::readVerified,
 *    tríplice igualdade + LFS) → validação 1–6 (MediaValidator) → $_FILES
 *    SINTÉTICO → wp_handle_sideload() com test_form=false. PROIBIDO
 *    wp_upload_bits() (carrega em memória e bypassa a validação profunda);
 *  - Dual-write (§A.5.2): bytes ANTES de qualquer DML — nunca o banco
 *    apontando para arquivo inexistente; arquivo órfão em uploads é tolerável
 *    (GC físico); falha após bytes escritos → COMPENSAÇÃO: unlink SOMENTE dos
 *    bytes escritos nesta operação, path de variável local — única exceção à
 *    regra nunca-deletar-bytes (§A.5.2.3, §A.7);
 *  - Preservação de original_path (determinístico: re-imports não movem o
 *    arquivo, mtime estável, URLs legadas não quebram); colisão com conteúdo
 *    DIFERENTE → Y/m corrente com sufixo + warning; colisão com MESMO hash →
 *    reuso idempotente (zero write); uploads_use_yearmonth_folders off → flat;
 *  - chmod explícito após o move (§A.10.2 — CVSYNC_FILE_MODE);
 *  - Regeneração coalescida pós-commit no fim do lote (§A.5.2.5): falha →
 *    applied-degraded (warning, não failed; deploy gate não depende);
 *  - Self-heal (§A.5.3): reparo puramente aditivo pós-restore de dump —
 *    envelope completo §A.5.1 sem exceção; tx atualiza SOMENTE state (+
 *    _wp_attached_file se o path mudou por colisão); NUNCA sobrescreve byte
 *    alheio; log binary_rematerialized.
 *
 * @package CVSync\Media
 */

declare(strict_types=1);

namespace CVSync\Media;

use CVSync\Engine\EntityRef;
use CVSync\ImportContext;
use CVSync\ImportGuard;
use CVSync\PathGuard;
use CVSync\Storage\AuditLog;
use CVSync\Storage\EntityStatus;
use CVSync\Storage\LogEntry;
use CVSync\Storage\LogResult;
use CVSync\Storage\StateStore;
use CVSync\Storage\SyncDirection;

defined('ABSPATH') || exit;

/** Resultado do sideload (passo 8) — usado pelo adapter antes do DML. */
final readonly class SideloadOutcome
{
    /**
     * @param string|null $writtenAbsPath Arquivo CRIADO nesta operação (alvo da
     *        compensação §A.5.2.3); null quando um arquivo idêntico foi reusado.
     * @param list<string> $warnings
     */
    public function __construct(
        public string $uploadsRelPath,   // path relativo ao basedir de uploads (valor de _wp_attached_file)
        public ?string $writtenAbsPath,
        public bool $degraded,
        public array $warnings = [],
    ) {
    }
}

/** Resultado de uma operação de materialização/self-heal. */
final readonly class MaterializeResult
{
    public function __construct(
        public LogResult $outcome,
        public ?string $uploadsRelPath = null,
        public bool $degraded = false,
        public ?string $error = null,
    ) {
    }
}

final class Materializer
{
    /** @var list<int> Attachments aguardando regeneração coalescida (fim do lote). */
    private array $pendingRegeneration = [];

    /** @var array<int,true> Attachments degraded (reportados, não regenerados). */
    private array $degraded = [];

    /** @var list<string> Paths relativos a uploads materializados neste lote (§A.10.3). */
    private array $materialized = [];

    public function __construct(
        private readonly MediaStore $store,
        private readonly MediaValidator $validator,
        private readonly StateStore $state,
        private readonly AuditLog $log,
        private readonly ImportGuard $guard,
        private readonly PathGuard $contentPaths,
    ) {
    }

    /**
     * Passos 5–8 da cadeia §A.5.1 + escrita em uploads. Deve ser chamado ANTES
     * de qualquer DML do attachment (ordem dual-write §A.5.2).
     *
     * @throws BinaryHashMismatchException|LfsPointerException
     * @throws \CVSync\Adapters\AdapterException Violação da cadeia (entidade rejeitada).
     */
    public function sideload(Sidecar $sidecar): SideloadOutcome
    {
        if (!function_exists('wp_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $uploads = wp_upload_dir();
        $baseDir = (string) $uploads['basedir'];

        // Passos 5+7: streaming blob→tmp com tríplice igualdade + LFS.
        $tmp = wp_tempnam($sidecar->originalFilename ?: 'cvsync-blob');
        if ($tmp === false) {
            throw new \CVSync\Adapters\AdapterException('wp_tempnam falhou para o sideload.');
        }
        try {
            $this->store->readVerified($sidecar->blobHex(), $sidecar->blobExtension(), $tmp);
        } catch (\Throwable $e) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
            throw $e;
        }

        // Passos 1–4: validação ANTES de escrever qualquer byte em uploads.
        $validation = $this->validator->validate($tmp, $sidecar);
        if (!$validation->ok()) {
            unlink($tmp);
            throw new \CVSync\Adapters\RejectedEntityException(
                'Cadeia §A.5.1 violada: ' . implode(' | ', $validation->violations)
            );
        }

        // Destino determinístico: original_path preservado (§A.5.2.1).
        $warnings = $validation->warnings;
        $yearMonthOn = (string) get_option('uploads_use_yearmonth_folders') === '1';
        $desiredRel = $this->desiredRelativePath($sidecar, $yearMonthOn);
        $targetAbs = $baseDir . '/' . $desiredRel;

        // Contenção §6.4 estendida ao uploads + symlink check (passo 6).
        $this->assertUploadsContainment($baseDir, $targetAbs);

        // Colisão: mesmo hash → reuso idempotente; conteúdo diferente → Y/m
        // corrente com sufixo + warning (§A.5.2.1).
        if (is_file($targetAbs)) {
            if ($this->sameContents($targetAbs, $sidecar->blobHex())) {
                unlink($tmp);

                return new SideloadOutcome($desiredRel, null, $validation->degraded, $warnings);
            }
            $warnings[] = sprintf('Colisão de path com conteúdo divergente em "%s" — usando Y/m corrente com sufixo.', $desiredRel);
            $desiredRel = null; // delega ao sideload o diretório corrente + unique filename
        }

        // Passo 8: $_FILES sintético → wp_handle_sideload (test_form=false).
        $subdir = $desiredRel !== null ? '/' . trim(dirname($desiredRel), '.') : null;
        $uploadDirFilter = null;
        if ($subdir !== null && $subdir !== '/') {
            $subdir = '/' . trim((string) dirname($desiredRel), '/');
            $uploadDirFilter = static function (array $dir) use ($subdir): array {
                $dir['subdir'] = $subdir;
                $dir['path'] = $dir['basedir'] . $subdir;
                $dir['url'] = $dir['baseurl'] . $subdir;

                return $dir;
            };
            add_filter('upload_dir', $uploadDirFilter);
        }

        $syntheticFile = [
            'name'     => sanitize_file_name(basename($desiredRel ?? $sidecar->originalFilename)),
            'type'     => $sidecar->mime,
            'tmp_name' => $tmp,
            'error'    => 0,
            'size'     => (int) filesize($tmp),
        ];

        try {
            $handled = wp_handle_sideload($syntheticFile, ['test_form' => false], null);
        } finally {
            if ($uploadDirFilter !== null) {
                remove_filter('upload_dir', $uploadDirFilter);
            }
        }

        if (isset($handled['error']) || !isset($handled['file'])) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
            throw new \CVSync\Adapters\AdapterException(
                'wp_handle_sideload falhou: ' . (string) ($handled['error'] ?? 'sem arquivo retornado')
            );
        }

        $finalAbs = (string) $handled['file'];
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
        chmod($finalAbs, (int) (\CVSync\Environment::constant('CVSYNC_FILE_MODE') ?? 0664));

        $relPath = ltrim(substr($finalAbs, strlen($baseDir)), '/');
        $this->materialized[] = $relPath;

        return new SideloadOutcome($relPath, $finalAbs, $validation->degraded, $warnings);
    }

    /**
     * Compensação do dual-write (§A.5.2.3): unlink SOMENTE de bytes escritos
     * NESTA operação cujo insert falhou — path de variável local, nunca
     * derivado de estado pré-existente. Única exceção à regra de ouro.
     */
    public function compensate(?string $writtenAbsPath): void
    {
        if ($writtenAbsPath !== null && is_file($writtenAbsPath)) {
            unlink($writtenAbsPath);
        }
    }

    /** Registra o attachment para a regeneração coalescida de fim de lote. */
    public function scheduleRegeneration(int $attachmentId): void
    {
        $this->pendingRegeneration[] = $attachmentId;
    }

    /**
     * Marca applied-degraded SEM agendar regeneração (§A.5.1.4/§A.5.6): acima
     * de 50 MP ou imagem truncada que É o commitada — o original serve;
     * degradação é qualidade de ambiente, retentável via wp media regenerate.
     */
    public function markDegraded(int $attachmentId): void
    {
        $this->degraded[$attachmentId] = true;
    }

    /**
     * Regeneração coalescida pós-commit (§A.5.2.5): gera as sizes DO TEMA DO
     * DESTINO e relê EXIF. Falha NÃO invalida o import → applied-degraded.
     * Dispara 'cvsync_files_materialized' com os paths do lote (§A.10.3).
     *
     * @return array<int,string> attachmentId => 'ok'|'degraded'
     */
    public function regeneratePending(): array
    {
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $results = [];
        foreach ($this->pendingRegeneration as $attachmentId) {
            $file = get_attached_file($attachmentId);
            if ($file === false || !is_file($file)) {
                $results[$attachmentId] = 'degraded';
                continue;
            }
            $metadata = wp_generate_attachment_metadata($attachmentId, $file);
            $results[$attachmentId] = is_array($metadata) && $metadata !== [] ? 'ok' : 'degraded';
            if (is_array($metadata) && $metadata !== []) {
                wp_update_attachment_metadata($attachmentId, $metadata);
            }
        }
        $this->pendingRegeneration = [];

        foreach (array_keys($this->degraded) as $attachmentId) {
            $results[$attachmentId] = 'degraded';
        }
        $this->degraded = [];

        if ($this->materialized !== []) {
            do_action('cvsync_files_materialized', $this->materialized);
        }
        $this->materialized = [];

        return $results;
    }

    /** @return list<string> Paths materializados no lote corrente (§A.10.3). */
    public function materializedFiles(): array
    {
        return $this->materialized;
    }

    /**
     * Self-heal binário (§A.5.3) — reparo pós-restore de dump. Puramente
     * aditivo: só escreve onde file_exists falha; nunca sobrescreve byte
     * alheio; tx atualiza SOMENTE a state (+ _wp_attached_file em colisão).
     */
    public function selfHeal(EntityRef $ref, ImportContext $ctx): MaterializeResult
    {
        if ($ctx->environment === 'prod') {
            return new MaterializeResult(LogResult::SkippedProdFlag, null, false, 'missing_binary apenas reportado em prod (§A.5.3.1)');
        }

        $work = function () use ($ref, $ctx): MaterializeResult {
            $record = $this->state->get($ref);
            if ($record === null || $record->dbId === null) {
                return new MaterializeResult(LogResult::Error, null, false, 'self-heal sem state/db_id');
            }
            $post = get_post($record->dbId);
            if (!$post instanceof \WP_Post || $post->post_type !== 'attachment') {
                return new MaterializeResult(LogResult::Error, null, false, 'self-heal: post não é attachment');
            }

            $attachedRel = (string) get_post_meta($post->ID, '_wp_attached_file', true);
            $uploads = wp_upload_dir();
            $attachedAbs = (string) $uploads['basedir'] . '/' . $attachedRel;
            if ($attachedRel !== '' && is_file($attachedAbs)) {
                return new MaterializeResult(LogResult::SkippedIdempotent, $attachedRel, false, 'binário presente — nada a reparar');
            }

            // Sidecar do repo: fonte do blob + validação.
            $sidecarPath = 'media/' . $post->post_name . '.attachment.yml';
            $raw = $this->contentPaths->read($sidecarPath);
            if ($raw === null) {
                return new MaterializeResult(LogResult::Error, null, false, 'self-heal sem sidecar: ' . $sidecarPath);
            }
            $sidecar = Sidecar::fromYaml($raw);

            // Integridade CAS contra o bin_hash do STATE (não só o nome do arquivo).
            if ($record->binHash !== null
                && strtolower($record->binHash) !== $sidecar->blobHex()
            ) {
                return new MaterializeResult(
                    LogResult::BinaryHashMismatch,
                    null,
                    false,
                    'bin_hash do state diverge do sidecar — não é reparo; segue a tabela de decisão'
                );
            }

            // Envelope completo §A.5.1 — sem exceção por ser "reparo".
            $outcome = $this->sideload($sidecar);

            // Transação: SOMENTE state (+ _wp_attached_file se o path mudou).
            $this->state->withLockedRow($ref, function () use ($record, $post, $attachedRel, $outcome): void {
                if ($outcome->uploadsRelPath !== $attachedRel) {
                    update_post_meta((int) $post->ID, '_wp_attached_file', $outcome->uploadsRelPath);
                }
                $this->state->upsert($ref, [
                    'db_hash'         => $record->lastSyncHash,
                    'status'          => EntityStatus::Ok,
                    'pending_payload' => null,
                ]);
            });

            $this->scheduleRegeneration((int) $post->ID);
            $this->appendLog($ref, LogResult::BinaryRematerialized, null, $outcome->uploadsRelPath);

            return new MaterializeResult(LogResult::BinaryRematerialized, $outcome->uploadsRelPath, $outcome->degraded);
        };

        return $this->guard->isImporting() ? $work() : $this->guard->run($work);
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /** Path relativo determinístico (original_path; flat quando Y/m off). */
    private function desiredRelativePath(Sidecar $sidecar, bool $yearMonthOn): string
    {
        $filename = sanitize_file_name($sidecar->originalFilename);
        $original = $sidecar->originalPath !== null ? trim($sidecar->originalPath, '/') : '';

        if ($yearMonthOn && $original !== '' && preg_match('/^\d{4}\/\d{2}\/[a-z0-9\-\.]+$/', $original) === 1) {
            return $original;
        }
        if ($yearMonthOn) {
            return gmdate('Y/m') . '/' . $filename;
        }

        return $filename;
    }

    /** Contenção §6.4 estendida ao uploads: realpath contido + is_link (passo 6). */
    private function assertUploadsContainment(string $baseDir, string $targetAbs): void
    {
        $realBase = realpath($baseDir);
        if ($realBase === false) {
            throw new \CVSync\Adapters\AdapterException('uploads basedir ilegível.');
        }

        $ancestor = dirname($targetAbs);
        while (!file_exists($ancestor) && $ancestor !== dirname($ancestor)) {
            $ancestor = dirname($ancestor);
        }
        $realAncestor = realpath($ancestor);
        if ($realAncestor === false
            || ($realAncestor !== $realBase && !str_starts_with($realAncestor, $realBase . '/'))
        ) {
            throw new \CVSync\Adapters\AdapterException(sprintf('Destino escapa do uploads dir: %s', $targetAbs));
        }
        if (is_link($targetAbs)) {
            throw new \CVSync\Adapters\AdapterException(sprintf('Destino em uploads é symlink: %s', $targetAbs));
        }
    }

    /** Colisão: compara o sha256 do arquivo existente com o blob esperado. */
    private function sameContents(string $absPath, string $expectedHex): bool
    {
        $ctx = hash_init('sha256');
        $in = fopen($absPath, 'rb');
        if ($in === false) {
            return false;
        }
        hash_update_stream($ctx, $in);
        fclose($in);

        return hash_equals(strtolower($expectedHex), hash_final($ctx));
    }

    private function appendLog(EntityRef $ref, LogResult $result, ?string $error, ?string $path): void
    {
        try {
            $this->log->append(new LogEntry(
                null,
                $ref,
                'attachment',
                SyncDirection::FileToDb,
                'cli',
                'cvsync-import',
                $path,
                null,
                null,
                null,
                $result,
                $error,
                null,
                new \DateTimeImmutable('now', wp_timezone())
            ));
        } catch (\Throwable) {
            // Audit log nunca derruba o fluxo.
        }
    }
}
