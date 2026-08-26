<?php
/**
 * AbstractPostAdapter — pipeline canônico compartilhado de todas as entidades
 * post-based (page, CPTs, wp_block, wp_template, wp_template_part,
 * wp_navigation, wp_global_styles; attachment estende via P4).
 *
 * Responsabilidades:
 *  - Leitura canônica (banco → forma do arquivo): frontmatter na keyOrder
 *    fixa + corpo byte-a-byte placeholderizado (PlaceholderCodec com o
 *    ReferenceResolver injetado) + meta da whitelist canonicalizado (§5.6) +
 *    termos identitários (§4.2.5) + meta providers (§3.3);
 *  - Identidade: UUID v4 espelhado em '_cvsync_uuid'; adoção de legado com
 *    scan ÚNICO de postmeta (§9.1) — P2 nunca lê postmeta; verificação de
 *    posse do UUID (§6.3) com detecção de rename × sequestro;
 *  - Escrita: decode de placeholders (estrutural não-resolvido → NÃO grava,
 *    §6.2) + wp_insert_post()/wp_update_post() gerando revision (§10.3);
 *  - Regras §3.2: auto-draft nunca é exportado nem recebe UUID; trash = false
 *    em exists() (deleção semântica, §5.5).
 *
 * @package CVSync\Adapters
 */

declare(strict_types=1);

namespace CVSync\Adapters;

use CVSync\ApplyResult;
use CVSync\Engine\CanonicalDocument;
use CVSync\Engine\Canonicalizer;
use CVSync\Engine\EntityRef;
use CVSync\Engine\Frontmatter\FrontmatterParser;
use CVSync\Engine\Placeholders\PlaceholderCodec;
use CVSync\ImportContext;
use CVSync\PathGuard;
use CVSync\Storage\EntityStatus;
use CVSync\Storage\StateStore;

defined('ABSPATH') || exit;

abstract class AbstractPostAdapter implements EntityAdapter
{
    /** Ordem fixa do frontmatter (fonte de verdade P3 — R4 r1-t2). */
    protected const FRONTMATTER_KEY_ORDER = [
        'uuid', 'post_type', 'slug', 'title', 'status',
        'parent', 'menu_order', 'template', 'terms', 'meta',
    ];

    /** Meta keys de referência a attachment cujo valor vira placeholder (§A.6). */
    protected const ATTACHMENT_META_KEYS = ['_thumbnail_id'];

    public function __construct(
        protected readonly StateStore $state,
        protected readonly ReferenceResolver $resolver,
        protected readonly PathGuard $paths,
    ) {
    }

    // ------------------------------------------------------------------
    // Identidade estática
    // ------------------------------------------------------------------

    public function kind(): string
    {
        return 'post';
    }

    public function keyOrder(): array
    {
        return static::FRONTMATTER_KEY_ORDER;
    }

    public function hasBlockBody(): bool
    {
        return true;
    }

    // ------------------------------------------------------------------
    // Existência e identidade
    // ------------------------------------------------------------------

    public function exists(EntityRef $ref): bool
    {
        $post = $this->resolvePost($ref);

        return $post instanceof \WP_Post && $post->post_status !== 'trash';
    }

    public function findByUuid(string $uuid): ?EntityRef
    {
        $ref = EntityRef::post($this->postType(), $uuid);

        // Hot path: state table (uq_entity) — nunca scan de postmeta aqui.
        $row = $this->state->get($ref);
        if ($row !== null && $row->dbId !== null) {
            return $ref;
        }

        // Adoção: scan ÚNICO de postmeta por '_cvsync_uuid' (§9.1).
        $found = get_posts([
            'post_type'      => $this->postType(),
            'post_status'    => $this->statuses(),
            'posts_per_page' => 1,
            'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- scan único de adoção por entidade (§9.1).
                ['key' => '_cvsync_uuid', 'value' => $uuid],
            ],
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        if ($found === []) {
            return null;
        }

        $this->state->upsert($ref, ['db_id' => (int) $found[0]]);

        return $ref;
    }

    public function findBySlug(string $slug): ?EntityRef
    {
        $postId = $this->resolver->postIdForSlug($this->postType(), $slug);
        if ($postId === null) {
            return null;
        }

        return EntityRef::post($this->postType(), $this->ensureUuid($postId));
    }

    /**
     * Meta existente prevalece (reassociação por slug mantém a identidade do
     * banco); sem meta e com $uuid do documento ⇒ adota-o (import path — o
     * export seguinte não pode mintar outro); sem ambos ⇒ mint local.
     */
    public function ensureUuid(int $dbId, ?string $uuid = null): string
    {
        $existing = get_post_meta($dbId, '_cvsync_uuid', true);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        // Import path: freshly applied post adopts the DOCUMENT uuid (identity
        // churn fix — files must not change uuid on the next export).
        if (is_string($uuid) && $uuid !== '' && wp_is_uuid($uuid)) {
            update_post_meta($dbId, '_cvsync_uuid', $uuid);
            return $uuid;
        }

        $uuid = wp_generate_uuid4();
        // Meta interno: fora da whitelist e dos hooks de dirty (§5.4).
        update_post_meta($dbId, '_cvsync_uuid', $uuid);

        return $uuid;
    }

    // ------------------------------------------------------------------
    // Leitura canônica (banco → arquivo)
    // ------------------------------------------------------------------

    public function readCanonical(EntityRef $ref): ?CanonicalDocument
    {
        $post = $this->resolvePost($ref);
        if (!$post instanceof \WP_Post) {
            return null;
        }
        // §3.2: auto-draft nunca é exportado (não tem slug estável); trash é
        // deleção semântica (§5.5) — o Exporter traduz para remoção de arquivo.
        if ($post->post_status === 'auto-draft' || $post->post_status === 'trash') {
            return null;
        }
        if (!in_array($post->post_status, $this->statuses(), true)) {
            return null;
        }

        $uuid = $this->ensureUuid((int) $post->ID);
        $ref = EntityRef::post($this->postType(), $uuid);

        $encoded = PlaceholderCodec::encode(
            $post->post_content,
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
            'status'     => $post->post_status,
            'parent'     => $this->canonicalParent($post),
            'menu_order' => (int) $post->menu_order,
        ];

        $template = get_post_meta((int) $post->ID, '_wp_page_template', true);
        if (is_string($template) && $template !== '' && $template !== 'default') {
            $frontmatter['template'] = $template;
        }

        $terms = $this->canonicalTerms((int) $post->ID);
        if ($terms !== []) {
            $frontmatter['terms'] = $terms;
        }

        $meta = $this->canonicalMeta((int) $post->ID);
        if ($meta !== []) {
            $frontmatter['meta'] = $meta;
        }

        return new CanonicalDocument($ref, $frontmatter, $encoded->content);
    }

    public function parseDocument(string $bytes): CanonicalDocument
    {
        [$frontmatter, $body] = FrontmatterParser::splitDocument($bytes);
        unset($frontmatter['hash']); // derivado — nunca entra no material (§4.2.3)

        $this->validateFrontmatter($frontmatter);

        $ref = EntityRef::post($this->postType(), (string) $frontmatter['uuid']);

        return new CanonicalDocument($ref, $frontmatter, $body);
    }

    /** Default §4.2: frontmatter (keyOrder) + corpo byte-a-byte + hash por último. */
    public function serializeDocument(CanonicalDocument $doc, string $hash): string
    {
        return \CVSync\Engine\Frontmatter\FrontmatterWriter::writeDocument(
            $doc->frontmatter,
            $this->keyOrder(),
            $doc->body,
            $hash
        );
    }

    public function validateFrontmatter(array $frontmatter): void
    {
        $uuid = $frontmatter['uuid'] ?? null;
        if (!is_string($uuid) || preg_match('/^[0-9a-fA-F-]{36}$/', $uuid) !== 1) {
            throw new RejectedEntityException('Frontmatter sem uuid válido.');
        }

        $slug = $frontmatter['slug'] ?? null;
        if (!is_string($slug) || preg_match('/^[a-z0-9][a-z0-9_\-]*$/', $slug) !== 1) {
            throw new RejectedEntityException(sprintf('Slug fora do padrão §6.4: "%s".', (string) $slug));
        }

        $postType = $frontmatter['post_type'] ?? null;
        if ($postType !== $this->postType()) {
            throw new RejectedEntityException(
                sprintf('post_type "%s" diverge do adapter "%s" (posse §6.3).', (string) $postType, $this->postType())
            );
        }

        $status = $frontmatter['status'] ?? 'publish';
        if (!in_array($status, $this->statuses(), true)) {
            throw new RejectedEntityException(
                sprintf('Status "%s" fora do mapa do post type "%s" (E3).', (string) $status, $this->postType())
            );
        }
    }

    // ------------------------------------------------------------------
    // Path
    // ------------------------------------------------------------------

    public function relativePath(CanonicalDocument $doc): string
    {
        return $this->baseDirectory() . '/' . $doc->slug() . $this->fileExtension();
    }

    public function locateFile(EntityRef $ref): ?string
    {
        foreach ($this->filesClaimingUuid($ref->key) as $path) {
            return $path; // ordenado; o primeiro (ou único) vence
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Escrita (arquivo → banco) — dentro de ImportGuard + withLockedRow
    // ------------------------------------------------------------------

    public function apply(CanonicalDocument $doc, ImportContext $ctx): ApplyResult
    {
        $decoded = PlaceholderCodec::decode(
            $doc->body,
            $this->resolver->importResolver(),
            home_url(),
            $this->resolver->termIdAttributes(),
        );

        // §6.2 estrutural: NÃO grava — o Importer marca pending_ref.
        if ($decoded->hasStructuralBlockers()) {
            return new ApplyResult(null, $decoded->unresolvedStructural, []);
        }

        // §6.3: posse do UUID — rename (um só arquivo com o uuid) × sequestro
        // (dois arquivos com o mesmo uuid → conflito, nunca apply).
        $claimants = $this->filesClaimingUuid($doc->uuid());
        if (count($claimants) > 1) {
            $post = $this->resolvePost($doc->ref);
            throw new UuidOwnershipMismatchException(
                sprintf('UUID %s reivindicado por %d arquivos: %s', $doc->uuid(), count($claimants), implode(', ', $claimants)),
                $doc->uuid(),
                $doc->slug(),
                $post instanceof \WP_Post ? $post->post_name : '(ausente)',
                $this->postType(),
                $post instanceof \WP_Post ? $post->post_type : '(ausente)',
            );
        }

        $post = $this->resolvePost($doc->ref);

        $postarr = [
            'post_type'    => $this->postType(),
            'post_name'    => $doc->slug(),
            'post_title'   => (string) ($doc->frontmatter['title'] ?? $doc->slug()),
            'post_content' => $decoded->content,
            'post_status'  => (string) ($doc->frontmatter['status'] ?? 'publish'),
            'menu_order'   => (int) ($doc->frontmatter['menu_order'] ?? 0),
            'post_parent'  => $this->resolveParentId($doc),
        ];

        if ($post instanceof \WP_Post) {
            $postarr['ID'] = $post->ID;
            $postId = wp_update_post(wp_slash($postarr), true);
        } else {
            $postId = wp_insert_post(wp_slash($postarr), true);
        }

        if (is_wp_error($postId)) {
            throw new AdapterException(
                sprintf('Falha ao gravar post (%s): %s', $doc->ref->toTupleString(), $postId->get_error_message())
            );
        }
        $postId = (int) $postId;

        $pendingTermRefs = $this->applyTerms($postId, $doc->terms()); // B.6.3
        $pendingMeta = $this->applyMeta($postId, $doc);
        // Import: the new/updated post adopts the DOCUMENT uuid (§6.3 identity).
        $this->ensureUuid($postId, $doc->uuid());
        $this->afterApply($postId, $doc);

        return new ApplyResult($postId, [], $decoded->unresolvedNonStructural, $pendingMeta, $pendingTermRefs);
    }

    public function delete(EntityRef $ref, bool $force = false): void
    {
        $post = $this->resolvePost($ref);
        if (!$post instanceof \WP_Post) {
            return;
        }

        if ($force) {
            wp_delete_post((int) $post->ID, true);
        } else {
            wp_trash_post((int) $post->ID);
        }
    }

    // ------------------------------------------------------------------
    // Hooks de extensão para subclasses
    // ------------------------------------------------------------------

    /** Pós-apply (ex.: invalidação de caches de global styles — §13.8). */
    protected function afterApply(int $postId, CanonicalDocument $doc): void
    {
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /**
     * Resolve o post da entidade: state (db_id) → adoção por uuid.
     * Valida o post_type da tupla (§6.3).
     */
    protected function resolvePost(EntityRef $ref): ?\WP_Post
    {
        $row = $this->state->get($ref);
        if ($row !== null && $row->dbId !== null) {
            $post = get_post($row->dbId);
            if ($post instanceof \WP_Post && $post->post_type === $this->postType()) {
                return $post;
            }
        }

        $adopted = $this->findByUuid($ref->key);
        if ($adopted === null) {
            return null;
        }

        $row = $this->state->get($adopted);
        if ($row === null || $row->dbId === null) {
            return null;
        }

        $post = get_post($row->dbId);

        return $post instanceof \WP_Post && $post->post_type === $this->postType() ? $post : null;
    }

    /** Parent canônico: SLUG do pai (nunca ID), null quando sem pai (§4.2). */
    protected function canonicalParent(\WP_Post $post): ?string
    {
        $parentId = (int) $post->post_parent;
        if ($parentId <= 0) {
            return null;
        }

        return $this->resolver->slugForPostId($parentId);
    }

    /** Parent por slug (metadado organizacional — não gera pending_ref). */
    protected function resolveParentId(CanonicalDocument $doc): int
    {
        $parentSlug = $doc->frontmatter['parent'] ?? null;
        if (!is_string($parentSlug) || $parentSlug === '') {
            return 0;
        }

        return $this->resolver->postIdForSlug($this->postType(), $parentSlug) ?? 0;
    }

    /**
     * Meta versionado: whitelist (§3.3) → canonicalização §5.6 → providers.
     *
     * @return array<string,mixed>
     */
    protected function canonicalMeta(int $postId): array
    {
        $meta = [];
        foreach ($this->metaWhitelist() as $key) {
            if (in_array($key, self::ATTACHMENT_META_KEYS, true)) {
                continue; // placeholderizado abaixo, nunca canonicalizado cru
            }
            $values = get_post_meta($postId, $key, false);
            if ($values === []) {
                continue;
            }
            $meta[$key] = Canonicalizer::canonicalizeMetaValues($values);
        }

        foreach (self::ATTACHMENT_META_KEYS as $key) {
            if (!in_array($key, $this->metaWhitelist(), true)) {
                continue;
            }
            $attachmentId = (int) get_post_meta($postId, $key, true);
            if ($attachmentId <= 0) {
                continue;
            }
            $slug = $this->resolver->slugForPostId($attachmentId);
            if ($slug !== null) {
                $meta[$key] = '{{attachment:' . $slug . '}}';
            }
            // Alvo ausente: meta simplesmente omitido (ausência visível, nunca ID).
        }

        /** Filtro §3.3: providers de meta fora da Meta API (Pods, ACF). */
        $providers = apply_filters('cvsync/meta_providers', []);
        foreach ((array) $providers as $provider) {
            if (!is_callable($provider)) {
                continue;
            }
            $extra = $provider($postId, $this->postType());
            if (is_array($extra)) {
                $meta = array_merge($meta, $extra);
            }
        }

        ksort($meta);

        return $meta;
    }

    /**
     * @return array<string,list<string>> taxonomia => slugs ordenados.
     */
    protected function canonicalTerms(int $postId): array
    {
        $terms = [];
        foreach ($this->identityTaxonomies() as $taxonomy) {
            $slugs = wp_get_post_terms($postId, $taxonomy, ['fields' => 'slugs']);
            if (is_wp_error($slugs) || $slugs === []) {
                continue;
            }
            sort($slugs);
            $terms[$taxonomy] = $slugs;
        }
        ksort($terms);

        return $terms;
    }

    /**
     * Aplica termos identitários (slugs) com o split do Apêndice B.6.3:
     *  - taxonomia NÃO-versionada: stub auto-criado pelo wp_set_object_terms (v1 inalterado);
     *  - taxonomia VERSIONADA + termo ausente no destino: OMITIDO do set +
     *    pendência qualificada '{taxonomy}:{slug}' (não-estrutural — a entidade
     *    deve chegar por si, no estágio 0; reprocessada quando o termo chegar).
     *
     * @param array<string,list<string>> $terms
     * @return list<string> Pendências term_refs[] qualificadas (B.6.3).
     */
    protected function applyTerms(int $postId, array $terms): array
    {
        $pendingTermRefs = [];
        $versioned = $this->versionedTaxonomies();

        foreach ($this->identityTaxonomies() as $taxonomy) {
            $slugs = $terms[$taxonomy] ?? [];
            $applicable = [];

            foreach ($slugs as $slug) {
                $slug = (string) $slug;
                if (in_array($taxonomy, $versioned, true) && term_exists($slug, $taxonomy) === null) {
                    $pendingTermRefs[] = $taxonomy . ':' . $slug; // B.6.3: omitir + term_refs[]
                    continue;
                }
                $applicable[] = $slug;
            }

            wp_set_object_terms($postId, $applicable, $taxonomy, false);
        }

        return $pendingTermRefs;
    }

    /**
     * Taxonomias versionadas (fonte: o MESMO filtro cvsync/taxonomies que o
     * registry lê — B.1.1; cache estático por request).
     *
     * @return list<string>
     */
    protected function versionedTaxonomies(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $extra = apply_filters('cvsync/taxonomies', []);
        $cache = [];
        foreach ((array) $extra as $key => $value) {
            $cache[] = is_int($key) ? (string) $value : (string) $key;
        }

        return $cache;
    }

    /**
     * Aplica meta da whitelist; placeholders {{attachment:slug}} são resolvidos
     * contra o banco local — não resolvidos são PULADOS e reportados (nunca
     * placeholder literal em coluna de meta, nunca ID de origem, §6).
     *
     * @return list<string> Slugs de meta pendente.
     */
    protected function applyMeta(int $postId, CanonicalDocument $doc): array
    {
        $pending = [];
        $meta = $doc->meta();

        foreach ($this->metaWhitelist() as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }
            $value = $meta[$key];

            if (in_array($key, self::ATTACHMENT_META_KEYS, true)) {
                if (is_string($value)
                    && preg_match('/^\{\{attachment:([^}]*)\}\}$/', $value, $m) === 1
                ) {
                    $resolved = $this->resolver->postIdForSlug('attachment', $m[1]);
                    if ($resolved === null) {
                        $pending[] = $m[1];
                        continue;
                    }
                    update_post_meta($postId, $key, $resolved);
                    continue;
                }
                // Valor numérico cru em meta de attachment = ID de origem → rejeita o valor.
                continue;
            }

            // Multi-valor: regravação canônica (delete + add na ordem ordenada).
            delete_post_meta($postId, $key);
            $values = is_array($value) && array_is_list($value) ? $value : [$value];
            foreach ($values as $single) {
                add_post_meta($postId, $key, $single);
            }
        }

        return $pending;
    }

    /**
     * Arquivos do diretório deste adapter cujo frontmatter declara o uuid.
     * Base da detecção rename × sequestro (§6.3) e do locateFile.
     *
     * @return list<string> Paths relativos, ordenados.
     */
    protected function filesClaimingUuid(string $uuid): array
    {
        $claimants = [];
        foreach ($this->paths->listFiles($this->baseDirectory()) as $relative) {
            if (!str_ends_with($relative, $this->fileExtension())) {
                continue;
            }
            $bytes = $this->paths->read($relative);
            if ($bytes === null) {
                continue;
            }
            try {
                [$frontmatter] = FrontmatterParser::splitDocument($bytes);
            } catch (\Throwable) {
                continue; // arquivo inválido não reivindica uuid; o lint/apply o rejeita
            }
            if (($frontmatter['uuid'] ?? null) === $uuid) {
                $claimants[] = $relative;
            }
        }
        sort($claimants);

        return $claimants;
    }
}
