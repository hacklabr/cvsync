<?php

declare(strict_types=1);

namespace CVSync\Engine\Placeholders;

/**
 * Result of PlaceholderCodec::decode() (file → db, §6.2).
 *
 * Classification per §6.2:
 *  - STRUCTURAL (wp:block / wp:navigation refs) unresolved → the entity MUST NOT
 *    be imported: pending_ref + pending_payload, reprocessed after each
 *    successful import of the reference.
 *  - Non-structural (media ids, taxQuery) unresolved → import with the LITERAL
 *    placeholder in the markup (inert in render — visible absence, never alien
 *    content); marked pending_ref.
 *  - {{home_url}} cannot be unresolved.
 */
final readonly class DecodeResult
{
    /**
     * @param string                 $content                  Resolved placeholders replaced
     *        by local IDs/URLs; unresolved ones kept as inert literals.
     * @param list<PlaceholderToken> $unresolvedStructural     Refs without a target — Importer
     *        MUST mark pending_ref and NOT persist (§6.2 structural).
     * @param list<PlaceholderToken> $unresolvedNonStructural  Media/terms without a target —
     *        import with literal + pending_ref.
     */
    public function __construct(
        public string $content,
        public array $unresolvedStructural = [],
        public array $unresolvedNonStructural = [],
    ) {
    }

    public function hasStructuralBlockers(): bool
    {
        return $this->unresolvedStructural !== [];
    }
}
