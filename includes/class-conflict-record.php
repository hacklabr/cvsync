<?php
/**
 * Linha tipada e imutável de wp_cvsync_conflicts (spec §7.4).
 *
 * Preserva o lado perdedor de um conflito. Para attachments, loser_payload é o
 * sidecar canônico + bin_hash em JSON (§A.4.2 — MEDIUMTEXT não é lugar de
 * megabytes; o blob perdedor permanece endereçável pelo hash no histórico git).
 *
 * @package CVSync\Storage
 */

declare(strict_types=1);

namespace CVSync\Storage;

use CVSync\Engine\EntityRef;

defined('ABSPATH') || exit;

final readonly class ConflictRecord
{
    /**
     * @param int|null $id null = registro novo (ainda não persistido).
     */
    public function __construct(
        public ?int $id,
        public EntityRef $ref,
        public string $loserSide,   // 'db' | 'file'
        public string $loserPayload, // forma canônica
        public string $winner,      // 'db' | 'file'
        public string $trigger,     // vocábulo §11.1 (coluna trigger_src — emenda A3)
        public string $actor,
        public ?string $gitHead,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $resolvedAt,
    ) {
        foreach (['loserSide', 'winner'] as $field) {
            if (! in_array($this->{$field}, ['db', 'file'], true)) {
                throw new \InvalidArgumentException(
                    sprintf('ConflictRecord: %s deve ser "db" ou "file", recebido "%s".', $field, $this->{$field})
                );
            }
        }
    }

    /**
     * @param object|array<string, mixed> $row Linha crua do $wpdb (tudo string).
     */
    public static function fromRow(object|array $row): self
    {
        $row  = (array) $row;
        $kind = (string) ($row['entity_kind'] ?? '');

        return new self(
            id: (int) $row['id'],
            ref: EntityRef::of($kind, (string) ($row['entity_key'] ?? '')),
            loserSide: (string) ($row['loser_side'] ?? ''),
            loserPayload: (string) ($row['loser_payload'] ?? ''),
            winner: (string) ($row['winner'] ?? ''),
            trigger: (string) ($row['trigger_src'] ?? ''),
            actor: (string) ($row['actor'] ?? ''),
            gitHead: isset($row['git_head']) && '' !== $row['git_head'] ? (string) $row['git_head'] : null,
            createdAt: new \DateTimeImmutable((string) ($row['created_at'] ?? 'now'), wp_timezone()),
            resolvedAt: isset($row['resolved_at']) && '' !== $row['resolved_at']
                ? new \DateTimeImmutable((string) $row['resolved_at'], wp_timezone())
                : null,
        );
    }

    /**
     * Serializa para colunas da tabela (sem id — autoincrement). Nulls reais
     * para colunas nullable: $wpdb->insert() grava NULL quando o valor é null;
     * string vazia seria rejeitada em DATETIME sob sql_mode estrito (MariaDB 12).
     *
     * @return array<string, string|null>
     */
    public function toRow(): array
    {
        return [
            'entity_kind'   => $this->ref->kind,
            'entity_key'    => $this->ref->key,
            'loser_side'    => $this->loserSide,
            'loser_payload' => $this->loserPayload,
            'winner'        => $this->winner,
            'trigger_src'   => $this->trigger,
            'actor'         => $this->actor,
            'git_head'      => $this->gitHead,
            'created_at'    => $this->createdAt->format('Y-m-d H:i:s'),
            'resolved_at'   => $this->resolvedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
