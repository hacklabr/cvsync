<?php
/**
 * `wp sync verify` — recalcula hashes dos dois lados × state (contrato §8.3,
 * §11.1, §A.4.3, §A.9.2, §A.10.5).
 *
 * Relatório por entidade: ok | drift-db | drift-file | orphan | pending_ref |
 * conflict | missing_binary | oversized-untracked. Seções agregadas:
 * tree-hash por tipo (§11.1), drift-external (otimizadores — §A.10.5, exit 0)
 * e security: uploads-php-exec (sonda do P4 — §A.9.2).
 *
 * Flags: --format=json, --deep (re-hash de blobs §A.4.3 — única varredura de
 * disco em massa, sob demanda explícita).
 *
 * Exit: ≠ 0 em divergência (apto para CI/pós-deploy). FAIL da sonda → exit
 * ≠ 0; INDETERMINADO da sonda → warning, exit 0 (§A.9.2 — nunca travar
 * operação por não-verificabilidade).
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

use CVSync\Engine\EntityRef;
use CVSync\Engine\Hasher;
use CVSync\Environment;
use CVSync\Media\PhpExecProbe;
use CVSync\Storage\EntityStatus;
use CVSync\Storage\Schema;
use CVSync\Storage\StateRecord;

defined('ABSPATH') || exit;

final class CommandVerify extends CommandBase
{
    public function __invoke(array $args, array $assocArgs): void
    {
        $this->warnInvalidConstants();

        $deep  = (bool) ($assocArgs['deep'] ?? false);
        $json  = $this->isJson($assocArgs);
        $items = [];
        $counts = [
            'ok' => 0, 'drift-db' => 0, 'drift-file' => 0, 'orphan' => 0,
            'pending_ref' => 0, 'conflict' => 0, 'missing_binary' => 0,
            'oversized-untracked' => 0, 'drift-external' => 0,
        ];

        foreach ($this->allRecords() as $record) {
            [$status, $detail] = $this->verifyRecord($record, $deep);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            if ('ok' !== $status) {
                $items[] = ['entity' => $record->ref->toTupleString(), 'status' => $status, 'detail' => $detail];
            }
        }

        // Órfãos de cobertura (posts do escopo sem state — §A.4.3).
        foreach ($this->c->state->findUntrackedPosts($this->c->adapters->versionedStatuses()) as $untracked) {
            $status = 'orphan';
            if ('attachment' === $untracked['post_type'] && $this->isOversized($untracked['id'])) {
                $status = 'oversized-untracked'; // acima do teto §A.5.4: fora do versionamento por definição
            }
            $counts[$status]++;
            $items[] = ['entity' => sprintf('post:%s:%s', $untracked['post_type'], $untracked['slug']), 'status' => $status, 'detail' => 'post sem linha de state'];
        }

        // Linhas dangling (state com db_id cujo post sumiu, sem tombstone).
        foreach ($this->c->state->findDanglingPostRefs($this->c->adapters->versionedStatuses()) as $dangling) {
            $counts['orphan']++;
            $items[] = ['entity' => $dangling->ref->toTupleString(), 'status' => 'orphan', 'detail' => 'state com db_id de post inexistente'];
        }

        // Tree-hash por tipo (§11.1) — um valor comparável entre ambientes.
        $treeHashes = [];
        foreach ($this->c->adapters->versionedPostTypes() as $postType) {
            $treeHashes[$postType] = $this->c->state->treeHash('post', $postType);
        }

        // Sonda PHP-off em uploads (§A.9.2) — somente CLI.
        $probe = null !== $this->c->phpExecProbe
            ? $this->c->phpExecProbe->check()
            : ['status' => PhpExecProbe::INDETERMINATE, 'detail' => 'P4 indisponível'];

        $divergent = $counts['drift-db'] + $counts['drift-file'] + $counts['orphan']
            + $counts['pending_ref'] + $counts['conflict'] + $counts['missing_binary']
            + $counts['oversized-untracked'];

        $securityFail = PhpExecProbe::FAIL === $probe['status'];

        $report = [
            'environment' => Environment::current(),
            'schema_version' => Schema::installedVersion(),
            'counts'      => $counts,
            'tree_hashes' => $treeHashes,
            'security'    => ['uploads-php-exec' => $probe],
            'items'       => $items,
        ];

        if ($json) {
            $this->jsonLine($report);
        } else {
            $this->render($report, $divergent);
        }

        if ($securityFail) {
            \WP_CLI::error('SECURITY: uploads-php-exec FAIL — ' . $probe['detail']); // exit ≠ 0 (§A.9.2)
        }
        if (PhpExecProbe::INDETERMINATE === $probe['status']) {
            \WP_CLI::warning('uploads-php-exec INDETERMINADO — ' . $probe['detail']); // exit 0 (§A.9.2)
        }

        \WP_CLI::halt($divergent > 0 ? 1 : 0);
    }

    // ------------------------------------------------------------------

    /**
     * @return array{0: string, 1: string} [status, detalhe]
     */
    private function verifyRecord(StateRecord $record, bool $deep): array
    {
        $ref = $record->ref;

        if (EntityStatus::Tombstone === $record->status) {
            return ['ok', 'tombstone dentro do TTL'];
        }
        if (EntityStatus::Conflict === $record->status) {
            return ['conflict', 'conflito pendente de resolução'];
        }
        if (EntityStatus::PendingRef === $record->status) {
            if (true === ($record->pendingPayload['missing_binary'] ?? null)) {
                return ['missing_binary', 'binário local ausente (§A.4.1) — self-heal no próximo apply'];
            }

            return ['pending_ref', 'refs: ' . implode(', ', (array) ($record->pendingPayload['refs'] ?? []))];
        }

        $adapter = $this->c->adapters->forRef($ref);
        if (null === $adapter) {
            return ['orphan', 'sem adapter registrado'];
        }

        // Lado banco.
        if ($adapter->exists($ref)) {
            try {
                $doc = $adapter->readCanonical($ref);
                $dbHash = null !== $doc ? $this->hex(Hasher::hashDocument($doc, $adapter->keyOrder())) : null;
            } catch (\Throwable $e) {
                return ['drift-db', 'lado banco não hasheável: ' . $e->getMessage()];
            }
            if (null !== $record->dbHash && null !== $dbHash && $dbHash !== $record->dbHash) {
                return ['drift-db', 'hash do banco diverge do state'];
            }
        }

        // Lado arquivo.
        $path = $adapter->locateFile($ref);
        if (null !== $path) {
            $bytes = $this->c->paths->read($path);
            if (null === $bytes) {
                return ['drift-file', 'arquivo ilegível: ' . $path];
            }
            try {
                $fileHash = $this->hex(Hasher::hashDocument($adapter->parseDocument($bytes), $adapter->keyOrder()));
            } catch (\Throwable $e) {
                return ['drift-file', 'arquivo não parseável: ' . $e->getMessage()];
            }
            if (null !== $record->fileHash && $fileHash !== $record->fileHash) {
                return ['drift-file', 'hash do arquivo diverge do state'];
            }
        } elseif (null !== $record->fileHash) {
            return ['drift-file', 'state registra arquivo ausente no repo'];
        }

        // Attachments: presença física do binário (pré-filtro) + --deep (re-hash §A.4.3).
        if ('attachment' === $ref->postType && null !== $record->dbId) {
            $attachedRel = (string) get_post_meta($record->dbId, '_wp_attached_file', true);
            $attachedAbs = (string) wp_upload_dir()['basedir'] . '/' . $attachedRel;
            if ('' === $attachedRel || ! is_file($attachedAbs)) {
                return ['missing_binary', 'binário ausente em uploads: ' . $attachedRel];
            }
            if ($deep && null !== $record->binHash) {
                $actual = hash_file('sha256', $attachedAbs);
                if (false !== $actual && ! hash_equals(strtolower($record->binHash), $actual)) {
                    return ['drift-external', 'binário reescrito out-of-band (otimizador? §A.10.5) — drift tolerado'];
                }
            }
        }

        return ['ok', ''];
    }

    /** Attachment não rastreado acima do teto §A.5.4 → oversized-untracked. */
    private function isOversized(int $attachmentId): bool
    {
        $file = get_attached_file($attachmentId);
        if (false === $file || ! is_file($file)) {
            return false;
        }
        $size = filesize($file);

        return false !== $size && $size > (int) Environment::constant('CVSYNC_ATTACHMENT_MAX_BYTES');
    }

    /**
     * Scan read-only da state table via StateStore::all() (P2 — r6 item 5;
     * o scan direto com $wpdb foi eliminado na integração).
     *
     * @return list<StateRecord>
     */
    private function allRecords(): array
    {
        return $this->c->state->all();
    }

    private function render(array $report, int $divergent): void
    {
        foreach ($report['items'] as $item) {
            \WP_CLI::log(sprintf('[%-20s] %-45s %s', $item['status'], $item['entity'], $item['detail']));
        }
        foreach ($report['tree_hashes'] as $postType => $hash) {
            \WP_CLI::log(sprintf('tree-hash[%s] = %s', $postType, $hash));
        }
        \WP_CLI::log(sprintf(
            'verify: %s (environment: %s, schema: v%d)',
            $divergent > 0 ? sprintf('%d divergência(s)', $divergent) : 'convergente',
            $report['environment'],
            $report['schema_version']
        ));
    }

    private function hex(string $hash): string
    {
        return str_starts_with($hash, Hasher::PREFIX) ? substr($hash, strlen(Hasher::PREFIX)) : $hash;
    }
}
