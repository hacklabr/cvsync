<?php
/**
 * MenuAdapter — menu clássico como documento declarativo `*.menu.yml` (§4.4).
 *
 *  - YAML INTEGRAL (sem fences de frontmatter): uuid, name, slug, locations,
 *    items — serializado por FrontmatterWriter::writeBlockYaml() (block-style,
 *    r1-t2 R2 entregue pelo P1);
 *  - hierarquia por ANINHAMENTO ('children'), ordem implícita pela sequência —
 *    elimina _menu_item_menu_item_parent e menu_order numéricos (fontes
 *    clássicas de conflito de merge);
 *  - destinos sempre por SLUG (nunca ID, §6); atributos extras só quando
 *    não-default; 'locations' dentro do arquivo do menu (§4.4);
 *  - identidade: entity_kind='nav_menu', entity_key=slug do termo; UUID em
 *    term meta '_cvsync_uuid' (adoção com scan único de termmeta);
 *  - state interno: linhas 'menu_location' ('{stylesheet}:{location}') são
 *    upserted na aplicação para observabilidade (§4.4).
 *
 * O corpo do CanonicalDocument é o YAML canônico inteiro e o frontmatter é
 * vazio — o hash incide sobre o documento re-serializado na forma canônica
 * (mesma forma gravada), mantendo "forma hasheada ≡ forma gravada".
 *
 * @package CVSync\Adapters
 */

declare(strict_types=1);

namespace CVSync\Adapters;

use CVSync\ApplyResult;
use CVSync\Engine\CanonicalDocument;
use CVSync\Engine\EntityRef;
use CVSync\Engine\Frontmatter\FrontmatterParser;
use CVSync\Engine\Frontmatter\FrontmatterWriter;
use CVSync\ImportContext;
use CVSync\PathGuard;
use CVSync\Storage\EntityStatus;
use CVSync\Storage\StateStore;

defined('ABSPATH') || exit;

final class MenuAdapter implements EntityAdapter
{
    /** Ordem canônica das chaves de um item (§4.4). */
    private const ITEM_KEY_ORDER = ['title', 'type', 'object', 'target', 'url', 'classes', 'children'];

    public function __construct(
        private readonly StateStore $state,
        private readonly ReferenceResolver $resolver,
        private readonly PathGuard $paths,
    ) {
    }

    // ------------------------------------------------------------------
    // Identidade estática
    // ------------------------------------------------------------------

    public function kind(): string
    {
        return 'nav_menu';
    }

    public function postType(): ?string
    {
        return null;
    }

    public function statuses(): array
    {
        return [];
    }

    public function baseDirectory(): string
    {
        return 'menus';
    }

    public function fileExtension(): string
    {
        return '.menu.yml';
    }

    public function metaAllowlist(): array
    {
        return [];
    }

    public function identityTaxonomies(): array
    {
        return [];
    }

    public function keyOrder(): array
    {
        return []; // documento YAML integral — sem frontmatter
    }

    public function hasBlockBody(): bool
    {
        return false;
    }

    // ------------------------------------------------------------------
    // Existência e identidade
    // ------------------------------------------------------------------

    public function exists(EntityRef $ref): bool
    {
        return $this->menuBySlug($ref->key) instanceof \WP_Term;
    }

    public function findByUuid(string $uuid): ?EntityRef
    {
        $terms = get_terms([
            'taxonomy'   => 'nav_menu',
            'hide_empty' => false,
            'number'     => 1,
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- scan único de adoção por entidade (§9.1).
                ['key' => '_cvsync_uuid', 'value' => $uuid],
            ],
        ]);

        if (is_wp_error($terms) || $terms === []) {
            return null;
        }

        return EntityRef::of('nav_menu', $terms[0]->slug);
    }

    public function findBySlug(string $slug): ?EntityRef
    {
        return $this->menuBySlug($slug) instanceof \WP_Term
            ? EntityRef::of('nav_menu', $slug)
            : null;
    }

    /**
     * Term-meta variant of ensureUuid: existing meta wins (slug re-adoption
     * keeps the db identity); else the DOCUMENT uuid is adopted when provided
     * (import path); else mint local.
     */
    public function ensureUuid(int $dbId, ?string $uuid = null): string
    {
        $existing = get_term_meta($dbId, '_cvsync_uuid', true);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        if (is_string($uuid) && $uuid !== '' && wp_is_uuid($uuid)) {
            update_term_meta($dbId, '_cvsync_uuid', $uuid);
            return $uuid;
        }

        $uuid = wp_generate_uuid4();
        update_term_meta($dbId, '_cvsync_uuid', $uuid);

        return $uuid;
    }

    // ------------------------------------------------------------------
    // Leitura canônica (banco → arquivo)
    // ------------------------------------------------------------------

    public function readCanonical(EntityRef $ref): ?CanonicalDocument
    {
        $menu = $this->menuBySlug($ref->key);
        if (!$menu instanceof \WP_Term) {
            return null;
        }

        $locations = get_nav_menu_locations();
        $assigned = array_values(array_keys(array_filter(
            $locations,
            static fn (int $menuId): bool => $menuId === (int) $menu->term_id
        )));
        sort($assigned);

        $items = wp_get_nav_menu_items($menu->term_id, ['orderby' => 'menu_order', 'order' => 'ASC']);
        $tree = $this->buildTree(is_array($items) ? $items : []);

        $data = [
            'uuid'      => $this->ensureUuid((int) $menu->term_id),
            'name'      => $menu->name,
            'slug'      => $menu->slug,
            'locations' => $assigned,
            'items'     => $tree,
        ];

        return new CanonicalDocument($ref, [], FrontmatterWriter::writeBlockYaml($data));
    }

    public function parseDocument(string $bytes): CanonicalDocument
    {
        $data = FrontmatterParser::parse($bytes);

        $slug = $data['slug'] ?? null;
        if (!is_string($slug) || preg_match('/^[a-z0-9][a-z0-9_\-]*$/', $slug) !== 1) {
            throw new RejectedEntityException('Menu sem slug válido (§6.4).');
        }
        if (!is_string($data['uuid'] ?? null)) {
            throw new RejectedEntityException('Menu sem uuid.');
        }
        if (!is_string($data['name'] ?? null)) {
            throw new RejectedEntityException('Menu sem name.');
        }

        $canonical = $this->canonicalizeData($data);

        return new CanonicalDocument(
            EntityRef::of('nav_menu', $slug),
            [],
            FrontmatterWriter::writeBlockYaml($canonical)
        );
    }

    /** A validação de menus ocorre em parseDocument (YAML integral). */
    public function validateFrontmatter(array $frontmatter): void
    {
    }

    /** YAML integral (§4.4): o documento canônico + 'hash' como última chave. */
    public function serializeDocument(CanonicalDocument $doc, string $hash): string
    {
        $data = FrontmatterParser::parse($doc->body); // forma canônica (sem hash)
        $data['hash'] = $hash;

        return FrontmatterWriter::writeBlockYaml($data);
    }

    public function relativePath(CanonicalDocument $doc): string
    {
        return $this->baseDirectory() . '/' . $doc->ref->key . $this->fileExtension();
    }

    public function locateFile(EntityRef $ref): ?string
    {
        $path = $this->baseDirectory() . '/' . $ref->key . $this->fileExtension();

        return $this->paths->exists($path) ? $path : null;
    }

    // ------------------------------------------------------------------
    // Escrita (arquivo → banco) — dentro de ImportGuard + withLockedRow
    // ------------------------------------------------------------------

    public function apply(CanonicalDocument $doc, ImportContext $ctx): ApplyResult
    {
        $data = FrontmatterParser::parse($doc->body);
        $data = $this->canonicalizeData($data);

        $menu = $this->menuBySlug($data['slug']);
        if (!$menu instanceof \WP_Term) {
            $menuId = wp_create_nav_menu($data['name']);
            if (is_wp_error($menuId)) {
                throw new AdapterException('Falha ao criar menu: ' . $menuId->get_error_message());
            }
            $created = get_term($menuId, 'nav_menu');
            if ($created instanceof \WP_Term && $created->slug !== $data['slug']) {
                wp_update_term((int) $created->term_id, 'nav_menu', ['slug' => $data['slug']]);
            }
            $menu = get_term($menuId, 'nav_menu');
        } else {
            wp_update_term((int) $menu->term_id, 'nav_menu', ['name' => $data['name']]);
        }
        if (!$menu instanceof \WP_Term) {
            throw new AdapterException('Menu não encontrado após criação.');
        }
        $menuId = (int) $menu->term_id;

        // Substituição declarativa: remove os itens atuais e recria a árvore.
        $existing = wp_get_nav_menu_items($menuId);
        foreach (is_array($existing) ? $existing : [] as $item) {
            wp_delete_post((int) $item->ID, true);
        }

        $pendencies = [];
        $position = 0;
        $this->createItems($menuId, is_array($data['items'] ?? null) ? $data['items'] : [], 0, $position, $pendencies);

        $this->applyLocations($menuId, array_map('strval', (array) ($data['locations'] ?? [])));

        // Import: the menu term adopts the DOCUMENT uuid (identity churn fix).
        $this->ensureUuid($menuId, $doc->uuid());

        return new ApplyResult($menuId, [], $pendencies, []);
    }

    public function delete(EntityRef $ref, bool $force = false): void
    {
        $menu = $this->menuBySlug($ref->key);
        if ($menu instanceof \WP_Term) {
            wp_delete_nav_menu($menu->term_id);
        }
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private function menuBySlug(string $slug): ?\WP_Term
    {
        $term = get_term_by('slug', $slug, 'nav_menu');

        return $term instanceof \WP_Term ? $term : null;
    }

    /**
     * Normaliza o documento para a forma canônica (ordem e chaves fixas) —
     * aplicado nos dois lados (export e parse) para hash estável.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function canonicalizeData(array $data): array
    {
        $locations = array_map('strval', (array) ($data['locations'] ?? []));
        sort($locations);

        return [
            'uuid'      => (string) ($data['uuid'] ?? ''),
            'name'      => (string) ($data['name'] ?? ''),
            'slug'      => (string) ($data['slug'] ?? ''),
            'locations' => $locations,
            'items'     => $this->canonicalizeItems((array) ($data['items'] ?? [])),
        ];
    }

    /**
     * @param list<mixed> $items
     * @return list<array<string,mixed>>
     */
    private function canonicalizeItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RejectedEntityException('Item de menu não é um mapa.');
            }
            $canonical = [];
            foreach (self::ITEM_KEY_ORDER as $key) {
                if (!array_key_exists($key, $item)) {
                    continue;
                }
                $canonical[$key] = $key === 'children'
                    ? $this->canonicalizeItems((array) $item[$key])
                    : $item[$key];
            }
            $out[] = $canonical;
        }

        return $out;
    }

    /**
     * Árvore aninhada de itens (banco → documento §4.4).
     *
     * @param list<\WP_Post> $items
     * @return list<array<string,mixed>>
     */
    private function buildTree(array $items, int $parentId = 0): array
    {
        $branch = [];
        foreach ($items as $item) {
            if ((int) $item->menu_item_parent !== $parentId) {
                continue;
            }
            $node = $this->canonicalItem($item);
            $children = $this->buildTree($items, (int) $item->ID);
            if ($children !== []) {
                $node['children'] = $children;
            }
            $branch[] = $node;
        }

        return $branch;
    }

    /**
     * Item do banco → mapa canônico (destinos por slug; extras só não-default).
     *
     * @return array<string,mixed>
     */
    private function canonicalItem(\WP_Post $item): array
    {
        $type = (string) get_post_meta($item->ID, '_menu_item_type', true);
        $object = (string) get_post_meta($item->ID, '_menu_item_object', true);
        $objectId = (int) get_post_meta($item->ID, '_menu_item_object_id', true);

        $node = [
            'title' => $item->title !== '' ? $item->title : (string) $item->post_title,
            'type'  => $type !== '' ? $type : 'custom',
        ];

        if ($object !== '') {
            $node['object'] = $object;
        }

        switch ($node['type']) {
            case 'post_type':
                $slug = $this->resolver->slugForPostId($objectId);
                if ($slug !== null) {
                    $node['target'] = $slug;
                }
                break;
            case 'taxonomy':
                $term = get_term($objectId, $object);
                if ($term instanceof \WP_Term) {
                    $node['target'] = $term->slug;
                }
                break;
            case 'post_type_archive':
                $node['target'] = $object;
                break;
            case 'custom':
            default:
                $url = (string) get_post_meta($item->ID, '_menu_item_url', true);
                if ($url !== '') {
                    $node['url'] = str_replace(home_url(), '{{home_url}}', $url);
                }
                $targetAttr = (string) get_post_meta($item->ID, '_menu_item_target', true);
                if ($targetAttr !== '') {
                    $node['target'] = $targetAttr; // ex.: _blank (só não-default)
                }
                break;
        }

        $classes = get_post_meta($item->ID, '_menu_item_classes', true);
        if (is_array($classes)) {
            $classes = array_values(array_filter(array_map('strval', $classes)));
            if ($classes !== []) {
                $node['classes'] = $classes;
            }
        }

        // Reordena na forma canônica (title, type, object, target, url, classes).
        $ordered = [];
        foreach (self::ITEM_KEY_ORDER as $key) {
            if ($key !== 'children' && array_key_exists($key, $node)) {
                $ordered[$key] = $node[$key];
            }
        }

        return $ordered;
    }

    /**
     * Cria a árvore de itens (ordem implícita pela sequência; parent por
     * aninhamento). Destinos não resolvidos: item PULADO + pendência
     * não-estrutural (ausência visível, nunca ID de origem — §6).
     *
     * @param list<array<string,mixed>> $items
     * @param list<\CVSync\Engine\Placeholders\PlaceholderToken> $pendencies
     */
    private function createItems(int $menuId, array $items, int $parentItemId, int &$position, array &$pendencies): void
    {
        foreach ($items as $item) {
            $position++;
            $args = $this->menuItemArgs($item, $parentItemId, $position, $pendencies);
            if ($args === null) {
                continue; // destino não resolvido — pendência registrada
            }

            $itemId = wp_update_nav_menu_item($menuId, 0, $args);
            if (is_wp_error($itemId)) {
                throw new AdapterException(
                    sprintf('Falha ao criar item de menu "%s": %s', (string) ($item['title'] ?? '?'), $itemId->get_error_message())
                );
            }

            $children = $item['children'] ?? [];
            if (is_array($children) && $children !== []) {
                $this->createItems($menuId, $children, (int) $itemId, $position, $pendencies);
            }
        }
    }

    /**
     * @param array<string,mixed> $item
     * @param list<\CVSync\Engine\Placeholders\PlaceholderToken> $pendencies
     * @return array<string,mixed>|null null = item pulado (destino não resolvido)
     */
    private function menuItemArgs(array $item, int $parentItemId, int $position, array &$pendencies): ?array
    {
        $type = (string) ($item['type'] ?? 'custom');
        $target = isset($item['target']) ? (string) $item['target'] : '';

        $args = [
            'menu-item-title'     => (string) ($item['title'] ?? ''),
            'menu-item-status'    => 'publish',
            'menu-item-parent-id' => $parentItemId,
            'menu-item-position'  => $position,
            'menu-item-type'      => $type,
        ];

        $classes = $item['classes'] ?? [];
        if (is_array($classes) && $classes !== []) {
            $args['menu-item-classes'] = implode(' ', array_map('sanitize_html_class', array_map('strval', $classes)));
        }

        switch ($type) {
            case 'post_type':
                $object = (string) ($item['object'] ?? 'page');
                $objectId = $this->resolver->postIdForSlug($object, $target);
                if ($objectId === null) {
                    $pendencies[] = new \CVSync\Engine\Placeholders\PlaceholderToken('menu_target', [$target]);
                    return null;
                }
                $args['menu-item-object'] = $object;
                $args['menu-item-object-id'] = $objectId;
                break;
            case 'taxonomy':
                $object = (string) ($item['object'] ?? 'category');
                $term = get_term_by('slug', $target, $object);
                if (!$term instanceof \WP_Term) {
                    $pendencies[] = new \CVSync\Engine\Placeholders\PlaceholderToken('menu_target', [$target]);
                    return null;
                }
                $args['menu-item-object'] = $object;
                $args['menu-item-object-id'] = (int) $term->term_id;
                break;
            case 'post_type_archive':
                $args['menu-item-object'] = (string) ($item['object'] ?? $target);
                $args['menu-item-object-id'] = 0;
                break;
            case 'custom':
            default:
                $url = (string) ($item['url'] ?? '');
                $args['menu-item-url'] = str_replace('{{home_url}}', home_url(), $url);
                if ($target !== '') {
                    $args['menu-item-target'] = sanitize_text_field($target); // ex.: _blank
                }
                break;
        }

        return $args;
    }

    /**
     * Aplica locations do arquivo (§4.4) + upsert das linhas internas
     * 'menu_location' ('{stylesheet}:{location}') para observabilidade.
     *
     * @param list<string> $locations
     */
    private function applyLocations(int $menuId, array $locations): void
    {
        $map = get_theme_mod('nav_menu_locations', []);
        $map = is_array($map) ? $map : [];

        // Desatribui este menu de qualquer location anterior.
        foreach ($map as $location => $assigned) {
            if ((int) $assigned === $menuId) {
                unset($map[$location]);
            }
        }
        foreach ($locations as $location) {
            $map[$location] = $menuId;
        }
        set_theme_mod('nav_menu_locations', $map);

        foreach ($locations as $location) {
            $this->state->upsert(
                EntityRef::of('menu_location', get_stylesheet() . ':' . $location),
                ['status' => EntityStatus::Ok]
            );
        }
    }
}
