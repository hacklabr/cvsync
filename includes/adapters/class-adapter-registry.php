<?php
/**
 * AdapterRegistry — configuração de post types versionados e ordem de
 * estágios do apply (§A.5.7).
 *
 * O conjunto versionado NUNCA é hardcoded (§3.2): defaults do core + filtro
 * 'cvsync/post_types'. Whitelist de meta por post type via filtro
 * 'cvsync/meta_whitelist' (§3.3). P4 registra o AttachmentAdapter no estágio 0
 * via register() (integração declarada — attachment não é construído aqui).
 *
 * Pré-condição dura (§3.2): todo post type versionado precisa de
 * post_type_supports(revisions) — assertOperable() lança na ativação/verify;
 * 'attachment' é a exceção declarada da errata E4.
 *
 * @package CVSync\Adapters
 */

declare(strict_types=1);

namespace CVSync\Adapters;

use CVSync\Engine\EntityRef;
use CVSync\PathGuard;
use CVSync\Storage\StateStore;

defined('ABSPATH') || exit;

final class AdapterRegistry
{
    /** @var array<string,EntityAdapter> post type => adapter */
    private array $byPostType = [];

    /** @var array<string,EntityAdapter> kind => adapter (kinds não-post) */
    private array $byKind = [];

    /** @var array<string,TermAdapter> taxonomy => adapter (Apêndice B.2.1) */
    private array $byTaxonomy = [];

    /**
     * 🟡B4 (r-b-verify): dir default sanitizado — sanitize_key permite '_' e
     * '.', que o SLUG_PATTERN do PathGuard rejeita em segmento de diretório
     * ('projeto_tag' → 'projeto_tags' quebraria todo export/import). Normaliza
     * para o alfabeto de path: underscore/ponto → hífen (precedente: menus/
     * attachments derivam de slugs já higienizados).
     */
    private static function defaultDirectoryFor(string $taxonomy): string
    {
        return str_replace(['_', '.'], '-', $taxonomy) . 's';
    }

    /** @var array<int,list<EntityAdapter>> estágio => adapters (§A.5.7 + B.6.2) */
    private array $stages = [];

    /** Apêndice B.1.2 — deny-list normativa: já têm dono ou são estruturais. */
    public const DENIED_TAXONOMIES = [
        'nav_menu', 'wp_theme', 'wp_pattern_category', 'wp_template_part_area', 'link_category', 'post_format',
    ];

    public function __construct(
        private readonly StateStore $state,
        private readonly ReferenceResolver $resolver,
        private readonly PathGuard $paths,
    ) {
    }

    /**
     * Constrói o registry com todos os adapters do P3 (posts, global styles,
     * menus, branding), filtros aplicados.
     */
    public static function withDefaults(StateStore $state, ReferenceResolver $resolver, PathGuard $paths): self
    {
        $registry = new self($state, $resolver, $paths);
        $registry->registerDefaults();

        return $registry;
    }

    /**
     * Configuração dos post types versionados (defaults + 'cvsync/post_types').
     *
     * Formato: [ 'post-type' => ['dir' => ..., 'ext' => ..., 'stage' => ...,
     * 'statuses' => [...], 'meta' => [...], 'identity_taxonomies' => [...]] ]
     * ou item numérico 'post-type' (dir/ext derivados do nome).
     *
     * @return array<string,array<string,mixed>>
     */
    public function postTypeConfig(): array
    {
        $defaults = [
            'page' => [
                'dir' => 'pages', 'ext' => '.page.html', 'stage' => 3,
                'meta' => ['_wp_page_template', '_thumbnail_id'],
            ],
            'wp_block' => [
                'dir' => 'patterns', 'ext' => '.pattern.html', 'stage' => 1,
                'identity_taxonomies' => ['wp_pattern_category'],
            ],
            'wp_template' => [
                'dir' => 'templates', 'ext' => '.template.html', 'stage' => 2,
                'identity_taxonomies' => ['wp_theme'],
            ],
            'wp_template_part' => [
                'dir' => 'templates/parts', 'ext' => '.template-part.html', 'stage' => 2,
                'identity_taxonomies' => ['wp_theme', 'wp_template_part_area'],
            ],
            'wp_navigation' => [
                'dir' => 'navigation', 'ext' => '.navigation.html', 'stage' => 1,
            ],
        ];

        /** Filtro §3.2: CPTs adicionais entram no pipeline canônico de posts. */
        $extra = apply_filters('cvsync/post_types', []);

        $config = $defaults;
        foreach ((array) $extra as $key => $value) {
            if (is_int($key)) {
                $postType = (string) $value;
                $config[$postType] = [
                    'dir' => $postType . 's', 'ext' => '.' . $postType . '.html', 'stage' => 3,
                    'meta' => ['_thumbnail_id'],
                ];
            } else {
                $config[(string) $key] = array_merge(
                    [
                        'dir' => $key . 's', 'ext' => '.' . $key . '.html', 'stage' => 3,
                        'meta' => ['_thumbnail_id'],
                    ],
                    (array) $value
                );
            }
        }

        return $config;
    }

    private function registerDefaults(): void
    {
        foreach ($this->postTypeConfig() as $postType => $config) {
            $whitelist = apply_filters(
                'cvsync/meta_whitelist',
                (array) ($config['meta'] ?? []),
                $postType
            );

            $adapter = new PostAdapter(
                $this->state,
                $this->resolver,
                $this->paths,
                $postType,
                (string) $config['dir'],
                (string) $config['ext'],
                (array) ($config['statuses'] ?? ['publish', 'draft', 'private']),
                (array) $whitelist,
                (array) ($config['identity_taxonomies'] ?? [])
            );
            $this->register($adapter, (int) $config['stage']);
        }

        // Apêndice B.1.1: taxonomias versionadas — filtro default VAZIO,
        // estágio 0 (ordem interna attachments→termos garantida em byStage()).
        foreach ($this->taxonomyConfig() as $taxonomy => $config) {
            $whitelist = apply_filters(
                'cvsync/term_meta_whitelist',
                (array) ($config['meta'] ?? ['thumbnail_id']),
                $taxonomy
            );

            $this->register(
                new TermAdapter(
                    $this->state,
                    $this->resolver,
                    $this->paths,
                    $taxonomy,
                    (string) ($config['dir'] ?? self::defaultDirectoryFor($taxonomy)),
                    array_values($whitelist)
                ),
                0
            );
        }

        $this->register(new GlobalStylesAdapter($this->state, $this->resolver, $this->paths), 2);
        $this->register(new MenuAdapter($this->state, $this->resolver, $this->paths), 4);
        $this->register(new BrandingAdapter($this->state, $this->resolver, $this->paths), 5);
    }

    /**
     * Apêndice B.1.1: configuração das taxonomias versionadas — filtro
     * 'cvsync/taxonomies', DEFAULT VAZIO (opt-in; o filtro adiciona, nunca
     * remove garantias da v1). Item de valor simples → defaults derivados
     * (dir `{taxonomy}s`, meta ['thumbnail_id']); associativo sobrescreve.
     *
     * @return array<string,array<string,mixed>>
     */
    public function taxonomyConfig(): array
    {
        $extra = apply_filters('cvsync/taxonomies', []);

        $config = [];
        foreach ((array) $extra as $key => $value) {
            if (is_int($key)) {
                $taxonomy = (string) $value;
                $config[$taxonomy] = ['dir' => self::defaultDirectoryFor($taxonomy), 'meta' => ['thumbnail_id']];
            } else {
                $config[(string) $key] = array_merge(
                    ['dir' => self::defaultDirectoryFor($key), 'meta' => ['thumbnail_id']],
                    (array) $value
                );
            }
        }

        return $config;
    }

    /** Registro explícito (P4 registra o AttachmentAdapter no estágio 0). */
    public function register(EntityAdapter $adapter, int $stage): void
    {
        $postType = $adapter->postType();
        if ($postType !== null && $postType !== '') {
            $this->byPostType[$postType] = $adapter;
        } elseif ($adapter instanceof TermAdapter) {
            $this->byTaxonomy[$adapter->taxonomy()] = $adapter; // B.2.1: dispatch por taxonomia
        } else {
            $this->byKind[$adapter->kind()] = $adapter;
        }
        $this->stages[$stage][] = $adapter;
    }

    /** Adapter da taxonomia versionada (B.1.1). */
    public function forTaxonomy(string $taxonomy): ?TermAdapter
    {
        return $this->byTaxonomy[$taxonomy] ?? null;
    }

    /**
     * Adapter do post type versionado — lookup direto pelo post_type (sem
     * EntityRef: vários call sites têm só o post_type em mãos).
     * Wrapper fino sobre o MESMO mapa byPostType do forRef(kind='post'):
     * `forPostType('page') === forRef(EntityRef::post('page', '<qualquer-key>'))`
     * para qualquer key. null = tipo não versionado (semântica pré-Apêndice B,
     * restaurada após remoção acidental na refatoração do dispatch por kind).
     */
    public function forPostType(string $postType): ?EntityAdapter
    {
        return $this->byPostType[$postType] ?? null;
    }

    /** @return list<string> Taxonomias versionadas (hooks de termos, B.2.4). */
    public function versionedTaxonomies(): array
    {
        return array_keys($this->byTaxonomy);
    }

    public function forRef(EntityRef $ref): ?EntityAdapter
    {
        if ($ref->kind === 'post') {
            return $this->byPostType[(string) $ref->postType] ?? null;
        }
        if ($ref->kind === 'term') {
            // B.2.1: entity_key='{taxonomy}:{slug}' — dispatch pela taxonomia.
            $split = TermAdapter::splitKey($ref->key);

            return $split !== null ? ($this->byTaxonomy[$split[0]] ?? null) : null;
        }

        return $this->byKind[$ref->kind] ?? null;
    }

    /**
     * Adapter dono de um path relativo (extensão + diretório base; o prefixo
     * mais longo vence — 'templates/parts' antes de 'templates').
     */
    public function adapterForPath(string $relativePath): ?EntityAdapter
    {
        $candidates = $this->all();
        usort(
            $candidates,
            static fn (EntityAdapter $a, EntityAdapter $b): int => strlen($b->baseDirectory()) <=> strlen($a->baseDirectory())
        );

        foreach ($candidates as $adapter) {
            if (str_ends_with($relativePath, $adapter->fileExtension())
                && str_starts_with($relativePath, $adapter->baseDirectory() . '/')
            ) {
                return $adapter;
            }
        }

        return null;
    }

    /** @return list<EntityAdapter> */
    public function all(): array
    {
        return array_values([...$this->byPostType, ...$this->byTaxonomy, ...$this->byKind]);
    }

    /**
     * Adapters agrupados por estágio, ordem crescente (§A.5.7 + B.6.2):
     * 0=attachment (P4) → TERMOS (B.6.2), 1=patterns/navigation,
     * 2=templates/global-styles, 3=pages/CPTs, 4=menus, 5=branding.
     *
     * Dentro do estágio 0 a ordem interna é attachments ANTES de termos
     * (partição estável — determinística independente da ordem de registro;
     * a dependência term→attachment via thumbnail_id é não-estrutural, mas a
     * ordem estável é de graça, B.6.2).
     *
     * @return array<int,list<EntityAdapter>>
     */
    public function byStage(): array
    {
        $stages = $this->stages;
        ksort($stages);

        if (isset($stages[0])) {
            usort($stages[0], static fn (EntityAdapter $a, EntityAdapter $b): int => self::stage0Rank($a) <=> self::stage0Rank($b));
        }

        return $stages;
    }

    /** 0=attachment, 1=termo, 2= demais — ordem interna estável do estágio 0. */
    private static function stage0Rank(EntityAdapter $adapter): int
    {
        if ($adapter->kind() === 'post' && $adapter->postType() === 'attachment') {
            return 0;
        }
        if ($adapter->kind() === 'term') {
            return 1;
        }

        return 2;
    }

    /**
     * Mapa post_type → statuses (errata E3/§A.2.3) — usado pelos Hooks e
     * pelas queries de auditoria do StateStore. Nunca lista global.
     *
     * @return array<string,list<string>>
     */
    public function versionedStatuses(): array
    {
        $map = [];
        foreach ($this->byPostType as $postType => $adapter) {
            $map[$postType] = $adapter->statuses();
        }

        return $map;
    }

    /** @return list<string> Post types versionados (para hooks save_post_{$type}). */
    public function versionedPostTypes(): array
    {
        return array_keys($this->byPostType);
    }

    /**
     * Pré-condição dura §3.2: revisions obrigatórias (rede de segurança de
     * rollback). 'attachment' é a exceção declarada (errata E4).
     *
     * @throws AdapterException Post type versionado sem suporte a revisions.
     */
    public function assertOperable(): void
    {
        foreach ($this->byPostType as $postType => $_adapter) {
            if ($postType === 'attachment') {
                continue; // E4
            }
            if (!post_type_exists($postType)) {
                throw new AdapterException(sprintf('Post type versionado não registrado: %s', $postType));
            }
            if (!post_type_supports($postType, 'revisions')) {
                throw new AdapterException(
                    sprintf('Post type "%s" sem suporte a revisions — o cvsync recusa-se a operar (§3.2).', $postType)
                );
            }
        }

        // Apêndice B.1.2 — deny-list normativa + taxonomias inexistentes/não-públicas.
        foreach ($this->byTaxonomy as $taxonomy => $_adapter) {
            if (in_array($taxonomy, self::DENIED_TAXONOMIES, true)) {
                throw new AdapterException(
                    sprintf(
                        'Taxonomia "%s" é deny-listed do Apêndice B (já tem dono no cvsync ou é estrutural do core) — remova-a do filtro cvsync/taxonomies.',
                        $taxonomy
                    )
                );
            }
            if (!taxonomy_exists($taxonomy)) {
                throw new AdapterException(sprintf('Taxonomia versionada não registrada: %s', $taxonomy));
            }
            $object = get_taxonomy($taxonomy);
            if ($object instanceof \WP_Taxonomy && empty($object->public)) {
                throw new AdapterException(
                    sprintf('Taxonomia "%s" não é pública (maquinaria interna) — fora do escopo do Apêndice B.', $taxonomy)
                );
            }
        }
    }
}
