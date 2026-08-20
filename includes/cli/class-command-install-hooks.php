<?php
/**
 * `wp sync install-hooks` — instala os git hooks versionados via
 * core.hooksPath (§8.2, §12). O CONTEÚDO dos hooks (`.githooks/`) é do
 * git-workflow-master; este comando apenas aponta o git para o diretório.
 *
 * Binário git permitido aqui: SAPI CLI (§5.7 — a proibição é do runtime web).
 * Exit: 0 sucesso; 1 sem git/.githooks ou falha do git config.
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

use CVSync\Triggers;

defined('ABSPATH') || exit;

final class CommandInstallHooks extends CommandBase
{
    public function __invoke(array $args, array $assocArgs): void
    {
        $repoRoot  = Triggers::repoRoot();
        $hooksDir  = $repoRoot . '/.githooks';

        if (! is_dir($repoRoot . '/.git') && ! is_file($repoRoot . '/.git')) {
            \WP_CLI::error(sprintf('Sem .git em %s — hooks só se aplicam a checkouts (artefato deployado não tem hooks).', $repoRoot));
        }
        if (! is_dir($hooksDir)) {
            \WP_CLI::error('.githooks/ ausente na raiz do repo (pacote do git-workflow-master) — nada a instalar.');
        }

        $output  = [];
        $exitCode = 1;
        $cmd     = sprintf(
            'cd %s && GIT_OPTIONAL_LOCKS=0 git config core.hooksPath .githooks 2>&1',
            escapeshellarg($repoRoot)
        );
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- SAPI CLI, binário git permitido (§5.7).
        exec($cmd, $output, $exitCode);

        if (0 !== $exitCode) {
            \WP_CLI::error('git config core.hooksPath falhou: ' . implode("\n", $output));
        }

        \WP_CLI::success(sprintf('core.hooksPath → .githooks (%s). Hooks ativos no próximo git checkout/merge.', $repoRoot));
        \WP_CLI::halt(0);
    }
}
