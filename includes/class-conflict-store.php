<?php
/**
 * ConflictStore — tabela wp_cvsync_conflicts (spec §7.4).
 *
 * Preserva o lado PERDEDOR de todo conflito (payload canônico; attachments:
 * sidecar + bin_hash em JSON — §A.4.2). Cobre menus/locations/global styles
 * (sem revisions) e dá visibilidade agregada (wp sync conflicts / conflict
 * show / resolve — P5 consome).
 *
 * Retenção (§7.4): últimas 5 NÃO-resolvidas por entidade (aplicada no record);
 * purge de resolvidas após 90 dias (cron de manutenção — P5 agenda).
 *
 * @package CVSync\Storage
 */

declare(strict_types=1);

namespace CVSync\Storage;

use CVSync\Engine\EntityRef;

defined('ABSPATH') || exit;

final class ConflictStore
{
    /** Não-resolvidas mantidas por entidade (§7.4). */
    private const KEEP_UNRESOLVED = 5;

    public function __construct(private readonly \wpdb $db)
    {
    }

    /**
     * Registra o perdedor e aplica a retenção (últimas 5 não-resolvidas da
     * entidade) na mesma operação.
     *
     * @return int ID do conflito registrado.
     * @throws StorageException
     */
    public function record(ConflictRecord $conflict): int
    {
        $result = $this->db->insert($this->table(), $conflict->toRow());
        if (false === $result) {
            $this->assertNoError('conflicts.record');
            throw new StorageException('conflicts.record falhou sem last_error.');
        }

        $id = (int) $this->db->insert_id;

        $this->enforceRetention($conflict->ref);

        return $id;
    }

    public function get(int $id): ?ConflictRecord
    {
        $row = $this->db->get_row(
            $this->db->prepare('SELECT * FROM %i WHERE id = %d', $this->table(), $id),
            ARRAY_A
        );
        $this->assertNoError('conflicts.get');

        return null === $row ? null : ConflictRecord::fromRow($row);
    }

    /**
     * Conflitos pendentes (wp sync conflicts). Via idx_entity.
     *
     * @return list<ConflictRecord>
     */
    public function listUnresolved(?EntityRef $ref = null, int $limit = 100): array
    {
        if (null !== $ref) {
            $rows = $this->db->get_results(
                $this->db->prepare(
                    'SELECT * FROM %i WHERE entity_kind = %s AND entity_key = %s AND resolved_at IS NULL ORDER BY id DESC LIMIT %d',
                    $this->table(),
                    $ref->kind,
                    $ref->key,
                    $limit
                ),
                ARRAY_A
            );
        } else {
            $rows = $this->db->get_results(
                $this->db->prepare(
                    'SELECT * FROM %i WHERE resolved_at IS NULL ORDER BY id DESC LIMIT %d',
                    $this->table(),
                    $limit
                ),
                ARRAY_A
            );
        }
        $this->assertNoError('conflicts.listUnresolved');

        return array_map(ConflictRecord::fromRow(...), $rows ?: []);
    }

    /**
     * Marca resolved_at (o vencedor já foi aplicado pelo engine/orquestrador
     * ANTES — esta marca é apenas o fechamento administrativo, §7.4).
     */
    public function markResolved(int $id): bool
    {
        $result = $this->db->update(
            $this->table(),
            ['resolved_at' => current_time('mysql')],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
        if (false === $result) {
            $this->assertNoError('conflicts.markResolved');
            throw new StorageException('conflicts.markResolved falhou sem last_error.');
        }

        return $result > 0;
    }

    /**
     * Purge de resolvidas mais velhas que N dias (default 90, §7.4).
     *
     * @return int Linhas removidas.
     */
    public function pruneResolved(int $olderThanDays = 90): int
    {
        $cutoff = (new \DateTimeImmutable('now', wp_timezone()))
            ->modify("-{$olderThanDays} days")
            ->format('Y-m-d H:i:s');

        $result = $this->db->query(
            $this->db->prepare(
                'DELETE FROM %i WHERE resolved_at IS NOT NULL AND resolved_at < %s',
                $this->table(),
                $cutoff
            )
        );
        $this->assertNoError('conflicts.pruneResolved');

        return (int) $result;
    }

    // ------------------------------------------------------------------

    private function table(): string
    {
        return Schema::table('conflicts');
    }

    /**
     * Mantém as últimas KEEP_UNRESOLVED não-resolvidas da entidade. A subquery
     * é embrulhada em tabela derivada (o MariaDB proíbe DELETE com subquery na
     * mesma tabela sem o wrapping).
     */
    private function enforceRetention(EntityRef $ref): void
    {
        $table = $this->table();

        $this->db->query(
            $this->db->prepare(
                "DELETE FROM {$table}
                 WHERE entity_kind = %s AND entity_key = %s AND resolved_at IS NULL
                   AND id NOT IN (
                       SELECT id FROM (
                           SELECT id FROM {$table}
                           WHERE entity_kind = %s AND entity_key = %s AND resolved_at IS NULL
                           ORDER BY id DESC
                           LIMIT %d
                       ) AS keep
                   )",
                $ref->kind,
                $ref->key,
                $ref->kind,
                $ref->key,
                self::KEEP_UNRESOLVED
            )
        );
        $this->assertNoError('conflicts.enforceRetention');
    }

    /**
     * @throws StorageException
     */
    private function assertNoError(string $operation): void
    {
        if ('' !== $this->db->last_error) {
            throw new StorageException(sprintf('%s: %s', $operation, $this->db->last_error));
        }
    }
}
