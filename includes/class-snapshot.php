<?php
/**
 * Snapshot — rede de rollback nº 3 (spec §11.2 + §A.10.4): snapshot pré-apply
 * do estado convergente das entidades afetadas por um lote.
 *
 *  - Destino: wp-content/uploads/cvsync-backups/<ts>/ — FORA do repo;
 *  - Escrito EXCLUSIVAMENTE em SAPI CLI (credenciais do operador — o webserver
 *    nunca escreve artefatos de recuperação, §7.4/§11.2); guard estrutural;
 *  - Posts+meta SEMPRE (barato — forma canônica placeholderizada, mesmo
 *    pipeline do export); binários SOMENTE os ausentes do repo com o mesmo
 *    hash (teste O(1) via bin_hash da state × MediaStore::exists — §A.10.4);
 *  - Layout: <ts>/content/<layout normal> + <ts>/binaries/<uploads-rel> +
 *    <ts>/manifest.json;
 *  - Retenção: últimos N (CVSYNC_SNAPSHOT_KEEP, default 10) + teto de disco
 *    (CVSYNC_SNAPSHOT_MAX_BYTES, default 512 MB) com purge LRU — snapshot que
 *    enche o disco é incidente criado pelo backup (§A.10.4);
 *  - Nunca tocado pelo GC físico de mídia (exclusão já implementada no P4).
 *
 * @package CVSync
 */

declare(strict_types=1);

namespace CVSync;

use CVSync\Adapters\AdapterRegistry;
use CVSync\Engine\EntityRef;
use CVSync\Engine\Hasher;
use CVSync\Media\MediaStore;
use CVSync\Storage\StateStore;

defined('ABSPATH') || exit;

final class Snapshot
{
    /** Diretório base dos snapshots, relativo ao basedir de uploads. */
    public const BASE_DIR = 'cvsync-backups';

    public function __construct(
        private readonly AdapterRegistry $adapters,
        private readonly StateStore $state,
        private readonly PathGuard $contentPaths,
        private readonly ?MediaStore $mediaStore = null,
    ) {
    }

    /**
     * Cria o snapshot pré-apply das entidades afetadas.
     *
     * @param list<EntityRef> $refs
     * @return array{timestamp: string, dir: string, entities: int, binaries: int, bytes: int}
     * @throws \RuntimeException SAPI não-CLI (o webserver nunca escreve snapshots).
     */
    public function create(array $refs, ?string $gitHead = null): array
    {
        $this->assertCli();

        $ts  = gmdate('Ymd-His');
        $dir = $this->baseDir() . '/' . $ts;
        if (! wp_mkdir_p($dir . '/content') || ! wp_mkdir_p($dir . '/binaries')) {
            throw new \RuntimeException(sprintf('Não foi possível criar o diretório de snapshot: %s', $dir));
        }

        $guard     = new PathGuard($dir . '/content');
        $manifest  = [
            'created_at' => current_time('mysql'),
            'environment' => Environment::current(),
            'git_head'   => $gitHead,
            'entities'   => [],
            'binaries'   => [],
        ];
        $bytes = 0;

        foreach ($refs as $ref) {
            $adapter = $this->adapters->forRef($ref);
            if (null === $adapter) {
                continue;
            }

            try {
                $doc = $adapter->readCanonical($ref);
            } catch (\Throwable) {
                continue; // entidade ilegível não aborta o snapshot do lote
            }
            if (null === $doc) {
                continue; // ausente/não exportável — nada a preservar
            }

            $hash     = Hasher::hashDocument($doc, $adapter->keyOrder());
            $contents = $adapter->serializeDocument($doc, $hash);
            $relative = $adapter->relativePath($doc);

            try {
                $guard->writeAtomic($relative, $contents);
            } catch (\Throwable) {
                continue; // path inválido não aborta o snapshot
            }
            $bytes += strlen($contents);

            $manifest['entities'][] = [
                'tuple' => $ref->toTupleString(),
                'path'  => $relative,
                'hash'  => $hash,
            ];

            $copied = $this->snapshotBinary($ref, $dir, $doc->binHash);
            if (null !== $copied) {
                $manifest['binaries'][] = $copied;
                $bytes += $copied['bytes'];
            }
        }

        file_put_contents(
            $dir . '/manifest.json',
            (string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $this->enforceRetention();

        return [
            'timestamp' => $ts,
            'dir'       => $dir,
            'entities'  => count($manifest['entities']),
            'binaries'  => count($manifest['binaries']),
            'bytes'     => $bytes,
        ];
    }

    /**
     * Lista os snapshots disponíveis (mais novos primeiro).
     *
     * @return list<string> Timestamps (nome do diretório).
     */
    public function list(): array
    {
        $base = $this->baseDir();
        if (! is_dir($base)) {
            return [];
        }

        $found = [];
        foreach ((array) scandir($base) as $entry) {
            if (preg_match('/^\d{8}-\d{6}$/', $entry) === 1 && is_dir($base . '/' . $entry)) {
                $found[] = $entry;
            }
        }
        rsort($found);

        return $found;
    }

    /**
     * Resolve um snapshot para restore: valida timestamp + manifest e devolve
     * os paths. O re-apply no banco é feito pelo comando `wp sync restore`
     * (Importer com PathGuard apontado para o content/ do snapshot).
     *
     * @return array{dir: string, content_dir: string, manifest: array<string, mixed>}
     * @throws \RuntimeException Snapshot inexistente/inválido ou SAPI não-CLI.
     */
    public function resolve(string $timestamp): array
    {
        $this->assertCli();

        if (preg_match('/^\d{8}-\d{6}$/', $timestamp) !== 1) {
            throw new \RuntimeException(sprintf('Timestamp de snapshot inválido: %s', $timestamp));
        }

        $dir = $this->baseDir() . '/' . $timestamp;
        if (! is_dir($dir . '/content')) {
            throw new \RuntimeException(sprintf('Snapshot não encontrado: %s (disponíveis: %s)', $timestamp, implode(', ', $this->list())));
        }

        $manifest = [];
        $manifestFile = $dir . '/manifest.json';
        if (is_file($manifestFile)) {
            $decoded = json_decode((string) file_get_contents($manifestFile), true);
            $manifest = is_array($decoded) ? $decoded : [];
        }

        return [
            'dir'         => $dir,
            'content_dir' => $dir . '/content',
            'manifest'    => $manifest,
        ];
    }

    /**
     * Re-materializa os binários do snapshot em uploads/ — puramente aditivo
     * (só escreve onde o arquivo está ausente; nunca sobrescreve byte alheio,
     * mesma regra do self-heal §A.5.3). Binários presentes são recuperáveis
     * de graça via git e não foram copiados no snapshot.
     *
     * @return list<string> Paths relativos a uploads re-materializados.
     */
    public function restoreBinaries(string $timestamp): array
    {
        $resolved = $this->resolve($timestamp);
        $restored = [];
        $uploads  = (string) wp_upload_dir()['basedir'];

        foreach ((array) ($resolved['manifest']['binaries'] ?? []) as $entry) {
            $uploadsRel = (string) ($entry['uploads_path'] ?? '');
            if ('' === $uploadsRel || str_contains($uploadsRel, '..')) {
                continue;
            }
            $source = $resolved['dir'] . '/binaries/' . $uploadsRel;
            $target = $uploads . '/' . $uploadsRel;
            if (! is_file($source) || is_file($target) || is_link($target)) {
                continue; // aditivo: nunca sobrescreve
            }
            if (! wp_mkdir_p(dirname($target))) {
                continue;
            }
            if (copy($source, $target)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
                chmod($target, (int) Environment::constant('CVSYNC_FILE_MODE'));
                $restored[] = $uploadsRel;
            }
        }

        return $restored;
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /**
     * Copia o binário de um attachment SOMENTE se ausente do repo com o mesmo
     * hash (§A.10.4): o teste é O(1) — bin_hash da state × MediaStore::exists.
     * Binário presente no repo é recuperável de graça via git.
     *
     * @return array{uploads_path: string, bytes: int}|null
     */
    private function snapshotBinary(EntityRef $ref, string $dir, ?string $binHash): ?array
    {
        if ('attachment' !== $ref->postType || null === $binHash || null === $this->mediaStore) {
            return null;
        }

        $record = $this->state->get($ref);
        if (null === $record || null === $record->dbId) {
            return null;
        }

        $uploadsRel = (string) get_post_meta($record->dbId, '_wp_attached_file', true);
        if ('' === $uploadsRel) {
            return null;
        }

        $ext = strtolower(pathinfo($uploadsRel, PATHINFO_EXTENSION));
        $hex = str_starts_with($binHash, Hasher::PREFIX) ? substr($binHash, strlen(Hasher::PREFIX)) : $binHash;

        // O(1): blob CAS presente no repo com o mesmo hash → recuperável via git.
        if ('' !== $ext && $this->mediaStore->exists($hex, $ext)) {
            return null;
        }

        $source = (string) wp_upload_dir()['basedir'] . '/' . $uploadsRel;
        if (! is_file($source) || is_link($source)) {
            return null;
        }

        $target = $dir . '/binaries/' . $uploadsRel;
        if (! wp_mkdir_p(dirname($target)) || ! copy($source, $target)) {
            return null;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
        chmod($target, (int) Environment::constant('CVSYNC_FILE_MODE'));

        return ['uploads_path' => $uploadsRel, 'bytes' => (int) filesize($target)];
    }

    /**
     * Retenção (§11.2/§A.10.4): últimos N snapshots + teto de disco com purge
     * LRU (mais antigos primeiro até caber no teto).
     */
    private function enforceRetention(): void
    {
        $keep      = (int) Environment::constant('CVSYNC_SNAPSHOT_KEEP');
        $maxBytes  = (int) Environment::constant('CVSYNC_SNAPSHOT_MAX_BYTES');
        $snapshots = $this->list(); // mais novos primeiro

        // Retenção por contagem.
        foreach (array_slice($snapshots, $keep) as $old) {
            $this->removeTree($this->baseDir() . '/' . $old);
        }

        // Teto de disco com purge LRU.
        $snapshots = array_slice($snapshots, 0, $keep);
        $sizes     = [];
        $total     = 0;
        foreach ($snapshots as $ts) {
            $size       = $this->treeSize($this->baseDir() . '/' . $ts);
            $sizes[$ts] = $size;
            $total     += $size;
        }

        foreach (array_reverse($snapshots) as $oldest) { // LRU: apaga do mais antigo
            if ($total <= $maxBytes) {
                break;
            }
            $this->removeTree($this->baseDir() . '/' . $oldest);
            $total -= $sizes[$oldest];
        }
    }

    private function baseDir(): string
    {
        return (string) wp_upload_dir()['basedir'] . '/' . self::BASE_DIR;
    }

    private function treeSize(string $dir): int
    {
        if (! is_dir($dir)) {
            return 0;
        }
        $total = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $total += $file->getSize();
            }
        }

        return $total;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir) || ! str_contains($dir, '/' . self::BASE_DIR . '/')) {
            return; // contenção: só remove dentro do diretório de snapshots
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

    /**
     * Snapshots são artefatos de recuperação escritos com credenciais do
     * operador (§7.4/§11.2) — nunca pelo webserver.
     *
     * @throws \RuntimeException
     */
    private function assertCli(): void
    {
        if (php_sapi_name() !== 'cli') {
            throw new \RuntimeException('Snapshots só podem ser escritos/restaurados via WP-CLI (§11.2).');
        }
    }
}
