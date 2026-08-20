<?php

declare(strict_types=1);

namespace CVSync\Engine\Placeholders;

/**
 * One parsed placeholder occurrence.
 *
 * args shape by kind: ref/attachment/attachment_url/missing → [slug|id];
 * term → [taxonomy, slug]; home_url → [].
 */
final readonly class PlaceholderToken
{
    /**
     * @param string       $kind   One of PlaceholderVocabulary's kinds.
     * @param list<string> $args
     * @param int          $offset Byte offset in the scanned content (-1 when synthetic).
     */
    public function __construct(
        public string $kind,
        public array $args,
        public int $offset = -1,
    ) {
    }

    /** First argument (slug / id), or null for argument-less kinds. */
    public function subject(): ?string
    {
        return $this->args[0] ?? null;
    }

    /** Taxonomy (term tokens only). */
    public function taxonomy(): ?string
    {
        return $this->kind === PlaceholderVocabulary::TERM ? ($this->args[0] ?? null) : null;
    }

    /** Rebuilds the canonical literal. */
    public function render(): string
    {
        if ($this->kind === PlaceholderVocabulary::HOME_URL) {
            return '{{home_url}}';
        }

        return '{{' . $this->kind . ':' . implode(':', $this->args) . '}}';
    }
}
