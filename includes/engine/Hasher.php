<?php

declare(strict_types=1);

namespace CVSync\Engine;

use CVSync\Engine\Frontmatter\FrontmatterWriter;

/**
 * Canonical hash — spec §5.1 (textual entities) and §A.4.1 (attachment composite).
 *
 * SINGLE SOURCE for the hash material: parts, separator and order are defined
 * HERE and nowhere else (reconciled R1 of r1). Adapters own the frontmatter
 * key order; the Hasher owns concatenation.
 *
 * NORMATIVE MATERIAL (write() output ends with a trailing "\n"):
 *
 *   textual:    H = sha256( dump(frontmatter) ‖ "\n" ‖ body )
 *   composite:  H = sha256( dump(frontmatter) ‖ "\n" ‖ "bin:" ‖ binHashHex ‖ "\n" ‖ body )
 *
 * where dump() = FrontmatterWriter::write() WITHOUT the 'hash' key and without
 * fences, and binHashHex = the 64 lowercase hex chars of sha256(original binary)
 * (prefix stripped). For attachments the body is '' (sidecar-only), which makes
 * the composite form exactly §A.4.1's
 *   SHA-256( sidecar_canônico_sem_hash ‖ "bin:" ‖ SHA-256(binário) ).
 *
 * Because the frontmatter is serialized by the SAME writer used to persist the
 * file, the hashed form is by construction the written form (D6 of r1).
 *
 * The Hasher NEVER performs I/O and never sees binary bytes: $binHash arrives
 * pre-computed by P4 (hash_update_stream during the streaming copy, §A.4.1
 * "hash on write"). Pure PHP: no WordPress functions.
 */
final class Hasher
{
    public const PREFIX = 'sha256:';

    /**
     * Canonical entity hash ('sha256:<64 lowercase hex>').
     *
     * @param list<string>|null $keyOrder Adapter-owned fixed key order (P3); null
     *        uses the frontmatter array's insertion order.
     */
    public static function hashDocument(CanonicalDocument $doc, ?array $keyOrder = null): string
    {
        $material = FrontmatterWriter::write($doc->frontmatter, $keyOrder) . "\n";

        if ($doc->binHash !== null) {
            $material .= 'bin:' . self::hexOf($doc->binHash) . "\n";
        }

        $material .= $doc->body;

        return self::PREFIX . hash('sha256', $material);
    }

    /** Utility: canonical hash of an arbitrary canonical string. */
    public static function hashString(string $canonicalUtf8): string
    {
        return self::PREFIX . hash('sha256', $canonicalUtf8);
    }

    /**
     * Normalizes a hash representation to 'sha256:<64 lowercase hex>'.
     *
     * @throws \InvalidArgumentException On malformed input.
     */
    public static function normalize(string $hexOrPrefixed): string
    {
        return self::PREFIX . self::hexOf($hexOrPrefixed);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function hexOf(string $hexOrPrefixed): string
    {
        $hex = strtolower(trim($hexOrPrefixed));
        if (str_starts_with($hex, self::PREFIX)) {
            $hex = substr($hex, strlen(self::PREFIX));
        }
        if (preg_match('/^[0-9a-f]{64}$/', $hex) !== 1) {
            throw new \InvalidArgumentException('Malformed sha256 hash: expected 64 hex chars.');
        }

        return $hex;
    }
}
