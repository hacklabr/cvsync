<?php
/**
 * PostAdapter — adapter canônico parametrizado por post type.
 *
 * Cobre page, CPTs configurados, wp_block, wp_template, wp_template_part e
 * wp_navigation: mesmo pipeline (frontmatter + corpo de blocos byte-a-byte),
 * configuração injetada pelo AdapterRegistry (diretório, extensão, statuses,
 * allowlist de meta, taxonomias identitárias) — o conjunto versionado nunca
 * é hardcoded (§3.2).
 *
 * Casos especiais do layout §4.1:
 *  - wp_template_part: 'templates/parts/{area}--{slug}.template-part.html'
 *    (área lida dos termos identitários — muda de área gera diff, §4.2.5);
 *  - demais: '{dir}/{slug}{ext}' plano; hierarquia via 'parent:' no
 *    frontmatter (alternativa normativa da spec §3.1 — não diretórios).
 *
 * @package CVSync\Adapters
 */

declare(strict_types=1);

namespace CVSync\Adapters;

use CVSync\Engine\CanonicalDocument;
use CVSync\PathGuard;
use CVSync\Storage\StateStore;

defined('ABSPATH') || exit;

class PostAdapter extends AbstractPostAdapter
{
    /**
     * @param list<string> $statuses
     * @param list<string> $metaAllowlist
     * @param list<string> $identityTaxonomies
     */
    public function __construct(
        StateStore $state,
        ReferenceResolver $resolver,
        PathGuard $paths,
        private readonly string $postTypeName,
        private readonly string $directory,
        private readonly string $extension,
        private readonly array $statusesConfig,
        private readonly array $metaAllowlistConfig,
        private readonly array $identityTaxonomiesConfig,
    ) {
        parent::__construct($state, $resolver, $paths);
    }

    public function postType(): string
    {
        return $this->postTypeName;
    }

    public function statuses(): array
    {
        return $this->statusesConfig;
    }

    public function baseDirectory(): string
    {
        return $this->directory;
    }

    public function fileExtension(): string
    {
        return $this->extension;
    }

    public function metaAllowlist(): array
    {
        return $this->metaAllowlistConfig;
    }

    public function identityTaxonomies(): array
    {
        return $this->identityTaxonomiesConfig;
    }

    public function relativePath(CanonicalDocument $doc): string
    {
        if ($this->postTypeName === 'wp_template_part') {
            $terms = $doc->terms();
            $area = (string) ($terms['wp_template_part_area'][0] ?? 'uncategorized');

            return $this->directory . '/' . $area . '--' . $doc->slug() . $this->extension;
        }

        return parent::relativePath($doc);
    }
}
