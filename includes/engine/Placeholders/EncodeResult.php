<?php

declare(strict_types=1);

namespace CVSync\Engine\Placeholders;

/**
 * Result of PlaceholderCodec::encode() (db → file, §6.1/§A.6).
 *
 * The engine never logs: pendencies travel in this VO for P3/P5 to report
 * (non-ignorable types — the result is not a bare string).
 */
final readonly class EncodeResult
{
    /**
     * @param string                  $content       Content with placeholders in place.
     * @param list<PlaceholderToken>  $missingTokens Tokens rendered as {{missing:ID}}
     *        (referenced target absent in the origin environment — inert form §6.1).
     * @param list<string>            $warnings      Short stable messages for the audit
     *        log (e.g. unresolved attachment URLs left as-is).
     */
    public function __construct(
        public string $content,
        public array $missingTokens = [],
        public array $warnings = [],
    ) {
    }
}
