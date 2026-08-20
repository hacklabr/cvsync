<?php
/**
 * `wp sync plan` — dry-run do plano completo (contrato §8.3). Log antes de
 * aplicar; usado no review e no pipeline. Exit 0/1 (1 = erro de plano, ex.:
 * migration pendente).
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

use CVSync\Environment;
use CVSync\ImportContext;

defined('ABSPATH') || exit;

final class CommandPlan extends CommandBase
{
    public function __invoke(array $args, array $assocArgs): void
    {
        $this->warnInvalidConstants();

        $ctx = new ImportContext(
            trigger: 'cli',
            environment: Environment::current(),
            dryRun: true,
        );

        $report = (new ApplyRunner($this->c))->run($ctx);

        if ($this->isJson($assocArgs)) {
            $this->jsonLine($report);
        } else {
            foreach ($report['items'] as $item) {
                if ('skip' === $item['decision']) {
                    continue; // o plano mostra trabalho, não convergência
                }
                \WP_CLI::log(sprintf(
                    '[%-18s] %-40s %s (%s)',
                    $item['decision'],
                    $item['entity'],
                    $item['path'] ?? '(sem arquivo)',
                    $item['reason']
                ));
            }
            \WP_CLI::log(sprintf(
                'Plano: %d a aplicar, %d a exportar, %d conflitos, %d pending-delete, %d purges.',
                $report['applied'],
                $report['exported'],
                $report['conflicts_db'],
                $report['pending_delete'],
                $report['purged_tombstones']
            ));
        }

        \WP_CLI::halt($report['failed'] > 0 ? 1 : 0);
    }
}
