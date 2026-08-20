<?php
/**
 * MediaStore — CAS de blobs (§A.3) + integridade na leitura (§A.5.1.7).
 *
 *  - Path do blob = função do hash: 'media/bin/{2hex}/{sha256}.{ext}' — fanout
 *    de 1 nível, imutável por construção; o filename original NUNCA entra no
 *    path do repo (§A.3.1 — "paths de repositório são estrutura gerada");
 *  - O blob NÃO é entidade na state table — é payload (refcount derivado);
 *  - Hash NA ESCRITA: SHA-256 computado via hash_update_stream() durante a
 *    própria cópia streaming (custo marginal zero, §A.4.1) — o Hasher do P1
 *    nunca vê bytes (desvio 5 do P1-r2: sem hashFileContents; nativo aqui);
 *  - Tríplice igualdade (§A.5.1.7): nome_do_arquivo == sidecar.blob ==
 *    sha256 recomputado NA MESMA PASSADA de stream; mismatch →
 *    BinaryHashMismatchException e NADA é materializado;
 *  - Detecção de pointer LFS antes da tríplice igualdade (§A.9.4);
 *  - Teto único CVSYNC_ATTACHMENT_MAX_BYTES (default 10 MB, §A.5.4) —
 *    acima → OversizedException (export reporta skipped-oversized).
 *
 * @package CVSync\Media
 */

declare(strict_types=1);

namespace CVSync\Media;

use CVSync\PathGuard;

defined('ABSPATH') || exit;

/** Blob acima do teto §A.5.4 (definição de escopo, não erro de integridade). */
class OversizedException extends \RuntimeException
{
}

/** Tríplice igualdade violada (§A.5.1.7) — nunca materializar. */
class BinaryHashMismatchException extends \RuntimeException
{
}

/** Pointer Git LFS detectado (§A.9.4) — mensagem dedicada e acionável. */
class LfsPointerException extends \RuntimeException
{
}

/** Resultado de uma escrita CAS. */
final readonly class StoredBlob
{
    public function __construct(
        public string $sha256Hex,   // 64 hex minúsculo, sem prefixo
        public int $size,
        public string $relativePath, // 'media/bin/{2hex}/{hash}.{ext}'
        public bool $alreadyPresent, // dedup CAS: mesmo hash → zero cópia
    ) {
    }
}

final class MediaStore
{
    private const LFS_PREFIX = 'version https://git-lfs.github.com/spec/v1';
    private const CHUNK = 1048576; // 1 MB por leitura de stream

    public function __construct(private readonly PathGuard $paths)
    {
    }

    /** Path relativo do blob CAS: 'media/bin/{2hex}/{sha256hex}.{ext}'. */
    public function blobPath(string $sha256Hex, string $ext): string
    {
        $hex = strtolower($sha256Hex);

        return 'media/bin/' . substr($hex, 0, 2) . '/' . $hex . '.' . $ext;
    }

    public function exists(string $sha256Hex, string $ext): bool
    {
        return $this->paths->exists($this->blobPath($sha256Hex, $ext));
    }

    public function size(string $sha256Hex, string $ext): ?int
    {
        $absolute = $this->paths->contentDir() . '/' . $this->blobPath($sha256Hex, $ext);

        return is_file($absolute) ? (int) filesize($absolute) : null;
    }

    /**
     * Export uploads→repo: cópia STREAMING computando o SHA-256 na mesma
     * passada (hash_update_stream — §A.4.1 "hash na escrita"), rename atômico
     * no path CAS. Dedup: blob já presente com o mesmo hash → zero cópia.
     * Isento da cadeia de validação (§A.5.1, declaração final — os bytes já
     * passaram pela validação de upload do WP na origem).
     *
     * @throws OversizedException Acima de CVSYNC_ATTACHMENT_MAX_BYTES (§A.5.4).
     * @throws \RuntimeException  Falha de I/O.
     */
    public function storeFromUploads(string $absSourcePath, string $ext): StoredBlob
    {
        if (!is_file($absSourcePath) || !is_readable($absSourcePath)) {
            throw new \RuntimeException(sprintf('Binário de origem ilegível: %s', $absSourcePath));
        }

        $size = (int) filesize($absSourcePath);
        $max = $this->maxBytes();
        if ($size > $max) {
            throw new OversizedException(
                sprintf('Blob de %d bytes acima do teto de %d bytes (§A.5.4 — skipped-oversized).', $size, $max)
            );
        }

        $ctx = hash_init('sha256');
        $mediaDir = $this->paths->contentDir() . '/media';
        if (!is_dir($mediaDir) && !wp_mkdir_p($mediaDir)) {
            throw new \RuntimeException('Não foi possível criar o diretório CAS.');
        }
        $tmp = tempnam($mediaDir, '.cvsync-blob-');
        if ($tmp === false) {
            throw new \RuntimeException('tempnam falhou no diretório CAS.');
        }

        try {
            $in = fopen($absSourcePath, 'rb');
            $out = fopen($tmp, 'wb');
            if ($in === false || $out === false) {
                throw new \RuntimeException('Falha ao abrir streams do blob.');
            }
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if ($chunk === false) {
                    throw new \RuntimeException('Falha de leitura no streaming do blob.');
                }
                hash_update($ctx, $chunk);
                fwrite($out, $chunk);
            }
            fclose($in);
            fclose($out);

            $hex = hash_final($ctx);
            $relative = $this->blobPath($hex, $ext);
            $target = $this->paths->contentDir() . '/' . $relative;

            if (is_file($target) && (int) filesize($target) === $size) {
                unlink($tmp); // dedup CAS (§A.3.1): mesmo hash → zero cópia

                return new StoredBlob($hex, $size, $relative, true);
            }

            // Contenção §6.4 + symlink check; rename cross-dir na MESMA partição
            // (tmp em media/, alvo em media/bin/xx/) permanece atômico.
            $this->paths->resolveWritable($relative);
            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !wp_mkdir_p($targetDir)) {
                throw new \RuntimeException(sprintf('Não foi possível criar %s', $targetDir));
            }
            if (is_link($target)) {
                throw new \RuntimeException(sprintf('Destino CAS é symlink: %s', $relative));
            }
            if (!rename($tmp, $target)) {
                throw new \RuntimeException(sprintf('rename CAS falhou: %s → %s', $tmp, $target));
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
            chmod($target, (int) (\CVSync\Environment::constant('CVSYNC_FILE_MODE') ?? 0664));

            return new StoredBlob($hex, $size, $relative, false);
        } catch (\Throwable $e) {
            if (is_string($tmp) && file_exists($tmp)) {
                unlink($tmp);
            }
            throw $e;
        }
    }

    /**
     * Leitura repo→tmp com TRÍPLICE IGUALDADE (§A.5.1.7): nome do arquivo ==
     * sha256 recomputado na mesma passada de stream == esperado pelo caller
     * (sidecar.blob; e bin_hash do state, quando aplicável — verificado pelo
     * Materializer). Detecção de pointer LFS ANTES (§A.9.4). Mismatch →
     * exceção; NADA é materializado em uploads.
     *
     * @param string $tmpPath Path ABSOLUTO do tmp de destino (uploads ou sys).
     *
     * @throws LfsPointerException|BinaryHashMismatchException|\RuntimeException
     */
    public function readVerified(string $sha256Hex, string $ext, string $tmpPath): int
    {
        $relative = $this->blobPath($sha256Hex, $ext);
        $source = $this->paths->contentDir() . '/' . $relative;
        if (!is_file($source) || is_link($source)) {
            throw new \RuntimeException(sprintf('Blob CAS ausente: %s (restore: git checkout <commit> -- content/media/bin/)', $relative));
        }

        $in = fopen($source, 'rb');
        $out = fopen($tmpPath, 'wb');
        if ($in === false || $out === false) {
            throw new \RuntimeException('Falha ao abrir streams da leitura verificada.');
        }

        $ctx = hash_init('sha256');
        $size = 0;
        $firstChunk = true;

        try {
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if ($chunk === false) {
                    throw new \RuntimeException('Falha de leitura no blob CAS.');
                }
                if ($firstChunk) {
                    $this->assertNotLfsPointer($chunk);
                    $firstChunk = false;
                }
                hash_update($ctx, $chunk);
                fwrite($out, $chunk);
                $size += strlen($chunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        $recomputed = hash_final($ctx);
        if (!hash_equals(strtolower($sha256Hex), $recomputed)) {
            unlink($tmpPath); // nunca deixa bytes não-verificados para o sideload
            throw new BinaryHashMismatchException(
                sprintf('binary-hash-mismatch: esperado %s, recomputado %s (%s).', $sha256Hex, $recomputed, $relative)
            );
        }

        return $size;
    }

    /**
     * SHA-256 streaming SEM escrita (hash_update_stream puro) — caminho de
     * leitura canônica (plan/verify/dry-run/snapshot): leitura nunca grava no
     * repo (r8, finding 🟡3). O export efetivo persiste via storeFromUploads.
     */
    public function hashOnly(string $absSourcePath): string
    {
        if (!is_file($absSourcePath) || !is_readable($absSourcePath)) {
            throw new \RuntimeException(sprintf('Binário ilegível para hash: %s', $absSourcePath));
        }

        $ctx = hash_init('sha256');
        $in = fopen($absSourcePath, 'rb');
        if ($in === false) {
            throw new \RuntimeException(sprintf('Falha ao abrir stream: %s', $absSourcePath));
        }
        hash_update_stream($ctx, $in);
        fclose($in);

        return hash_final($ctx);
    }

    /** Pointer LFS nos primeiros bytes (§A.9.4 — diagnóstico dedicado). */
    private function assertNotLfsPointer(string $firstChunk): void
    {
        if (str_starts_with($firstChunk, self::LFS_PREFIX)) {
            throw new LfsPointerException(
                'lfs-pointer-detected: o blob é um pointer Git LFS, não o binário. LFS não é suportado na v1 (§A.9.4) — faça checkout com os objetos LFS materializados ou remova o filtro LFS.'
            );
        }
    }

    private function maxBytes(): int
    {
        // Leitura única via Environment (validador + warning §10.1 — r8, 🟡9).
        return (int) (\CVSync\Environment::constant('CVSYNC_ATTACHMENT_MAX_BYTES') ?? 10 * 1024 * 1024);
    }
}
