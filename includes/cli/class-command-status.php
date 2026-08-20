<?php
/**
 * `wp sync status` — estado do sync no ambiente (contrato §8.3). Exit 0
 * sempre (comando de observação).
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

use CVSync\Environment;
use CVSync\Storage\Schema;
use CVSync\Triggers;

defined('ABSPATH') || exit;

final class CommandStatus extends CommandBase
{
    public function __invoke(array $args, array $assocArgs): void
    {
        $this->warnInvalidConstants();

        global $wpdb;

        $policy = Environment::policy();
        $table  = Schema::table('state');

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela própria do plugin.
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A) ?: [];
        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[(string) $row['status']] = (int) $row['n'];
        }

        $head        = Triggers::readHead(Triggers::repoRoot());
        $lastApplied = $this->c->state->lastAppliedHead();

        $report = [
            'environment'       => Environment::current(),
            'policy'            => $policy,
            'conflict_winner'   => Environment::conflictWinner(),
            'deploy_gate'       => Environment::deployGate(),
            'schema_version'    => Schema::installedVersion(),
            'schema_required'   => Schema::SCHEMA_VERSION,
            'migration_pending' => Schema::needsMigration(),
            'content_dir'       => Environment::contentDir(),
            'content_writable'  => is_writable(Environment::contentDir()),
            'head'              => $head,
            'last_applied_head' => $lastApplied,
            'head_diverged'     => null !== $head && null !== $lastApplied && $head !== $lastApplied,
            'state_by_status'   => $byStatus,
            'snapshots'         => $this->c->snapshot->list(),
            'unresolved_conflicts' => count($this->c->conflicts->listUnresolved()),
        ];

        if ($this->isJson($assocArgs)) {
            $this->jsonLine($report);
        } else {
            \WP_CLI::log(sprintf('Ambiente: %s (apply_auto: %s, export_auto: %s, conflict_winner: %s, deploy_gate: %s, deleções: %s)', $report['environment'], $policy['apply_auto'] ? 'on' : 'OFF', $policy['export_auto'] ? 'on' : 'OFF', $report['conflict_winner'], $report['deploy_gate'], $policy['deletion']));
            \WP_CLI::log(sprintf('Schema: v%d (requerido v%d)%s', $report['schema_version'], $report['schema_required'], $report['migration_pending'] ? ' — MIGRATION PENDENTE' : ''));
            \WP_CLI::log(sprintf('HEAD: %s | último aplicado: %s%s', $head ?? '(sem .git)', $lastApplied ?? '(nenhum)', $report['head_diverged'] ? ' — DIVERGIDO (reconcile pendente)' : ''));
            foreach ($byStatus as $status => $n) {
                \WP_CLI::log(sprintf('  state[%s] = %d', $status, $n));
            }
            \WP_CLI::log(sprintf('Conflitos não resolvidos: %d | Snapshots: %d', $report['unresolved_conflicts'], count($report['snapshots'])));
        }

        \WP_CLI::halt(0);
    }
}
