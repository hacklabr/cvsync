<?php
/**
 * EntityAdapter — contrato único entre entidades versionáveis e o sync (P3).
 *
 * Consumido por Exporter (banco → arquivo), Importer (arquivo → banco) e pelo
 * orquestrador de estágios (§A.5.7 via AdapterRegistry). Implementações:
 * PostAdapter (page, CPTs, wp_block, wp_template, wp_template_part,
 * wp_navigation), GlobalStylesAdapter, MenuAdapter, BrandingAdapter (P3) e
 * AttachmentAdapter (P4, media/).
 *
 * Invariantes do contrato:
 *  - Nenhum método expõe IDs de ambiente na forma canônica: readCanonical()
 *    devolve a forma JÁ placeholderizada (o hash §5.1 incide sobre ela).
 *  - apply() NÃO abre transação nem guard: é chamado DENTRO de
 *    ImportGuard::run() + StateStore::withLockedRow() (orquestrados pelo
 *    Importer — r1-t2). Escrita exclusivamente via APIs públicas do WP,
 *    gerando revisions (§10.3) — nunca supressão de hooks.
 *  - A identidade é a tupla §6.3 (entity_kind, post_type, entity_key=uuid);
 *    db_id é estado de sincronização e vive no StateRecord (P2).
 *
 * @package CVSync\Adapters
 */

declare(strict_types=1);

namespace CVSync\Adapters;

use CVSync\ApplyResult;
use CVSync\Engine\CanonicalDocument;
use CVSync\Engine\EntityRef;
use CVSync\ImportContext;

defined('ABSPATH') || exit;

interface EntityAdapter
{
    // ------------------------------------------------------------------
    // Identidade estática do tipo (configuração — nunca hardcoded fora do registry)
    // ------------------------------------------------------------------

    /** 'post' | 'nav_menu' | 'menu_location' | 'branding'. */
    public function kind(): string;

    /** Post type quando kind='post'; null para os demais. */
    public function postType(): ?string;

    /**
     * Mapa post_type→statuses (errata E3/§A.2.3): conteúdo →
     * ['publish','draft','private']; attachment → ['inherit'] (P4).
     *
     * @return list<string>
     */
    public function statuses(): array;

    /** Diretório base relativo a content/ ('pages', 'templates/parts', 'menus', 'site'). */
    public function baseDirectory(): string;

    /** Extensão com o kind ('.page.html', '.menu.yml', '.global-styles.json'). */
    public function fileExtension(): string;

    /**
     * Whitelist de meta versionado (§3.3). Exclusões permanentes feitas pelo
     * chamador: '_cvsync_*' e '_edit_last'.
     *
     * @return list<string>
     */
    public function metaWhitelist(): array;

    /**
     * Taxonomias identitárias (§4.2.5): entram no payload canônico e no hash.
     *
     * @return list<string>
     */
    public function identityTaxonomies(): array;

    /**
     * Ordem fixa das chaves do frontmatter (fonte de verdade P3, R4 r1-t2) —
     * repassada ao Hasher/FrontmatterWriter a cada hash/escrita.
     *
     * @return list<string>
     */
    public function keyOrder(): array;

    // ------------------------------------------------------------------
    // Existência e identidade (§4.2.4, §6.3)
    // ------------------------------------------------------------------

    /** A entidade está viva no banco (trash conta como deletado, §5.5). */
    public function exists(EntityRef $ref): bool;

    /**
     * Localiza por UUID: state table (hot path) → scan ÚNICO de postmeta/termmeta
     * por '_cvsync_uuid' (adoção — §9.1) + upsert no StateStore. P2 nunca lê
     * postmeta: a resolução UUID↔objeto é deste pacote.
     */
    public function findByUuid(string $uuid): ?EntityRef;

    /** Fallback de adoção de legado por slug (§4.2.4), dentro do mapa de statuses. */
    public function findBySlug(string $slug): ?EntityRef;

    /**
     * Gera/persiste o UUID v4 (meta '_cvsync_uuid' — meta interno, excluído
     * dos hooks de dirty e da whitelist, §5.4). Entidades auto-draft NUNCA
     * recebem UUID (§3.2).
     */
    public function ensureUuid(int $dbId): string;

    // ------------------------------------------------------------------
    // Leitura canônica (banco → forma do arquivo)
    // ------------------------------------------------------------------

    /**
     * Forma canônica placeholderizada, pronta para Hasher/FrontmatterWriter.
     * null = entidade ausente ou não exportável agora (auto-draft, status fora
     * do mapa, template de origem não-custom, …).
     */
    public function readCanonical(EntityRef $ref): ?CanonicalDocument;

    /**
     * Interpreta o ARQUIVO (bytes) na forma canônica. O adapter conhece o
     * próprio formato: frontmatter+corpo (posts) × YAML integral (menus,
     * branding). Conteúdo permanece placeholderizado — a resolução contra o
     * banco local acontece em apply().
     *
     * @throws RejectedEntityException Documento malformado ou fora do schema.
     */
    public function parseDocument(string $bytes): CanonicalDocument;

    /**
     * Serialização do ARQUIVO (bytes finais gravados pelo Exporter). O adapter
     * é dono do formato (r6, gap G-P4-1): frontmatter+corpo com fences para
     * posts (default em AbstractPostAdapter, via FrontmatterWriter::writeDocument
     * com o hash como última linha); YAML integral com 'hash' como última chave
     * para menus/branding/sidecars. Invariante: a forma serializada DEVE
     * re-parsear (parseDocument) para o mesmo CanonicalDocument — a forma
     * gravada ≡ a forma hasheada.
     */
    public function serializeDocument(CanonicalDocument $doc, string $hash): string;

    /**
     * Schema do frontmatter (§10.2 — validação pré-insert): chaves obrigatórias,
     * tipos, post_type coerente com o arquivo, slug no formato §6.4.
     *
     * @param array<string,mixed> $frontmatter
     * @throws RejectedEntityException
     */
    public function validateFrontmatter(array $frontmatter): void;

    /**
     * O corpo do documento é markup de blocos (sujeito a parse_blocks
     * round-trip + anti-regressão §6.2) ou não (JSON/YAML).
     */
    public function hasBlockBody(): bool;

    // ------------------------------------------------------------------
    // Path do arquivo (slug-based; segmentos validados pelo PathGuard §6.4)
    // ------------------------------------------------------------------

    /** Path relativo a content/ para ESTA forma canônica (ex.: 'pages/sobre.page.html'). */
    public function relativePath(CanonicalDocument $doc): string;

    /**
     * Path relativo do arquivo ATUAL da entidade no repo, se existir — para
     * posts (nomeados por slug, chaveados por uuid) exige scan do diretório
     * casando o uuid do frontmatter; escala alvo torna o custo irrelevante
     * (§13.9). null = não encontrado.
     */
    public function locateFile(EntityRef $ref): ?string;

    // ------------------------------------------------------------------
    // Escrita (arquivo → banco) e deleção
    // ------------------------------------------------------------------

    /**
     * Aplica a forma canônica no banco via APIs públicas do WP (gera revision).
     * Deve: (1) decodificar placeholders via PlaceholderCodec com o resolver
     * injetado; (2) refs ESTRUTURAIS não resolvidas → retornar ApplyResult com
     * unresolvedStructural SEM gravar nada (§6.2); (3) não-estruturais → gravar
     * com o placeholder literal (inerte) e reportar em unresolvedNonStructural.
     *
     * Pré-condição: ImportGuard + withLockedRow ativos (orquestrados pelo Importer).
     */
    public function apply(CanonicalDocument $doc, ImportContext $ctx): ApplyResult;

    /**
     * Deleção repo→banco (§5.5): trash por default (wp_trash_post); $force só
     * via WP-CLI --force-delete. Attachments (P4) seguem a errata E5 (post
     * removido, bytes preservados).
     */
    public function delete(EntityRef $ref, bool $force = false): void;
}
