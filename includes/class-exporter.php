<?php
/**
 * Exporter — banco → arquivo (spec §2.3).
 *
 * Fluxo por entidade:
 *  1. Named lock por entidade (Locks::tryAcquireEntity), timeout 3s,
 *     FAIL-OPEN: sem lock → null (a entidade permanece dirty_db e o próximo
 *     ciclo reprocessa; NÃO gera linha de audit log — r1-t2);
 *  2. Lê o ESTADO FINAL do banco via adapter (chamado no shutdown — elimina
 *     o bug do "meta tardio", §2.2.1);
 *  3. Serialização canônica + hash via Hasher (forma hasheada ≡ forma gravada);
 *  4. IDEMPOTÊNCIA ESTRITA: arquivo existente byte-idêntico → NÃO escreve
 *     nada, nem mtime (§2.3.3, §4.2.2);
 *  5. Escrita atômica tmp+rename com chmod explícito e contenção §6.4
 *     (PathGuard); FS read-only → 'skipped-fs-readonly' com log, nunca
 *     fatal (§10.7);
 *  6. Entidade viva no state mas ausente/trash no banco → deleção semântica
 *     (§5.5): remove o arquivo e grava tombstone (admin é autoridade em dev).
 *
 * Rename de slug (§4.2.4): mesmo UUID, path novo → o arquivo antigo é removido
 * após a escrita do novo (git detecta o rename).
 *
 * @package CVSync
 */

declare(strict_types=1);

namespace CVSync;

use CVSync\Adapters\AdapterRegistry;
use CVSync\Engine\EntityRef;
use CVSync\Engine\Hasher;
use CVSync\Storage\AuditLog;
use CVSync\Storage\EntityStatus;
use CVSync\Storage\Locks;
use CVSync\Storage\LogEntry;
use CVSync\Storage\LogResult;
use CVSync\Storage\StateStore;
use CVSync\Storage\SyncDirection;

defined('ABSPATH') || exit;

final class Exporter
{
    public function __construct(
        private readonly AdapterRegistry $adapters,
        private readonly StateStore $state,
        private readonly Locks $locks,
        private readonly PathGuard $paths,
        private readonly AuditLog $log,
    ) {
    }

    /**
     * Exporta UMA entidade. null = lock fail-open não adquirida (§5.8) —
     * silencioso por design: a entidade permanece dirty_db.
     */
    public function export(EntityRef $ref, string $trigger): ?ExportResult
    {
        $lock = $this->locks->tryAcquireEntity($ref);
        if ($lock === null) {
            return null; // fail-open (§5.8) — próximo ciclo reprocessa
        }

        try {
            return $this->doExport($ref, $trigger);
        } finally {
            $lock->release();
        }
    }

    /**
     * Re-export BATCH-SAFE (r6, expectativa r4-devops §5.1): os mesmos passos
     * canônicos de export() SEM named lock por entidade — chamado exclusivamente
     * com a batch lock do apply já presa (§5.8: uma named lock por sessão
     * MariaDB; um segundo GET_LOCK liberaria a batch silenciosamente).
     *
     * Usado pelo ApplyRunner para o re-export lossless de conflitos winner=db
     * (§7.5) e de regressão de worktree (§5.7, caso 9).
     */
    public function exportWithinBatch(EntityRef $ref, string $trigger): LogResult
    {
        return $this->doExport($ref, $trigger)->outcome;
    }

    /**
     * Flush do shutdown (§8.1): exporta as entidades dirty_db lendo o estado
     * FINAL do banco (debounce natural que coalesce rajadas de meta).
     *
     * @return array{exported:int,skipped:int,errors:int,results:list<ExportResult>}
     */
    public function exportDirty(string $trigger = 'save-hook'): array
    {
        $summary = ['exported' => 0, 'skipped' => 0, 'errors' => 0, 'results' => []];

        foreach ($this->state->dirtyQueue() as $record) {
            if ($record->status !== EntityStatus::DirtyDb) {
                continue; // o shutdown só exporta; dirty_file/pending/conflict são do apply
            }

            $result = $this->export($record->ref, $trigger);
            if ($result === null) {
                $summary['skipped']++;
                continue;
            }

            $summary['results'][] = $result;
            match ($result->outcome) {
                LogResult::Applied => $summary['exported']++,
                LogResult::Error => $summary['errors']++,
                default => $summary['skipped']++,
            };
        }

        return $summary;
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private function doExport(EntityRef $ref, string $trigger): ExportResult
    {
        $adapter = $this->adapters->forRef($ref);
        if ($adapter === null) {
            return new ExportResult(LogResult::Error, null, null, 'Sem adapter para ' . $ref->toTupleString());
        }

        try {
            $doc = $adapter->readCanonical($ref);

            if ($doc === null) {
                return $this->handleNotExportable($adapter, $ref, $trigger);
            }

            // A forma hasheada é a forma gravada (D6): serializa uma vez.
            // O adapter é dono do formato (r6, G-P4-1): frontmatter+fences para
            // posts; YAML integral para menus/branding/sidecars.
            $hash = Hasher::hashDocument($doc, $adapter->keyOrder());
            $bytes = $adapter->serializeDocument($doc, $hash);
            $relative = $adapter->relativePath($doc);

            // Idempotência estrita (§2.3.3): byte-idêntico → zero escrita de
            // ARQUIVO — mas a linha sai da fila dirty (fix B1 dogfood: caso 1
            // da tabela §5.2 exige skip + status 'ok'; touchFileMeta deixava
            // status='dirty_db' eterno em entidade convergida). recordSync
            // grava os 3 hashes juntos (invariante §5.4) sem tocar o FS.
            if ($this->paths->matchesContents($relative, $bytes)) {
                $this->state->recordSync($ref, SyncDirection::DbToFile, self::hashHex($hash), null, $this->paths->mtime($relative));

                return new ExportResult(LogResult::SkippedIdempotent, $relative, $hash);
            }

            // Degradação graciosa em FS read-only (§10.7) — writabilidade do
            // ANCESTRAL EXISTENTE mais próximo (fix ibiomas: dir alvo de 2+
            // níveis ausentes, ex. terms/categories → dirname=terms/ também
            // inexistente → is_writable=false → "skipped-fs-readonly" eterno,
            // e a criação de árvore do writeAtomic nunca rodava. Árvore
            // ausente NÃO é read-only; o erro genuíno — ancestral existente
            // não gravável — continua detectado).
            $targetDir = dirname($this->paths->resolveWritable($relative));
            $probe = $targetDir;
            while (!is_dir($probe)) {
                $parent = dirname($probe);
                if ($parent === $probe) {
                    $probe = $this->paths->contentDir(); // raiz da contenção — sempre existente
                    break;
                }
                $probe = $parent;
            }
            if (!is_writable($probe)) {
                $this->appendLog($ref, $trigger, $relative, null, null, LogResult::SkippedFsReadonly, sprintf('FS read-only (ancestral %s)', $probe));

                return new ExportResult(LogResult::SkippedFsReadonly, $relative, $hash, 'FS read-only');
            }

            $this->paths->writeAtomic($relative, $bytes);

            // Rename de slug: remove o arquivo no path antigo (mesmo UUID).
            $oldPath = $adapter->locateFile($ref);
            if ($oldPath !== null && $oldPath !== $relative) {
                $this->paths->delete($oldPath);
            }

            $this->state->recordSync(
                $ref,
                SyncDirection::DbToFile,
                self::hashHex($hash),
                null,
                $this->paths->mtime($relative)
            );
            $this->appendLog($ref, $trigger, $relative, null, $hash, LogResult::Applied, null);

            return new ExportResult(LogResult::Applied, $relative, $hash);
        } catch (\Throwable $e) {
            $this->appendLog($ref, $trigger, null, null, null, LogResult::Error, $e->getMessage());

            return new ExportResult(LogResult::Error, null, null, $e->getMessage());
        }
    }

    /**
     * readCanonical() null: auto-draft/status fora do mapa (skip silencioso)
     * × entidade deletada/trash no banco (deleção semântica §5.5: o export
     * remove o arquivo e grava tombstone — admin é autoridade em dev).
     */
    private function handleNotExportable(
        \CVSync\Adapters\EntityAdapter $adapter,
        EntityRef $ref,
        string $trigger
    ): ExportResult {
        if (!$adapter->exists($ref)) {
            $record = $this->state->get($ref);
            $path = $adapter->locateFile($ref);

            // Fix B2 (dogfood): entidade ausente no banco JÁ convergida
            // (arquivo removido + tombstone) → idempotente. Antes: log
            // 'applied/db-deleted' e re-tombstone A CADA export (branding
            // 'applied' em toda rodada, fila dirty eterna).
            if ($path === null
                && ($record === null || $record->status === EntityStatus::Tombstone)
            ) {
                return new ExportResult(LogResult::SkippedIdempotent, null, null, 'db-deleted já convergido (tombstone)');
            }

            if ($path !== null) {
                $this->paths->delete($path);
            }
            if ($record !== null) {
                $this->state->tombstone($ref);
            }
            $this->appendLog($ref, $trigger, $path, $record?->lastSyncHash, null, LogResult::Applied, 'db-deleted');

            return new ExportResult(LogResult::Applied, $path, null);
        }

        return new ExportResult(LogResult::SkippedIdempotent, null, null, 'auto-draft ou status fora do mapa (§3.2/E3)');
    }

    private function appendLog(
        EntityRef $ref,
        string $trigger,
        ?string $filePath,
        ?string $beforeHash,
        ?string $afterHash,
        LogResult $result,
        ?string $error
    ): void {
        try {
            $this->log->append(new LogEntry(
                null,
                $ref,
                $ref->postType ?? '',
                SyncDirection::DbToFile,
                $trigger,
                (wp_get_current_user()->user_login ?: 'system'),
                $filePath,
                $beforeHash !== null ? self::hashHex($beforeHash) : null,
                $afterHash !== null ? self::hashHex($afterHash) : null,
                null,
                $result,
                $error,
                null,
                new \DateTimeImmutable('now', wp_timezone())
            ));
        } catch (\Throwable) {
            // Audit log nunca derruba o fluxo de export (ferramenta de debug, §9.3).
        }
    }

    /** State table guarda o HEX (CHAR(64)); o prefixo 'sha256:' é da forma de arquivo. */
    private static function hashHex(string $hash): string
    {
        return str_starts_with($hash, Hasher::PREFIX) ? substr($hash, strlen(Hasher::PREFIX)) : $hash;
    }
}
