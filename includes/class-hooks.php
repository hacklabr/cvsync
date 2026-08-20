<?php
/**
 * Hooks — camada de detecção banco → arquivo (spec §8.1 + erratas E2/E3).
 *
 * REGRAS NORMATIVAS (auditáveis pelo code-reviewer):
 *  1. Guards de posts usam EXCLUSIVAMENTE wp_is_post_autosave() /
 *     wp_is_post_revision() (+ DOING_AUTOSAVE, WP_IMPORTING, CVSYNC_IMPORTING
 *     via ImportGuard). PROIBIDO filtrar por post_status cru (E3/§A.2.3 —
 *     'inherit' cru silenciaria todos os attachments);
 *  2. Meta: dirty só se a chave ∈ whitelist do adapter do post type;
 *     exclusões permanentes '_cvsync_*' e '_edit_last'/'_edit_lock' (§5.4);
 *  3. E2: o hook 'wp_update_attachment_metadata' NUNCA é registrado
 *     (regeneração de thumbnails não é edição);
 *  4. Escopo por MAPA post_type→statuses (AdapterRegistry::versionedStatuses),
 *     nunca lista global (E3);
 *  5. Escrita: dirty-mark na state table + gravação no 'shutdown' lendo o
 *     ESTADO FINAL do banco (debounce natural, §2.2.1);
 *  6. Attachments (add_attachment / attachment_updated / delete_attachment)
 *     são registrados pelo P4 (media) — integração declarada; este pacote
 *     cobre os post types de CONTEÚDO + menus + theme_mods/branding.
 *
 * Trash: save_post com status 'trash' marca dirty — o Exporter traduz para
 * remoção de arquivo + tombstone (deleção semântica §5.5). Auto-draft: nunca
 * marca (§3.2).
 *
 * @package CVSync
 */

declare(strict_types=1);

namespace CVSync;

use CVSync\Adapters\AdapterRegistry;
use CVSync\Engine\EntityRef;
use CVSync\Storage\EntityStatus;
use CVSync\Storage\StateStore;

defined('ABSPATH') || exit;

final class Hooks
{
    /** Meta interno excluído dos hooks de dirty (§5.4). */
    private const EXCLUDED_META = ['_edit_last', '_edit_lock'];

    public function __construct(
        private readonly AdapterRegistry $adapters,
        private readonly StateStore $state,
        private readonly Exporter $exporter,
        private readonly ImportGuard $guard,
    ) {
    }

    /**
     * Registra todos os hooks. Chamado pelo bootstrap (P6). Os hooks
     * save_post_{$type} são anexados no 'init' tardio para respeitar o
     * conjunto filtrado 'cvsync/post_types' e post types registrados por
     * terceiros.
     */
    public function register(): void
    {
        add_action('init', [$this, 'registerPostTypeHooks'], 1000);

        // Meta (§8.1: fecha o buraco "meta não toca post_modified").
        add_action('added_post_meta', [$this, 'onMetaChanged'], 10, 3);
        add_action('updated_post_meta', [$this, 'onMetaChanged'], 10, 3);
        add_action('deleted_post_meta', [$this, 'onMetaChanged'], 10, 3);

        // Família nav_menu (§8.1).
        add_action('wp_create_nav_menu', [$this, 'onNavMenuChanged'], 10, 1);
        add_action('wp_update_nav_menu', [$this, 'onNavMenuChanged'], 10, 1);
        add_action('delete_nav_menu', [$this, 'onNavMenuDeleted'], 10, 2);

        // Termos: nav_menu (itens) + taxonomias identitárias (§4.2.5).
        add_action('set_object_terms', [$this, 'onSetObjectTerms'], 10, 6);

        // Branding: custom_logo em theme_mods; site_icon em option (§A.6).
        add_action('update_option_theme_mods_' . get_stylesheet(), [$this, 'onThemeModsUpdated'], 10, 2);
        add_action('update_option_site_icon', [$this, 'onSiteIconChanged'], 10, 2);
        add_action('add_option_site_icon', [$this, 'onSiteIconAdded'], 10, 2);

        // Escrita: no shutdown, lendo o estado FINAL do banco.
        add_action('shutdown', [$this, 'onShutdown']);

        // E2: 'wp_update_attachment_metadata' NUNCA é registrado aqui nem em P4.
    }

    /** Anexa save_post_{$type} / before_delete_post do conjunto versionado. */
    public function registerPostTypeHooks(): void
    {
        foreach ($this->adapters->versionedPostTypes() as $postType) {
            if ($postType === 'attachment') {
                // 🟡1 (r7): attachments NUNCA entram no fluxo genérico — o ciclo
                // de vida de mídia é exclusivo do MediaHooks+fluxo dedicado do P4
                // (escopo referenced §A.5.5, setBinaryMeta, skipped-oversized).
                continue;
            }
            add_action('save_post_' . $postType, [$this, 'onSavePost'], 100, 3);
        }
        add_action('before_delete_post', [$this, 'onBeforeDeletePost'], 10, 2);
    }

    // ------------------------------------------------------------------
    // Callbacks
    // ------------------------------------------------------------------

    public function onSavePost(int $postId, \WP_Post $post, bool $update): void
    {
        if ($post->post_type === 'attachment') {
            return; // 🟡1: ciclo de vida de mídia é exclusivo do P4
        }
        if ($this->isSuppressed($postId)) {
            return;
        }
        if ($post->post_status === 'auto-draft') {
            return; // §3.2: nunca recebe UUID nem é exportado
        }
        $adapter = $this->adapters->forPostType($post->post_type);
        if ($adapter === null) {
            return;
        }
        // Mapa post_type→statuses (E3): trash passa (deleção semântica §5.5).
        if ($post->post_status !== 'trash' && !in_array($post->post_status, $adapter->statuses(), true)) {
            return;
        }

        $this->markPostDirty($post);
    }

    public function onBeforeDeletePost(int $postId, \WP_Post $post): void
    {
        if ($post->post_type === 'attachment') {
            return; // 🟡1: deleção de mídia é do MediaHooks (tombstone §A.7)
        }
        if ($this->guard->isImporting()) {
            return;
        }
        if ($this->adapters->forPostType($post->post_type) === null) {
            return;
        }

        $this->markPostDirty($post); // Exporter: arquivo removido + tombstone
    }

    /**
     * added/updated/deleted_post_meta — filtrados pela whitelist (§8.1).
     * Assinatura real: ($meta_id, $object_id, $meta_key).
     */
    public function onMetaChanged(mixed $metaId, int $objectId, string $metaKey): void
    {
        if ($this->guard->isImporting() || $this->isInternalMeta($metaKey)) {
            return;
        }
        $post = get_post($objectId);
        if (!$post instanceof \WP_Post || $this->isSuppressed((int) $post->ID)) {
            return;
        }
        if ($post->post_type === 'attachment') {
            return; // 🟡1: meta de attachment (alt/attached_file) é do MediaHooks
        }
        $adapter = $this->adapters->forPostType($post->post_type);
        if ($adapter === null || !in_array($metaKey, $adapter->metaWhitelist(), true)) {
            return;
        }

        $this->markPostDirty($post);
    }

    public function onNavMenuChanged(int $menuId): void
    {
        if ($this->guard->isImporting()) {
            return;
        }
        $menu = wp_get_nav_menu_object($menuId);
        if (!$menu instanceof \WP_Term) {
            return;
        }

        $this->state->markDirty(EntityRef::of('nav_menu', $menu->slug), EntityStatus::DirtyDb);
    }

    public function onNavMenuDeleted(mixed $term, mixed $ttId = null): void
    {
        if ($this->guard->isImporting()) {
            return;
        }
        $slug = $term instanceof \WP_Term ? $term->slug : null;
        if ($slug === null) {
            return;
        }

        // Menu deletado no admin: Exporter remove o arquivo + tombstone (§5.5).
        $this->state->markDirty(EntityRef::of('nav_menu', $slug), EntityStatus::DirtyDb);
    }

    public function onSetObjectTerms(int $objectId, array $terms, array $ttIds, string $taxonomy, bool $append, array $oldTtIds): void
    {
        if ($this->guard->isImporting()) {
            return;
        }

        if ($taxonomy === 'nav_menu') {
            foreach ($ttIds as $ttId) {
                $term = get_term_by('term_taxonomy_id', (int) $ttId, 'nav_menu');
                if ($term instanceof \WP_Term) {
                    $this->state->markDirty(EntityRef::of('nav_menu', $term->slug), EntityStatus::DirtyDb);
                }
            }
            return;
        }

        // Taxonomia identitária de um post versionado (§4.2.5: muda o hash).
        $post = get_post($objectId);
        if (!$post instanceof \WP_Post || $this->isSuppressed((int) $post->ID)) {
            return;
        }
        $adapter = $this->adapters->forPostType($post->post_type);
        if ($adapter !== null && in_array($taxonomy, $adapter->identityTaxonomies(), true)) {
            $this->markPostDirty($post);
        }
    }

    /**
     * theme_mods do tema ativo: custom_logo (branding §A.6) e
     * nav_menu_locations (menus cujo vínculo mudou).
     */
    public function onThemeModsUpdated(mixed $oldValue, mixed $newValue): void
    {
        if ($this->guard->isImporting()) {
            return;
        }
        $old = is_array($oldValue) ? $oldValue : [];
        $new = is_array($newValue) ? $newValue : [];

        if (($old['custom_logo'] ?? null) !== ($new['custom_logo'] ?? null)) {
            $this->state->markDirty(
                EntityRef::of('branding', get_stylesheet() . ':custom_logo'),
                EntityStatus::DirtyDb
            );
        }

        $oldLocations = (array) ($old['nav_menu_locations'] ?? []);
        $newLocations = (array) ($new['nav_menu_locations'] ?? []);
        if ($oldLocations !== $newLocations) {
            foreach (array_unique(array_merge(array_values($oldLocations), array_values($newLocations))) as $menuId) {
                $menu = wp_get_nav_menu_object((int) $menuId);
                if ($menu instanceof \WP_Term) {
                    $this->state->markDirty(EntityRef::of('nav_menu', $menu->slug), EntityStatus::DirtyDb);
                }
            }
        }
    }

    public function onSiteIconChanged(mixed $oldValue, mixed $newValue): void
    {
        if ($this->guard->isImporting() || (int) $oldValue === (int) $newValue) {
            return;
        }
        $this->state->markDirty(EntityRef::of('branding', 'core:site_icon'), EntityStatus::DirtyDb);
    }

    public function onSiteIconAdded(string $option, mixed $value): void
    {
        if ($this->guard->isImporting()) {
            return;
        }
        $this->state->markDirty(EntityRef::of('branding', 'core:site_icon'), EntityStatus::DirtyDb);
    }

    /**
     * Gravação no shutdown (§2.2.1): o Exporter lê o estado FINAL do banco —
     * coalesce natural de rajadas de meta; nunca roda durante um import.
     */
    public function onShutdown(): void
    {
        if ($this->guard->isImporting()) {
            return;
        }

        $this->exporter->exportDirty('save-hook');
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /**
     * Guards normativos (E3): EXCLUSIVAMENTE autosave/revision + flags de
     * contexto. Nenhum filtro por post_status cru.
     */
    private function isSuppressed(int $postId): bool
    {
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return true;
        }
        if (defined('DOING_AUTOSAVE') && constant('DOING_AUTOSAVE')) {
            return true;
        }
        if (defined('WP_IMPORTING') && constant('WP_IMPORTING')) {
            return true;
        }

        return $this->guard->isImporting();
    }

    private function isInternalMeta(string $metaKey): bool
    {
        return str_starts_with($metaKey, '_cvsync_') || in_array($metaKey, self::EXCLUDED_META, true);
    }

    /** Dirty-mark com adoção: UUID garantido + db_id + pré-filtro db_modified. */
    private function markPostDirty(\WP_Post $post): void
    {
        $adapter = $this->adapters->forPostType($post->post_type);
        if ($adapter === null) {
            return;
        }

        $uuid = $adapter->ensureUuid((int) $post->ID);
        $ref = EntityRef::post($post->post_type, $uuid);

        $this->state->upsert($ref, [
            'db_id'       => (int) $post->ID,
            'status'      => EntityStatus::DirtyDb,
            'db_modified' => new \DateTimeImmutable('now', wp_timezone()),
        ]);
    }
}
