<?php
/**
 * BrandingAdapter — mini-entidades de branding (Apêndice A, §A.6).
 *
 *  - entity_kind='branding', entity_key '{stylesheet}:custom_logo' e
 *    'core:site_icon'; payload = placeholder {{attachment:slug}} do anexo;
 *  - arquivo ÚNICO 'site/branding.yml' (baixíssimo churn) — as duas
 *    mini-entidades vivem no mesmo documento; o adapter grava o state das
 *    DUAS keys presentes (recordSync idempotente; o Importer regrava a key
     principal sem efeito);
 *  - custom_logo vive em theme_mods_{stylesheet}; site_icon na option
 *    'site_icon' (§A.6);
 *  - último estágio do apply (§A.5.7 — registrado como stage 5 no registry);
 *  - YAML integral (sem fences), hash sobre o documento re-serializado.
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
use CVSync\Engine\Hasher;
use CVSync\Engine\Placeholders\PlaceholderToken;
use CVSync\ImportContext;
use CVSync\PathGuard;
use CVSync\Storage\StateStore;
use CVSync\Storage\SyncDirection;

defined('ABSPATH') || exit;

final class BrandingAdapter implements EntityAdapter
{
    private const FILE_PATH = 'site/branding.yml';

    /** Campos versionados: chave YAML → entity_key suffix. */
    private const FIELDS = [
        'custom_logo' => 'custom_logo',
        'site_icon'   => 'core:site_icon',
    ];

    public function __construct(
        private readonly StateStore $state,
        private readonly ReferenceResolver $resolver,
        private readonly PathGuard $paths,
    ) {
    }

    public function kind(): string
    {
        return 'branding';
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
        return 'site';
    }

    public function fileExtension(): string
    {
        return '.branding.yml';
    }

    public function metaWhitelist(): array
    {
        return [];
    }

    public function identityTaxonomies(): array
    {
        return [];
    }

    public function keyOrder(): array
    {
        return [];
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
        return $this->currentValue($ref->key) !== null;
    }

    public function findByUuid(string $uuid): ?EntityRef
    {
        return null; // branding não tem UUID — a entity_key É a identidade (§A.6)
    }

    public function findBySlug(string $slug): ?EntityRef
    {
        $ref = EntityRef::of('branding', $slug);

        return $this->exists($ref) ? $ref : null;
    }

    public function ensureUuid(int $dbId): string
    {
        return ''; // sem portador de meta — identidade pela entity_key
    }

    // ------------------------------------------------------------------
    // Leitura canônica (banco → arquivo)
    // ------------------------------------------------------------------

    public function readCanonical(EntityRef $ref): ?CanonicalDocument
    {
        $data = $this->readData();
        if ($data === null) {
            return null;
        }

        return new CanonicalDocument($ref, [], FrontmatterWriter::writeBlockYaml($data));
    }

    public function parseDocument(string $bytes): CanonicalDocument
    {
        $data = FrontmatterParser::parse($bytes);

        $stylesheet = $data['stylesheet'] ?? null;
        if (!is_string($stylesheet) || $stylesheet === '') {
            throw new RejectedEntityException('branding.yml sem stylesheet.');
        }

        $canonical = $this->canonicalizeData($data);

        // Key principal do documento: a primeira mini-entidade presente.
        $key = array_key_exists('custom_logo', $canonical)
            ? $stylesheet . ':custom_logo'
            : 'core:site_icon';

        return new CanonicalDocument(
            EntityRef::of('branding', $key),
            [],
            FrontmatterWriter::writeBlockYaml($canonical)
        );
    }

    /** A validação de branding ocorre em parseDocument (YAML integral). */
    public function validateFrontmatter(array $frontmatter): void
    {
    }

    /** YAML integral (§A.6): o documento canônico + 'hash' como última chave. */
    public function serializeDocument(CanonicalDocument $doc, string $hash): string
    {
        $data = FrontmatterParser::parse($doc->body);
        $data['hash'] = $hash;

        return FrontmatterWriter::writeBlockYaml($data);
    }

    public function relativePath(CanonicalDocument $doc): string
    {
        return self::FILE_PATH;
    }

    public function locateFile(EntityRef $ref): ?string
    {
        return $this->paths->exists(self::FILE_PATH) ? self::FILE_PATH : null;
    }

    // ------------------------------------------------------------------
    // Escrita (arquivo → banco) — dentro de ImportGuard + withLockedRow
    // ------------------------------------------------------------------

    public function apply(CanonicalDocument $doc, ImportContext $ctx): ApplyResult
    {
        $data = $this->canonicalizeData(FrontmatterParser::parse($doc->body));

        if (($data['stylesheet'] ?? '') !== get_stylesheet()) {
            throw new RejectedEntityException(
                sprintf('Branding do tema "%s" recusado: tema ativo é "%s".', (string) $data['stylesheet'], get_stylesheet())
            );
        }

        $pendencies = [];
        foreach (self::FIELDS as $field => $keySuffix) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            $attachmentId = null;

            if (is_string($value) && $value !== '') {
                if (preg_match('/^\{\{attachment:([^}]*)\}\}$/', $value, $m) !== 1) {
                    throw new RejectedEntityException(
                        sprintf('Branding "%s" com valor não-placeholderizado (IDs nunca cruzam a fronteira, §6).', $field)
                    );
                }
                $attachmentId = $this->resolver->postIdForSlug('attachment', $m[1]);
                if ($attachmentId === null) {
                    $pendencies[] = new PlaceholderToken('attachment', [$m[1]]);
                    continue; // ausência visível; nunca ID de origem
                }
            }

            if ($field === 'custom_logo') {
                set_theme_mod('custom_logo', $attachmentId ?? 0);
            } else {
                update_option('site_icon', $attachmentId ?? 0);
            }
        }

        // State das DUAS mini-entidades presentes no documento (mesmo hash).
        $hash = Hasher::hashDocument($doc);
        $stylesheet = (string) $data['stylesheet'];
        foreach (self::FIELDS as $field => $keySuffix) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $key = $field === 'custom_logo' ? $stylesheet . ':' . $keySuffix : $keySuffix;
            $this->state->recordSync(EntityRef::of('branding', $key), SyncDirection::FileToDb, $hash);
        }

        return new ApplyResult(null, [], $pendencies, []);
    }

    public function delete(EntityRef $ref, bool $force = false): void
    {
        if (str_ends_with($ref->key, ':custom_logo')) {
            remove_theme_mod('custom_logo');
        } else {
            delete_option('site_icon');
        }
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /** Valor atual (attachment ID) de uma mini-entidade. */
    private function currentValue(string $entityKey): ?int
    {
        if (str_ends_with($entityKey, ':custom_logo')) {
            $value = get_theme_mod('custom_logo');
        } else {
            $value = get_option('site_icon');
        }
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    /**
     * Documento canônico a partir do banco. null quando nenhuma mini-entidade
     * tem valor (nada a versionar).
     *
     * @return array<string,mixed>|null
     */
    private function readData(): ?array
    {
        $data = ['stylesheet' => get_stylesheet()];
        $hasAny = false;

        foreach (self::FIELDS as $field => $keySuffix) {
            $key = $field === 'custom_logo' ? get_stylesheet() . ':' . $keySuffix : $keySuffix;
            $attachmentId = $this->currentValue($key);
            if ($attachmentId === null) {
                continue;
            }
            $slug = $this->resolver->slugForPostId($attachmentId);
            if ($slug === null) {
                continue;
            }
            $data[$field] = '{{attachment:' . $slug . '}}';
            $hasAny = true;
        }

        return $hasAny ? $this->canonicalizeData($data) : null;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function canonicalizeData(array $data): array
    {
        $out = ['stylesheet' => (string) ($data['stylesheet'] ?? '')];
        foreach (self::FIELDS as $field => $_suffix) {
            if (array_key_exists($field, $data)) {
                $out[$field] = $data[$field] === null ? null : (string) $data[$field];
            }
        }

        return $out;
    }
}
