<?php
/**
 * PathGuard — contenção de path/symlink (spec §6.4, cláusula obrigatória) e
 * escrita atômica de arquivo (§10.7).
 *
 * Slugs e parents vêm do frontmatter — input de PR, tratado como terceiro:
 *  1. Todo segmento de diretório validado contra ^[a-z0-9][a-z0-9\-]*$ antes
 *     de compor qualquer path;
 *  2. realpath() do diretório pai (ou do ancestral existente mais próximo)
 *     DEVE estar contido no content dir configurado;
 *  3. is_link() no destino antes do rename() (equivalente a O_NOFOLLOW) — um
 *     symlink plantado em content/ não faz o exporter escrever fora da árvore.
 *
 * Escrita: tmp na MESMA partição + rename + chmod explícito (CVSYNC_FILE_MODE/
 * CVSYNC_DIR_MODE — nunca depender de umask de request, §10.7).
 *
 * @package CVSync
 */

declare(strict_types=1);

namespace CVSync;

defined('ABSPATH') || exit;

/** Path escapa da raiz de conteúdo configurada (§6.4). */
class PathEscapesRootException extends \RuntimeException
{
}

/** Destino é um symlink (O_NOFOLLOW equivalente, §6.4). */
class SymlinkTargetException extends \RuntimeException
{
}

final class PathGuard
{
    /** Segmento de diretório / slug (§6.4 + fix underscore: sanitize_title
     *  preserva '_' e slugs REAIS do WP contêm underscore — '_' não abre
     *  vetor de traversal; '/', '\0', '..', segmentos vazios e path absoluto
     *  continuam barrados antes na validate()). */
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_\-]*$/';

    /** Nome de arquivo: slug + extensões com kind ('.page.html', '.menu.yml'). */
    private const FILENAME_PATTERN = '/^[a-z0-9][a-z0-9_\-]*(\.[a-z0-9][a-z0-9_\-]*)+$/';

    private readonly string $root;

    public function __construct(?string $contentDir = null)
    {
        $dir = $contentDir
            ?? (defined('CVSYNC_CONTENT_DIR') ? (string) constant('CVSYNC_CONTENT_DIR') : null)
            ?? dirname(ABSPATH) . '/content';

        $real = realpath($dir);
        $this->root = rtrim($real !== false ? $real : $dir, '/');
    }

    /** Raiz de conteúdo (CVSYNC_CONTENT_DIR ou <repo-root>/content, §4.1). */
    public function contentDir(): string
    {
        return $this->root;
    }

    /**
     * Resolve e valida um path relativo para ESCRITA (§6.4).
     *
     * @throws PathEscapesRootException Segmento inválido ou ancestral fora da raiz.
     * @throws SymlinkTargetException   Destino é symlink.
     */
    public function resolveWritable(string $relative): string
    {
        $absolute = $this->validate($relative);

        // Contenção: realpath do ancestral existente mais próximo DEVE estar
        // contido na raiz (diretórios novos ainda não têm realpath próprio).
        $ancestor = dirname($absolute);
        while (!file_exists($ancestor) && $ancestor !== dirname($ancestor)) {
            $ancestor = dirname($ancestor);
        }
        $realAncestor = realpath($ancestor);
        if ($realAncestor === false || !$this->isContained($realAncestor)) {
            throw new PathEscapesRootException(
                sprintf('Path escapa de content/: %s (ancestral real: %s)', $relative, $realAncestor ?: 'inexistente')
            );
        }

        if (is_link($absolute)) {
            throw new SymlinkTargetException(sprintf('Destino é symlink, escrita recusada: %s', $relative));
        }

        return $absolute;
    }

    /** Leitura contida (importer/lint). null = arquivo inexistente. */
    public function read(string $relative): ?string
    {
        $absolute = $this->validate($relative);
        if (!is_file($absolute) || is_link($absolute)) {
            return null;
        }
        $real = realpath($absolute);
        if ($real === false || !$this->isContained($real)) {
            throw new PathEscapesRootException(sprintf('Leitura fora de content/: %s', $relative));
        }

        $bytes = file_get_contents($real);

        return $bytes === false ? null : $bytes;
    }

    public function exists(string $relative): bool
    {
        $absolute = $this->validate($relative);

        return is_file($absolute) && !is_link($absolute);
    }

    /** Lista recursiva de arquivos sob um subdiretório relativo (paths relativos). */
    public function listFiles(string $relativeDir): array
    {
        $base = $this->root . '/' . trim($relativeDir, '/');
        if (!is_dir($base)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && !$file->isLink()) {
                $found[] = ltrim(substr($file->getPathname(), strlen($this->root)), '/');
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Escrita atômica: tmp na mesma partição + rename + chmod explícito (§10.7).
     *
     * @throws PathEscapesRootException|SymlinkTargetException|\RuntimeException
     */
    public function writeAtomic(string $relative, string $bytes): void
    {
        $absolute = $this->resolveWritable($relative);

        $dir = dirname($absolute);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            throw new \RuntimeException(sprintf('Não foi possível criar o diretório: %s', $dir));
        }
        $this->chmod($dir, $this->dirMode());

        $tmp = tempnam($dir, '.cvsync-');
        if ($tmp === false) {
            throw new \RuntimeException(sprintf('tempnam falhou em: %s', $dir));
        }

        try {
            if (file_put_contents($tmp, $bytes, LOCK_EX) === false) {
                throw new \RuntimeException(sprintf('Escrita do tmp falhou: %s', $tmp));
            }
            $this->chmod($tmp, $this->fileMode());

            // O_NOFOLLOW equivalente IMEDIATAMENTE antes do rename (§6.4).
            if (is_link($absolute)) {
                throw new SymlinkTargetException(sprintf('Destino é symlink, escrita recusada: %s', $relative));
            }
            if (!rename($tmp, $absolute)) {
                throw new \RuntimeException(sprintf('rename atômico falhou: %s → %s', $tmp, $absolute));
            }
        } catch (\Throwable $e) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
            throw $e;
        }
    }

    /** Remoção contida (export de entidade deletada no banco, §5.5). */
    public function delete(string $relative): bool
    {
        $absolute = $this->resolveWritable($relative);
        if (!is_file($absolute)) {
            return false;
        }

        return unlink($absolute);
    }

    /**
     * Comparação byte-a-byte para idempotência estrita (§2.3.3): true quando o
     * arquivo existe e é idêntico ao conteúdo canônico — o exporter NÃO escreve.
     */
    public function matchesContents(string $relative, string $bytes): bool
    {
        $existing = $this->read($relative);

        return $existing !== null && hash_equals($existing, $bytes);
    }

    /** mtime para o pré-filtro da state table (nunca prova — §5). */
    public function mtime(string $relative): ?int
    {
        $absolute = $this->validate($relative);
        if (!is_file($absolute)) {
            return null;
        }
        $mtime = filemtime($absolute);

        return $mtime === false ? null : $mtime;
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /**
     * Valida segmentos e retorna o path absoluto (sem garantir contenção).
     *
     * @throws PathEscapesRootException
     */
    private function validate(string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, "\0")) {
            throw new PathEscapesRootException(sprintf('Path inválido: "%s"', $relative));
        }

        $segments = explode('/', $relative);
        $last = array_pop($segments);

        foreach ($segments as $segment) {
            if (preg_match(self::SLUG_PATTERN, $segment) !== 1) {
                throw new PathEscapesRootException(
                    sprintf('Segmento de diretório fora do padrão §6.4: "%s" em "%s"', $segment, $relative)
                );
            }
        }
        if ($last === null || preg_match(self::FILENAME_PATTERN, $last) !== 1) {
            throw new PathEscapesRootException(
                sprintf('Nome de arquivo fora do padrão §6.4: "%s"', $relative)
            );
        }

        return $this->root . '/' . implode('/', [...$segments, $last]);
    }

    private function isContained(string $realPath): bool
    {
        $root = $this->root;

        return $realPath === $root || str_starts_with($realPath, $root . '/');
    }

    private function fileMode(): int
    {
        return defined('CVSYNC_FILE_MODE') ? (int) constant('CVSYNC_FILE_MODE') : 0664;
    }

    private function dirMode(): int
    {
        return defined('CVSYNC_DIR_MODE') ? (int) constant('CVSYNC_DIR_MODE') : 0775;
    }

    private function chmod(string $path, int $mode): void
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
        chmod($path, $mode);
    }
}
