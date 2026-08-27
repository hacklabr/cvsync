<?php
/**
 * VerifyRunner — computação do `wp sync verify` (§8.3, §11.1, §A.4.3,
 * §A.9.2, §A.10.5) isolada em classe reutilizável: o comando WP-CLI
 * (CommandVerify) E o handler admin (IoHandlers::handleRunVerify — botão
 * "Verificar agora" do painel) consomem o MESMO caminho. Zero dependência de
 * WP_CLI — web-safe por construção (a sonda PhpExecProbe autolimita-se a CLI
 * e devolve INDETERMINADO em request web, §A.9.2).
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

use CVSync\Engine\Hasher;
use CVSync\Environment;
use CVSync\Media\PhpExecProbe;
use CVSync\Storage\EntityStatus;
use CVSync\Storage\Schema;
use CVSync\Storage\StateRecord;

defined('ABSPATH') || exit;

final class VerifyRunner
{
    public function __construct(private readonly Container $c)
    {
    }

    /**
     * @return array{report: array<string, mixed>, divergent: int, security_fail: bool}
     */
    public function compute(bool $deep = false): array
    {
        $items  = [];
        $counts = [
            'ok' => 0, 'drift-db' => 0, 'drift-file' => 0, 'orphan' => 0,
            'pending_ref' => 0, 'conflict' => 0, 'missing_binary' => 0,
            'oversized-untracked' => 0, 'drift-external' => 0,
            'orphaned-term' => 0, // Apêndice B.7.2 — informativo, NÃO soma em divergent
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

        // Termos de taxonomia (Apêndice B.7.2) — apenas quando há taxonomias
        // versionadas configuradas (default vazio, B.1.1).
        $taxonomies = $this->versionedTaxonomies();
        if ([] !== $taxonomies) {
            $this->verifyTerms($taxonomies, $counts, $items);
        }

        // Tree-hash por tipo (§11.1) — um valor comparável entre ambientes.
        $treeHashes = [];
        foreach ($this->c->adapters->versionedPostTypes() as $postType) {
            $treeHashes[$postType] = $this->c->state->treeHash('post', $postType);
        }
        if ([] !== $taxonomies) {
            $treeHashes['term'] = $this->c->state->treeHash('term'); // B.7.2 — comparável entre ambientes
        }

        // Sonda PHP-off em uploads (§A.9.2) — autolimitada a CLI (web → INDETERMINADO).
        $probe = null !== $this->c->phpExecProbe
            ? $this->c->phpExecProbe->check()
            : ['status' => PhpExecProbe::INDETERMINATE, 'detail' => 'P4 indisponível'];

        $divergent = $counts['drift-db'] + $counts['drift-file'] + $counts['orphan']
            + $counts['pending_ref'] + $counts['conflict'] + $counts['missing_binary']
            + $counts['oversized-untracked'];

        return [
            'report' => [
                'environment'   => Environment::current(),
                'schema_version' => Schema::installedVersion(),
                'counts'        => $counts,
                'tree_hashes'   => $treeHashes,
                'security'      => ['uploads-php-exec' => $probe],
                'items'         => $items,
            ],
            'divergent'     => $divergent,
            'security_fail' => PhpExecProbe::FAIL === $probe['status'],
        ];
    }

    /**
     * Taxonomias versionadas (B.1.1): AdapterRegistry quando expõe; senão o
     * filtro cvsync/taxonomies direto (mesma semântica do CommandBase).
     *
     * @return list<string>
     */
    private function versionedTaxonomies(): array
    {
        if (method_exists($this->c->adapters, 'versionedTaxonomies')) {
            return $this->c->adapters->versionedTaxonomies();
        }

        $taxonomies = [];
        foreach ((array) apply_filters('cvsync/taxonomies', []) as $key => $value) {
            $taxonomies[] = is_int($key) ? (string) $value : (string) $key;
        }

        return array_values(array_unique($taxonomies));
    }

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

        // Attachments: presença física do binário (pré-filtro) + deep (re-hash §A.4.3).
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

    /**
     * Seções de termos do verify (Apêndice B.7.2): untracked + dangling →
     * orphan (divergente); órfãos informativos → 'orphaned-term' (NÃO soma em
     * divergent; nunca auto-limpeza). APIs do P2 (findUntrackedTerms/
     * findDanglingTermRefs).
     *
     * @param list<string> $taxonomies
     * @param array<string, int> $counts
     * @param list<array<string, string>> $items
     */
    private function verifyTerms(array $taxonomies, array &$counts, array &$items): void
    {
        foreach ($this->c->state->findUntrackedTerms($taxonomies) as $term) {
            $row    = (array) $term;
            $counts['orphan']++;
            $items[] = [
                'entity' => sprintf('term::%s:%s', (string) ($row['taxonomy'] ?? ''), (string) ($row['slug'] ?? '')),
                'status' => 'orphan',
                'detail' => 'termo sem linha de state',
            ];
        }

        foreach ($this->c->state->findDanglingTermRefs() as $record) {
            $counts['orphan']++;
            $items[] = ['entity' => $record->ref->toTupleString(), 'status' => 'orphan', 'detail' => 'state com db_id de termo inexistente'];
        }

        foreach ($this->c->state->all('term') as $record) {
            if (EntityStatus::Tombstone === $record->status) {
                continue;
            }
            [$taxonomy, $slug] = explode(':', $record->ref->key, 2) + [1 => ''];
            if ('' === $slug || ! taxonomy_exists($taxonomy)) {
                continue;
            }
            $term = get_term_by('slug', $slug, $taxonomy);
            if (! $term instanceof \WP_Term) {
                continue; // dangling já coberto acima
            }
            if ($this->hasVersionedReferencer((int) $term->term_id, $taxonomy)) {
                continue;
            }
            $counts['orphaned-term']++;
            $items[] = [
                'entity' => $record->ref->toTupleString(),
                'status' => 'orphaned-term',
                'detail' => 'termo versionado sem post versionado referenciando (informativo — B.7.2)',
            ];
        }
    }

    /** Heurística do órfão informativo: algum post versionado referencia o termo? */
    private function hasVersionedReferencer(int $termId, string $taxonomy): bool
    {
        $objectIds = get_objects_in_term([$termId], $taxonomy);
        if (is_wp_error($objectIds)) {
            return true; // erro de leitura → conservador: não reporta órfão
        }

        $versioned = $this->c->adapters->versionedPostTypes();
        foreach ($objectIds as $objectId) {
            $post = get_post((int) $objectId);
            if ($post instanceof \WP_Post && in_array($post->post_type, $versioned, true)) {
                return true;
            }
        }

        return false;
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

    /** @return list<StateRecord> */
    private function allRecords(): array
    {
        return $this->c->state->all();
    }

    private function hex(string $hash): string
    {
        return str_starts_with($hash, Hasher::PREFIX) ? substr($hash, strlen(Hasher::PREFIX)) : $hash;
    }
}
