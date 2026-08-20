<?php
/**
 * `wp sync purge-revisions --older-than=90d` — contenção de volume de
 * revisions (§10.3: revisions SEMPRE no import; a contenção é purge
 * documentado, nunca supressão).
 *
 * Segurança: NUNCA remove a revision mais recente de cada post (rede
 * self-service da UI) e nunca toca posts que não são revisions.
 *
 * Exit 0 sempre (housekeeping; falhas individuais são warnings).
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

defined('ABSPATH') || exit;

final class CommandPurgeRevisions extends CommandBase
{
    public function __invoke(array $args, array $assocArgs): void
    {
        $days   = $this->olderThanDays($assocArgs);
        $cutoff = (new \DateTimeImmutable('now', wp_timezone()))->modify("-{$days} days")->format('Y-m-d H:i:s');

        global $wpdb;

        // Revisions antigas com pelo menos uma revision MAIS NOVA no mesmo pai
        // (a mais recente de cada post é intocável — rede self-service §7.4.1).
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT r.ID FROM {$wpdb->posts} r
                 WHERE r.post_type = 'revision' AND r.post_date < %s
                   AND EXISTS (
                       SELECT 1 FROM {$wpdb->posts} newer
                       WHERE newer.post_type = 'revision'
                         AND newer.post_parent = r.post_parent
                         AND newer.post_date > r.post_date
                   )
                 ORDER BY r.ID ASC",
                $cutoff
            )
        ) ?: [];

        $removed = 0;
        foreach ($ids as $id) {
            $deleted = wp_delete_post_revision((int) $id);
            if (false !== $deleted && null !== $deleted) {
                $removed++;
            } else {
                \WP_CLI::warning(sprintf('Falha ao remover revision #%d.', (int) $id));
            }
        }

        \WP_CLI::log(sprintf('purge-revisions: %d revision(s) anteriores a %s removida(s) (a mais recente de cada post é sempre preservada).', $removed, $cutoff));
        \WP_CLI::halt(0);
    }
}
