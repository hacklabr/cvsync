<?php
/**
 * Entrada tipada e imutável de wp_cvsync_log (spec §9.3 + §A.10.5).
 *
 * Append-only: a tabela não tem updated_at e o AuditLog não expõe nenhum
 * método de UPDATE/DELETE de linha (apenas o prune do ring buffer).
 *
 * @package CVSync\Storage
 */

declare(strict_types=1);

namespace CVSync\Storage;

use CVSync\Engine\EntityRef;

defined('ABSPATH') || exit;

final readonly class LogEntry
{
    /**
     * @param int|null $id null = entrada nova (ainda não persistida).
     */
    public function __construct(
        public ?int $id,
        public EntityRef $ref,
        public string $postType, // '' para kinds não-post (emenda A1)
        public ?SyncDirection $direction, // null em eventos sem direção (ex.: verify)
        public string $trigger,  // 'cli'|'git-hook'|'deploy'|'cron'|'save-hook'|'passive' (coluna trigger_src — A3)
        public string $actor,
        public ?string $filePath,
        public ?string $beforeHash,
        public ?string $afterHash,
        public ?int $bytes,      // §A.10.5: bytes copiados/materializados
        public LogResult $result,
        public ?string $error,
        public ?int $durationMs,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param object|array<string, mixed> $row Linha crua do $wpdb (tudo string).
     * @throws StorageException direction/result fora do vocabulário.
     */
    public static function fromRow(object|array $row): self
    {
        $row  = (array) $row;
        $kind = (string) ($row['entity_kind'] ?? '');
        $key  = (string) ($row['entity_key'] ?? '');
        $pt   = (string) ($row['post_type'] ?? '');

        $ref = 'post' === $kind ? EntityRef::post($pt, $key) : EntityRef::of($kind, $key);

        $direction = null;
        if (isset($row['direction']) && '' !== $row['direction']) {
            try {
                $direction = SyncDirection::from((string) $row['direction']);
            } catch (\ValueError $e) {
                throw new StorageException(
                    sprintf('wp_cvsync_log: direction desconhecido "%s".', (string) $row['direction']),
                    0,
                    $e
                );
            }
        }

        try {
            $result = LogResult::from((string) ($row['result'] ?? ''));
        } catch (\ValueError $e) {
            throw new StorageException(
                sprintf('wp_cvsync_log: result desconhecido "%s".', (string) $row['result']),
                0,
                $e
            );
        }

        return new self(
            id: (int) $row['id'],
            ref: $ref,
            postType: $pt,
            direction: $direction,
            trigger: (string) ($row['trigger_src'] ?? ''),
            actor: (string) ($row['actor'] ?? ''),
            filePath: isset($row['file_path']) && '' !== $row['file_path'] ? (string) $row['file_path'] : null,
            beforeHash: isset($row['before_hash']) && '' !== $row['before_hash'] ? (string) $row['before_hash'] : null,
            afterHash: isset($row['after_hash']) && '' !== $row['after_hash'] ? (string) $row['after_hash'] : null,
            bytes: isset($row['bytes']) && '' !== $row['bytes'] ? (int) $row['bytes'] : null,
            result: $result,
            error: isset($row['error']) && '' !== $row['error'] ? (string) $row['error'] : null,
            durationMs: isset($row['duration_ms']) && '' !== $row['duration_ms'] ? (int) $row['duration_ms'] : null,
            createdAt: new \DateTimeImmutable((string) ($row['created_at'] ?? 'now'), wp_timezone()),
        );
    }

    /**
     * Serializa para colunas da tabela (sem id — autoincrement). Nulls reais
     * para colunas nullable (ver nota em ConflictRecord::toRow()).
     *
     * @return array<string, string|int|null>
     */
    public function toRow(): array
    {
        return [
            'entity_kind' => $this->ref->kind,
            'entity_key'  => $this->ref->key,
            'post_type'   => $this->postType,
            'direction'   => $this->direction?->value ?? '',
            'trigger_src' => $this->trigger,
            'actor'       => $this->actor,
            'file_path'   => $this->filePath,
            'before_hash' => $this->beforeHash,
            'after_hash'  => $this->afterHash,
            'bytes'       => $this->bytes,
            'result'      => $this->result->value,
            'error'       => $this->error,
            'duration_ms' => $this->durationMs,
            'created_at'  => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }
}
