<?php

declare(strict_types=1);

namespace CVSync\Engine\Placeholders;

/**
 * Placeholder codec — export (§6.1/§A.6), import (§6.2), scan and the
 * anti-regression validation (§6.2, mandatory clause).
 *
 * Purity split (reconciled r1-t2): the engine owns the vocabulary, the
 * traversal and the token grammar; the RESOLUTION against WordPress is
 * injected by the adapters (P3) as callables — the engine never calls WP.
 *
 *   $resolveId:   fn(string $class, ?string $taxonomy, int|string $value): ?string
 *                 export-side; class ∈ ref|attachment|term|attachment_url;
 *                 value is the numeric id (or the plain URL for attachment_url);
 *                 returns the slug or null (→ {{missing:ID}} / warning).
 *   $isMediaId:   fn(int $id): bool — export-side classifier for "id"/"ids"
 *                 attributes: true ⇒ {{attachment:slug}}, false ⇒ {{ref:slug}}
 *                 (the target's post_type decides; injected by P3, which knows
 *                 the database). Null ⇒ legacy all-media classification.
 *   $resolveSlug: fn(string $class, ?string $taxonomy, string $slug): int|string|null
 *                 import-side; returns the local id (int) for ref/attachment/term,
 *                 the local URL (string) for attachment_url, or null → pendency.
 *
 * Attribute classification (which JSON key is a ref × media id × term id) lives
 * in PlaceholderVocabulary as a static table — stable core vocabulary; P3
 * extends it via WP filters at the border (R6, reconciled r1-t2).
 *
 * Escaping note: block attribute JSON escapes "/" as "\/" (core json_encode
 * without JSON_UNESCAPED_SLASHES). URL replacements therefore run in BOTH
 * forms — plain and slash-escaped — and decode re-escapes inside block
 * comments. Exact string replace, never regex over JSON values (§6.1).
 *
 * Pure PHP: no WordPress functions, no I/O.
 */
final class PlaceholderCodec
{
    /** Matches full block comments (open/self-closing/close); capture group 1
     *  keeps delimiters on preg_split(DELIM_CAPTURE). */
    private const BLOCK_COMMENT_PATTERN = '/(<!-- (?:\/)?wp:.*?-->)/s';

    /**
     * Export (db → file, §6.1/§A.6): numeric ids → placeholders, attachment
     * URLs → {{attachment_url:slug}}, home URL → {{home_url}}.
     *
     * @param callable $resolveId          See class docblock.
     * @param string|null $homeUrl         home_url() of the origin (exact replace).
     * @param string|null $uploadsBaseUrl  Absolute uploads base URL of the origin
     *        (e.g. 'https://site/wp-content/uploads'); enables attachment_url.
     * @param array<string,string> $termIdAttributes Extra scalar term-id attributes
     *        (attribute => taxonomy) — P3 injects the filtered list.
     * @param callable|null $isMediaId     See class docblock: fn(int): bool.
     *        Classifies "id"/"ids" targets — media ⇒ {{attachment:}}, any other
     *        post_type ⇒ {{ref:}}. Null ⇒ legacy all-media classification.
     */
    public static function encode(
        string $content,
        callable $resolveId,
        ?string $homeUrl = null,
        ?string $uploadsBaseUrl = null,
        array $termIdAttributes = PlaceholderVocabulary::DEFAULT_TERM_ATTRIBUTES,
        ?callable $isMediaId = null,
    ): EncodeResult {
        $missing = [];
        $warnings = [];

        // 1. Numeric ids inside block attribute JSON.
        $content = self::mapBlockComments(
            $content,
            static function (string $comment) use ($resolveId, $termIdAttributes, $isMediaId, &$missing, &$warnings): string {
                return self::encodeAttributes($comment, $resolveId, $termIdAttributes, $isMediaId, $missing, $warnings);
            },
        );

        // 2. Attachment URLs (attribute values AND inner HTML — §A.6).
        if ($uploadsBaseUrl !== null && $uploadsBaseUrl !== '') {
            $content = self::encodeAttachmentUrls($content, $resolveId, $uploadsBaseUrl, $warnings);
        }

        // 3. home_url LAST (it is a prefix of attachment URLs — §6.1 exact replace).
        if ($homeUrl !== null && $homeUrl !== '') {
            $content = str_replace(
                [$homeUrl, self::escapeSlashes($homeUrl)],
                '{{home_url}}',
                $content,
            );
        }

        return new EncodeResult($content, $missing, $warnings);
    }

    /**
     * Import (file → db, §6.2): placeholders → local ids/URLs.
     * Call assertNoRawNumericRefs() BEFORE this method (mandatory clause).
     *
     * @param callable $resolveSlug See class docblock.
     * @param array<string,string> $termIdAttributes See encode().
     */
    public static function decode(
        string $content,
        callable $resolveSlug,
        ?string $homeUrl = null,
        array $termIdAttributes = PlaceholderVocabulary::DEFAULT_TERM_ATTRIBUTES,
    ): DecodeResult {
        $unresolvedStructural = [];
        $unresolvedNonStructural = [];

        // 1. Numeric placeholders inside block attribute JSON (escaped context).
        $content = self::mapBlockComments(
            $content,
            static function (string $comment) use (
                $resolveSlug,
                $homeUrl,
                &$unresolvedStructural,
                &$unresolvedNonStructural,
            ): string {
                $comment = self::decodeAttributes(
                    $comment,
                    $resolveSlug,
                    $unresolvedStructural,
                    $unresolvedNonStructural,
                );
                $comment = self::decodeAttachmentUrls($comment, $resolveSlug, true, $unresolvedNonStructural);
                if ($homeUrl !== null && $homeUrl !== '') {
                    $comment = str_replace('{{home_url}}', self::escapeSlashes($homeUrl), $comment);
                }
                return $comment;
            },
        );

        // 2. Plain-text context (inner HTML outside block comments).
        $content = self::mapOutsideBlockComments(
            $content,
            static function (string $segment) use ($resolveSlug, $homeUrl, &$unresolvedNonStructural): string {
                $segment = self::decodeAttachmentUrls($segment, $resolveSlug, false, $unresolvedNonStructural);
                if ($homeUrl !== null && $homeUrl !== '') {
                    $segment = str_replace('{{home_url}}', $homeUrl, $segment);
                }
                return $segment;
            },
        );

        return new DecodeResult($content, $unresolvedStructural, $unresolvedNonStructural);
    }

    /**
     * All placeholder occurrences, in offset order.
     *
     * @return list<PlaceholderToken>
     */
    public static function scan(string $content): array
    {
        preg_match_all(
            PlaceholderVocabulary::PATTERN,
            $content,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $tokens = [];
        $count = count($matches[0]);
        for ($i = 0; $i < $count; $i++) {
            $argsRaw = $matches[2][$i][0] ?? '';
            $tokens[] = new PlaceholderToken(
                $matches[1][$i][0],
                $argsRaw === '' ? [] : explode(':', $argsRaw),
                $matches[0][$i][1],
            );
        }

        return $tokens;
    }

    /**
     * Anti-regression validation (§6.2, mandatory clause): any bare numeric
     * "ref"/"id"/"ids" (or injected attribute) inside block attribute JSON is a
     * legacy/handcrafted artifact → RawNumericReferenceException. The Importer
     * rejects the entity with a clear error.
     *
     * @param list<string> $attributeNames P3 injects the filtered list
     *        ('cvsync/raw_id_attributes', default PlaceholderVocabulary::DEFAULT_RAW_ATTRIBUTES).
     *
     * @throws RawNumericReferenceException Carries all occurrences.
     */
    public static function assertNoRawNumericRefs(
        string $content,
        array $attributeNames = PlaceholderVocabulary::DEFAULT_RAW_ATTRIBUTES,
    ): void {
        $occurrences = [];

        preg_match_all(
            self::BLOCK_COMMENT_PATTERN,
            $content,
            $comments,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($comments[0] as [$comment, $commentOffset]) {
            [$json] = self::extractJson($comment);
            if ($json === null) {
                continue;
            }
            $jsonOffset = strpos($comment, $json);

            foreach ($attributeNames as $attr) {
                $quoted = preg_quote((string) $attr, '/');
                foreach (
                    [
                        '/"' . $quoted . '"\s*:\s*\d+/',
                        '/"' . $quoted . '"\s*:\s*\[\s*\d/',
                    ] as $pattern
                ) {
                    if (preg_match_all($pattern, $json, $m, PREG_OFFSET_CAPTURE) !== false) {
                        foreach ($m[0] as [$text, $offset]) {
                            $occurrences[] = sprintf(
                                '%s at offset %d',
                                $text,
                                $commentOffset + $jsonOffset + $offset,
                            );
                        }
                    }
                }
            }
        }

        if ($occurrences !== []) {
            throw new RawNumericReferenceException($occurrences);
        }
    }

    // ------------------------------------------------------------ encode paths

    /**
     * @param callable              $resolveId
     * @param array<string,string>  $termIdAttributes
     * @param callable|null         $isMediaId fn(int): bool — "id"/"ids" classifier.
     * @param list<PlaceholderToken> $missing
     * @param list<string>          $warnings
     */
    private static function encodeAttributes(
        string $comment,
        callable $resolveId,
        array $termIdAttributes,
        ?callable $isMediaId,
        array &$missing,
        array &$warnings,
    ): string {
        [$json, $start, $end] = self::extractJson($comment);
        if ($json === null) {
            return $comment;
        }

        $resolve = static function (string $class, ?string $taxonomy, int $id) use (
            $resolveId,
            &$missing,
            &$warnings,
        ): string {
            $slug = $resolveId($class, $taxonomy, $id);
            if ($slug !== null) {
                return '"{{' . $class . ($taxonomy !== null ? ':' . $taxonomy : '') . ':' . $slug . '}}"'
                    // term tokens place taxonomy before slug
                    ;
            }
            $token = new PlaceholderToken(PlaceholderVocabulary::MISSING, [(string) $id]);
            $missing[] = $token;
            $warnings[] = sprintf('unresolved %s id %d → %s', $class, $id, $token->render());
            return '"' . $token->render() . '"';
        };

        // taxQuery arrays: "taxQuery":{"category":[1,2]} — key IS the taxonomy.
        $json = (string) preg_replace_callback(
            '/"taxQuery"\s*:\s*\{([^{}]*)\}/',
            static function (array $m) use ($resolve): string {
                $inner = (string) preg_replace_callback(
                    '/"([a-zA-Z0-9_-]+)"\s*:\s*\[([\d,\s]*)\]/',
                    static function (array $mm) use ($resolve): string {
                        $ids = array_filter(
                            array_map('trim', explode(',', $mm[2])),
                            static fn (string $v): bool => $v !== '',
                        );
                        $tokens = array_map(
                            static fn (string $id): string => $resolve(
                                PlaceholderVocabulary::TERM,
                                $mm[1],
                                (int) $id,
                            ),
                            $ids,
                        );
                        return '"' . $mm[1] . '":[' . implode(',', $tokens) . ']';
                    },
                    $m[1],
                );
                return '"taxQuery":{' . $inner . '}';
            },
            $json,
        );

        // Scalar term-id attributes (configurable table).
        foreach ($termIdAttributes as $attr => $taxonomy) {
            $json = (string) preg_replace_callback(
                '/"' . preg_quote((string) $attr, '/') . '"\s*:\s*(\d+)/',
                static fn (array $m): string => '"' . $attr . '":'
                    . $resolve(PlaceholderVocabulary::TERM, (string) $taxonomy, (int) $m[1]),
                $json,
            );
        }

        // Structural refs (wp:block / wp:navigation).
        $json = (string) preg_replace_callback(
            '/"(ref)"\s*:\s*(\d+)/',
            static fn (array $m): string => '"ref":'
                . $resolve(PlaceholderVocabulary::REF, null, (int) $m[2]),
            $json,
        );

        // Id-classifier for "id"/"ids" attributes: media target ⇒ attachment,
        // any other post_type ⇒ structural ref. Null (not injected) keeps the
        // legacy all-media classification.
        $idClass = static fn (int $id): string => $isMediaId !== null && !$isMediaId($id)
            ? PlaceholderVocabulary::REF
            : PlaceholderVocabulary::ATTACHMENT;

        // Id attributes (scalar and arrays) — class per target post_type.
        $json = (string) preg_replace_callback(
            '/"(id)"\s*:\s*(\d+)/',
            static fn (array $m): string => '"id":'
                . $resolve($idClass((int) $m[2]), null, (int) $m[2]),
            $json,
        );
        $json = (string) preg_replace_callback(
            '/"(ids)"\s*:\s*\[([\d,\s]*)\]/',
            static function (array $m) use ($resolve, $idClass): string {
                $ids = array_filter(
                    array_map('trim', explode(',', $m[2])),
                    static fn (string $v): bool => $v !== '',
                );
                $tokens = array_map(
                    static fn (string $id): string => $resolve(
                        $idClass((int) $id),
                        null,
                        (int) $id,
                    ),
                    $ids,
                );
                return '"ids":[' . implode(',', $tokens) . ']';
            },
            $json,
        );

        return substr($comment, 0, $start) . $json . substr($comment, $end + 1);
    }

    /**
     * Attachment URLs → {{attachment_url:slug}} over the WHOLE content (both the
     * plain and the slash-escaped JSON form). Exact-prefix match, never a regex
     * over arbitrary JSON values (§6.1).
     *
     * @param list<string> $warnings
     */
    private static function encodeAttachmentUrls(
        string $content,
        callable $resolveId,
        string $uploadsBaseUrl,
        array &$warnings,
    ): string {
        $escapedBase = self::escapeSlashes($uploadsBaseUrl);

        // Escaped (inside block JSON): path chars may be slash-escaped too.
        $content = (string) preg_replace_callback(
            '#' . preg_quote($escapedBase, '#') . '((?:\\\\/|[^"\s<>\\\\])*)#',
            static function (array $m) use ($resolveId, &$warnings): string {
                $plainUrl = str_replace('\\/', '/', $m[0]);
                return self::encodeOneUrl($plainUrl, $m[0], $resolveId, $warnings);
            },
            $content,
        );

        // Plain (inner HTML).
        $content = (string) preg_replace_callback(
            '#' . preg_quote($uploadsBaseUrl, '#') . '([^\s"\'<>]*)#',
            static function (array $m) use ($resolveId, &$warnings): string {
                return self::encodeOneUrl($m[0], $m[0], $resolveId, $warnings);
            },
            $content,
        );

        return $content;
    }

    /**
     * @param list<string> $warnings
     */
    private static function encodeOneUrl(
        string $plainUrl,
        string $originalForm,
        callable $resolveId,
        array &$warnings,
    ): string {
        $slug = $resolveId(PlaceholderVocabulary::ATTACHMENT_URL, null, $plainUrl);
        if ($slug !== null) {
            return '{{' . PlaceholderVocabulary::ATTACHMENT_URL . ':' . $slug . '}}';
        }
        $warnings[] = sprintf('attachment URL not resolved, left as-is: %s', $plainUrl);
        return $originalForm;
    }

    // ------------------------------------------------------------ decode paths

    /**
     * @param list<PlaceholderToken> $unresolvedStructural
     * @param list<PlaceholderToken> $unresolvedNonStructural
     */
    private static function decodeAttributes(
        string $comment,
        callable $resolveSlug,
        array &$unresolvedStructural,
        array &$unresolvedNonStructural,
    ): string {
        [$json, $start, $end] = self::extractJson($comment);
        if ($json === null) {
            return $comment;
        }

        $resolve = static function (
            string $class,
            ?string $taxonomy,
            string $slug,
            string $original,
        ) use ($resolveSlug, &$unresolvedStructural, &$unresolvedNonStructural): string {
            $resolved = $resolveSlug($class, $taxonomy, $slug);
            if (is_int($resolved)) {
                return (string) $resolved;
            }
            $args = $taxonomy !== null ? [$taxonomy, $slug] : [$slug];
            $token = new PlaceholderToken($class, $args);
            if ($class === PlaceholderVocabulary::REF) {
                $unresolvedStructural[] = $token;
            } else {
                $unresolvedNonStructural[] = $token;
            }
            return $original; // literal stays (inert)
        };

        // Scalar tokens: "ref":"{{ref:slug}}", "id":"{{ref|attachment:slug}}"
        // (class per target post_type — see encode), "<attr>":"{{term:tax:slug}}".
        $json = (string) preg_replace_callback(
            '/"(ref|id)"\s*:\s*"\{\{(ref|attachment):([^}]*)\}\}"/',
            static fn (array $m): string => '"' . $m[1] . '":'
                . $resolve($m[2], null, $m[3], '"{{' . $m[2] . ':' . $m[3] . '}}"'),
            $json,
        );
        $json = (string) preg_replace_callback(
            '/"([a-zA-Z][a-zA-Z0-9]*)"\s*:\s*"\{\{term:([a-zA-Z0-9_-]+):([^}]*)\}\}"/',
            static fn (array $m): string => '"' . $m[1] . '":'
                . $resolve(PlaceholderVocabulary::TERM, $m[2], $m[3], $m[0]),
            $json,
        );

        // Array tokens: "ids":["{{attachment:a}}",…] and taxQuery-style
        // "<tax>":["{{term:tax:x}}",…]. attachment|ref accepted for ids arrays
        // (classification is per target post_type — see encode); only touched
        // when tokens are present.
        $json = (string) preg_replace_callback(
            '/"(ids|[a-zA-Z0-9_-]+)"\s*:\s*\[([^\]]*\{\{[^\]]*)\]/',
            static function (array $m) use ($resolve): string {
                $inner = (string) preg_replace_callback(
                    '/"\{\{(attachment|term|ref):([^}]*)\}\}"/',
                    static function (array $mm) use ($resolve): string {
                        if ($mm[1] === PlaceholderVocabulary::TERM) {
                            $parts = explode(':', $mm[2], 2);
                            return $resolve(
                                PlaceholderVocabulary::TERM,
                                $parts[0],
                                $parts[1] ?? '',
                                $mm[0],
                            );
                        }
                        return $resolve($mm[1], null, $mm[2], $mm[0]);
                    },
                    $m[2],
                );
                return '"' . $m[1] . '":[' . $inner . ']';
            },
            $json,
        );

        return substr($comment, 0, $start) . $json . substr($comment, $end + 1);
    }

    /**
     * {{attachment_url:slug}} → local URL. Inside block JSON the URL is
     * slash-escaped; in inner HTML it is plain. Unresolved → literal + pendency.
     *
     * @param list<PlaceholderToken> $unresolvedNonStructural
     */
    private static function decodeAttachmentUrls(
        string $content,
        callable $resolveSlug,
        bool $escapedContext,
        array &$unresolvedNonStructural,
    ): string {
        return (string) preg_replace_callback(
            '/\{\{attachment_url:([^}]*)\}\}/',
            static function (array $m) use ($resolveSlug, $escapedContext, &$unresolvedNonStructural): string {
                $resolved = $resolveSlug(PlaceholderVocabulary::ATTACHMENT_URL, null, $m[1]);
                if (is_string($resolved)) {
                    return $escapedContext ? self::escapeSlashes($resolved) : $resolved;
                }
                $unresolvedNonStructural[] = new PlaceholderToken(
                    PlaceholderVocabulary::ATTACHMENT_URL,
                    [$m[1]],
                );
                return $m[0];
            },
            $content,
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Applies $fn to each full block comment (open/self-closing/close).
     */
    private static function mapBlockComments(string $content, callable $fn): string
    {
        return (string) preg_replace_callback(
            self::BLOCK_COMMENT_PATTERN,
            static fn (array $m): string => $fn($m[0]),
            $content,
        );
    }

    /**
     * Applies $fn to the segments OUTSIDE block comments only.
     */
    private static function mapOutsideBlockComments(string $content, callable $fn): string
    {
        $parts = preg_split(
            self::BLOCK_COMMENT_PATTERN,
            $content,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );
        if ($parts === false) {
            return $content;
        }

        // With DELIM_CAPTURE, odd indexes are the captured block comments.
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $parts[$i] = $fn($part);
            }
        }

        return implode('', $parts);
    }

    /**
     * Extracts the attribute JSON substring of a block comment (first '{' to
     * last '}'). Returns [json|null, startOffset, endOffset].
     *
     * @return array{0: string|null, 1: int, 2: int}
     */
    private static function extractJson(string $comment): array
    {
        $start = strpos($comment, '{');
        if ($start === false) {
            return [null, 0, 0];
        }
        $end = strrpos($comment, '}');
        if ($end === false || $end < $start) {
            return [null, 0, 0];
        }

        return [substr($comment, $start, $end - $start + 1), $start, $end];
    }

    /** Slash-escaped form used inside core-serialized block JSON. */
    private static function escapeSlashes(string $value): string
    {
        return str_replace('/', '\\/', $value);
    }
}
