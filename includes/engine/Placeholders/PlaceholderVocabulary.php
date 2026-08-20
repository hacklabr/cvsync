<?php

declare(strict_types=1);

namespace CVSync\Engine\Placeholders;

/**
 * Placeholder vocabulary and patterns — spec §6.1/§6.2 and §A.6.
 * Single source used by export, import, lint and verify.
 *
 * Principle (mandatory clause §6): numeric origin IDs NEVER cross the
 * environment border. Files contain only placeholders; import writes locally
 * resolved IDs or the literal placeholder — never the origin number.
 */
final class PlaceholderVocabulary
{
    /** {{ref:slug}} — STRUCTURAL (wp:block / wp:navigation refs): unresolved blocks import. */
    public const REF = 'ref';

    /** {{attachment:slug}} — non-structural (media id attributes: "id", "ids"). */
    public const ATTACHMENT = 'attachment';

    /** {{attachment_url:slug}} — §A.6: attachment URL (attribute + inner HTML). */
    public const ATTACHMENT_URL = 'attachment_url';

    /** {{term:taxonomy:slug}} — term ids in queries (tagId, taxQuery, fixed categories). */
    public const TERM = 'term';

    /** {{home_url}} — exact-string replace of home_url(), never regex in JSON. */
    public const HOME_URL = 'home_url';

    /** {{missing:id}} — inert form: target absent in the ORIGIN environment. */
    public const MISSING = 'missing';

    /** Single PCRE matching any token: groups (kind, args?). */
    public const PATTERN = '/\{\{(ref|attachment_url|attachment|term|home_url|missing)(?::([^}]*))?\}\}/';

    /**
     * Attribute keys whose bare numeric value is an anti-regression violation
     * (§6.2). Injectable into PlaceholderCodec::assertNoRawNumericRefs() —
     * P3 extends via the WP filter 'cvsync/raw_id_attributes' at the border
     * (the engine cannot call apply_filters).
     *
     * @var list<string>
     */
    public const DEFAULT_RAW_ATTRIBUTES = ['ref', 'id', 'ids'];

    /**
     * Scalar attribute keys holding term IDs: attribute => taxonomy.
     * Injectable into encode/decode; P3 extends via filter for third-party blocks.
     * Generic taxQuery arrays ("taxQuery":{"<taxonomy>":[ids]}) are handled
     * separately and always on.
     *
     * @var array<string,string>
     */
    public const DEFAULT_TERM_ATTRIBUTES = [
        'tagId' => 'post_tag',
    ];
}
