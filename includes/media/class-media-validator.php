<?php
/**
 * MediaValidator — cadeia de validação repo→uploads (§A.5.1, cláusula central
 * de segurança). Passos 1–6, executados ANTES de escrever qualquer byte em
 * uploads. O passo 7 (tríplice igualdade) vive no stream do MediaStore e o
 * passo 8 (sideload) no Materializer.
 *
 * O uploads é webroot e o input vem de PR (terceiro) — o envelope é MAIS
 * estrito que para texto:
 *  1. Whitelist estática própria (CVSYNC_ATTACHMENT_MIME_TYPES; default
 *     jpeg/png/webp/gif/pdf) por INTERSEÇÃO com os mimes do site — nunca
 *     união; unfiltered_upload é ignorado por invariante; SVG default-deny
 *     (§A.9.3 — opt-in exige sanitizador dedicado, senão fail-closed);
 *  2. Validação profunda: wp_check_filetype_and_ext() — NUNCA
 *     wp_check_filetype() isolado (zero inspeção de bytes);
 *  3. Rejeição de double-extension (evil.php.jpg) + sanitize_file_name();
 *  4. Teto de 50 MP via wp_getimagesize() header-only ANTES de qualquer
 *     processamento: acima → NÃO é erro, sinaliza degraded (§A.5.1.4 — bytes
 *     materializados, post inserido, regenerate pulado = applied-degraded);
 *  5. Pointer LFS — no stream do MediaStore (lfs-pointer-detected, §A.9.4);
 *  6. Contenção de path no uploads — no Materializer (realpath contido +
 *     is_link antes do rename, §6.4 estendida).
 *
 * @package CVSync\Media
 */

declare(strict_types=1);

namespace CVSync\Media;

defined('ABSPATH') || exit;

/** Resultado da cadeia de validação. */
final readonly class ValidationResult
{
    /**
     * @param list<string> $violations Vazia = aprovado.
     * @param bool         $degraded   true = acima de 50 MP → regenerate pulado (applied-degraded).
     * @param list<string> $warnings   Não-bloqueantes (ex.: otimização ≥ 2 MB, §A.5.4).
     */
    public function __construct(
        public array $violations,
        public bool $degraded = false,
        public array $warnings = [],
    ) {
    }

    public function ok(): bool
    {
        return $this->violations === [];
    }
}

final class MediaValidator
{
    /** Whitelist default (§A.5.1.1). ZIP-bombs fora por construção (.zip ausente). */
    private const DEFAULT_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf',
    ];

    /** Extensões executáveis em QUALQUER posição do nome (double-extension). */
    private const EXECUTABLE_PATTERN = '/\.(php[0-9]?|phtml|phar|pht|sh|bash|exe|cgi|pl|py|rb|asp|aspx|jsp|htaccess)(\.|$)/i';

    /** Teto de megapixels (pixel/decompression bombs — §A.5.1.4). */
    private const MAX_MEGAPIXELS = 50_000_000;

    /** Warning de otimização (§A.5.4 — lint/review; nunca bloqueia). */
    private const OPTIMIZATION_WARNING_BYTES = 2_097_152; // 2 MB

    /**
     * Executa os passos 1–4 sobre o blob JÁ copiado para um tmp (fora de
     * uploads). Nunca escreve em uploads.
     */
    public function validate(string $blobTmpPath, Sidecar $sidecar): ValidationResult
    {
        $violations = [];
        $warnings = [];
        $degraded = false;

        // Passo 1 — whitelist estática por INTERSEÇÃO (nunca união).
        $allowed = $this->allowedMimeTypes();
        if (!in_array($sidecar->mime, $allowed, true)) {
            $violations[] = sprintf(
                'MIME "%s" fora da whitelist efetiva (%s) — unfiltered_upload é ignorado por invariante.',
                $sidecar->mime,
                implode(', ', $allowed)
            );
        }

        // Passo 2 — validação profunda de conteúdo (inspeção real de bytes).
        if (!function_exists('wp_check_filetype_and_ext')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $filename = sanitize_file_name($sidecar->originalFilename);
        $check = wp_check_filetype_and_ext($blobTmpPath, $filename, $this->buildMimeCheckMap($allowed));
        if (empty($check['ext']) || empty($check['type'])) {
            $violations[] = sprintf(
                'wp_check_filetype_and_ext rejeitou "%s" (conteúdo não corresponde ao MIME declarado — renomear shell.php não passa).',
                $filename
            );
        } elseif (!in_array((string) $check['type'], $allowed, true)) {
            $violations[] = sprintf('MIME real "%s" detectado na inspeção está fora da whitelist.', (string) $check['type']);
        }

        // Passo 3 — double-extension (evil.php.jpg): executável em qualquer posição.
        if (preg_match(self::EXECUTABLE_PATTERN, $sidecar->originalFilename) === 1) {
            $violations[] = sprintf('Double-extension/executável rejeitado: "%s".', $sidecar->originalFilename);
        }

        // Passo 4 — teto de megapixels (header-only; ANTES de qualquer processamento).
        if (str_starts_with($sidecar->mime, 'image/')) {
            $size = @wp_getimagesize($blobTmpPath); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- falha de leitura é tratada abaixo
            if ($size !== false) {
                $pixels = (int) $size[0] * (int) $size[1];
                if ($pixels > self::MAX_MEGAPIXELS) {
                    $degraded = true; // applied-degraded (§A.5.1.4) — nunca OOM no deploy
                }
            }
        }

        // Warning de otimização (§A.5.4 — "otimize/converta para WebP").
        $bytes = filesize($blobTmpPath);
        if ($bytes !== false && $bytes >= self::OPTIMIZATION_WARNING_BYTES) {
            $warnings[] = sprintf(
                'Blob com %s — otimize/converta para WebP (warning ≥ 2 MB, §A.5.4).',
                size_format($bytes)
            );
        }

        return new ValidationResult($violations, $degraded, $warnings);
    }

    /**
     * Whitelist efetiva: whitelist estática do plugin ∩ mimes permitidos do
     * site. INTERSEÇÃO, nunca união; unfiltered_upload ignorado por
     * invariante (o plugin nunca define, nunca exige, não honra).
     * Leitura EXCLUSIVA via registry do Environment (§10.1; R4 do r9):
     * valor inválido → default fail-safe + warning.
     *
     * @return list<string>
     */
    public function allowedMimeTypes(): array
    {
        $csv = (string) (\CVSync\Environment::constant('CVSYNC_ATTACHMENT_MIME_TYPES')
            ?? implode(',', self::DEFAULT_MIME_TYPES));
        $static = array_values(array_filter(array_map(
            static fn (string $m): string => trim($m),
            explode(',', $csv)
        )));

        // SVG: default-deny (§A.9.3); opt-in EXIGE sanitizador dedicado (fail-closed).
        $svgAllowed = (bool) \CVSync\Environment::constant('CVSYNC_ATTACHMENT_ALLOW_SVG')
            && class_exists(\enshrined\svgSanitize\Sanitizer::class);
        if (!$svgAllowed) {
            $static = array_values(array_diff($static, ['image/svg+xml']));
        }

        $siteMimes = array_values(wp_get_mime_types());

        return array_values(array_intersect($static, $siteMimes));
    }

    /**
     * Mapa ext=>mime restrito à whitelist, para wp_check_filetype_and_ext.
     *
     * @param list<string> $allowed
     * @return array<string,string>
     */
    private function buildMimeCheckMap(array $allowed): array
    {
        $map = [];
        foreach (wp_get_mime_types() as $exts => $mime) {
            if (!in_array($mime, $allowed, true)) {
                continue;
            }
            foreach (explode('|', $exts) as $ext) {
                $map[$ext] = $mime;
            }
        }

        return $map;
    }
}
