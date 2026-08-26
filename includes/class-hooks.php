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

    /** @var array<int,array{uuid:string,key:string}> stash uuid/key entre pre_delete_term e delete_term (B.2.4). */
    private array $pendingTermDeletes = [];

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

        // Apêndice B.2.4 — termos versionados (taxonomias via cvsync/taxonomies).
        add_action('created_term', [$this, 'onTermChanged'], 10, 3);
        add_action('edited_term', [$this, 'onTermChanged'], 10, 3);
        add_action('pre_delete_term', [$this, 'onPreDeleteTerm'], 10, 2);
        add_action('delete_term', [$this, 'onDeleteTerm'], 10, 4);
        add_action('added_term_meta', [$this, 'onTermMetaChanged'], 10, 3);
        add_action('updated_term_meta', [$this, 'onTermMetaChanged'], 10, 3);
        add_action('deleted_term_meta', [$this, 'onTermMetaChanged'], 10, 3);
        // E2-bis: 'edited_term_taxonomies' NUNCA é registrado (cache de
        // descendentes); updates de count não disparam edited_term (SQL direto).

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

    // ------------------------------------------------------------------
    // Apêndice B.2.4 — termos versionados
    // ------------------------------------------------------------------

    /** created_term/edited_term → dirty_db (guards: ImportGuard OBRIGATÓRIO — o apply de posts auto-cria termos via wp_set_object_terms; taxonomy ∈ versionadas). Rename admin → rekey da linha (B.2.3). */
    public function onTermChanged(int $termId, int $ttId, string $taxonomy): void
    {
        if ($this->guard->isImporting() || !$this->isVersionedTaxonomy($taxonomy)) {
            return;
        }

        $term = get_term_by('term_taxonomy_id', $ttId, $taxonomy);
        if (!$term instanceof \WP_Term) {
            return;
        }

        // Rename vindo do ADMIN: linha existente tem a chave VELHA → rekey
        // (resolve por tt_id, imune a rename) ANTES de marcar dirty.
        $row = $this->termStateRowByTtId($ttId);
        if ($row !== null && $row->ref->key !== $taxonomy . ':' . $term->slug) {
            $adapter = $this->adapters->forTaxonomy($taxonomy);
            $adapter?->rekey($ttId, $term->slug);
        }

        $this->state->markDirty(
            \CVSync\Adapters\TermAdapter::refFor($taxonomy, $term->slug),
            EntityStatus::DirtyDb
        );
    }

    /**
     * Dirty-mark REVERSO (cláusula obrigatória B.2.4): pre_delete_term dispara
     * ANTES da remoção — relationships intactas. delete_term já as removeu
     * (query lá = falso negativo silencioso). Uma query indexada (tt_id),
     * bounded, filtrada a posts versionados.
     * Também captura o uuid (termmeta some com a deleção) para o delete_term.
     */
    public function onPreDeleteTerm(\WP_Term $term, string $taxonomy): void
    {
        if ($this->guard->isImporting() || !$this->isVersionedTaxonomy($taxonomy)) {
            return;
        }

        $this->pendingTermDeletes[(int) $term->term_taxonomy_id] = [
            'uuid' => (string) get_term_meta($term->term_id, '_cvsync_uuid', true),
            'key'  => $taxonomy . ':' . $term->slug,
        ];

        $objectIds = get_objects_in_term([$term->term_id], $taxonomy); // API pública, indexada
        if (is_wp_error($objectIds)) {
            return;
        }
        foreach ($objectIds as $postId) {
            $post = get_post((int) $postId);
            if ($post instanceof \WP_Post && in_array($post->post_type, $this->adapters->versionedPostTypes(), true)) {
                $this->markPostDirty($post); // shutdown re-exporta o post SEM o termo deletado
            }
        }
    }

    /**
     * delete_term (após deleção): marca dirty para o shutdown remover o
     * arquivo + tombstone (E5-bis — termos não têm trash). O uuid vem do
     * stash do pre_delete_term; sem uuid e sem linha de state, nada a fazer
     * (verify reporta untracked).
     */
    public function onDeleteTerm(int $termId, int $ttId, string $taxonomy, mixed $deletedTerm): void
    {
        if ($this->guard->isImporting() || !$this->isVersionedTaxonomy($taxonomy)) {
            return;
        }

        $stashed = $this->pendingTermDeletes[$ttId] ?? null;
        unset($this->pendingTermDeletes[$ttId]);

        $row = $this->termStateRowByTtId($ttId);
        if ($row !== null) {
            $this->state->markDirty($row->ref, EntityStatus::DirtyDb); // exportDirty: arquivo removido + tombstone
            return;
        }
        if ($stashed !== null && $stashed['uuid'] !== '') {
            // Linha ausente (nunca exportado): cria via markDirty para o shutdown
            // remover eventual arquivo órfão com esse uuid — caminho de consistência.
            $this->state->markDirty(EntityRef::of('term', $stashed['key']), EntityStatus::DirtyDb);
        }
    }

    /** Term meta da whitelist (B.2.4) — alt/thumbnail de termos. Assinatura: ($meta_id, $term_id, $meta_key). */
    public function onTermMetaChanged(mixed $metaId, int $termId, string $metaKey): void
    {
        if ($this->guard->isImporting() || str_starts_with($metaKey, '_cvsync_')) {
            return;
        }
        $term = get_term($termId);
        if (!$term instanceof \WP_Term) {
            return;
        }
        $adapter = $this->adapters->forTaxonomy($term->taxonomy);
        if ($adapter === null || !in_array($metaKey, $adapter->metaWhitelist(), true)) {
            return;
        }

        $ref = $this->termRefByTtId((int) $term->term_taxonomy_id, $term->taxonomy);
        if ($ref !== null) {
            $this->state->markDirty($ref, EntityStatus::DirtyDb);
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

    /** Taxonomia ∈ conjunto versionado (filtro cvsync/taxonomies, B.1.1). */
    private function isVersionedTaxonomy(string $taxonomy): bool
    {
        return in_array($taxonomy, $this->adapters->versionedTaxonomies(), true);
    }

    /**
     * EntityRef de termo por tt_id (imune a rename em trânsito): resolve o
     * termo pela instância term_taxonomy e compõe '{taxonomy}:{slug}'.
     */
    private function termRefByTtId(int $ttId, string $taxonomy): ?\CVSync\Engine\EntityRef
    {
        $term = get_term_by('term_taxonomy_id', $ttId, $taxonomy);
        if (!$term instanceof \WP_Term) {
            return null;
        }

        return \CVSync\Adapters\TermAdapter::refFor($taxonomy, $term->slug);
    }

    /**
     * Linha de state do termo por tt_id. O findByDbId do P2 é post-only
     * (kind='post' hardcoded) — match em PHP sobre all('term') (escala alvo:
     * centenas; uma vez por deleção/renome).
     */
    private function termStateRowByTtId(int $ttId): ?\CVSync\Storage\StateRecord
    {
        foreach ($this->state->all('term') as $record) {
            if ($record->dbId === $ttId) {
                return $record;
            }
        }

        return null;
    }

    /** Dirty-mark com adoção: UUID garantido + db_id + pré-filtro db_modified. */
    private function markPostDirty(\WP_Post $post): void
    {
        $adapter = $this->adapters->forPostType($post->post_type);
        if ($adapter === null) {
            return;
        }

        $uuid = $adapter->ensureUuid((int) $post->ID);
        if ($uuid === '') {
            // BUG 4 (dogfood): adapter sem portador de uuid (ex.: branding-like)
            // — identidade incompleta NUNCA fatala (EntityRef lança com key
            // vazia); skip com log, o verify reporta a entidade untracked.
            error_log(sprintf('[cvsync] dirty-mark: post %d (%s) sem uuid — ignorado.', $post->ID, $post->post_type));

            return;
        }
        $ref = EntityRef::post($post->post_type, $uuid);

        $this->state->upsert($ref, [
            'db_id'       => (int) $post->ID,
            'status'      => EntityStatus::DirtyDb,
            'db_modified' => new \DateTimeImmutable('now', wp_timezone()),
        ]);
    }
}
