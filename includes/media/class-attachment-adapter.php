<?php
/**
 * AttachmentAdapter — anexos como entidades de primeira classe (§A.2.1):
 * entity_kind='post', post_type='attachment' na state table — reutiliza
 * uq_entity, db_id, tombstones e a tabela de 9 casos sem ramo novo no engine.
 *
 * Exceções declaradas:
 *  - E4: SEM revisions (core não registra para attachment) — a rede é git +
 *    cvsync_conflicts + bytes preservados (§A.7);
 *  - E5: SEM trash por default — delete() remove o POST com o filtro
 *    'wp_delete_attachment_files' → [] dentro do escopo (bytes preservados;
 *    §A.7 regra de ouro);
 *  - Binário local ausente ⇒ db_hash=NULL + pending_ref com
 *    pending_payload {"missing_binary":true} — caminho DIRETO P4→StateStore,
 *    nunca hash parcial, nunca DecisionEngine (§A.4.1);
 *  - Export lê SEMPRE o original (chave 'original_image' quando
 *    _wp_attached_file aponta para '-scaled' — §A.5.2.7); width/height/
 *    blob_size extraídos DO BINÁRIO via getimagesize (nunca do meta, §A.3.2.1);
 *  - O apply escreve bytes ANTES de qualquer DML (dual-write §A.5.2) e a
 *    compensação (unlink dos bytes desta operação) ocorre no catch, antes do
 *    rollback do withLockedRow.
 *
 * NOTA DE FORMATO: o sidecar é YAML integral (§A.3.2) — suportado pelo
 * Exporter genérico via serializeDocument() (r6, G-P4-1 resolvido). O fluxo
 * dedicado exportAttachment() PERMANECE como caminho primário de mídia por
 * paridade de comportamento: setBinaryMeta (bin_* da state, §A.4.2) e
 * OversizedException → skipped-oversized (§A.5.4), que o fluxo genérico não
 * cobre.
 *
 * @package CVSync\Media
 */

declare(strict_types=1);

namespace CVSync\Media;

use CVSync\Adapters\AbstractPostAdapter;
use CVSync\Adapters\ReferenceResolver;
use CVSync\ApplyResult;
use CVSync\Engine\CanonicalDocument;
use CVSync\Engine\EntityRef;
use CVSync\Engine\Hasher;
use CVSync\ImportContext;
use CVSync\PathGuard;
use CVSync\Storage\AuditLog;
use CVSync\Storage\EntityStatus;
use CVSync\Storage\LogEntry;
use CVSync\Storage\LogResult;
use CVSync\Storage\StateStore;
use CVSync\Storage\SyncDirection;

defined('ABSPATH') || exit;

final class AttachmentAdapter extends AbstractPostAdapter
{
    /**
     * Sidecar completo do último readCanonical() (o CanonicalDocument carrega
     * apenas o subconjunto hasheado; os campos informativos são necessários
     * para serializar o arquivo — §A.3.2).
     */
    private ?Sidecar $pendingSidecar = null;

    public function __construct(
        StateStore $state,
        ReferenceResolver $resolver,
        PathGuard $paths,
        private readonly MediaStore $store,
        private readonly Materializer $materializer,
        private readonly AuditLog $log,
        private readonly ?\CVSync\Storage\Locks $locks = null,
    ) {
        parent::__construct($state, $resolver, $paths);
    }

    public function postType(): string
    {
        return 'attachment';
    }

    public function statuses(): array
    {
        return ['inherit']; // E3/§A.2.3
    }

    public function baseDirectory(): string
    {
        return 'media';
    }

    public function fileExtension(): string
    {
        return '.attachment.yml';
    }

    public function metaWhitelist(): array
    {
        return ['_wp_attached_file', '_wp_attachment_image_alt']; // §A.2.4 — positiva e curta
    }

    public function identityTaxonomies(): array
    {
        return [];
    }

    public function keyOrder(): array
    {
        return Sidecar::HASH_KEY_ORDER;
    }

    public function hasBlockBody(): bool
    {
        return false;
    }

    public function relativePath(CanonicalDocument $doc): string
    {
        return 'media/' . $doc->slug() . $this->fileExtension();
    }

    public function locateFile(EntityRef $ref): ?string
    {
        // Sidecars são nomeados por slug: resolve o post e deriva o path.
        $post = $this->resolvePost($ref);
        if ($post instanceof \WP_Post) {
            $path = 'media/' . $post->post_name . $this->fileExtension();

            return $this->paths->exists($path) ? $path : null;
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Export (banco → repo) — fluxo dedicado (sidecar + blob CAS)
    // ------------------------------------------------------------------

    public function readCanonical(EntityRef $ref): ?CanonicalDocument
    {
        $sidecar = $this->buildSidecar($ref, false); // leitura: nunca grava no repo (🟡3)
        if ($sidecar === null) {
            return null;
        }
        $this->pendingSidecar = $sidecar;

        return new CanonicalDocument(
            $ref,
            $sidecar->hashFields(),
            '',
            $sidecar->blob
        );
    }

    /**
     * Sidecar YAML integral (§A.3.2): TODOS os campos + 'hash' por último.
     * Depende do sidecar montado pelo readCanonical() imediatamente anterior
     * (fluxo do Exporter); sem ele, não há como reconstruir os campos
     * informativos a partir do CanonicalDocument.
     */
    public function serializeDocument(CanonicalDocument $doc, string $hash): string
    {
        if ($this->pendingSidecar === null || $this->pendingSidecar->uuid !== $doc->uuid()) {
            throw new \CVSync\Adapters\AdapterException(
                'AttachmentAdapter::serializeDocument sem sidecar montado (readCanonical prévio obrigatório).'
            );
        }

        return $this->pendingSidecar->toYaml($hash);
    }

    /**
     * Export dedicado de UM attachment (MediaHooks no shutdown / P5 bulk):
     * blob CAS + sidecar, idempotente (byte-idêntico → zero escrita).
     * Com Locks injetado, adquire named lock por entidade FAIL-OPEN (§5.8) —
     * sem lock, a entidade é reprocessada no próximo ciclo (null → skip silencioso).
     */
    public function exportAttachment(EntityRef $ref, string $trigger): ?LogResult
    {
        $lock = $this->locks?->tryAcquireEntity($ref);
        if ($this->locks !== null && $lock === null) {
            return null; // fail-open — próximo ciclo reprocessa
        }

        try {
            return $this->doExportAttachment($ref, $trigger);
        } finally {
            $lock?->release();
        }
    }

    private function doExportAttachment(EntityRef $ref, string $trigger): LogResult
    {
        $post = $this->resolvePost($ref);

        if (!$post instanceof \WP_Post) {
            // Deleção no banco (§A.7): export remove SOMENTE o sidecar; o blob
            // fica para o GC. (O core apaga os bytes no fluxo do admin — não o plugin.)
            $record = $this->state->get($ref);
            $path = $record !== null ? $this->locateFile($ref) : null;
            if ($path !== null) {
                $this->paths->delete($path);
            }
            if ($record !== null) {
                $this->state->tombstone($ref);
            }

            return LogResult::Applied;
        }

        try {
            $sidecar = $this->buildSidecar($ref, true); // export efetivo: persiste o blob no CAS
        } catch (OversizedException $e) {
            $this->appendLog($ref, $trigger, LogResult::SkippedOversized, $e->getMessage());

            return LogResult::SkippedOversized; // §A.5.4 — escopo, não erro
        }

        if ($sidecar === null) {
            return LogResult::SkippedIdempotent; // missing_binary já sinalizado no state
        }

        $doc = new CanonicalDocument($ref, $sidecar->hashFields(), '', $sidecar->blob);
        $hash = Hasher::hashDocument($doc, Sidecar::HASH_KEY_ORDER);
        $yaml = $sidecar->toYaml($hash);
        $relative = $this->relativePath($doc);

        // Idempotência estrita (r8, 🔴3): byte-idêntico → skip SEM escrita e
        // sem recordSync redundante (espelha o Exporter genérico; --check verde).
        if ($this->paths->matchesContents($relative, $yaml)) {
            $this->state->touchFileMeta($ref, $this->hashHex($hash), $this->paths->mtime($relative));

            return LogResult::SkippedIdempotent;
        }

        // Degradação graciosa em FS read-only (§10.7 — nunca fatal; r8, 🔵5).
        $targetDir = dirname($this->paths->resolveWritable($relative));
        if (!is_writable(is_dir($targetDir) ? $targetDir : dirname($targetDir))) {
            $this->appendLog($ref, $trigger, LogResult::SkippedFsReadonly, 'FS read-only');

            return LogResult::SkippedFsReadonly;
        }

        $this->paths->writeAtomic($relative, $yaml);

        $original = $this->originalBinaryPath($post);
        $binMtime = $original !== null && is_file($original['abs']) ? (int) filemtime($original['abs']) : null;

        $this->state->upsert($ref, ['db_id' => (int) $post->ID]);
        $this->state->recordSync($ref, SyncDirection::DbToFile, $this->hashHex($hash), null, $this->paths->mtime($relative));
        $this->state->setBinaryMeta($ref, $sidecar->blobHex(), $sidecar->blobSize, $binMtime);
        $this->appendLog($ref, $trigger, LogResult::Applied, null);

        return LogResult::Applied;
    }

    /**
     * Monta o sidecar a partir do post+binário. Binário local ausente ⇒
     * caminho direto P4→P2 (§A.4.1) e null.
     *
     * $persistBlob: true SOMENTE no export efetivo (grava o blob no CAS);
     * false em TODO caminho de leitura (plan/verify/dry-run/snapshot/conflict
     * dump) — leitura canônica nunca escreve no repo (r8, 🟡3). Pré-filtro
     * §A.4.2: size+mtime iguais aos da state ⇒ reusa bin_hash sem I/O de blob
     * (pré-filtros decidem "vale hashear", nunca "quem vence" — §5).
     */
    private function buildSidecar(EntityRef $ref, bool $persistBlob): ?Sidecar
    {
        $post = $this->resolvePost($ref);
        if (!$post instanceof \WP_Post || $post->post_status !== 'inherit') {
            return null;
        }

        $original = $this->originalBinaryPath($post);
        if ($original === null || !is_file($original['abs'])) {
            // §A.4.1: NUNCA hash parcial — sinaliza missing_binary direto no state.
            $this->state->upsert($ref, ['db_hash' => null]);
            $this->state->setPendingPayload($ref, ['refs' => [], 'missing_binary' => true]);
            $this->state->setStatus($ref, EntityStatus::PendingRef);

            return null;
        }

        $size = (int) filesize($original['abs']);
        $mtime = (int) filemtime($original['abs']);
        $ext = strtolower((string) pathinfo($original['abs'], PATHINFO_EXTENSION));

        $record = $this->state->get($ref);
        if ($record !== null
            && $record->binHash !== null
            && $record->binSize === $size
            && $record->binMtime === $mtime
        ) {
            $hex = $record->binHash; // pré-filtro: convergente — zero I/O de blob
            if ($persistBlob && !$this->store->exists($hex, $ext)) {
                $this->store->storeFromUploads($original['abs'], $ext); // CAS incompleto: materializa
            }
        } elseif ($persistBlob) {
            $hex = $this->store->storeFromUploads($original['abs'], $ext)->sha256Hex; // hash na escrita
        } else {
            $hex = $this->store->hashOnly($original['abs']); // leitura: streaming, sem escrita
        }

        $sidecar = new Sidecar();
        $sidecar->uuid = $this->ensureUuid((int) $post->ID);
        $sidecar->slug = $post->post_name;
        $sidecar->title = $post->post_title;
        $sidecar->alt = (string) get_post_meta($post->ID, '_wp_attachment_image_alt', true);
        $sidecar->caption = $post->post_excerpt;
        $sidecar->description = $post->post_content;
        $sidecar->mime = $post->post_mime_type;
        $sidecar->originalFilename = basename($original['rel']);
        $sidecar->originalPath = $original['rel']; // informativo (§A.3.2.2)
        $sidecar->parent = $post->post_parent > 0
            ? $this->resolver->slugForPostId((int) $post->post_parent)
            : null;
        $sidecar->blob = Hasher::PREFIX . $hex;
        $sidecar->blobSize = $size;

        // Deriváveis do BINÁRIO (nunca do meta — §A.3.2.1), informativos.
        if (str_starts_with($sidecar->mime, 'image/')) {
            $imageSize = @getimagesize($original['abs']); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if ($imageSize !== false) {
                $sidecar->width = (int) $imageSize[0];
                $sidecar->height = (int) $imageSize[1];
            }
        }

        return $sidecar;
    }

    /**
     * Path do binário ORIGINAL (§A.5.2.7): quando _wp_attached_file aponta
     * para '-scaled', usa a chave 'original_image' do metadata.
     *
     * @return array{abs:string,rel:string}|null
     */
    private function originalBinaryPath(\WP_Post $post): ?array
    {
        $rel = (string) get_post_meta($post->ID, '_wp_attached_file', true);
        if ($rel === '') {
            return null;
        }

        $meta = get_post_meta($post->ID, '_wp_attachment_metadata', true);
        if (is_array($meta)
            && isset($meta['original_image'])
            && preg_match('/-scaled\.[a-z0-9]+$/i', $rel) === 1
        ) {
            $rel = trailingslashit(dirname($rel)) . $meta['original_image'];
        }

        $uploads = wp_upload_dir();

        return ['abs' => (string) $uploads['basedir'] . '/' . ltrim($rel, '/'), 'rel' => $rel];
    }

    // ------------------------------------------------------------------
    // Import (repo → banco) — via Importer do P3 (guard + withLockedRow)
    // ------------------------------------------------------------------

    public function parseDocument(string $bytes): CanonicalDocument
    {
        $sidecar = Sidecar::fromYaml($bytes);
        $ref = EntityRef::post('attachment', $sidecar->uuid);

        return new CanonicalDocument($ref, $sidecar->hashFields(), '', $sidecar->blob);
    }

    /** A validação de attachments ocorre em Sidecar::fromYaml (YAML integral). */
    public function validateFrontmatter(array $frontmatter): void
    {
    }

    public function apply(CanonicalDocument $doc, ImportContext $ctx): ApplyResult
    {
        // Campos informativos (original_path) vivem fora do material hasheado —
        // relidos do arquivo do repo (já validado por Sidecar::fromYaml).
        $raw = $this->paths->read($this->relativePath($doc));
        $sidecar = Sidecar::fromYaml((string) $raw);

        // §6.3 — posse do UUID (r8, 🔴4): MESMA verificação do pipeline de
        // posts — dois sidecars com o mesmo uuid = sequestro → conflito, NUNCA
        // apply. Sem exceção para mídia (o apêndice não abre nenhuma).
        $claimants = $this->filesClaimingUuid($sidecar->uuid);
        if (count($claimants) > 1) {
            $post = $this->resolvePost($doc->ref);
            throw new \CVSync\Adapters\UuidOwnershipMismatchException(
                sprintf('UUID %s reivindicado por %d sidecars: %s', $sidecar->uuid, count($claimants), implode(', ', $claimants)),
                $sidecar->uuid,
                $sidecar->slug,
                $post instanceof \WP_Post ? $post->post_name : '(ausente)',
                'attachment',
                $post instanceof \WP_Post ? $post->post_type : '(ausente)',
            );
        }

        // Dual-write §A.5.2: bytes ANTES de qualquer DML.
        $outcome = $this->materializer->sideload($sidecar);

        try {
            $post = $this->resolvePost($doc->ref);
            $parentId = $this->resolveParentSlug($sidecar->parent);

            $postarr = [
                'post_type'      => 'attachment',
                'post_name'      => $sidecar->slug,
                'post_title'     => $sidecar->title,
                'post_excerpt'   => $sidecar->caption,
                'post_content'   => $sidecar->description,
                'post_mime_type' => $sidecar->mime,
                'post_status'    => 'inherit',
                'post_parent'    => $parentId ?? 0, // §A.5.2.8: 0 + parent-fixup; NÃO é pending_ref
            ];

            if ($post instanceof \WP_Post) {
                $postarr['ID'] = $post->ID;
                $attachmentId = wp_update_post(wp_slash($postarr), true);
            } else {
                $attachmentId = wp_insert_attachment(wp_slash($postarr), $outcome->uploadsRelPath, $parentId ?? 0, true);
            }
            if (is_wp_error($attachmentId)) {
                throw new \CVSync\Adapters\AdapterException(
                    'Falha ao gravar attachment: ' . $attachmentId->get_error_message()
                );
            }
            $attachmentId = (int) $attachmentId;

            update_post_meta($attachmentId, '_wp_attached_file', $outcome->uploadsRelPath);
            update_post_meta($attachmentId, '_wp_attachment_image_alt', $sidecar->alt);
            // Import: the attachment adopts the SIDECAR uuid (identity churn fix).
            $this->ensureUuid($attachmentId, $sidecar->uuid);

            // bin_mtime = filemtime do arquivo MATERIALIZADO (não time() — 🔵 R5
            // do r9: com time() o pré-filtro errava um re-hash após cada import).
            $materializedAbs = (string) (wp_upload_dir()['basedir'] ?? '') . '/' . $outcome->uploadsRelPath;
            $binMtime = is_file($materializedAbs) ? (int) filemtime($materializedAbs) : null;
            $this->state->setBinaryMeta($doc->ref, $sidecar->blobHex(), $sidecar->blobSize, $binMtime);
        } catch (\Throwable $e) {
            // Compensação §A.5.2.3: unlink SOMENTE dos bytes desta operação.
            $this->materializer->compensate($outcome->writtenAbsPath);
            throw $e;
        }

        // Regeneração coalescida no fim do lote (§A.5.2.5); degraded (50 MP)
        // pula a regeneração e é reportado sem falhar o deploy (§A.5.1.4/A.5.6).
        if ($outcome->degraded) {
            $this->materializer->markDegraded($attachmentId);
        } else {
            $this->materializer->scheduleRegeneration($attachmentId);
        }

        $this->appendLog($doc->ref, $ctx->trigger, LogResult::Sideloaded, null);

        return new ApplyResult($attachmentId, [], [], []);
    }

    /**
     * E5 (§A.7): remove o POST e PRESERVA os bytes — filtro
     * 'wp_delete_attachment_files' → [] dentro do escopo.
     */
    public function delete(EntityRef $ref, bool $force = false): void
    {
        $post = $this->resolvePost($ref);
        if (!$post instanceof \WP_Post) {
            return;
        }

        if (!function_exists('wp_delete_attachment')) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }

        $preserve = static fn (): array => [];
        add_filter('wp_delete_attachment_files', $preserve);
        try {
            wp_delete_attachment((int) $post->ID, true);
        } finally {
            remove_filter('wp_delete_attachment_files', $preserve);
        }
    }

    public function exists(EntityRef $ref): bool
    {
        $post = $this->resolvePost($ref);

        // §A.2.3c: ausência do post = deleção; trash só existe sob MEDIA_TRASH.
        return $post instanceof \WP_Post && $post->post_status !== 'trash';
    }

    /** Remoção contida do sidecar (deleção banco→repo, §A.7: blob fica para o GC). */
    public function deleteFile(string $relative): void
    {
        $this->paths->delete($relative);
    }

    /**
     * Scan de claimants do uuid sobre sidecars (YAML integral — o parser de
     * frontmatter+fences do pai não se aplica a `*.attachment.yml`). Base da
     * verificação de posse §6.3 para mídia (r8, 🔴4).
     *
     * @return list<string> Paths relativos, ordenados.
     */
    protected function filesClaimingUuid(string $uuid): array
    {
        $claimants = [];
        foreach ($this->paths->listFiles($this->baseDirectory()) as $relative) {
            if (!str_ends_with($relative, $this->fileExtension()) || str_contains($relative, '/bin/')) {
                continue;
            }
            $bytes = $this->paths->read($relative);
            if ($bytes === null) {
                continue;
            }
            try {
                $sidecar = Sidecar::fromYaml($bytes);
            } catch (\Throwable) {
                continue; // sidecar inválido não reivindica uuid; o lint/apply o rejeita
            }
            if ($sidecar->uuid === $uuid) {
                $claimants[] = $relative;
            }
        }
        sort($claimants);

        return $claimants;
    }

    /** Parent por slug (§A.5.2.8): metadado organizacional; null → fixup. */
    private function resolveParentSlug(?string $parentSlug): ?int
    {
        if ($parentSlug === null || $parentSlug === '') {
            return null;
        }

        $query = new \WP_Query([
            'name'           => $parentSlug,
            'post_type'      => 'any',
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        return $query->posts !== [] ? (int) $query->posts[0] : null;
    }

    private function appendLog(EntityRef $ref, string $trigger, LogResult $result, ?string $error): void
    {
        try {
            $this->log->append(new LogEntry(
                null,
                $ref,
                'attachment',
                null,
                $trigger,
                (wp_get_current_user()->user_login ?: 'system'),
                null,
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

    private function hashHex(string $hash): string
    {
        return str_starts_with($hash, Hasher::PREFIX) ? substr($hash, strlen(Hasher::PREFIX)) : $hash;
    }
}
