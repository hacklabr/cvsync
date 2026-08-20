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

    /** @var array<int,list<EntityAdapter>> estágio => adapters (§A.5.7) */
    private array $stages = [];

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

        $this->register(new GlobalStylesAdapter($this->state, $this->resolver, $this->paths), 2);
        $this->register(new MenuAdapter($this->state, $this->resolver, $this->paths), 4);
        $this->register(new BrandingAdapter($this->state, $this->resolver, $this->paths), 5);
    }

    /** Registro explícito (P4 registra o AttachmentAdapter no estágio 0). */
    public function register(EntityAdapter $adapter, int $stage): void
    {
        $postType = $adapter->postType();
        if ($postType !== null) {
            $this->byPostType[$postType] = $adapter;
        } else {
            $this->byKind[$adapter->kind()] = $adapter;
        }
        $this->stages[$stage][] = $adapter;
    }

    public function forPostType(string $postType): ?EntityAdapter
    {
        return $this->byPostType[$postType] ?? null;
    }

    public function forRef(EntityRef $ref): ?EntityAdapter
    {
        if ($ref->kind === 'post') {
            return $this->byPostType[(string) $ref->postType] ?? null;
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
        return array_values([...$this->byPostType, ...$this->byKind]);
    }

    /**
     * Adapters agrupados por estágio, ordem crescente (§A.5.7):
     * 0=attachment (P4), 1=patterns/navigation, 2=templates/global-styles,
     * 3=pages/CPTs, 4=menus, 5=branding.
     *
     * @return array<int,list<EntityAdapter>>
     */
    public function byStage(): array
    {
        $stages = $this->stages;
        ksort($stages);

        return $stages;
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
    }
}
