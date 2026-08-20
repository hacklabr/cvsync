<?php
/**
 * `wp sync attachments gc --blobs|--files` — os dois GCs espelhados (§A.7.1),
 * delegados ao MediaGarbageCollector do P4.
 *
 *  --blobs  GC do repo: blob sem sidecar referenciando E sem linha não-
 *           tombstone na state. Remoção efetiva = commit HUMANO em PR próprio
 *           (`chore(media): gc`) — o plugin lista/prepara, nunca commita.
 *  --files  GC físico: arquivo sem _wp_attached_file correspondente em NENHUM
 *           post (guard cruzado incluindo não-versionados); nunca varre
 *           uploads/cvsync-backups/**.
 *
 * Dry-run por default; --execute aplica. --older-than=90d (180d recomendado
 * em prod). Exit 0.
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

defined('ABSPATH') || exit;

final class CommandAttachments extends CommandBase
{
    public function __invoke(array $args, array $assocArgs): void
    {
        $this->warnInvalidConstants();

        if ('gc' !== ($args[0] ?? '')) {
            \WP_CLI::error('Uso: wp sync attachments gc (--blobs|--files) [--execute] [--older-than=90d]');
        }
        if (null === $this->c->mediaGc) {
            \WP_CLI::error('Pacote de mídia (P4) indisponível.');
        }

        $blobs   = (bool) ($assocArgs['blobs'] ?? false);
        $files   = (bool) ($assocArgs['files'] ?? false);
        $execute = (bool) ($assocArgs['execute'] ?? false);
        $days    = $this->olderThanDays($assocArgs);

        if ($blobs === $files) {
            \WP_CLI::error('Escolha exatamente um modo: --blobs ou --files.');
        }

        $report = $blobs
            ? $this->c->mediaGc->collectBlobs(! $execute)
            : $this->c->mediaGc->collectFiles($days, ! $execute);

        if ($this->isJson($assocArgs)) {
            $this->jsonLine([
                'mode'       => $blobs ? 'blobs' : 'files',
                'dry_run'    => $report->dryRun,
                'candidates' => $report->candidates,
                'blocked'    => $report->blocked,
                'removed'    => $report->removed,
            ]);
        } else {
            foreach ($report->candidates as $candidate) {
                \WP_CLI::log('  coletável: ' . $candidate);
            }
            foreach ($report->blocked as $blocked) {
                \WP_CLI::log('  bloqueado por guard: ' . $blocked);
            }
            \WP_CLI::log(sprintf(
                'attachments gc --%s: %d candidato(s), %d bloqueado(s), %d removido(s)%s.',
                $blobs ? 'blobs' : 'files',
                count($report->candidates),
                count($report->blocked),
                $report->removed,
                $report->dryRun ? ' (dry-run — --execute para aplicar)' : ''
            ));
            if ($blobs && ! $report->dryRun && $report->removed > 0) {
                \WP_CLI::warning('Blobs removidos do working tree — a remoção efetiva é commit HUMANO em PR próprio (chore(media): gc). O plugin nunca commita.');
            }
        }

        \WP_CLI::halt(0);
    }
}
