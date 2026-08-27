<?php

declare(strict_types=1);

namespace CVSync\Engine;

/**
 * Canonical form of an entity — already placeholderized, already canonicalized.
 *
 * The ONLY hash material (spec §5.1) and the ONLY write material (§4.2).
 * Adapters (P3/P4) build it; Hasher/FrontmatterWriter consume it.
 * The engine never reads WordPress or the filesystem.
 *
 * An attachment whose local binary is unreadable MUST NOT produce a
 * CanonicalDocument (§A.4.1): P4 signals db_hash=NULL + pending_payload
 * {"missing_binary":true} directly to the state store, outside the
 * decision table.
 */
final readonly class CanonicalDocument
{
    /**
     * @param array<string,mixed> $frontmatter Ordered key=>value pairs in final canonical
     *        order, WITHOUT the 'hash' key (it is derived). Meta already passed through
     *        the Canonicalizer; identity taxonomies included (§4.2.5) under 'terms';
     *        global-styles JSON already canonicalized into 'body'.
     * @param string $body Byte-exact body (block markup / canonical JSON / '' for
     *        sidecar-only attachments). Never re-serialized by the engine.
     * @param string|null $binHash 'sha256:…' (or bare 64-hex) of the original binary
     *        (attachments, §A.4.1); null for textual entities. Computed by P4 during
     *        the streaming copy — the Hasher never sees binary bytes.
     */
    public function __construct(
        public EntityRef $ref,
        public array $frontmatter,
        public string $body,
        public ?string $binHash = null,
    ) {
        if (array_key_exists('hash', $this->frontmatter)) {
            throw new \InvalidArgumentException('CanonicalDocument: frontmatter must not contain the "hash" key.');
        }
    }

    /** Derived views into the frontmatter — single source of truth, no duplication. */
    public function uuid(): string
    {
        return (string) ($this->frontmatter['uuid'] ?? '');
    }

    public function slug(): string
    {
        return (string) ($this->frontmatter['slug'] ?? '');
    }

    /** @return array<string,mixed> Allowlisted canonical meta (frontmatter['meta']). */
    public function meta(): array
    {
        $meta = $this->frontmatter['meta'] ?? [];
        return is_array($meta) ? $meta : [];
    }

    /** @return array<string,list<string>> Identity taxonomy terms (frontmatter['terms']). */
    public function terms(): array
    {
        $terms = $this->frontmatter['terms'] ?? [];
        return is_array($terms) ? $terms : [];
    }
}
