<?php
/**
 * TermAdapter — termos de taxonomia como entidades versionadas de primeira
 * classe (Apêndice B, B.2–B.4).
 *
 *  - Identidade: entity_kind='term', post_type='', entity_key='{taxonomy}:{slug}'
 *    (split pela PRIMEIRA ocorrência de ':' — reversível porque taxonomy passa
 *    por sanitize_key e nunca contém ':'); precedente menu_location/branding;
 *  - db_id = term_taxonomy_id (1:1 com a entidade, globalmente único; term_id
 *    NUNCA persistido — recuperável via get_term_by('term_taxonomy_id'));
 *  - uuid v4 em termmeta '_cvsync_uuid' (precedente MenuAdapter): rename
 *    bidirecional; rekey() da linha de state no rename vindo do admin
 *    (assinatura do P2 conforme apêndice B.2.3, com fallback sinalizado);
 *  - readCanonical: payload SEM uuid/hash no material hasheado (taxonomy, slug,
 *    name, description, parent-slug, meta whitelist canonicalizada +
 *    thumbnail_id placeholderizado);
 *  - serializeDocument: YAML integral via writeBlockYaml (precedente menu);
 *  - apply: wp_update_term/wp_insert_term DENTRO de ImportGuard+withLockedRow
 *    (o Importer orquestra); anti-sequestro por uuid (>1 claimant →
 *    UuidOwnershipMismatchException); validação de ACICLICIDADE pré-insert
 *    (RejectedEntityException 'hierarchy cycle' — o core não valida ciclos);
 *    NUNCA toca wp_set_object_terms (associação vive no payload do post, B.6.1);
 *  - Adoção de legado: state (hot path) → scan ÚNICO de termmeta por
 *    '_cvsync_uuid' → fallback (taxonomy, slug).
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
use CVSync\Engine\Frontmatter\FrontmatterWriter;
use CVSync\ImportContext;
use CVSync\PathGuard;
use CVSync\Storage\StateStore;

defined('ABSPATH') || exit;

final class TermAdapter implements EntityAdapter
{
    /** Ordem canônica das chaves do documento (hash por último). */
    public const KEY_ORDER = ['uuid', 'taxonomy', 'slug', 'name', 'description', 'parent', 'meta', 'hash'];

    /** Subconjunto hasheado (uuid/hash NUNCA — B.3). */
    public const HASH_KEY_ORDER = ['taxonomy', 'slug', 'name', 'description', 'parent', 'meta'];

    /** Meta keys de referência a attachment (placeholderizadas — B.4). */
    private const ATTACHMENT_META_KEYS = ['thumbnail_id'];

    /** B1: uuid do último parseDocument (fora do material hasheado; consumido no apply). */
    private ?string $pendingUuid = null;

    public function __construct(
        private readonly StateStore $state,
        private readonly ReferenceResolver $resolver,
        private readonly PathGuard $paths,
        private readonly string $taxonomy,
        private readonly string $directory,
        private readonly array $metaWhitelist,
    ) {
        if (preg_match('/^[a-z0-9_.\-]+$/', $taxonomy) !== 1 || str_contains($taxonomy, ':')) {
            throw new AdapterException(sprintf('Taxonomia inválida (sanitize_key, sem ":"): "%s"', $taxonomy));
        }
    }

    // ------------------------------------------------------------------
    // Identidade estática
    // ------------------------------------------------------------------

    public function kind(): string
    {
        return 'term';
    }

    public function postType(): ?string
    {
        return null; // 🟡B5 (r-b-verify): contrato da interface — null para não-posts;
                     // impede o termo de cair na fila de parents de POSTS do Importer.
    }

    public function statuses(): array
    {
        return []; // terms não têm filtro de status (B.2.5) — existência = versionável
    }

    public function baseDirectory(): string
    {
        return 'terms/' . $this->directory;
    }

    public function fileExtension(): string
    {
        return '.term.yml';
    }

    public function metaWhitelist(): array
    {
        return $this->metaWhitelist;
    }

    public function identityTaxonomies(): array
    {
        return [];
    }

    /**
     * Ordem de hash do frontmatter canônico (o material §B.4: taxonomy, slug,
     * name, description, parent, meta — SEM uuid/hash). Passada ao Hasher/
     * FrontmatterWriter; [] só é seguro para documentos de frontmatter vazio
     * (menu/branding) — aqui o frontmatter É o documento.
     */
    public function keyOrder(): array
    {
        return self::HASH_KEY_ORDER;
    }

    public function hasBlockBody(): bool
    {
        return false;
    }

    /** Taxonomia atendida por ESTA instância (uma por taxonomia, como PostAdapter por post type). */
    public function taxonomy(): string
    {
        return $this->taxonomy;
    }

    /**
     * EntityRef para (taxonomy, slug) — chave composta '{taxonomy}:{slug}'
     * (split reversível pela primeira ocorrência de ':').
     */
    public static function refFor(string $taxonomy, string $slug): EntityRef
    {
        return EntityRef::of('term', $taxonomy . ':' . $slug);
    }

    /** Decompõe uma entity_key de termo: [taxonomy, slug] | null. */
    public static function splitKey(string $entityKey): ?array
    {
        $pos = strpos($entityKey, ':');
        if ($pos === false || $pos === 0 || $pos === strlen($entityKey) - 1) {
            return null;
        }

        return [substr($entityKey, 0, $pos), substr($entityKey, $pos + 1)];
    }

    // ------------------------------------------------------------------
    // Existência e identidade
    // ------------------------------------------------------------------

    public function exists(EntityRef $ref): bool
    {
        [$taxonomy, $slug] = self::splitKey($ref->key) ?? [$this->taxonomy, $ref->key];

        return get_term_by('slug', $slug, $taxonomy) instanceof \WP_Term;
    }

    public function findByUuid(string $uuid): ?EntityRef
    {
        // Hot path: state (uq_entity) — nunca scan de termmeta em rotina.
        $terms = get_terms([
            'taxonomy'   => $this->taxonomy,
            'hide_empty' => false,
            'number'     => 1,
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- scan ÚNICO de adoção por entidade (B.2.3).
                ['key' => '_cvsync_uuid', 'value' => $uuid],
            ],
        ]);

        if (is_wp_error($terms) || $terms === []) {
            return null;
        }

        return self::refFor($this->taxonomy, $terms[0]->slug);
    }

    public function findBySlug(string $slug): ?EntityRef
    {
        return $this->exists(self::refFor($this->taxonomy, $slug))
            ? self::refFor($this->taxonomy, $slug)
            : null;
    }

    /**
     * UUID em termmeta '_cvsync_uuid'. $dbId é o **term_taxonomy_id**
     * (convenção db_id do Apêndice B.2.1 — os callers falam tt_id; a API de
     * termmeta fala term_id e é resolvida aqui dentro). $uuid opcional:
     * re-adoção com o uuid do DOCUMENTO no import (contrato da interface —
     * mesmo papel de AbstractPostAdapter::ensureUuid). Meta interno: fora
     * da whitelist e dos hooks (§5.4).
     */
    public function ensureUuid(int $dbId, ?string $uuid = null): string
    {
        $termId = $this->termIdOfTt($dbId);
        if ($termId === null) {
            throw new AdapterException(sprintf('ensureUuid: tt_id %d não resolve termo na taxonomia %s.', $dbId, $this->taxonomy));
        }

        $existing = get_term_meta($termId, '_cvsync_uuid', true);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $uuid ??= wp_generate_uuid4();
        update_term_meta($termId, '_cvsync_uuid', $uuid);

        return $uuid;
    }

    /** tt_id → term_id (APIs de termmeta falam term_id; state fala tt_id). */
    private function termIdOfTt(int $ttId): ?int
    {
        $term = get_term_by('term_taxonomy_id', $ttId, $this->taxonomy);
        if ($term instanceof \WP_Term) {
            return (int) $term->term_id;
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Leitura canônica (banco → arquivo)
    // ------------------------------------------------------------------

    public function readCanonical(EntityRef $ref): ?CanonicalDocument
    {
        [$taxonomy, $slug] = self::splitKey($ref->key) ?? [$this->taxonomy, $ref->key];
        $term = get_term_by('slug', $slug, $taxonomy);
        if (!$term instanceof \WP_Term) {
            return null;
        }

        $uuid = $this->ensureUuid((int) $term->term_taxonomy_id);
        $this->pendingUuid = $uuid; // B1: stash para serializeDocument (uuid fora do frontmatter)

        $frontmatter = [
            'taxonomy'    => $taxonomy,
            'slug'        => $term->slug,
            'name'        => $term->name,
            'description' => (string) $term->description,
            'parent'      => $this->canonicalParent($term),
        ];

        $meta = $this->canonicalMeta((int) $term->term_id);
        if ($meta !== []) {
            $frontmatter['meta'] = $meta;
        }

        return new CanonicalDocument($ref, $frontmatter, '');
    }

    public function parseDocument(string $bytes): CanonicalDocument
    {
        $data = FrontmatterParser::parse($bytes);
        unset($data['hash']); // derivado — nunca no material (B.3)

        $this->validateFrontmatter($data);

        // B1 (r-b-verify): uuid NUNCA entra no frontmatter do documento — o
        // material hasheado é HASH_KEY_ORDER (sem uuid/hash; B.4) e o writer
        // é estrito (chave fora da ordem → InvalidArgumentException). O uuid
        // viaja no stash de instância (precedente pendingSidecar do
        // AttachmentAdapter, 🔵10/r7) e é consumido no apply() para
        // anti-sequestro/re-adoção.
        $uuid = (string) ($data['uuid'] ?? '');
        unset($data['uuid']);
        if ($uuid !== '') {
            $this->pendingUuid = $uuid;
        }

        $ref = self::refFor((string) $data['taxonomy'], (string) $data['slug']);

        return new CanonicalDocument($ref, $data, '');
    }

    public function validateFrontmatter(array $frontmatter): void
    {
        $taxonomy = $frontmatter['taxonomy'] ?? null;
        if (!is_string($taxonomy)
            || $taxonomy !== $this->taxonomy
            || preg_match('/^[a-z0-9_.\-]+$/', $taxonomy) !== 1
            || str_contains($taxonomy, ':')
        ) {
            throw new RejectedEntityException(
                sprintf('Termo com taxonomy divergente do adapter ("%s" × "%s").', (string) $taxonomy, $this->taxonomy)
            );
        }

        $slug = $frontmatter['slug'] ?? null;
        if (!is_string($slug) || preg_match('/^[a-z0-9][a-z0-9_\-]*$/', $slug) !== 1) {
            throw new RejectedEntityException(sprintf('Slug de termo fora do padrão §6.4: "%s".', (string) $slug));
        }

        if (!is_string($frontmatter['name'] ?? null)) {
            throw new RejectedEntityException('Termo sem name.');
        }

        $parent = $frontmatter['parent'] ?? null;
        if ($parent !== null && (!is_string($parent) || preg_match('/^[a-z0-9][a-z0-9_\-]*$/', $parent) !== 1)) {
            throw new RejectedEntityException('parent de termo deve ser slug da MESMA taxonomy ou null (B.4).');
        }
    }

    /**
     * YAML integral (B.3): uuid + documento canônico + 'hash' por último.
     * B1: o uuid não vive no frontmatter do doc — vem do stash preenchido pelo
     * readCanonical() imediatamente anterior (fluxo do Exporter; mesmo padrão
     * do AttachmentAdapter::serializeDocument × pendingSidecar).
     */
    public function serializeDocument(CanonicalDocument $doc, string $hash): string
    {
        if ($this->pendingUuid === null || $this->pendingUuid === '') {
            throw new AdapterException(
                'TermAdapter::serializeDocument sem uuid em stash (readCanonical/parseDocument prévio obrigatório).'
            );
        }

        $fields = ['uuid' => $this->pendingUuid];
        foreach (self::HASH_KEY_ORDER as $key) {
            $fields[$key] = $doc->frontmatter[$key] ?? null;
        }
        if (($fields['meta'] ?? null) === null || $fields['meta'] === []) {
            unset($fields['meta']); // meta vazio → omitido (forma canônica estável)
        }
        $fields['hash'] = $hash;

        return FrontmatterWriter::writeBlockYaml($fields);
    }

    // ------------------------------------------------------------------
    // Path
    // ------------------------------------------------------------------

    public function relativePath(CanonicalDocument $doc): string
    {
        return $this->baseDirectory() . '/' . $doc->slug() . $this->fileExtension();
    }

    /**
     * Path do arquivo ATUAL da entidade. B3 (r-b-verify): no rename admin o
     * rekey muda a chave ANTES do export — um path derivado só da chave nunca
     * encontraria o arquivo do slug velho (órfão → dois claimants →
     * UuidOwnershipMismatchException no apply seguinte, violando B.8.4).
     *
     * Estratégia determinística: escaneia por uuid e PREFERE o claimant
     * divergente do path canônico atual (o arquivo do slug VELHO — é ele que
     * o Exporter precisa remover na mesma operação do rename); sem divergente,
     * o path canônico (caso normal). Precedente:
     * AbstractPostAdapter::locateFile via filesClaimingUuid.
     */
    public function locateFile(EntityRef $ref): ?string
    {
        [$taxonomy, $slug] = self::splitKey($ref->key) ?? [$this->taxonomy, $ref->key];
        $current = $this->baseDirectory() . '/' . $slug . $this->fileExtension();

        // uuid da entidade: stash do parseDocument (fluxo de import) ou
        // termmeta (fluxo de export — o termo vivo no banco).
        $uuid = $this->pendingUuid;
        if ($uuid === null || $uuid === '') {
            $term = get_term_by('slug', $slug, $taxonomy);
            if ($term instanceof \WP_Term) {
                $uuid = (string) get_term_meta((int) $term->term_id, '_cvsync_uuid', true);
            }
        }

        if ($uuid !== null && $uuid !== '') {
            foreach ($this->filesClaimingUuid($uuid) as $claimant) {
                if ($claimant !== $current) {
                    return $claimant; // rename em trânsito: arquivo do slug VELHO
                }
            }
        }

        return $this->paths->exists($current) ? $current : null;
    }

    // ------------------------------------------------------------------
    // Escrita (arquivo → banco) — dentro de ImportGuard + withLockedRow
    // ------------------------------------------------------------------

    public function apply(CanonicalDocument $doc, ImportContext $ctx): ApplyResult
    {
        $taxonomy = (string) $doc->frontmatter['taxonomy'];
        $slug = $doc->slug();
        // B1: uuid vem do stash do parseDocument (não do frontmatter).
        $uuid = $this->pendingUuid ?? '';
        if ($uuid === '') {
            throw new RejectedEntityException('Termo sem uuid no documento (B.3).');
        }

        // §6.3 anti-sequestro (B.2.3): >1 arquivo com o mesmo uuid nesta taxonomy.
        $claimants = $this->filesClaimingUuid($uuid);
        if (count($claimants) > 1) {
            $term = get_term_by('slug', $slug, $taxonomy);
            throw new UuidOwnershipMismatchException(
                sprintf('UUID %s reivindicado por %d arquivos: %s', $uuid, count($claimants), implode(', ', $claimants)),
                $uuid,
                $slug,
                $term instanceof \WP_Term ? $term->slug : '(ausente)',
                'term:' . $taxonomy,
                $term instanceof \WP_Term ? 'term:' . $taxonomy : '(ausente)',
            );
        }

        // B.6.4 — aciclicidade pré-insert (o core NÃO valida ciclos).
        $this->assertAcyclicParent($taxonomy, $slug, $doc->frontmatter['parent'] ?? null);

        $parentId = $this->resolveParentId($taxonomy, $doc->frontmatter['parent'] ?? null);

        $args = [
            'slug'        => $slug,
            'name'        => (string) $doc->frontmatter['name'],
            'description' => (string) ($doc->frontmatter['description'] ?? ''),
            'parent'      => $parentId,
        ];

        // Resolve a entidade: state (hot path) → uuid (rename repo-side) → slug.
        $existing = $this->resolveTerm($doc, $uuid);
        if ($existing instanceof \WP_Term) {
            // Rename vindo do repo (mesmo uuid): atualiza o slug, associations intactas.
            $result = wp_update_term((int) $existing->term_id, $taxonomy, $args);
        } else {
            $result = wp_insert_term($args['name'], $taxonomy, $args);
        }

        if (is_wp_error($result)) {
            throw new AdapterException(
                sprintf('Falha ao gravar termo %s:%s: %s', $taxonomy, $slug, $result->get_error_message())
            );
        }
        $termId = (int) ($result['term_id'] ?? 0);

        // db_id = term_taxonomy_id (B.2.1) — derivado ANTES do primeiro uso
        // (B2: undefined var sob strict_types) e adotando o uuid do documento
        // (mesma semântica de re-adoção dos posts).
        $ttId = $this->ttIdOf($termId, $taxonomy);
        $this->ensureUuid($ttId, $uuid);
        $pendingMeta = $this->applyMeta($termId, $doc);

        return new ApplyResult($ttId, [], [], $pendingMeta);
    }

    public function delete(EntityRef $ref, bool $force = false): void
    {
        [$taxonomy, $slug] = self::splitKey($ref->key) ?? [$this->taxonomy, $ref->key];
        $term = get_term_by('slug', $slug, $taxonomy);
        if (!$term instanceof \WP_Term) {
            return;
        }

        // E5-bis: permanente (sem trash). Preservação = git + conflicts (B.2.2).
        wp_delete_term((int) $term->term_id, $taxonomy);
    }

    /**
     * Rekey do rename vindo do ADMIN (B.2.3): hook resolve por tt_id (imune a
     * rename) → linha existente tem entity_key velho → atualiza a chave.
     * StateStore::rekey() entrega a semântica normativa (tx via withLockedRow,
     * colisão de uq_entity checada, kind/postType invariantes).
     */
    public function rekey(int $ttId, string $newSlug): void
    {
        $term = get_term_by('term_taxonomy_id', $ttId, $this->taxonomy);
        if (!$term instanceof \WP_Term) {
            return;
        }
        $oldRef = self::refFor($this->taxonomy, $term->slug);
        $newRef = self::refFor($this->taxonomy, $newSlug);
        if ($oldRef->equals($newRef)) {
            return;
        }

        $this->state->rekey($oldRef, $newRef);
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /** Termo correspondente ao doc: state (db_id=tt_id) → uuid → slug. */
    /** @param string $uuid uuid do documento (stash do parseDocument — B1). */
    private function resolveTerm(CanonicalDocument $doc, string $uuid): ?\WP_Term
    {
        $row = $this->state->get($doc->ref);
        if ($row !== null && $row->dbId !== null) {
            $term = get_term_by('term_taxonomy_id', $row->dbId, $this->taxonomy);
            if ($term instanceof \WP_Term) {
                return $term;
            }
        }

        // Rename repo-side: mesmo uuid em arquivo com slug novo.
        $found = get_terms([
            'taxonomy'   => $this->taxonomy,
            'hide_empty' => false,
            'number'     => 1,
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- lookup pontual por uuid na adoção/rename.
                ['key' => '_cvsync_uuid', 'value' => $uuid],
            ],
        ]);
        if (!is_wp_error($found) && $found !== []) {
            return $found[0];
        }

        $term = get_term_by('slug', $doc->slug(), $this->taxonomy);

        return $term instanceof \WP_Term ? $term : null;
    }

    private function canonicalParent(\WP_Term $term): ?string
    {
        if ((int) $term->parent <= 0) {
            return null;
        }
        $parent = get_term((int) $term->parent, $term->taxonomy);

        return $parent instanceof \WP_Term ? $parent->slug : null;
    }

    /**
     * Meta da whitelist, canonicalizado (§5.6); thumbnail_id → {{attachment:slug}}.
     *
     * @return array<string,mixed>
     */
    private function canonicalMeta(int $termId): array
    {
        $meta = [];
        foreach ($this->metaWhitelist as $key) {
            if (in_array($key, self::ATTACHMENT_META_KEYS, true)) {
                $attachmentId = (int) get_term_meta($termId, $key, true);
                if ($attachmentId <= 0) {
                    continue;
                }
                $slug = $this->resolver->slugForPostId($attachmentId);
                if ($slug !== null) {
                    $meta[$key] = '{{attachment:' . $slug . '}}';
                }
                continue;
            }
            $values = get_term_meta($termId, $key, false);
            if ($values === []) {
                continue;
            }
            $meta[$key] = Canonicalizer::canonicalizeMetaValues($values);
        }
        ksort($meta);

        return $meta;
    }

    /**
     * Aplica meta; placeholders de attachment resolvidos localmente — não
     * resolvidos → pulados + pendência (nunca ID de origem, §6).
     *
     * @return list<string>
     */
    private function applyMeta(int $termId, CanonicalDocument $doc): array
    {
        $pending = [];
        $meta = $doc->frontmatter['meta'] ?? [];
        if (!is_array($meta)) {
            return $pending;
        }

        foreach ($this->metaWhitelist as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }
            $value = $meta[$key];

            if (in_array($key, self::ATTACHMENT_META_KEYS, true)) {
                if (is_string($value) && preg_match('/^\{\{attachment:([^}]*)\}\}$/', $value, $m) === 1) {
                    $resolved = $this->resolver->postIdForSlug('attachment', $m[1]);
                    if ($resolved === null) {
                        $pending[] = $m[1];
                        continue;
                    }
                    update_term_meta($termId, $key, $resolved);
                }
                continue; // valor cru não-attachment em thumbnail_id → descartado (nunca ID de origem)
            }

            delete_term_meta($termId, $key);
            $values = is_array($value) && array_is_list($value) ? $value : [$value];
            foreach ($values as $single) {
                add_term_meta($termId, $key, $single);
            }
        }

        return $pending;
    }

    /**
     * Aciclicidade (B.6.4): caminha ancestrais a partir do parent proposto;
     * se alcança o próprio slug → ciclo → rejeição. Máx. profundidade = altura
     * da taxonomy (bounded pela escala alvo; guard de 100 níveis por sanidade).
     */
    private function assertAcyclicParent(string $taxonomy, string $slug, ?string $parentSlug): void
    {
        if ($parentSlug === null || $parentSlug === '') {
            return;
        }
        if ($parentSlug === $slug) {
            throw new RejectedEntityException('hierarchy cycle: termo é pai de si mesmo.');
        }

        $visited = [$slug => true];
        $current = $parentSlug;
        $depth = 0;

        while ($current !== null && $depth < 100) {
            if (isset($visited[$current])) {
                throw new RejectedEntityException(
                    sprintf('hierarchy cycle detectado via ancestor "%s" (B.6.4).', $current)
                );
            }
            $visited[$current] = true;

            $term = get_term_by('slug', $current, $taxonomy);
            if (!$term instanceof \WP_Term || (int) $term->parent <= 0) {
                return;
            }
            $parent = get_term((int) $term->parent, $taxonomy);
            $current = $parent instanceof \WP_Term ? $parent->slug : null;
            $depth++;
        }
    }

    private function resolveParentId(string $taxonomy, ?string $parentSlug): int
    {
        if ($parentSlug === null || $parentSlug === '') {
            return 0;
        }
        $term = get_term_by('slug', $parentSlug, $taxonomy);

        return $term instanceof \WP_Term ? (int) $term->term_id : 0; // ausente → 0 + fixup de fim de lote (B.6.4)
    }

    private function ttIdOf(int $termId, string $taxonomy): int
    {
        $term = get_term($termId, $taxonomy);
        if ($term instanceof \WP_Term) {
            $ttId = (int) $term->term_taxonomy_id; // exposto pelo core em WP_Term
            if ($ttId > 0) {
                return $ttId;
            }
        }

        // Fallback: lookup direto pela tabela (term_taxonomy é do core).
        $tt = term_exists($termId, $taxonomy);
        if (is_array($tt) && isset($tt['term_taxonomy_id'])) {
            return (int) $tt['term_taxonomy_id'];
        }

        return $termId; // inalcançável na prática; nunca usado como identidade antes de validado
    }

    /**
     * Arquivos desta taxonomy cujo uuid casa (anti-sequestro §6.3).
     *
     * @return list<string>
     */
    private function filesClaimingUuid(string $uuid): array
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
                $data = FrontmatterParser::parse($bytes);
            } catch (\Throwable) {
                continue; // inválido não reivindica uuid; lint/apply o rejeita
            }
            if (($data['uuid'] ?? null) === $uuid && ($data['taxonomy'] ?? null) === $this->taxonomy) {
                $claimants[] = $relative;
            }
        }
        sort($claimants);

        return $claimants;
    }
}
