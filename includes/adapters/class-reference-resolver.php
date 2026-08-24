<?php
/**
 * ReferenceResolver — lado WordPress da resolução de placeholders.
 *
 * Split reconciliado (r1-t2 / desvio 6 do P1-r2): o PlaceholderCodec (P1) é
 * puro e recebe callables; ESTA classe é quem resolve ID↔slug↔URL contra o
 * banco local. Assinaturas exatas do codec:
 *
 *   export ($resolveId):   fn(string $class, ?string $taxonomy, int|string $value): ?string
 *   import ($resolveSlug): fn(string $class, ?string $taxonomy, string $slug): int|string|null
 *   classify ($isMediaId): fn(int $postId): bool — injetado no encode para
 *                          classificar "id"/"ids" pelo post_type do alvo
 *                          (attachment ⇒ {{attachment:}}, demais ⇒ {{ref:}}).
 *
 * class ∈ ref | attachment | term | attachment_url (PlaceholderVocabulary).
 * Resolução por SLUG (estável cross-ambiente), nunca por ID de origem (§6).
 *
 * @package CVSync\Adapters
 */

declare(strict_types=1);

namespace CVSync\Adapters;

use CVSync\Engine\Placeholders\PlaceholderVocabulary;

defined('ABSPATH') || exit;

final class ReferenceResolver
{
    /**
     * Post types alvo de refs estruturais (wp:block/wp:navigation "ref") E de
     * ids classificados como {{ref:}} no export (wp:navigation-link "id"
     * apontando para páginas/posts — classificação por post_type do alvo).
     */
    private const REF_POST_TYPES = ['wp_block', 'wp_navigation', 'page', 'post'];

    /** @var array<int, string|false> Cache ID → post_type. */
    private array $postTypeByPostId = [];

    /** @var array<int, string|false> Cache ID → slug. */
    private array $slugByPostId = [];

    /** @var array<string, int|null> Cache "{type}:{slug}" → post ID local. */
    private array $postIdBySlug = [];

    /** @var array<string, int|null> Cache "{taxonomy}:{slug}" → term ID local. */
    private array $termIdBySlug = [];

    /** Callable do lado export (banco → arquivo). */
    public function exportResolver(): callable
    {
        return fn(string $class, ?string $taxonomy, int|string $value): ?string => match ($class) {
            PlaceholderVocabulary::REF,
            PlaceholderVocabulary::ATTACHMENT => $this->postSlugById((int) $value),
            PlaceholderVocabulary::TERM => $this->termSlugById((string) $taxonomy, (int) $value),
            PlaceholderVocabulary::ATTACHMENT_URL => $this->attachmentSlugByUrl((string) $value),
            default => null,
        };
    }

    /** Callable do lado import (arquivo → banco). */
    public function importResolver(): callable
    {
        return function (string $class, ?string $taxonomy, string $slug): int|string|null {
            return match ($class) {
                PlaceholderVocabulary::REF => $this->postIdBySlug(self::REF_POST_TYPES, $slug),
                PlaceholderVocabulary::ATTACHMENT => $this->postIdBySlug(['attachment'], $slug),
                PlaceholderVocabulary::TERM => $this->termIdBySlug((string) $taxonomy, $slug),
                PlaceholderVocabulary::ATTACHMENT_URL => $this->attachmentUrlBySlug($slug),
                default => null,
            };
        };
    }

    /**
     * Classificador injetado no PlaceholderCodec::encode() (export): o alvo de
     * um "id"/"ids" é mídia? attachment ⇒ {{attachment:}}; qualquer outro
     * post_type ⇒ {{ref:}}. Sem fallback — o braço ATTACHMENT do import
     * continua consultando APENAS 'attachment' (filosofia do plugin).
     */
    public function isMediaId(): callable
    {
        return fn(int $postId): bool => $this->postTypeById($postId) === 'attachment';
    }

    /**
     * Atributos-ID para a validação anti-regressão §6.2 (lista injetável —
     * P1 nunca chama apply_filters; o filtro vive aqui, na fronteira WP).
     *
     * @return list<string>
     */
    public function rawIdAttributes(): array
    {
        $config = $this->placeholderAttributesConfig();

        return $config['raw_id_attributes'];
    }

    /**
     * Atributos escalares de term-ID (attribute => taxonomy) para encode/decode.
     *
     * @return array<string,string>
     */
    public function termIdAttributes(): array
    {
        $config = $this->placeholderAttributesConfig();

        return $config['term_id_attributes'];
    }

    /**
     * Filtro de extensibilidade 'cvsync/placeholder_attributes' (blocos de
     * terceiros com atributos-ID próprios).
     *
     * @return array{raw_id_attributes: list<string>, term_id_attributes: array<string,string>}
     */
    private function placeholderAttributesConfig(): array
    {
        $defaults = [
            'raw_id_attributes'  => PlaceholderVocabulary::DEFAULT_RAW_ATTRIBUTES,
            'term_id_attributes' => PlaceholderVocabulary::DEFAULT_TERM_ATTRIBUTES,
        ];

        $filtered = apply_filters('cvsync/placeholder_attributes', $defaults);

        return is_array($filtered) ? array_merge($defaults, $filtered) : $defaults;
    }

    /** Slug de um post arbitrário (export de `parent`, featured image, etc.). */
    public function slugForPostId(int $postId): ?string
    {
        return $this->postSlugById($postId);
    }

    /** Post ID local por slug para um post type arbitrário (parent de páginas/CPTs). */
    public function postIdForSlug(string $postType, string $slug): ?int
    {
        return $this->postIdBySlug([$postType], $slug);
    }

    /**
     * Invalida os caches (inclusive os NEGATIVOS — 🟡8 r7): chamado antes do
     * parent-fixup e do reprocessamento de pending_ref no fim do lote, quando
     * slugs antes ausentes podem ter sido importados no mesmo batch (§A.5.2.8).
     */
    public function flushCaches(): void
    {
        $this->postTypeByPostId = [];
        $this->slugByPostId = [];
        $this->postIdBySlug = [];
        $this->termIdBySlug = [];
    }

    // ------------------------------------------------------------------
    // Export (ID → slug)
    // ------------------------------------------------------------------

    private function postTypeById(int $postId): ?string
    {
        if (!array_key_exists($postId, $this->postTypeByPostId)) {
            $post = get_post($postId);
            $this->postTypeByPostId[$postId] = $post instanceof \WP_Post ? $post->post_type : false;
        }

        return $this->postTypeByPostId[$postId] !== false ? $this->postTypeByPostId[$postId] : null;
    }

    private function postSlugById(int $postId): ?string
    {
        if (!array_key_exists($postId, $this->slugByPostId)) {
            $post = get_post($postId);
            $this->slugByPostId[$postId] = $post instanceof \WP_Post ? $post->post_name : false;
        }

        return $this->slugByPostId[$postId] !== false ? $this->slugByPostId[$postId] : null;
    }

    private function termSlugById(string $taxonomy, int $termId): ?string
    {
        $term = get_term($termId, $taxonomy);

        return $term instanceof \WP_Term ? $term->slug : null;
    }

    /**
     * URL absoluta do anexo → slug: casa pelo sufixo de '_wp_attached_file'
     * relativo ao baseurl de uploads.
     */
    private function attachmentSlugByUrl(string $url): ?string
    {
        $baseurl = (string) (wp_upload_dir()['baseurl'] ?? '');
        if ($baseurl === '' || !str_starts_with($url, $baseurl)) {
            return null;
        }

        $relative = ltrim(substr($url, strlen($baseurl)), '/');
        $found = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- lookup único por arquivo; sem índice alternativo no core.
                ['key' => '_wp_attached_file', 'value' => $relative],
            ],
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        return $found !== [] ? $this->postSlugById((int) $found[0]) : null;
    }

    // ------------------------------------------------------------------
    // Import (slug → ID/URL local)
    // ------------------------------------------------------------------

    /**
     * @param list<string> $postTypes
     */
    private function postIdBySlug(array $postTypes, string $slug): ?int
    {
        $cacheKey = implode(',', $postTypes) . ':' . $slug;
        if (!array_key_exists($cacheKey, $this->postIdBySlug)) {
            $query = new \WP_Query([
                'name'           => $slug,
                'post_type'      => $postTypes,
                'post_status'    => ['publish', 'draft', 'private', 'inherit'],
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);
            $ids = $query->posts;
            $this->postIdBySlug[$cacheKey] = $ids !== [] ? (int) $ids[0] : null;
        }

        return $this->postIdBySlug[$cacheKey];
    }

    private function termIdBySlug(string $taxonomy, string $slug): ?int
    {
        $cacheKey = $taxonomy . ':' . $slug;
        if (!array_key_exists($cacheKey, $this->termIdBySlug)) {
            $term = get_term_by('slug', $slug, $taxonomy);
            $this->termIdBySlug[$cacheKey] = $term instanceof \WP_Term ? (int) $term->term_id : null;
        }

        return $this->termIdBySlug[$cacheKey];
    }

    /**
     * Reexpansão §A.6: resolve contra o BANCO do destino (attachment já
     * materializado), nunca contra o filesystem do repo.
     */
    private function attachmentUrlBySlug(string $slug): ?string
    {
        $id = $this->postIdBySlug(['attachment'], $slug);
        if ($id === null) {
            return null;
        }

        $url = wp_get_attachment_url($id);

        return $url !== false ? $url : null;
    }
}
