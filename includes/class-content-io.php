<?php
/**
 * ZipIo — export/import de content/ como zip (painel de configuração).
 *
 * EXPORT (buildContentZip): empacota TODO o CVSYNC_CONTENT_DIR — tudo lá é
 * artefato versionável por construção (§4.1: zero artefatos de estado, zero
 * entradas .gitignore) — nada a excluir.
 *
 * IMPORT (validateAndExtract): o zip é INPUT DE TERCEIRO (upload) e atravessa
 * a mesma fronteira de confiança de um PR — sem revisão humana. Cadeia
 * obrigatória ANTES de qualquer byte tocar o content dir ativo:
 *
 *  a. Teto de tamanho: 200 MB somados dos tamanhos DESCOMPACTADOS de todas
 *     as entradas (zip bomb) E 200 MB do arquivo físico. Constante de classe
 *     documentada (CVSYNC_ATTACHMENT_MAX_BYTES × ~20 arquivos médios seria
 *     arbitrário; 200 MB cobre a escala-alvo §13.9 com folga de 10×);
 *  b. Varredura estrutural de TODAS as entradas (sem I/O): path traversal
 *     (`..`, absoluto, drive, `\0`, backslash), symlinks (mode bits Unix do
 *     zip), double-extension/executáveis em QUALQUER posição (espelho da
 *     hard rule §A.9.5), subdir ∈ whitelist conhecida + extensão ∈ whitelist
 *     por tipo de artefato (incl. blobs `media/bin/<2hex>/<64hex>.<mime-ext>`);
 *  c. Extração para dir tmp (NUNCA no destino) + validação de CONTEÚDO dos
 *     artefatos textuais: UTF-8, tags YAML `!` (§4.3), frontmatter parseável
 *     (P1 FrontmatterParser — mesma classe do lint), markup de blocos com
 *     balanceamento e anti-regressão §6.2 (`{"ref":123}` cru), LFS pointer
 *     (§A.9.4) e magic bytes dos blobs (finfo — nunca extensão);
 *  d. Backup do content/ atual (rename para `.backup-<ts>` no PAI — fora do
 *     dir ativo) e swap atômico (rename) do extraído. Falha pós-backup →
 *     restauração automática do backup.
 *
 * Duplicação REGISTRADA (relatório content-io-devops.md): DANGEROUS_EXTENSIONS,
 * magic map e o regex de anti-regressão duplicam `bin/cvsync-lint.php`
 * (script procedural, sem classe carregável — não posso tocá-lo). O parser
 * de frontmatter NÃO é duplicado: FrontmatterParser (P1) é reusado.
 *
 * @package CVSync
 */

declare(strict_types=1);

namespace CVSync;

defined('ABSPATH') || exit;

/** Falha de export/import de content (mensagens acionáveis para a tela admin). */
class ContentIoException extends \RuntimeException
{
}

/** Violação da cadeia de validação do import (input rejeitado — nada tocado). */
class ZipValidationException extends ContentIoException
{
}

final class ZipIo
{
    /** Teto do zip (físico e soma descompactada) — ver cabeçalho (a). */
    public const MAX_ZIP_BYTES = 209_715_200; // 200 MB

    /** Executável/double-extension em QUALQUER posição (espelho §A.9.5 do lint). */
    private const DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phpt',
        'sh', 'bash', 'zsh', 'exe', 'com', 'bat', 'cmd', 'scr', 'msi', 'dll',
        'pl', 'py', 'rb', 'cgi', 'htaccess',
    ];

    /** Extensões de blob aceitas em media/bin/** (whitelist MIME §A.5.1.1). */
    private const BLOB_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];

    /** finfo magic-bytes por extensão de blob (nunca confiar na extensão). */
    private const BLOB_MAGIC = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'pdf'  => 'application/pdf',
    ];

    /** Extensões de artefatos textuais por kind (spec §4.1 + apêndices A/B). */
    private const TEXT_EXTENSIONS = [
        '.page.html', '.layout-part.html', '.pattern.html', '.template.html',
        '.template-part.html', '.navigation.html', '.global-styles.json',
        '.menu.yml', '.attachment.yml', '.term.yml',
    ];

    private const LFS_POINTER_PREFIX = 'version https://git-lfs.github.com/spec/v1';

    /**
     * Subdiretórios permitidos na RAIZ do zip (= raiz do content dir). `site`
     * (branding.yml) e os defaults da spec; dirs de CPTs configurados entram
     * via filtro `cvsync/io_allowed_dirs`.
     *
     * @return array<string, true>
     */
    private static function allowedRootDirs(): array
    {
        $dirs = [
            'pages', 'patterns', 'templates', 'global-styles', 'navigation',
            'menus', 'media', 'terms', 'site', 'layout-parts',
        ];
        foreach ((array) apply_filters('cvsync/io_allowed_dirs', []) as $extra) {
            $dirs[] = (string) $extra;
        }

        return array_fill_keys($dirs, true);
    }

    // ------------------------------------------------------------------
    // Export
    // ------------------------------------------------------------------

    /**
     * Empacota todo o content dir em um zip tmp.
     *
     * @return string Path absoluto do zip tmp (quem chamou faz o unlink).
     * @throws ContentIoException Content dir ausente/ilegível ou falha do ZipArchive.
     */
    public static function buildContentZip(): string
    {
        $contentDir = Environment::contentDir();
        if (! is_dir($contentDir)) {
            throw new ContentIoException(sprintf('Content dir não existe: %s (nada há para exportar).', $contentDir));
        }

        $zipPath = wp_tempnam('cvsync-content-' . gmdate('Ymd-His') . '-') . '.zip';
        if ('' === $zipPath) {
            throw new ContentIoException('Não foi possível criar arquivo temporário para o zip.');
        }

        $zip = new \ZipArchive();
        $open = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if (true !== $open) {
            throw new ContentIoException(sprintf('ZipArchive::open falhou (código %d) em %s.', (int) $open, $zipPath));
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($contentDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || ! $file->isFile() || $file->isLink()) {
                    continue;
                }
                $abs = $file->getPathname();
                $rel = ltrim(substr($abs, strlen($contentDir)), '/\\');
                if (! $zip->addFile($abs, $rel)) {
                    throw new ContentIoException(sprintf('Falha ao adicionar ao zip: %s', $rel));
                }
            }
            if (! $zip->close()) {
                throw new ContentIoException('ZipArchive::close falhou (zip incompleto).');
            }
        } catch (\Throwable $e) {
            $zip->close();
            if (file_exists($zipPath)) {
                unlink($zipPath);
            }
            throw $e;
        }

        return $zipPath;
    }

    // ------------------------------------------------------------------
    // Import
    // ------------------------------------------------------------------

    /**
     * Valida e extrai o zip, terminando com o swap atômico do content dir.
     *
     * Ordem: (a) teto; (b) varredura estrutural das entradas; extração para
     * tmp; (c) validação de conteúdo no tmp; (d) backup + swap. Qualquer
     * violação ANTES do swap → ZipValidationException e NADA é tocado;
     * falha pós-backup → restauração automática.
     *
     * @param string $zipPath      Path do zip (tmp de upload).
     * @param int    $maxEntities Teto de artefatos de entidade (recusa com
     *                             direcionamento para WP-CLI acima disso).
     * @return array{files: int, entities: int, bytes: int, backup: string, dir: string}
     * @throws ZipValidationException|ContentIoException
     */
    public static function validateAndExtract(string $zipPath, int $maxEntities = 50): array
    {
        // (a) Teto físico.
        $physical = filesize($zipPath);
        if (false === $physical) {
            throw new ContentIoException('Zip ilegível (filesize falhou).');
        }
        if ($physical > self::MAX_ZIP_BYTES) {
            throw new ZipValidationException(sprintf('Zip acima do teto (%s > %s bytes).', number_format($physical), number_format(self::MAX_ZIP_BYTES)));
        }

        $zip = new \ZipArchive();
        $open = $zip->open($zipPath);
        if (true !== $open) {
            throw new ZipValidationException(sprintf('Zip corrompido ou ilegível (ZipArchive código %d).', (int) $open));
        }

        try {
            // (a) Teto descompactado (zip bomb) + contagem de entidades.
            $uncompressed = 0;
            $entities     = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (false === $stat) {
                    throw new ZipValidationException(sprintf('Entrada #%d do zip ilegível.', $i));
                }
                $uncompressed += (int) $stat['size'];
                if (self::isEntityArtifact($stat['name'])) {
                    $entities++;
                }
            }
            if ($uncompressed > self::MAX_ZIP_BYTES) {
                throw new ZipValidationException(sprintf('Conteúdo descompactado acima do teto (%s bytes — zip bomb?).', number_format($uncompressed)));
            }
            if ($entities > $maxEntities) {
                throw new ZipValidationException(sprintf(
                    'O zip contém %d artefatos de entidade (teto da tela: %d) — use WP-CLI: `wp sync apply` (lote grande, sem timeout de request).',
                    $entities,
                    $maxEntities
                ));
            }

            // (b) Varredura estrutural de TODAS as entradas.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (false === $stat) {
                    throw new ZipValidationException(sprintf('Entrada #%d do zip ilegível.', $i));
                }
                self::assertEntryStructure($stat['name']);
            }

            // Extração para tmp — NUNCA direto no destino.
            $tmpDir = self::makeTmpDir();
            if (! $zip->extractTo($tmpDir)) {
                throw new ZipValidationException('Falha ao extrair o zip para o diretório temporário.');
            }

            try {
                // (c) Validação de conteúdo no tmp.
                $bytes = self::validateExtractedTree($tmpDir);

                // Z5: teto sobre os bytes REAIS extraídos (headers do zip
                // podem mentir) — recusa antes de qualquer swap.
                if ($bytes > self::MAX_ZIP_BYTES) {
                    throw new ZipValidationException(sprintf(
                        'Conteúdo real extraído (%s bytes) acima do teto (%s) — sizes declarados divergem do descompactado (zip bomb?).',
                        number_format($bytes),
                        number_format(self::MAX_ZIP_BYTES)
                    ));
                }

                // (d) Backup + swap atômico.
                return self::backupAndSwap($tmpDir, $entities, $bytes);
            } catch (\Throwable $e) {
                self::removeTree($tmpDir);
                throw $e;
            }
        } finally {
            $zip->close();
        }
    }

    // ------------------------------------------------------------------
    // (b) Varredura estrutural
    // ------------------------------------------------------------------

    /**
     * Uma entrada do zip: nome e pertença à árvore esperada.
     *
     * Nota (symlinks): a extensão ZipArchive do PHP não expõe os external
     * attributes Unix do zip; a checagem efetiva acontece no tmp extraído
     * (`is_link` em validateExtractedTree) — um symlink no zip vira arquivo
     * regular com o conteúdo do alvo no extractTo, e um symlink plantado é
     * rejeitado lá antes de qualquer swap.
     *
     * @throws ZipValidationException
     */
    private static function assertEntryStructure(string $name): void
    {
        if ('' === $name) {
            return;
        }

        // Path traversal / encoding hostil.
        if (str_contains($name, "\0")) {
            throw new ZipValidationException('Entrada com byte NUL no nome.');
        }
        $normalized = str_replace('\\', '/', $name);
        if (str_starts_with($normalized, '/') || preg_match('#^[a-z]:#i', $normalized) === 1) {
            throw new ZipValidationException(sprintf('Entrada com path absoluto: %s', $name));
        }
        // Entradas de DIRETÓRIO (terminam com '/'): ferramentas externas (zip
        // -r, Finder, Explorer) sempre as emitem — o próprio buildContentZip
        // não (LEAVES_ONLY). Aceitas (Z4): só o primeiro segmento precisa
        // pertencer à whitelist; a extração materializa os dirs e a validação
        // de conteúdo roda sobre ARQUIVOS. Checadas ANTES do loop de segmentos
        // (o '/' final produziria um segmento vazio falso-positivo).
        if (str_ends_with($normalized, '/')) {
            $first = explode('/', $normalized)[0];
            if ('' === $first || ! isset(self::allowedRootDirs()[$first])) {
                throw new ZipValidationException(sprintf('Diretório fora da árvore esperada: %s', $name));
            }

            return;
        }

        foreach (explode('/', $normalized) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                throw new ZipValidationException(sprintf('Segmento de path inválido ("%s") em: %s', $segment, $name));
            }
        }

        // Whitelist de subdir × extensão/kind.
        if (! self::isExpectedArtifactPath($normalized)) {
            throw new ZipValidationException(sprintf(
                'Path fora dos artefatos esperados sob content/: %s (subdirs/extensões conhecidos apenas — ver spec §4.1/A.3/B.3)',
                $name
            ));
        }

        // Executáveis/double-extension em QUALQUER posição (§A.9.5).
        $filename  = basename($normalized);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $parts     = explode('.', strtolower($filename));
        array_shift($parts); // nome base
        foreach ($parts as $ext) {
            if (in_array($ext, self::DANGEROUS_EXTENSIONS, true)) {
                throw new ZipValidationException(sprintf('Extensão executável ("%s") em: %s — rejeitado (§A.9.5).', $ext, $name));
            }
        }
        if (in_array($extension, self::DANGEROUS_EXTENSIONS, true)) {
            throw new ZipValidationException(sprintf('Extensão executável em: %s — rejeitado (§A.9.5).', $name));
        }
    }

    /**
     * O path relativo é um artefato esperado sob content/?
     * media/bin/<2hex>/<64hex>.<ext> · media/<slug>.attachment.yml ·
     * <dir-conhecido>/<...>.<ext-conhecida> · site/branding.yml.
     */
    private static function isExpectedArtifactPath(string $rel): bool
    {
        $first = explode('/', $rel)[0];

        // Blobs CAS (A.3).
        if (preg_match('#^media/bin/[0-9a-f]{2}/[0-9a-f]{64}\.(' . implode('|', self::BLOB_EXTENSIONS) . ')$#', $rel) === 1) {
            return true;
        }

        // Sidecars de attachment (A.3).
        if (preg_match('#^media/[a-z0-9][a-z0-9\-]*\.attachment\.yml$#', $rel) === 1) {
            return true;
        }

        // Branding (A.6).
        if ('site/branding.yml' === $rel) {
            return true;
        }

        if (! isset(self::allowedRootDirs()[$first]) || 'media' === $first) {
            return false;
        }

        foreach (self::TEXT_EXTENSIONS as $ext) {
            if (str_ends_with($rel, $ext)) {
                return true;
            }
        }

        // Arquivos dentro de templates/parts já cobertos por .template-part.html.
        return false;
    }

    /** Contagem de entidades (para o teto da tela). */
    private static function isEntityArtifact(string $name): bool
    {
        $rel = str_replace('\\', '/', $name);

        return self::isExpectedArtifactPath($rel) && ! str_ends_with($rel, '/');
    }

    // ------------------------------------------------------------------
    // (c) Validação de conteúdo no tmp
    // ------------------------------------------------------------------

    /**
     * Valida a árvore extraída: UTF-8, YAML seguro (sem tags `!`), frontmatter
     * parseável (P1), markup balanceado + anti-regressão §6.2, LFS pointer,
     * magic bytes dos blobs, symlinks plantados.
     *
     * Z5: soma os bytes REAIS de TODOS os arquivos extraídos (nunca os sizes
     * declarados do central directory — um zip craftado mente nos headers e a
     * bomba só aparece no disco) e o chamador recusa acima do teto ANTES do
     * swap.
     *
     * @return int Total de bytes reais extraídos.
     * @throws ZipValidationException
     */
    private static function validateExtractedTree(string $tmpDir): int
    {
        $bytes    = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tmpDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $rel = ltrim(substr($file->getPathname(), strlen($tmpDir)), '/\\');

            // Z5: contagem real ANTES de qualquer validação cara.
            $bytes += (int) $file->getSize();

            if ($file->isLink()) {
                throw new ZipValidationException(sprintf('Symlink no conteúdo extraído: %s — rejeitado.', $rel));
            }

            // Blobs: LFS pointer + magic bytes (finfo — nunca extensão).
            if (preg_match('#^media/bin/[0-9a-f]{2}/#', $rel) === 1) {
                self::validateBlob($file->getPathname(), $rel);
                continue;
            }

            // Artefatos textuais.
            $contents = (string) file_get_contents($file->getPathname());
            if ('' !== $contents && mb_check_encoding($contents, 'UTF-8') !== true) {
                throw new ZipValidationException(sprintf('Artefato não-UTF-8: %s', $rel));
            }
            if (str_starts_with($contents, self::LFS_POINTER_PREFIX)) {
                throw new ZipValidationException(sprintf('Pointer Git LFS detectado: %s — re-exporte do ambiente de origem (§A.9.4).', $rel));
            }
            if (preg_match('/^!|\n!/m', $contents) === 1) {
                throw new ZipValidationException(sprintf('Tag YAML customizada ("!") em: %s — proibida (§4.3).', $rel));
            }

            self::validateTextArtifact($rel, $contents);
            $bytes += strlen($contents);
        }

        return $bytes;
    }

    /**
     * Artefato textual: frontmatter parseável via P1 + markup de blocos com
     * anti-regressão §6.2 (raw numeric ref) — reuso das classes do engine;
     * o balanceamento fino é o do lint (duplicação mínima registrada).
     *
     * @throws ZipValidationException
     */
    private static function validateTextArtifact(string $rel, string $contents): bool
    {
        $isJson = str_ends_with($rel, '.global-styles.json');

        // YAML integral (menu/term/branding): parse completo do documento.
        if (preg_match('/\.(menu|term)\.yml$|^site\/branding\.yml$/', $rel) === 1) {
            try {
                $doc = \CVSync\Engine\Frontmatter\FrontmatterParser::parse($contents);
                if ([] === $doc) {
                    throw new \RuntimeException('documento vazio');
                }
            } catch (\Throwable $e) {
                throw new ZipValidationException(sprintf('YAML inválido em %s: %s', $rel, $e->getMessage()));
            }

            return true;
        }

        // Frontmatter + corpo (posts/global-styles).
        if (str_contains($contents, '---')) {
            try {
                [, $body] = \CVSync\Engine\Frontmatter\FrontmatterParser::splitDocument($contents);
            } catch (\Throwable $e) {
                throw new ZipValidationException(sprintf('Frontmatter inválido em %s: %s', $rel, $e->getMessage()));
            }
        } else {
            $body = $isJson ? $contents : '';
        }

        // Anti-regressão §6.2: IDs numéricos crus em atributos de bloco.
        foreach (\CVSync\Engine\Placeholders\PlaceholderVocabulary::DEFAULT_RAW_ATTRIBUTES as $attr) {
            if (preg_match(sprintf('/"%s"\s*:\s*\d+/', preg_quote($attr, '/')), $body) === 1) {
                throw new ZipValidationException(sprintf(
                    'Referência numérica crua ("%s": <número>) em %s — IDs nunca cruzam ambientes; re-exporte da origem (§6.2).',
                    $attr,
                    $rel
                ));
            }
        }

        if ($isJson && json_decode($body, true) === null && trim($body) !== 'null' && trim($body) !== '') {
            throw new ZipValidationException(sprintf('Corpo JSON inválido em %s: %s', $rel, json_last_error_msg()));
        }

        return true;
    }

    /**
     * Blob CAS: magic bytes (finfo) coerentes com a extensão + scan full-file
     * `<?php`/`<?=` (Z7 — paridade com o lint §A.9.5: polyglot pós-IEND;
     * magic bytes não pegam código embutido). Streaming — nunca carrega o
     * blob inteiro em memória.
     */
    private static function validateBlob(string $abs, string $rel): void
    {
        $expected = self::BLOB_MAGIC[strtolower(pathinfo($rel, PATHINFO_EXTENSION))] ?? null;
        if (null === $expected) {
            throw new ZipValidationException(sprintf('Extensão de blob fora da whitelist MIME: %s', $rel));
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($abs);
        if (is_string($mime) && $mime !== $expected) {
            throw new ZipValidationException(sprintf('Magic bytes divergem da extensão em %s: %s ≠ %s — rejeitado.', $rel, $mime, $expected));
        }

        $handle = @fopen($abs, 'rb');
        if (false === $handle) {
            throw new ZipValidationException(sprintf('Blob ilegível: %s', $rel));
        }
        try {
            $carry = '';
            while (($chunk = fread($handle, 1_048_576)) !== false) {
                if ('' === $chunk) {
                    break;
                }
                if (str_contains($carry . $chunk, '<?php') || str_contains($carry . $chunk, '<?=')) {
                    throw new ZipValidationException(sprintf('Código PHP embutido no blob %s — polyglot rejeitado (§A.9.5).', $rel));
                }
                $carry = substr($chunk, -4); // janela entre chunks (token pode cruzar a fronteira)
            }
        } finally {
            fclose($handle);
        }
    }

    // ------------------------------------------------------------------
    // (d) Backup + swap
    // ------------------------------------------------------------------

    /**
     * Backup + troca do content dir.
     *
     * Caminho primário: rename do dir (swap atômico). QUANDO o content dir é
     * um mountpoint (bind mount docker — o caso do projeto-base), rename
     * falha (EBUSY/EXDEV); o fallback é copy-swap portável: backup por CÓPIA
     * para o pai (cross-device ok), limpeza dos filhos e cópia validada dos
     * extraídos — com restauração automática do backup em falha parcial
     * (degradação documentada no relatório: a janela deixa de ser um único
     * rename, mas o import é operação manual do painel, nunca gatilho passivo).
     *
     * @return array{files: int, entities: int, bytes: int, backup: string, dir: string}
     * @throws ContentIoException Falha na troca (com restauração do backup).
     */
    private static function backupAndSwap(string $tmpDir, int $entities, int $bytes): array
    {
        $contentDir = rtrim(Environment::contentDir(), '/');
        $parent     = dirname($contentDir);
        $backup     = $parent . '/.' . basename($contentDir) . '.backup-' . gmdate('Ymd-His');
        $hadPrevious = is_dir($contentDir);

        // Caminho primário: swap atômico por rename (content dir regular).
        if ($hadPrevious ? @rename($contentDir, $backup) : true) {
            if (@rename($tmpDir, $contentDir)) {
                @chmod($contentDir, (int) Environment::constant('CVSYNC_DIR_MODE'));

                return self::swapResult($contentDir, $entities, $bytes, $backup);
            }
            // Falha no segundo rename: restaurar e cair no copy-swap.
            if ($hadPrevious && is_dir($backup)) {
                @rename($backup, $contentDir);
            }
        }

        // Fallback copy-swap (content dir é mountpoint — rename proibido).
        if ($hadPrevious) {
            if (! self::copyTree($contentDir, $backup)) {
                throw new ContentIoException(sprintf('Falha ao copiar o content dir atual para o backup (%s) — nada foi alterado.', $backup));
            }
        }

        try {
            self::clearDir($contentDir); // mountpoint permanece; filhos saem
            if (! self::copyTreeChildren($tmpDir, $contentDir)) {
                throw new \RuntimeException('cópia dos artefatos extraídos para o content dir falhou.');
            }
        } catch (\Throwable $e) {
            // Restauração best-effort do backup (cópia cross-device ok);
            // o backup permanece disponível para recuperação manual.
            if ($hadPrevious && is_dir($backup)) {
                self::clearDir($contentDir);
                self::copyTreeChildren($backup, $contentDir);
            }
            throw new ContentIoException('Troca do content dir falhou — backup restaurado (ou preservado em ' . $backup . '): ' . $e->getMessage());
        }

        self::removeTree($tmpDir);

        return self::swapResult($contentDir, $entities, $bytes, $backup);
    }

    /**
     * @return array{files: int, entities: int, bytes: int, backup: string, dir: string}
     */
    private static function swapResult(string $contentDir, int $entities, int $bytes, string $backup): array
    {
        $files = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($contentDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files++;
            }
        }

        return ['files' => $files, 'entities' => $entities, 'bytes' => $bytes, 'backup' => $backup, 'dir' => $contentDir];
    }

    /** Cópia recursiva dir→dir (cria $target). Cross-device ok. */
    private static function copyTree(string $source, string $target): bool
    {
        if (! wp_mkdir_p($target)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo) {
                continue;
            }
            $dest = $target . substr($file->getPathname(), strlen($source));
            if ($file->isDir()) {
                if (! wp_mkdir_p($dest)) {
                    return false;
                }
            } elseif (! copy($file->getPathname(), $dest)) {
                return false;
            }
        }

        return true;
    }

    /** Copia os FILHOS de $source para DENTRO de $target (existente). */
    private static function copyTreeChildren(string $source, string $target): bool
    {
        foreach ((array) scandir($source) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $from = $source . '/' . $entry;
            $to   = $target . '/' . $entry;
            if (is_dir($from)) {
                if (! self::copyTree($from, $to)) {
                    return false;
                }
            } elseif (! copy($from, $to)) {
                return false;
            }
        }

        return true;
    }

    /** Remove os filhos de $dir (o próprio dir permanece — mountpoint). */
    private static function clearDir(string $dir): void
    {
        foreach ((array) scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && ! is_link($path) ? self::removeTree($path) : @unlink($path);
        }
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /**
     * Dir tmp de extração NO PAI do content dir (mesmo filesystem do swap —
     * rename cross-device entre /tmp e o bind mount falha com EXDEV) e com a
     * permissão necessária para o backup+swap (o pai precisa ser gravável
     * pelo usuário do runtime web; se não for, a mensagem prescreve o fix).
     */
    private static function makeTmpDir(): string
    {
        $parent = dirname(rtrim(Environment::contentDir(), '/'));
        if (! is_dir($parent) || ! is_writable($parent)) {
            throw new ContentIoException(sprintf(
                'O diretório pai do content dir (%s) precisa ser gravável pelo runtime web para o swap atômico do import — ajuste a permissão (chown/grupo) e tente novamente.',
                $parent
            ));
        }

        do {
            $dir = rtrim($parent, '/') . '/.cvsync-import-' . bin2hex(random_bytes(8));
        } while (is_dir($dir));

        if (! wp_mkdir_p($dir)) {
            throw new ContentIoException('Não foi possível criar diretório temporário para extração.');
        }

        return $dir;
    }

    private static function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }
}
