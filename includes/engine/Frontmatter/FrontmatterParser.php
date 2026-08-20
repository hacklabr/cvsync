<?php

declare(strict_types=1);

namespace CVSync\Engine\Frontmatter;

/**
 * Safe YAML parser (spec §4.3 — mandatory clause) + document splitter.
 *
 * Content files arrive via PR — they are third-party input and treated as such:
 *  - PECL yaml (yaml_parse) is FORBIDDEN by the spec; never used here.
 *  - Primary: Symfony YAML — Yaml::parse() WITHOUT PARSE_OBJECT, WITH
 *    PARSE_EXCEPTION_ON_INVALID_TYPE.
 *  - Fallback: restricted built-in parser (used when symfony/yaml is absent —
 *    detected via class_exists) covering exactly the subset the writer emits:
 *    flat/nested maps, double-quoted/bare scalars, inline scalar lists,
 *    block-style lists of maps (menus).
 *  - ALWAYS, on both paths: pre-scan rejecting any YAML tag (prefix '!',
 *    including !php/object) and duplicate sibling keys; post-parse validation
 *    accepting only scalar/array/null recursively (never objects).
 *
 * Pure PHP: no WordPress functions, no I/O.
 */
final class FrontmatterParser
{
    /**
     * Splits a "frontmatter + body" document and parses the frontmatter.
     *
     * Rules: the document MUST open with "---\n"; the frontmatter closes at the
     * first exact "---" line; the body is the bytes after the closing fence,
     * preserved 1:1 (no trim, no EOL normalization — LF is an entry precondition;
     * CRLF inside the frontmatter is rejected, in the body it passes through
     * intact and the hash reports it).
     *
     * @return array{0: array<string,mixed>, 1: string} [frontmatter, body]
     *
     * @throws FrontmatterException Malformed document, invalid YAML, rejected tag,
     *         duplicate key, non-pure data, CRLF in frontmatter.
     */
    public static function splitDocument(string $contents): array
    {
        if (!str_starts_with($contents, "---\n")) {
            throw new FrontmatterException('Document must open with a "---" fence line (LF).');
        }

        $close = strpos($contents, "\n---\n", 3);
        if ($close === false) {
            if (str_ends_with($contents, "\n---")) {
                $close = strlen($contents) - 4;
                $raw = substr($contents, 4, $close - 4);
                return [self::parse($raw), ''];
            }
            throw new FrontmatterException('Closing "---" fence line not found.');
        }

        $raw = substr($contents, 4, $close - 4);
        if (str_contains($raw, "\r")) {
            throw new FrontmatterException('CRLF detected in frontmatter (LF is mandatory).');
        }

        $body = substr($contents, $close + 5);

        return [self::parse($raw), $body];
    }

    /**
     * Safe parse of the canonical YAML subset (also used for full-YAML documents
     * such as *.menu.yml and *.attachment.yml sidecars).
     *
     * @return array<string,mixed>|list<mixed>
     *
     * @throws FrontmatterException
     */
    public static function parse(string $raw): array
    {
        self::assertNoTags($raw);
        self::assertNoDuplicateKeys($raw);

        if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            try {
                $data = \Symfony\Component\Yaml\Yaml::parse(
                    $raw,
                    \Symfony\Component\Yaml\Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
                );
            } catch (\Throwable $e) {
                throw new FrontmatterException('Invalid YAML: ' . $e->getMessage(), 0, $e);
            }
        } else {
            $data = self::parseRestricted($raw);
        }

        if ($data === null) {
            return [];
        }
        if (!is_array($data)) {
            throw new FrontmatterException('Frontmatter must be a map or a list.');
        }

        self::assertPureData($data);

        return $data;
    }

    // ------------------------------------------------------------------ guards

    /**
     * Rejects any YAML tag (prefix '!') outside quoted strings — kills
     * !php/object and every custom tag (CI lint mirrors this rule).
     *
     * @throws FrontmatterException
     */
    private static function assertNoTags(string $raw): void
    {
        foreach (explode("\n", $raw) as $i => $line) {
            $stripped = preg_replace('/"(?:\\\\.|[^"\\\\])*"/', '""', (string) $line) ?? '';
            if (preg_match('/(?:^|\s)!/', $stripped) === 1) {
                throw new FrontmatterException(sprintf('YAML tag (!) rejected at line %d.', $i + 1));
            }
        }
    }

    /**
     * Rejects duplicate sibling keys (Symfony YAML would silently last-win).
     * A "- " list-item line resets the key set of its level (each menu item
     * legitimately repeats 'title', 'type', …).
     *
     * @throws FrontmatterException
     */
    private static function assertNoDuplicateKeys(string $raw): void
    {
        /** @var array<int,array<string,true>> $levels indent => seen keys */
        $levels = [];

        foreach (explode("\n", $raw) as $i => $line) {
            $line = (string) $line;
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if (preg_match('/^(\s*)- /', $line, $m) === 1) {
                $itemIndent = strlen($m[1]);
                // New sibling item: forget keys seen at its key column.
                unset($levels[$itemIndent + 2]);
                // Drop deeper levels too.
                foreach (array_keys($levels) as $level) {
                    if ($level > $itemIndent + 2) {
                        unset($levels[$level]);
                    }
                }
                $line = substr($line, $itemIndent + 2);
                $keyIndent = $itemIndent + 2;
            } else {
                $keyIndent = strlen($line) - strlen(ltrim($line, ' '));
                $line = ltrim($line, ' ');
            }

            if (preg_match('/^([A-Za-z0-9_.-]+):(\s|$)/', $line, $m) !== 1) {
                continue;
            }
            $key = $m[1];

            foreach (array_keys($levels) as $level) {
                if ($level > $keyIndent) {
                    unset($levels[$level]);
                }
            }

            if (isset($levels[$keyIndent][$key])) {
                throw new FrontmatterException(
                    sprintf('Duplicate key "%s" at line %d.', $key, $i + 1),
                );
            }
            $levels[$keyIndent][$key] = true;
        }
    }

    /**
     * Post-parse validation: only scalar/array/null survive (never objects).
     *
     * @throws FrontmatterException
     */
    private static function assertPureData(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $v) {
                self::assertPureData($v);
            }
            return;
        }
        if (!is_scalar($value) && $value !== null) {
            throw new FrontmatterException(
                'Non-scalar value in YAML data: ' . get_debug_type($value),
            );
        }
    }

    // ------------------------------------------------------- restricted parser

    /**
     * Restricted built-in parser — fallback when symfony/yaml is absent.
     * Covers exactly what FrontmatterWriter emits.
     *
     * @return array<string,mixed>|list<mixed>
     *
     * @throws FrontmatterException
     */
    private static function parseRestricted(string $raw): array
    {
        $lines = explode("\n", $raw);
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $pos = 0;
        $result = self::parseBlock($lines, $pos, 0);

        if ($pos !== count($lines)) {
            throw new FrontmatterException(sprintf('Unexpected content at line %d.', $pos + 1));
        }

        return $result;
    }

    /**
     * @param list<string> $lines
     * @return array<string,mixed>|list<mixed>
     *
     * @throws FrontmatterException
     */
    private static function parseBlock(array $lines, int &$pos, int $indent): array
    {
        $map = [];
        $list = [];
        $isList = null;

        while ($pos < count($lines)) {
            $line = $lines[$pos];
            if (trim($line) === '') {
                $pos++;
                continue;
            }

            $lineIndent = strlen($line) - strlen(ltrim($line, ' '));
            if ($lineIndent < $indent) {
                break;
            }
            if ($lineIndent > $indent) {
                throw new FrontmatterException(sprintf('Bad indentation at line %d.', $pos + 1));
            }

            $content = substr($line, $indent);
            if (str_starts_with($content, '#')) {
                $pos++;
                continue;
            }

            if (str_starts_with($content, '- ')) {
                if ($isList === false) {
                    throw new FrontmatterException(
                        sprintf('Cannot mix list and map at the same level (line %d).', $pos + 1),
                    );
                }
                $isList = true;
                $list[] = self::parseListItem($lines, $pos, $indent);
                continue;
            }

            if ($isList === true) {
                throw new FrontmatterException(
                    sprintf('Cannot mix map and list at the same level (line %d).', $pos + 1),
                );
            }
            $isList = false;

            [$key, $valueText] = self::splitKeyValue($content, $pos);
            if (array_key_exists($key, $map)) {
                throw new FrontmatterException(sprintf('Duplicate key "%s" (line %d).', $key, $pos + 1));
            }
            $pos++;
            $map[$key] = self::parseValueOrNested($valueText, $lines, $pos, $indent + 2);
        }

        return $isList ? $list : $map;
    }

    /**
     * @param list<string> $lines
     *
     * @throws FrontmatterException
     */
    private static function parseListItem(array $lines, int &$pos, int $indent): mixed
    {
        $itemText = substr($lines[$pos], $indent + 2);

        if (!self::looksLikeKeyValue($itemText)) {
            $pos++;
            return self::parseInlineValue(trim($itemText), $pos - 1);
        }

        // Map item: first entry is inline after "- ", continuations at indent+2.
        $item = [];
        [$key, $valueText] = self::splitKeyValue($itemText, $pos);
        $pos++;
        $item[$key] = self::parseValueOrNested($valueText, $lines, $pos, $indent + 4);

        while ($pos < count($lines)) {
            $next = $lines[$pos];
            if (trim($next) === '') {
                $pos++;
                continue;
            }
            $nextIndent = strlen($next) - strlen(ltrim($next, ' '));
            if ($nextIndent !== $indent + 2) {
                break;
            }
            $nextContent = substr($next, $nextIndent);
            if (str_starts_with($nextContent, '- ')) {
                break;
            }
            [$k, $v] = self::splitKeyValue($nextContent, $pos);
            if (array_key_exists($k, $item)) {
                throw new FrontmatterException(sprintf('Duplicate key "%s" (line %d).', $k, $pos + 1));
            }
            $pos++;
            $item[$k] = self::parseValueOrNested($v, $lines, $pos, $indent + 4);
        }

        return $item;
    }

    /**
     * @param list<string> $lines
     *
     * @throws FrontmatterException
     */
    private static function parseValueOrNested(string $valueText, array $lines, int &$pos, int $childIndent): mixed
    {
        $valueText = trim($valueText);
        if ($valueText !== '') {
            return self::parseInlineValue($valueText, $pos);
        }

        // Empty value: nested block (deeper indentation) or null.
        if ($pos < count($lines)) {
            $next = $lines[$pos];
            if (trim($next) !== '') {
                $nextIndent = strlen($next) - strlen(ltrim($next, ' '));
                if ($nextIndent >= $childIndent) {
                    return self::parseBlock($lines, $pos, $nextIndent);
                }
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws FrontmatterException
     */
    private static function splitKeyValue(string $text, int $pos): array
    {
        if (preg_match('/^([A-Za-z0-9_.-]+):(\s+(.*))?$/s', $text, $m) !== 1) {
            throw new FrontmatterException(sprintf('Expected "key: value" at line %d.', $pos + 1));
        }

        return [$m[1], $m[3] ?? ''];
    }

    private static function looksLikeKeyValue(string $text): bool
    {
        return preg_match('/^[A-Za-z0-9_.-]+:(\s|$)/', $text) === 1;
    }

    /**
     * @throws FrontmatterException
     */
    private static function parseInlineValue(string $text, int $pos): mixed
    {
        if ($text === '' || $text === 'null' || $text === '~') {
            return null;
        }
        if ($text === 'true') {
            return true;
        }
        if ($text === 'false') {
            return false;
        }
        if (str_starts_with($text, '|') || str_starts_with($text, '>')) {
            throw new FrontmatterException(
                sprintf('Block scalars are outside the canonical subset (line %d).', $pos + 1),
            );
        }
        if (str_starts_with($text, '[')) {
            return self::parseInlineList($text, $pos);
        }
        if (str_starts_with($text, '"')) {
            return self::parseQuoted($text, $pos);
        }
        if (preg_match('/^-?\d+$/', $text) === 1) {
            return (int) $text;
        }
        if (preg_match('/^-?(\d+\.\d*|\.\d+|\d+)([eE][+-]?\d+)?$/', $text) === 1
            && preg_match('/[.eE]/', $text) === 1
        ) {
            return (float) $text;
        }

        return $text; // bare string
    }

    /**
     * @return list<mixed>
     *
     * @throws FrontmatterException
     */
    private static function parseInlineList(string $text, int $pos): array
    {
        if (!str_ends_with($text, ']')) {
            throw new FrontmatterException(sprintf('Unterminated inline list at line %d.', $pos + 1));
        }

        $inner = trim(substr($text, 1, -1));
        if ($inner === '') {
            return [];
        }

        // Split on top-level commas, respecting double-quoted segments.
        $items = [];
        $buf = '';
        $inQuote = false;
        $escaped = false;
        foreach (str_split($inner) as $ch) {
            if ($inQuote) {
                $buf .= $ch;
                if ($escaped) {
                    $escaped = false;
                } elseif ($ch === '\\') {
                    $escaped = true;
                } elseif ($ch === '"') {
                    $inQuote = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inQuote = true;
                $buf .= $ch;
                continue;
            }
            if ($ch === ',') {
                $items[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if ($inQuote) {
            throw new FrontmatterException(sprintf('Unterminated string in inline list at line %d.', $pos + 1));
        }
        if (trim($buf) !== '') {
            $items[] = trim($buf);
        }

        return array_map(
            static fn (string $item): mixed => self::parseInlineValue($item, $pos),
            $items,
        );
    }

    /**
     * Parses a double-quoted scalar (the writer's only string form).
     *
     * @throws FrontmatterException
     */
    private static function parseQuoted(string $text, int $pos): string
    {
        $out = '';
        $len = strlen($text);
        $i = 1; // skip opening quote

        while ($i < $len) {
            $ch = $text[$i];
            if ($ch === '"') {
                $rest = trim(substr($text, $i + 1));
                if ($rest !== '') {
                    throw new FrontmatterException(
                        sprintf('Trailing content after quoted string at line %d.', $pos + 1),
                    );
                }
                return $out;
            }
            if ($ch === '\\') {
                $i++;
                if ($i >= $len) {
                    break;
                }
                $esc = $text[$i];
                $out .= match ($esc) {
                    '\\' => '\\',
                    '"' => '"',
                    '/' => '/',
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\x08",
                    'f' => "\x0C",
                    '0' => "\x00",
                    'u' => self::parseUnicodeEscape($text, $i, $pos),
                    default => throw new FrontmatterException(
                        sprintf('Unknown escape "\%s" at line %d.', $esc, $pos + 1),
                    ),
                };
                $i++;
                continue;
            }
            $out .= $ch;
            $i++;
        }

        throw new FrontmatterException(sprintf('Unterminated quoted string at line %d.', $pos + 1));
    }

    /**
     * Reads \uXXXX at position $i (pointing at 'u'); advances $i past the 4 hex digits.
     *
     * @throws FrontmatterException
     */
    private static function parseUnicodeEscape(string $text, int &$i, int $pos): string
    {
        $hex = substr($text, $i + 1, 4);
        if (preg_match('/^[0-9A-Fa-f]{4}$/', $hex) !== 1) {
            throw new FrontmatterException(sprintf('Invalid \\u escape at line %d.', $pos + 1));
        }
        $i += 4;

        $code = hexdec($hex);
        // Minimal UTF-8 encoding for BMP code points.
        if ($code < 0x80) {
            return chr($code);
        }
        if ($code < 0x800) {
            return chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
        }

        return chr(0xE0 | ($code >> 12))
            . chr(0x80 | (($code >> 6) & 0x3F))
            . chr(0x80 | ($code & 0x3F));
    }
}
