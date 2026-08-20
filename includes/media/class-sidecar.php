<?php
/**
 * Sidecar — DTO + serializer do `content/media/{slug}.attachment.yml` (§A.3.2).
 *
 * Regras normativas:
 *  - YAML INTEGRAL (sem fences), parser seguro do P1 (§4.3/§A.3.2.3);
 *  - campos deriváveis do binário (width/height/blob_size) e original_path são
 *    INFORMATIVOS — nunca material de hash (§A.3.2.1/2); uuid NUNCA no hash
 *    (§5.4); original_filename é OBRIGATÓRIO (única fonte do basename na
 *    materialização, §A.3.2.4);
 *  - material de hash = HASH_KEY_ORDER (subconjunto), composto com o binário
 *    pelo Hasher do P1 (§A.4.1).
 *
 * @package CVSync\Media
 */

declare(strict_types=1);

namespace CVSync\Media;

use CVSync\Adapters\RejectedEntityException;
use CVSync\Engine\Frontmatter\FrontmatterParser;
use CVSync\Engine\Frontmatter\FrontmatterWriter;
use CVSync\Engine\Hasher;

defined('ABSPATH') || exit;

final class Sidecar
{
    /** Ordem fixa de TODAS as chaves do arquivo (hash por último). */
    public const KEY_ORDER = [
        'uuid', 'slug', 'title', 'alt', 'caption', 'description', 'mime',
        'original_filename', 'original_path', 'parent', 'blob',
        'blob_size', 'width', 'height', 'hash',
    ];

    /** Subconjunto que entra no hash composto (§A.3.2, §A.4.1). */
    public const HASH_KEY_ORDER = [
        'slug', 'title', 'alt', 'caption', 'description', 'mime',
        'original_filename', 'parent', 'blob',
    ];

    public string $uuid = '';
    public string $slug = '';
    public string $title = '';
    public string $alt = '';
    public string $caption = '';
    public string $description = '';
    public string $mime = '';
    public string $originalFilename = '';
    public ?string $originalPath = null;  // informativo — fora do hash
    public ?string $parent = null;        // slug do post pai (nullable)
    public string $blob = '';             // 'sha256:<hex>' — componente binário do hash
    public ?int $blobSize = null;         // informativo
    public ?int $width = null;            // informativo — extraído DO BINÁRIO no export
    public ?int $height = null;           // idem

    /**
     * Parse seguro (§4.3) + validação mínima do formato §A.3.2.
     *
     * @throws RejectedEntityException
     */
    public static function fromYaml(string $raw): self
    {
        try {
            $data = FrontmatterParser::parse($raw);
        } catch (\Throwable $e) {
            throw new RejectedEntityException('Sidecar inválido: ' . $e->getMessage(), 0, $e);
        }

        $sidecar = new self();
        $sidecar->uuid = (string) ($data['uuid'] ?? '');
        $sidecar->slug = (string) ($data['slug'] ?? '');
        $sidecar->title = (string) ($data['title'] ?? '');
        $sidecar->alt = (string) ($data['alt'] ?? '');
        $sidecar->caption = (string) ($data['caption'] ?? '');
        $sidecar->description = (string) ($data['description'] ?? '');
        $sidecar->mime = (string) ($data['mime'] ?? '');
        $sidecar->originalFilename = (string) ($data['original_filename'] ?? '');
        $sidecar->originalPath = isset($data['original_path']) ? (string) $data['original_path'] : null;
        $sidecar->parent = isset($data['parent']) && $data['parent'] !== null ? (string) $data['parent'] : null;
        $sidecar->blob = (string) ($data['blob'] ?? '');
        $sidecar->blobSize = isset($data['blob_size']) ? (int) $data['blob_size'] : null;
        $sidecar->width = isset($data['width']) ? (int) $data['width'] : null;
        $sidecar->height = isset($data['height']) ? (int) $data['height'] : null;

        if ($sidecar->uuid === '' || $sidecar->slug === '' || $sidecar->originalFilename === '' || $sidecar->blob === '') {
            throw new RejectedEntityException('Sidecar sem uuid/slug/original_filename/blob obrigatórios (§A.3.2).');
        }
        if (preg_match('/^[a-z0-9][a-z0-9\-]*$/', $sidecar->slug) !== 1) {
            throw new RejectedEntityException(sprintf('Slug de attachment fora do padrão §6.4: "%s".', $sidecar->slug));
        }
        // blob sempre prefixado 'sha256:<64hex>' (Hasher::normalize valida).
        try {
            $sidecar->blob = Hasher::normalize($sidecar->blob);
        } catch (\Throwable $e) {
            throw new RejectedEntityException('Sidecar com blob malformado: ' . $e->getMessage(), 0, $e);
        }

        return $sidecar;
    }

    /** Serialização canônica byte-idêntica (hash como ÚLTIMA linha). */
    public function toYaml(string $entityHash): string
    {
        $fields = $this->allFields();
        $fields['hash'] = $entityHash;

        return FrontmatterWriter::write($fields, self::KEY_ORDER);
    }

    /** Frontmatter hasheável: SOMENTE o subconjunto §A.4.1, sem uuid/deriváveis. */
    public function hashFields(): array
    {
        $all = $this->allFields();
        $fields = [];
        foreach (self::HASH_KEY_ORDER as $key) {
            $fields[$key] = $all[$key] ?? null;
        }

        return $fields;
    }

    /** Extensão do blob: do original_filename, sanitizada (não é identidade — §A.3.1). */
    public function blobExtension(): string
    {
        $ext = pathinfo($this->originalFilename, PATHINFO_EXTENSION);

        return strtolower((string) preg_replace('/[^a-z0-9]/', '', strtolower($ext)));
    }

    /** Hex do blob (sem prefixo) para paths CAS e comparações. */
    public function blobHex(): string
    {
        return substr(Hasher::normalize($this->blob), strlen(Hasher::PREFIX));
    }

    /** @return array<string,mixed> */
    private function allFields(): array
    {
        return [
            'uuid'              => $this->uuid,
            'slug'              => $this->slug,
            'title'             => $this->title,
            'alt'               => $this->alt,
            'caption'           => $this->caption,
            'description'       => $this->description,
            'mime'              => $this->mime,
            'original_filename' => $this->originalFilename,
            'original_path'     => $this->originalPath,
            'parent'            => $this->parent,
            'blob'              => $this->blob,
            'blob_size'         => $this->blobSize,
            'width'             => $this->width,
            'height'            => $this->height,
        ];
    }
}
