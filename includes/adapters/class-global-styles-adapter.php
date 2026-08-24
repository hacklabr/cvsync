<?php
/**
 * GlobalStylesAdapter — wp_global_styles (spec §4.5).
 *
 * Diferenças do pipeline canônico de posts:
 *  - post_content é JSON, não markup: o corpo canônico é
 *    Canonicalizer::canonicalizeJson() (ordenação recursiva de chaves,
 *    flags fixas — §4.5/§5.6), placeholderizado DEPOIS da canonicalização
 *    ({{home_url}} por replace exato);
 *  - um arquivo por stylesheet: 'global-styles/{stylesheet}.global-styles.json';
 *  - o import VALIDA o tema ativo contra o stylesheet do arquivo (§4.5) —
 *    divergência → RejectedEntityException, nunca apply;
 *  - após o write, invalida os caches do WP_Theme_JSON_Resolver (§13.8).
 *
 * @package CVSync\Adapters
 */

declare(strict_types=1);

namespace CVSync\Adapters;

use CVSync\ApplyResult;
use CVSync\Engine\CanonicalDocument;
use CVSync\Engine\Canonicalizer;
use CVSync\Engine\EntityRef;
use CVSync\Engine\Placeholders\PlaceholderCodec;
use CVSync\ImportContext;
use CVSync\PathGuard;
use CVSync\Storage\StateStore;

defined('ABSPATH') || exit;

final class GlobalStylesAdapter extends AbstractPostAdapter
{
    protected const FRONTMATTER_KEY_ORDER = [
        'uuid', 'post_type', 'slug', 'title', 'status', 'stylesheet', 'terms', 'meta',
    ];

    public function __construct(StateStore $state, ReferenceResolver $resolver, PathGuard $paths)
    {
        parent::__construct($state, $resolver, $paths);
    }

    public function postType(): string
    {
        return 'wp_global_styles';
    }

    public function statuses(): array
    {
        return ['publish'];
    }

    public function baseDirectory(): string
    {
        return 'global-styles';
    }

    public function fileExtension(): string
    {
        return '.global-styles.json';
    }

    public function metaWhitelist(): array
    {
        return [];
    }

    public function identityTaxonomies(): array
    {
        return ['wp_theme'];
    }

    public function hasBlockBody(): bool
    {
        return false;
    }

    public function relativePath(CanonicalDocument $doc): string
    {
        $stylesheet = (string) ($doc->frontmatter['stylesheet'] ?? $doc->slug());

        return $this->baseDirectory() . '/' . $stylesheet . $this->fileExtension();
    }

    public function readCanonical(EntityRef $ref): ?CanonicalDocument
    {
        $post = $this->resolvePost($ref);
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            return null;
        }

        $uuid = $this->ensureUuid((int) $post->ID);
        $ref = EntityRef::post($this->postType(), $uuid);
        $stylesheet = get_stylesheet();

        // JSON canônico primeiro; placeholders depois (replace exato de string).
        $body = PlaceholderCodec::encode(
            Canonicalizer::canonicalizeJson($post->post_content),
            $this->resolver->exportResolver(),
            home_url(),
            (string) (wp_upload_dir()['baseurl'] ?? ''),
            $this->resolver->termIdAttributes(),
            $this->resolver->isMediaId(),
        );

        $frontmatter = [
            'uuid'       => $uuid,
            'post_type'  => $this->postType(),
            'slug'       => $post->post_name,
            'title'      => $post->post_title,
            'status'     => 'publish',
            'stylesheet' => $stylesheet,
        ];

        $terms = $this->canonicalTerms((int) $post->ID);
        if ($terms !== []) {
            $frontmatter['terms'] = $terms;
        }

        return new CanonicalDocument($ref, $frontmatter, $body->content);
    }

    public function validateFrontmatter(array $frontmatter): void
    {
        parent::validateFrontmatter($frontmatter);

        $stylesheet = $frontmatter['stylesheet'] ?? null;
        if (!is_string($stylesheet) || preg_match('/^[a-z0-9][a-z0-9\-]*$/', $stylesheet) !== 1) {
            throw new RejectedEntityException('Global styles sem stylesheet válido no frontmatter.');
        }
    }

    public function apply(CanonicalDocument $doc, ImportContext $ctx): ApplyResult
    {
        // §4.5: o tema ativo DEVE corresponder ao namespace do arquivo.
        $stylesheet = (string) ($doc->frontmatter['stylesheet'] ?? '');
        if ($stylesheet !== get_stylesheet()) {
            throw new RejectedEntityException(
                sprintf('Global styles do tema "%s" recusado: tema ativo é "%s".', $stylesheet, get_stylesheet())
            );
        }

        $decoded = PlaceholderCodec::decode(
            $doc->body,
            $this->resolver->importResolver(),
            home_url(),
            $this->resolver->termIdAttributes(),
        );
        if ($decoded->hasStructuralBlockers()) {
            return new ApplyResult(null, $decoded->unresolvedStructural, []);
        }

        $post = $this->resolvePost($doc->ref) ?? $this->findByStylesheet($stylesheet);

        $postarr = [
            'post_type'    => $this->postType(),
            'post_name'    => 'wp-global-styles-' . $stylesheet,
            'post_title'   => (string) ($doc->frontmatter['title'] ?? 'Custom Styles'),
            'post_content' => $decoded->content,
            'post_status'  => 'publish',
        ];

        if ($post instanceof \WP_Post) {
            $postarr['ID'] = $post->ID;
            $postId = wp_update_post(wp_slash($postarr), true);
        } else {
            $postId = wp_insert_post(wp_slash($postarr), true);
        }

        if (is_wp_error($postId)) {
            throw new AdapterException('Falha ao gravar wp_global_styles: ' . $postId->get_error_message());
        }

        $this->applyTerms((int) $postId, $doc->terms());
        $this->ensureUuid((int) $postId);

        return new ApplyResult((int) $postId, [], $decoded->unresolvedNonStructural, []);
    }

    /** Invalidação dos caches de theme.json (§13.8) — após o commit do import. */
    protected function afterApply(int $postId, CanonicalDocument $doc): void
    {
        if (method_exists(\WP_Theme_JSON_Resolver::class, 'clean_cached_data')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }
    }

    private function findByStylesheet(string $stylesheet): ?\WP_Post
    {
        $found = get_posts([
            'post_type'      => $this->postType(),
            'post_status'    => 'publish',
            'name'           => 'wp-global-styles-' . $stylesheet,
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);

        return $found[0] instanceof \WP_Post ? $found[0] : null;
    }
}
