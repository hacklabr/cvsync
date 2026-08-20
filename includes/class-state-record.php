<?php
/**
 * Linha tipada e imutável de wp_cvsync_state (spec §9.1 + §A.4.2).
 *
 * Mutações acontecem exclusivamente via StateStore. fromRow() faz cast
 * explícito de TODOS os campos: $wpdb->get_row()/get_results() devolvem
 * strings via mysqli ("123" para id, "0" para flags), e comparações estritas
 * na tabela de decisão quebrariam sem a normalização.
 *
 * post_type: no banco é NOT NULL DEFAULT '' (emenda A1); o EntityRef do P1
 * usa '' para kinds não-post — nenhuma conversão null↔'' é necessária (o VO
 * convergido já fala a língua do banco).
 *
 * @package CVSync\Storage
 */

declare(strict_types=1);

namespace CVSync\Storage;

use CVSync\Engine\EntityRef;

defined('ABSPATH') || exit;

final readonly class StateRecord
{
    /**
     * @param array<string, mixed>|null $pendingPayload JSON decodificado; null = sem pendência.
     */
    public function __construct(
        public int $id,
        public EntityRef $ref,
        public ?int $dbId,
        public ?string $dbHash,
        public ?\DateTimeImmutable $dbModified,
        public ?string $fileHash,
        public ?int $fileMtime,
        public ?string $binHash,
        public ?int $binSize,
        public ?int $binMtime,
        public ?string $lastSyncHash,
        public ?SyncDirection $lastSyncDirection,
        public ?\DateTimeImmutable $lastSyncAt,
        public ?string $lastAppliedHead,
        public int $formatVersion,
        public EntityStatus $status,
        public ?\DateTimeImmutable $tombstoneAt,
        public ?array $pendingPayload,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Normaliza uma linha crua do $wpdb (array associativo ou objeto, tudo
     * string) para o VO tipado.
     *
     * @param object|array<string, mixed> $row
     * @throws StorageException Linha malformada (status/direction fora do vocabulário).
     */
    public static function fromRow(object|array $row): self
    {
        $row = (array) $row;

        $kind     = (string) ($row['entity_kind'] ?? '');
        $postType = (string) ($row['post_type'] ?? '');
        $key      = (string) ($row['entity_key'] ?? '');

        $ref = 'post' === $kind
            ? EntityRef::post($postType, $key)
            : EntityRef::of($kind, $key);

        try {
            $status = EntityStatus::from((string) ($row['status'] ?? ''));
        } catch (\ValueError $e) {
            throw new StorageException(
                sprintf('wp_cvsync_state: status desconhecido "%s" na linha %s.', (string) $row['status'], (string) ($row['id'] ?? '?')),
                0,
                $e
            );
        }

        $direction = self::nullString($row['last_sync_direction'] ?? null);
        if (null !== $direction) {
            try {
                $direction = SyncDirection::from($direction);
            } catch (\ValueError $e) {
                throw new StorageException(
                    sprintf('wp_cvsync_state: last_sync_direction desconhecido "%s".', (string) $row['last_sync_direction']),
                    0,
                    $e
                );
            }
        }

        $pendingPayload = null;
        $rawPayload     = self::nullString($row['pending_payload'] ?? null);
        if (null !== $rawPayload && '' !== $rawPayload) {
            $decoded = json_decode($rawPayload, true);
            if (! is_array($decoded)) {
                throw new StorageException(
                    sprintf('wp_cvsync_state: pending_payload não é JSON de objeto na linha %s.', (string) ($row['id'] ?? '?'))
                );
            }
            $pendingPayload = $decoded;
        }

        return new self(
            id: (int) $row['id'],
            ref: $ref,
            dbId: self::nullInt($row['db_id'] ?? null),
            dbHash: self::nullString($row['db_hash'] ?? null),
            dbModified: self::nullDate($row['db_modified'] ?? null),
            fileHash: self::nullString($row['file_hash'] ?? null),
            fileMtime: self::nullInt($row['file_mtime'] ?? null),
            binHash: self::nullString($row['bin_hash'] ?? null),
            binSize: self::nullInt($row['bin_size'] ?? null),
            binMtime: self::nullInt($row['bin_mtime'] ?? null),
            lastSyncHash: self::nullString($row['last_sync_hash'] ?? null),
            lastSyncDirection: $direction,
            lastSyncAt: self::nullDate($row['last_sync_at'] ?? null),
            lastAppliedHead: self::nullString($row['last_applied_head'] ?? null),
            formatVersion: (int) ($row['format_version'] ?? 1),
            status: $status,
            tombstoneAt: self::nullDate($row['tombstone_at'] ?? null),
            pendingPayload: $pendingPayload,
            createdAt: self::date($row['created_at'] ?? null),
            updatedAt: self::date($row['updated_at'] ?? null),
        );
    }

    private static function nullString(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (string) $value;
    }

    private static function nullInt(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (int) $value;
    }

    private static function nullDate(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return new \DateTimeImmutable((string) $value, wp_timezone());
    }

    private static function date(mixed $value): \DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return new \DateTimeImmutable('now', wp_timezone());
        }

        return new \DateTimeImmutable((string) $value, wp_timezone());
    }
}
