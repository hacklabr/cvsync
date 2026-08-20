<?php
/**
 * MediaGarbageCollector — os dois GCs espelhados (§A.7.1).
 *
 * REGRA DE OURO (§A.7): o plugin nunca deleta bytes — deleção de entidade é
 * operação de metadado; remoção de bytes é operação HUMANA separada. Ambos os
 * GCs são dry-run por default; a remoção efetiva de blobs do repo é commit
 * humano em PR próprio (o plugin lista/prepara, nunca commita). Única exceção
 * à regra: a compensação do dual-write (§A.5.2.3) — NÃO vive aqui.
 *
 *  - collectBlobs (repo): blob sem nenhum sidecar referenciando E sem linha
 *    não-tombstone na state (idx_binhash); tombstone dentro do TTL bloqueia
 *    (janela anti-ressurreição);
 *  - collectFiles (uploads): arquivo sem _wp_attached_file correspondente em
 *    NENHUM post (guard de referência cruzada em TODA a biblioteca, incluindo
 *    não-versionados); --older-than (default 90d, alinhado ao TTL); NUNCA
 *    varre nem toca uploads/cvsync-backups/**.
 *
 * Nenhum dos dois toca a state table nem exige refcount armazenado.
 *
 * @package CVSync\Media
 */

declare(strict_types=1);

namespace CVSync\Media;

use CVSync\PathGuard;
use CVSync\Storage\EntityStatus;
use CVSync\Storage\StateStore;

defined('ABSPATH') || exit;

/** Relatório de um GC (dry-run ou efetivo). */
final readonly class GcReport
{
    /**
     * @param list<string> $candidates Paths relativos coletáveis.
     * @param list<string> $blocked    Bloqueados por guard (tombstone TTL / referência cruzada).
     */
    public function __construct(
        public array $candidates,
        public array $blocked,
        public bool $dryRun,
        public int $removed = 0,
    ) {
    }
}

final class MediaGarbageCollector
{
    private const DEFAULT_TTL_DAYS = 90;

    public function __construct(
        private readonly StateStore $state,
        private readonly PathGuard $contentPaths,
    ) {
    }

    /**
     * GC do repo (§A.7.1 — `wp sync attachments gc --blobs`). Coleta blob sem
     * sidecar referenciando E sem linha não-tombstone na state. Dry-run por
     * default; a remoção efetiva é para uso interativo — o commit é humano.
     */
    public function collectBlobs(bool $dryRun = true): GcReport
    {
        // 1. Hashes referenciados por sidecars (scan do diretório plano media/).
        $referenced = [];
        foreach ($this->contentPaths->listFiles('media') as $relative) {
            if (!str_ends_with($relative, '.attachment.yml') || str_contains($relative, '/bin/')) {
                continue;
            }
            $raw = $this->contentPaths->read($relative);
            if ($raw === null) {
                continue;
            }
            try {
                $referenced[Sidecar::fromYaml($raw)->blobHex()] = true;
            } catch (\Throwable) {
                $referenced[basename($relative)] = true; // sidecar ilegível → NUNCA coletar por suspeita
            }
        }

        // 2. Blobs vivos na state (idx_binhash) — inclui tombstones RECENTES
        //    (TTL bloqueia: janela anti-ressurreição). TTL via Environment (🔵4 r7).
        $tombstoneCutoff = (new \DateTimeImmutable('now', wp_timezone()))
            ->modify('-' . (int) (\CVSync\Environment::constant('CVSYNC_TOMBSTONE_TTL_DAYS') ?? self::DEFAULT_TTL_DAYS) . ' days');

        $candidates = [];
        $blocked = [];
        $removed = 0;

        foreach ($this->contentPaths->listFiles('media/bin') as $relative) {
            $hex = pathinfo(basename($relative), PATHINFO_FILENAME);
            if (preg_match('/^[0-9a-f]{64}$/', $hex) !== 1) {
                continue; // não é blob CAS — nunca tocado
            }

            if (isset($referenced[$hex])) {
                continue;
            }

            $rows = $this->state->findByBinHash($hex);
            $hasLiveRow = false;
            $hasRecentTombstone = false;
            foreach ($rows as $row) {
                if ($row->status !== EntityStatus::Tombstone) {
                    $hasLiveRow = true;
                } elseif ($row->tombstoneAt !== null && $row->tombstoneAt > $tombstoneCutoff) {
                    $hasRecentTombstone = true;
                }
            }
            if ($hasLiveRow || $hasRecentTombstone) {
                $blocked[] = $relative;
                continue;
            }

            $candidates[] = $relative;
            if (!$dryRun) {
                if ($this->contentPaths->delete($relative)) {
                    $removed++;
                }
            }
        }

        return new GcReport($candidates, $blocked, $dryRun, $removed);
    }

    /**
     * GC físico (§A.7.1 — `wp sync attachments gc --files`). Guard de
     * referência cruzada em TODA a biblioteca (incluindo não-versionados) —
     * sem este guard o GC recria o desastre da deleção física. Nunca varre
     * uploads/cvsync-backups/** (snapshots têm retenção própria).
     *
     * Guard POSITIVO (r8, finding 🔴1): são referenciados — e NUNCA coletados —
     *  (a) todo valor de `_wp_attached_file` (originais e `-scaled` ativos);
     *  (b) todo arquivo de `_wp_attachment_metadata`: `sizes[*].file`
     *     (thumbnails intermediários, relativos ao dir do attached file) e
     *     `original_image` (o original não-escalado de imagens `-scaled` — os
     *     bytes que o próprio plugin trata como canônicos, §A.5.2.7);
     *  (c) `file` do próprio metadata (redundância barata).
     * Regra de ouro §A.7: na dúvida, NÃO coleta.
     */
    public function collectFiles(?int $olderThanDays = null, bool $dryRun = true): GcReport
    {
        global $wpdb;

        $olderThanDays ??= (int) (\CVSync\Environment::constant('CVSYNC_TOMBSTONE_TTL_DAYS') ?? self::DEFAULT_TTL_DAYS);

        $uploads = wp_upload_dir();
        $baseDir = (string) $uploads['basedir'];
        $cutoff = time() - ($olderThanDays * DAY_IN_SECONDS);

        $referenced = $this->crossReferenceGuard();

        $candidates = [];
        $removed = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }
            $pathname = $file->getPathname();
            $relative = ltrim(substr($pathname, strlen($baseDir)), '/');

            if (str_starts_with($relative, 'cvsync-backups/')) {
                continue; // snapshots: retenção própria — NUNCA tocados (§A.7.1)
            }
            if (isset($referenced[$relative])) {
                continue; // referenciado por ALGUM post (versionado ou não)
            }
            if ($file->getMTime() > $cutoff) {
                continue; // guard temporal
            }

            $candidates[] = $relative;
            if (!$dryRun && unlink($pathname)) {
                $removed++;
            }
        }

        return new GcReport($candidates, [], $dryRun, $removed);
    }

    /**
     * Conjunto de paths relativos (a partir do basedir de uploads)
     * referenciados por QUALQUER post da biblioteca — originais, `-scaled`
     * ativos, thumbnails e originais não-escalados (finding 🔴1 do r7).
     *
     * @return array<string,true>
     */
    private function crossReferenceGuard(): array
    {
        global $wpdb;

        $referenced = [];

        // (a) Originais / -scaled ativos.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guard de segurança exige varredura completa da meta key; sem API pública equivalente.
        $attached = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
                '_wp_attached_file'
            ),
            ARRAY_A
        ) ?: [];

        $dirByPost = [];
        foreach ($attached as $row) {
            $rel = (string) $row['meta_value'];
            if ($rel === '') {
                continue;
            }
            $referenced[$rel] = true;
            $dirByPost[(int) $row['post_id']] = self::relativeDir($rel);
        }

        // (b) Derivados do metadata: thumbnails (sizes[].file) + original_image.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idem: guard positivo exige o metadata de toda a biblioteca.
        $metas = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
                '_wp_attachment_metadata'
            ),
            ARRAY_A
        ) ?: [];

        foreach ($metas as $row) {
            $meta = maybe_unserialize((string) $row['meta_value']);
            if (!is_array($meta)) {
                continue; // metadata ilegível → NÃO libera nada deste post (na dúvida, não coleta)
            }
            $postId = (int) $row['post_id'];
            $dir = $dirByPost[$postId]
                ?? (isset($meta['file']) && is_string($meta['file']) ? self::relativeDir($meta['file']) : '');

            if (isset($meta['file']) && is_string($meta['file']) && $meta['file'] !== '') {
                $referenced[$meta['file']] = true; // (c) redundância barata
            }
            if (isset($meta['original_image']) && is_string($meta['original_image']) && $meta['original_image'] !== '') {
                $referenced[$dir . $meta['original_image']] = true; // original não-escalado (§A.5.2.7)
            }
            if (isset($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $size) {
                    if (is_array($size) && isset($size['file']) && is_string($size['file']) && $size['file'] !== '') {
                        $referenced[$dir . $size['file']] = true; // thumbnail intermediário
                    }
                }
            }
        }

        return $referenced;
    }

    /**
     * Dir relativo (ao basedir de uploads) normalizado: '2026/08/' quando
     * yearmonth on, '' quando flat (uploads_use_yearmonth_folders off).
     * `trailingslashit(dirname('foto.jpg'))` produz './' — que NUNCA casa o
     * candidato flat 'foto-150x150.jpg' (R2 do r9: thumbnails e original_image
     * voltavam a ser coletáveis em modo flat).
     */
    private static function relativeDir(string $relativePath): string
    {
        $dir = dirname($relativePath);

        return $dir === '.' || $dir === '' ? '' : trailingslashit($dir);
    }
}
