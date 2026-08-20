<?php

declare(strict_types=1);

namespace CVSync\Engine;

/**
 * Logical identity of a versionable entity — mirrors the uq_entity tuple of
 * the state table (spec §9.1) and is the key of the UUID-ownership check (§6.3).
 *
 * Identity ≠ state: db_id is deliberately NOT here. It is synchronization
 * state (changes with import/tombstone) and lives in the P2 StateRecord.
 *
 * postType follows emend A1 (ratified r1-t2): string, '' for non-post kinds
 * ('nav_menu', 'menu_location', 'branding') — the column is NOT NULL DEFAULT ''
 * because NULL in a UNIQUE KEY never collides on MariaDB. No null anywhere:
 * what talks to the engine and what talks to the bank see the same '' (the
 * null↔'' mapping of earlier drafts was dropped with the unified VO).
 *
 * Contract consumed by P2 (CVSync\Storage) and P3 (adapters):
 *   $ref->kind, $ref->postType, $ref->key, $ref->toTupleString(),
 *   EntityRef::post($postType, $key), EntityRef::of($kind, $key).
 */
final readonly class EntityRef
{
    /**
     * @param string $kind     'post' | 'nav_menu' | 'menu_location' | 'branding'
     * @param string $postType Post type when kind='post'; '' otherwise (A1).
     * @param string $key      UUID (posts) | term slug | '{stylesheet}:{location}'.
     */
    public function __construct(
        public string $kind,
        public string $postType,
        public string $key,
    ) {
        if ($this->kind === '' || $this->key === '') {
            throw new \InvalidArgumentException('EntityRef: kind and key must be non-empty.');
        }
        if ($this->kind === 'post' && $this->postType === '') {
            throw new \InvalidArgumentException('EntityRef: postType is required when kind is "post".');
        }
        if ($this->kind !== 'post' && $this->postType !== '') {
            throw new \InvalidArgumentException('EntityRef: postType must be "" for non-post kinds.');
        }
    }

    /** Factory for post-based entities (page, CPTs, wp_block, attachment, …). */
    public static function post(string $postType, string $key): self
    {
        return new self('post', $postType, $key);
    }

    /** Factory for non-post entities (nav_menu, menu_location, branding): postType=''. */
    public static function of(string $kind, string $key): self
    {
        return new self($kind, '', $key);
    }

    public function equals(EntityRef $other): bool
    {
        return $this->kind === $other->kind
            && $this->postType === $other->postType
            && $this->key === $other->key;
    }

    /** Canonical tuple for logs/CLI: "{kind}:{post_type}:{key}" ('post:page:u1', 'nav_menu::principal'). */
    public function toTupleString(): string
    {
        return $this->kind . ':' . $this->postType . ':' . $this->key;
    }
}
