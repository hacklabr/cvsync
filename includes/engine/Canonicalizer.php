<?php

declare(strict_types=1);

namespace CVSync\Engine;

use CVSync\Engine\Exception\CanonicalizationException;

/**
 * Canonicalization pipeline — normative contract of spec §5.6.
 *
 * Pipeline for meta values: maybe_unserialize (pure-PHP port, allowed_classes=false)
 * → type normalization → multi-value defined ordering → recursive ksort →
 * json_encode with fixed flags. The same pipeline applies to wp_global_styles
 * JSON (§4.5).
 *
 * Type normalization rule (stable, documented — §5.6 step 2):
 *  - Serialized input carries native types by construction (unserialize restores
 *    int/float/bool/null). NOTHING is promoted afterwards.
 *  - Strings that were NOT serialized are NEVER promoted to numbers — the
 *    discriminator is serialization origin, not the string's shape.
 *  - Float with integral value serialized as float (d:1;) stays float and encodes
 *    as "1.0" thanks to JSON_PRESERVE_ZERO_FRACTION; i:1; encodes as 1; the plain
 *    string "1" encodes as "\"1\"". Three distinct, stable forms — a genuine type
 *    divergence between two databases is reported by the hash, by design (D2, r1).
 *  - Associative arrays are ksort'ed recursively; LISTS preserve order (order is
 *    semantic in lists, e.g. menu items).
 *
 * Pure PHP: no WordPress functions, no I/O.
 */
final class Canonicalizer
{
    /** Fixed json_encode flags (§5.6 step 5 + D2 zero-fraction). */
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_THROW_ON_ERROR;

    /**
     * Full §5.6 pipeline over ONE meta value (as returned by get_post_meta).
     *
     * Idempotent: canonicalizeValue(canonicalizeValue($x)) ≡ canonicalizeValue($x).
     *
     * @throws CanonicalizationException Value not representable (object, resource,
     *         NaN/INF float, or serialized payload containing objects).
     */
    public static function canonicalizeValue(mixed $value): mixed
    {
        return self::canonicalize($value, true);
    }

    /**
     * Multi-value meta (get_post_meta without $single): canonicalizes each item and
     * sorts with a defined comparison (AFTER canonicalization, over each item's
     * canonical JSON form, using PHP `<=>` string semantics — numeric-looking
     * strings compare numerically; deterministic on both sides, which is all
     * §5.6 requires). A single item does not become a list.
     *
     * @param list<mixed> $values
     * @return list<mixed>|mixed List for 2+ values; the single canonicalized value for 1;
     *         empty list for 0.
     */
    public static function canonicalizeMetaValues(array $values): mixed
    {
        if (count($values) === 1) {
            return self::canonicalize(reset($values), true);
        }

        $canonical = array_map(
            static fn (mixed $v): mixed => self::canonicalize($v, true),
            array_values($values),
        );

        usort(
            $canonical,
            static fn (mixed $a, mixed $b): int => self::encodeJson($a) <=> self::encodeJson($b),
        );

        return $canonical;
    }

    /**
     * json_encode with FIXED flags: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
     * | JSON_PRESERVE_ZERO_FRACTION (+ THROW_ON_ERROR).
     *
     * @throws CanonicalizationException On encoding failure.
     */
    public static function encodeJson(mixed $value): string
    {
        try {
            return json_encode($value, self::JSON_FLAGS);
        } catch (\JsonException $e) {
            throw new CanonicalizationException('json_encode failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * wp_global_styles JSON (§4.5): strict decode → canonicalize (no unserialize —
     * JSON strings are data, never PHP-serialized payloads) → fixed-flags encode.
     * Input and output are strings; output is the hashable/writable canonical form.
     *
     * @throws CanonicalizationException Invalid JSON or non-representable content.
     */
    public static function canonicalizeJson(string $json): string
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CanonicalizationException('Invalid JSON: ' . $e->getMessage(), 0, $e);
        }

        return self::encodeJson(self::canonicalize($decoded, false));
    }

    /**
     * Recursive core. $allowUnserialize applies only to the top-level string
     * (a serialized payload is a flat string at the meta boundary).
     *
     * @throws CanonicalizationException
     */
    private static function canonicalize(mixed $value, bool $allowUnserialize): mixed
    {
        if (is_string($value) && $allowUnserialize) {
            $value = self::unserializeIfNeeded($value);
        }

        if (is_object($value) || is_resource($value)) {
            throw new CanonicalizationException(
                'Value is not canonically representable: ' . get_debug_type($value),
            );
        }

        if (is_float($value) && (is_nan($value) || is_infinite($value))) {
            throw new CanonicalizationException('NaN/INF floats are not canonically representable.');
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::canonicalize($v, false);
            }
            if (!array_is_list($out)) {
                ksort($out);
            }
            return $out;
        }

        return $value;
    }

    /**
     * Pure-PHP port of WordPress maybe_unserialize() for the canonical pipeline.
     *
     * Differences from the WP original, all deliberate:
     *  - unserialize() runs with allowed_classes = false (never instantiates objects);
     *  - a payload that decodes to __PHP_Incomplete_Class (i.e. it DID contain an
     *    object) throws instead of silently degrading;
     *  - a syntactically serialized but corrupt payload is returned as the original
     *    string (maybe_unserialize semantics).
     *
     * @throws CanonicalizationException Serialized payload contains an object.
     */
    private static function unserializeIfNeeded(string $data): mixed
    {
        if (!self::isSerialized($data)) {
            return $data;
        }

        $result = @unserialize($data, ['allowed_classes' => false]);

        if ($result === false && $data !== 'b:0;') {
            // Corrupt serialized-looking string: maybe_unserialize returns the input.
            return $data;
        }

        self::assertNoIncompleteClass($result);

        return $result;
    }

    /**
     * Strict port of WordPress is_serialized($data, true).
     */
    private static function isSerialized(string $data): bool
    {
        $data = trim($data);

        if ($data === 'N;') {
            return true;
        }
        if (strlen($data) < 4) {
            return false;
        }
        if ($data[1] !== ':') {
            return false;
        }
        $lastc = $data[strlen($data) - 1];
        if ($lastc !== ';' && $lastc !== '}') {
            return false;
        }

        $token = $data[0];
        switch ($token) {
            case 's':
                if (substr($data, -2, 1) !== '"') {
                    return false;
                }
                // no break — 's' shares the pattern check with 'a'/'O'
            case 'a':
            case 'O':
                return (bool) preg_match("/^{$token}:[0-9]+:/s", $data);
            case 'b':
            case 'i':
            case 'd':
                return (bool) preg_match("/^{$token}:[0-9.E+-]+;$/", $data);
        }

        return false;
    }

    /**
     * @throws CanonicalizationException When the payload contains __PHP_Incomplete_Class.
     */
    private static function assertNoIncompleteClass(mixed $value): void
    {
        if ($value instanceof \__PHP_Incomplete_Class) {
            throw new CanonicalizationException(
                'Serialized payload contains an object (not canonically representable).',
            );
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                self::assertNoIncompleteClass($v);
            }
        }
    }
}
