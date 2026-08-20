<?php

declare(strict_types=1);

namespace CVSync\Engine\Frontmatter;

/**
 * Strict, idempotent YAML writer (spec §4.2).
 *
 * Canonical-form rules:
 *  - Key order: when $keyOrder is provided it is enforced — every key of $fields
 *    must appear in $keyOrder (unknown key → InvalidArgumentException); keys of
 *    $keyOrder absent from $fields are omitted. When null, insertion order of
 *    $fields is used (the adapter is the owner of ordering — contract R4).
 *  - Strings ALWAYS double-quoted, minimal escaping (\\, \", \n, \r, \t,
 *    other control chars → \uXXXX).
 *  - ints/bools/null rendered literally; floats via JSON_PRESERVE_ZERO_FRACTION.
 *  - Scalar lists inline: [a, b] — list order is semantic and never sorted here
 *    (callers sort where order is not semantic, e.g. via Canonicalizer).
 *  - Nested associative maps: keys ksort'ed, 2-space indentation per level.
 *  - Lists of maps are NOT valid in frontmatter mode (the §4.2 subset);
 *    use writeBlockYaml() (menu documents, §4.4).
 *  - LF forced; no timestamps (§4.2.3).
 *
 * Strict idempotence (acceptance criterion): two renders of the same canonical
 * input are byte-identical.
 *
 * Pure PHP: no WordPress functions, no I/O.
 */
final class FrontmatterWriter
{
    /**
     * Renders the frontmatter YAML lines (WITHOUT the --- fences), each line
     * "\n"-terminated, trailing newline included.
     *
     * @param array<string,mixed> $fields   Already canonicalized (meta via Canonicalizer).
     * @param list<string>|null   $keyOrder Fixed key order supplied by the adapter (P3).
     *
     * @throws \InvalidArgumentException Key outside $keyOrder, or a list of maps
     *         (frontmatter subset violation).
     */
    public static function write(array $fields, ?array $keyOrder = null): string
    {
        if ($keyOrder !== null) {
            $unknown = array_diff(array_keys($fields), $keyOrder);
            if ($unknown !== []) {
                throw new \InvalidArgumentException(
                    'Frontmatter keys outside the fixed order: ' . implode(', ', $unknown),
                );
            }
            $ordered = [];
            foreach ($keyOrder as $key) {
                if (array_key_exists($key, $fields)) {
                    $ordered[$key] = $fields[$key];
                }
            }
            $fields = $ordered;
        }

        return self::renderMap($fields, 0, false);
    }

    /**
     * Full document: frontmatter + byte-exact body (body is NEVER touched, §4.2.1).
     * When $hash is provided it is appended as the LAST frontmatter line
     * ('hash' is never part of the hash material itself).
     */
    public static function writeDocument(
        array $frontmatter,
        ?array $keyOrder,
        string $body,
        ?string $hash = null,
    ): string {
        $yaml = self::write($frontmatter, $keyOrder);
        if ($hash !== null) {
            $yaml .= 'hash: ' . self::renderScalar($hash) . "\n";
        }

        return "---\n" . $yaml . "---\n" . $body;
    }

    /**
     * Block-style YAML document (menu files, §4.4): like the frontmatter subset
     * but lists of maps render as nested "- key: value" blocks (recursive
     * 'items'/'children'). Scalar lists stay inline ([primary]). Top-level key
     * order = insertion order (the adapter builds the ordered array).
     *
     * @param array<string,mixed> $data
     *
     * @throws \InvalidArgumentException On non-representable values.
     */
    public static function writeBlockYaml(array $data): string
    {
        return self::renderMap($data, 0, true);
    }

    /**
     * @param array<string|int,mixed> $map
     */
    private static function renderMap(array $map, int $indent, bool $blockLists): string
    {
        $out = '';
        foreach ($map as $key => $value) {
            $out .= self::renderEntry((string) $key, $value, $indent, $blockLists);
        }

        return $out;
    }

    private static function renderEntry(string $key, mixed $value, int $indent, bool $blockLists): string
    {
        $pad = str_repeat(' ', $indent);

        if (is_array($value)) {
            if ($value === []) {
                return $pad . $key . ": []\n";
            }
            if (array_is_list($value)) {
                if (self::isScalarList($value)) {
                    return $pad . $key . ': ['
                        . implode(', ', array_map(self::renderScalar(...), $value))
                        . "]\n";
                }
                if (!$blockLists) {
                    throw new \InvalidArgumentException(
                        "List of maps is not valid in frontmatter mode (key '$key'); use writeBlockYaml().",
                    );
                }
                $out = $pad . $key . ":\n";
                foreach ($value as $item) {
                    if (!is_array($item) || array_is_list($item)) {
                        throw new \InvalidArgumentException(
                            "Block-style list items must be maps (key '$key').",
                        );
                    }
                    $out .= self::renderListItem($item, $indent + 2);
                }
                return $out;
            }
            // Associative map: keys sorted, one nesting level deeper.
            ksort($value);
            return $pad . $key . ":\n" . self::renderMap($value, $indent + 2, $blockLists);
        }

        return $pad . $key . ': ' . self::renderScalar($value) . "\n";
    }

    /**
     * Renders one "- key: value" item of a block-style list: first key on the
     * dash line, subsequent keys aligned two spaces past the dash, nested
     * structures two spaces deeper than their key.
     *
     * @param array<string|int,mixed> $map
     */
    private static function renderListItem(array $map, int $indent): string
    {
        $pad = str_repeat(' ', $indent);
        $out = '';
        $first = true;

        foreach ($map as $key => $value) {
            $key = (string) $key;
            $prefix = $first ? $pad . '- ' : $pad . '  ';
            $first = false;
            $childIndent = $indent + 4; // nested content under a key at "- " column

            if (is_array($value)) {
                if ($value === []) {
                    $out .= $prefix . $key . ": []\n";
                    continue;
                }
                if (array_is_list($value) && self::isScalarList($value)) {
                    $out .= $prefix . $key . ': ['
                        . implode(', ', array_map(self::renderScalar(...), $value))
                        . "]\n";
                    continue;
                }
                if (array_is_list($value)) {
                    $out .= $prefix . $key . ":\n";
                    foreach ($value as $item) {
                        if (!is_array($item) || array_is_list($item)) {
                            throw new \InvalidArgumentException(
                                "Block-style list items must be maps (key '$key').",
                            );
                        }
                        $out .= self::renderListItem($item, $childIndent);
                    }
                    continue;
                }
                ksort($value);
                $out .= $prefix . $key . ":\n" . self::renderMap($value, $childIndent, true);
                continue;
            }

            $out .= $prefix . $key . ': ' . self::renderScalar($value) . "\n";
        }

        return $out;
    }

    /**
     * @param list<mixed> $list
     */
    private static function isScalarList(array $list): bool
    {
        foreach ($list as $item) {
            if (is_array($item) || is_object($item) || is_resource($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Scalar rendering: null/bool/int literal, float via zero-fraction-preserving
     * JSON, string ALWAYS double-quoted with minimal escaping.
     */
    private static function renderScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                throw new \InvalidArgumentException('NaN/INF floats are not representable.');
            }
            return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }
        if (is_string($value)) {
            return self::quoteString($value);
        }

        throw new \InvalidArgumentException(
            'Value is not representable in canonical YAML: ' . get_debug_type($value),
        );
    }

    private static function quoteString(string $value): string
    {
        $out = str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $value,
        );
        $out = preg_replace_callback(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
            static fn (array $m): string => sprintf('\\u%04X', ord($m[0])),
            $out,
        ) ?? $out;

        return '"' . $out . '"';
    }
}
