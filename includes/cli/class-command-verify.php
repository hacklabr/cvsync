<?php
/**
 * `wp sync verify` — recalcula hashes dos dois lados × state (contrato §8.3,
 * §11.1, §A.4.3, §A.9.2, §A.10.5).
 *
 * Relatório por entidade: ok | drift-db | drift-file | orphan | pending_ref |
 * conflict | missing_binary | oversized-untracked. Seções agregadas:
 * tree-hash por tipo (§11.1), drift-external (otimizadores — §A.10.5, exit 0)
 * e security: uploads-php-exec (sonda do P4 — §A.9.2).
 *
 * Flags: --format=json, --deep (re-hash de blobs §A.4.3 — única varredura de
 * disco em massa, sob demanda explícita).
 *
 * A computação vive em VerifyRunner (compartilhada com o botão "Verificar
 * agora" do painel — mesmo caminho, sem WP_CLI); esta classe é o shell CLI
 * (render + exit codes).
 *
 * Exit: ≠ 0 em divergência (apto para CI/pós-deploy). FAIL da sonda → exit
 * ≠ 0; INDETERMINADO da sonda → warning, exit 0 (§A.9.2 — nunca travar
 * operação por não-verificabilidade).
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

use CVSync\Media\PhpExecProbe;

defined('ABSPATH') || exit;

final class CommandVerify extends CommandBase
{
    public function __invoke(array $args, array $assocArgs): void
    {
        $this->warnInvalidConstants();

        $deep = (bool) ($assocArgs['deep'] ?? false);

        $result = (new VerifyRunner($this->c))->compute($deep);
        $report = $result['report'];

        if ($this->isJson($assocArgs)) {
            $this->jsonLine($report);
        } else {
            $this->render($report, $result['divergent']);
        }

        $probe = $report['security']['uploads-php-exec'];

        if ($result['security_fail']) {
            \WP_CLI::error('SECURITY: uploads-php-exec FAIL — ' . $probe['detail']); // exit ≠ 0 (§A.9.2)
        }
        if (PhpExecProbe::INDETERMINATE === $probe['status']) {
            \WP_CLI::warning('uploads-php-exec INDETERMINADO — ' . $probe['detail']); // exit 0 (§A.9.2)
        }

        \WP_CLI::halt($result['divergent'] > 0 ? 1 : 0);
    }

    private function render(array $report, int $divergent): void
    {
        foreach ($report['items'] as $item) {
            \WP_CLI::log(sprintf('[%-20s] %-45s %s', $item['status'], $item['entity'], $item['detail']));
        }
        foreach ($report['tree_hashes'] as $postType => $hash) {
            \WP_CLI::log(sprintf('tree-hash[%s] = %s', $postType, $hash));
        }
        \WP_CLI::log(sprintf(
            'verify: %s (environment: %s, schema: v%d)',
            $divergent > 0 ? sprintf('%d divergência(s)', $divergent) : 'convergente',
            $report['environment'],
            $report['schema_version']
        ));
    }
}
