<?php
/**
 * AuditLog — tabela wp_cvsync_log, append-only (spec §9.3).
 *
 * Ferramenta de debug e blame ("por que esta página mudou?", §11.1), NÃO
 * auditoria de conteúdo (isso é revisions + git). Retenção: ring buffer —
 * últimos 500 registros OU 30 dias (configurável), via prune() chamado por
 * cron de manutenção (P5 agenda; este pacote só expõe).
 *
 * Append-only por construção: a tabela não tem updated_at e esta classe não
 * expõe UPDATE/DELETE de linha.
 *
 * @package CVSync\Storage
 */

declare(strict_types=1);

namespace CVSync\Storage;

use CVSync\Engine\EntityRef;

defined('ABSPATH') || exit;

final class AuditLog
{
    public function __construct(private readonly \wpdb $db)
    {
    }

    /**
     * @return int ID da entrada gravada.
     * @throws StorageException
     */
    public function append(LogEntry $entry): int
    {
        $result = $this->db->insert($this->table(), $entry->toRow());
        if (false === $result) {
            $this->assertNoError('log.append');
            throw new StorageException('log.append falhou sem last_error.');
        }

        return (int) $this->db->insert_id;
    }

    /**
     * wp sync blame <entity> — via idx_blame (entity_kind, entity_key, created_at).
     *
     * @return list<LogEntry> Mais recentes primeiro.
     */
    public function blame(EntityRef $ref, int $limit = 20): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare(
                'SELECT * FROM %i WHERE entity_kind = %s AND entity_key = %s ORDER BY created_at DESC, id DESC LIMIT %d',
                $this->table(),
                $ref->kind,
                $ref->key,
                $limit
            ),
            ARRAY_A
        );
        $this->assertNoError('log.blame');

        return array_map(LogEntry::fromRow(...), $rows ?: []);
    }

    /**
     * wp sync log --last=N.
     *
     * @return list<LogEntry> Mais recentes primeiro.
     */
    public function recent(int $limit = 50): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare(
                'SELECT * FROM %i ORDER BY id DESC LIMIT %d',
                $this->table(),
                $limit
            ),
            ARRAY_A
        );
        $this->assertNoError('log.recent');

        return array_map(LogEntry::fromRow(...), $rows ?: []);
    }

    /**
     * Ring buffer (default: últimos 500 registros OU 30 dias, §9.3):
     *  1. DELETE por idade (via idx_created);
     *  2. DELETE além do teto de linhas — subquery embrulhada em tabela
     *     derivada (o MariaDB proíbe DELETE com subquery na mesma tabela sem
     *     o wrapping).
     *
     * @return int Linhas removidas (total das duas passadas).
     */
    public function prune(int $maxRows = 500, int $maxDays = 30): int
    {
        $table   = $this->table();
        $deleted = 0;

        $cutoff = (new \DateTimeImmutable('now', wp_timezone()))
            ->modify("-{$maxDays} days")
            ->format('Y-m-d H:i:s');

        $byAge = $this->db->query(
            $this->db->prepare('DELETE FROM %i WHERE created_at < %s', $table, $cutoff)
        );
        $this->assertNoError('log.prune.age');
        $deleted += (int) $byAge;

        $byCount = $this->db->query(
            $this->db->prepare(
                "DELETE FROM {$table}
                 WHERE id NOT IN (
                     SELECT id FROM (
                         SELECT id FROM {$table} ORDER BY id DESC LIMIT %d
                     ) AS keep
                 )",
                $maxRows
            )
        );
        $this->assertNoError('log.prune.count');
        $deleted += (int) $byCount;

        return $deleted;
    }

    // ------------------------------------------------------------------

    private function table(): string
    {
        return Schema::table('log');
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
