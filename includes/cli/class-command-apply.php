<?php
/**
 * `wp sync apply` — reconcilia arquivo → banco (contrato §8.3).
 *
 * Flags: --dry-run, --force, --force-locks (interativo), --delete,
 * --force-delete, --format=json.
 *
 * Exit codes (§8.3):
 *  0 — sucesso (inclui conflitos auto-resolvidos: NUNCA sobem exit code);
 *  1 — failed > 0 (falha o deploy) ou recusa de ambiente/lock;
 *  2 — CVSYNC_DEPLOY_GATE=halt + conflito auto-resolvido no lote;
 *  3 — migration de schema pendente (fail-closed §5.9): recusa com destaque
 *       e ação prescritiva, SEM resumo — "reative o plugin ou rode a
 *       migration no pipeline".
 *
 * skipped-locked (§8.4) conta como failed para o exit code (a entidade
 * permanece divergente no state — retry natural no próximo checkpoint); em
 * deploy, um lote com entidades travadas NÃO é convergente.
 *
 * Mensagem pós-resolução §7.5 emitida verbatim para cada conflito com
 * winner=db (o working tree diverge do HEAD após o re-export).
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

use CVSync\Environment;
use CVSync\ImportContext;

defined('ABSPATH') || exit;

final class CommandApply extends CommandBase
{
    public function __invoke(array $args, array $assocArgs): void
    {
        // Migração pendente = PRIMEIRA linha da saída, como ERRO (exit 3),
        // antes de qualquer warning/resumo (fix ibiomas: o resumo "failed 1"
        // no fim era lido como "apply não faz nada").
        $this->refuseIfMigrationPending();

        $this->warnInvalidConstants();

        $dryRun     = (bool) ($assocArgs['dry-run'] ?? false);
        $force      = (bool) ($assocArgs['force'] ?? false);
        $forceLocks = (bool) ($assocArgs['force-locks'] ?? false);
        $delete     = (bool) ($assocArgs['delete'] ?? false);
        $forceDel   = (bool) ($assocArgs['force-delete'] ?? false);
        $json       = $this->isJson($assocArgs);

        // Matriz §7.3: prod fail-closed (triplo fator); --force-locks só com TTY.
        if (! $dryRun) {
            if (null !== ($refusal = $this->mutationRefusal($force))) {
                \WP_CLI::error($refusal); // exit 1
            }
            if (null !== ($refusal = $this->forceLocksRefusal($forceLocks))) {
                \WP_CLI::error($refusal); // exit 1
            }
        }

        $ctx = new ImportContext(
            trigger: $this->detectTrigger(),
            environment: Environment::current(),
            dryRun: $dryRun,
            forceLocks: $forceLocks,
            allowDelete: $delete || $forceDel,
        );

        $report = (new ApplyRunner($this->c))->run($ctx, $forceDel);

        // Corrida mid-run (migration pendeu entre o gate e o lote): mesma
        // recusa com destaque, exit 3 — SEM resumo antes.
        if (is_array($report['migration_pending'] ?? false)) {
            $this->refuseIfMigrationPending(); // needsMigration() agora true → erro exit 3
        }

        if ($json) {
            $this->jsonLine($report);
        } else {
            $this->render($report);
        }

        // Exit codes do contrato §8.3.
        $conflicts = $report['conflicts_db'] + $report['conflicts_file'];
        if ($report['failed'] > 0 || $report['skipped_locked'] > 0) {
            \WP_CLI::halt(1);
        }
        if ($conflicts > 0 && 'halt' === Environment::deployGate()) {
            \WP_CLI::halt(2);
        }
        \WP_CLI::halt(0);
    }

    /** Render texto (humano) + mensagem pós-resolução §7.5 verbatim. */
    private function render(array $report): void
    {
        foreach ($report['conflicts'] as $conflict) {
            $this->postResolutionMessage($conflict);
        }
        foreach ($report['errors'] as $error) {
            \WP_CLI::warning($error);
        }

        \WP_CLI::log(sprintf(
            'Applied %d, exported %d, skipped %d, skipped-locked %d, deleted %d, pending-delete %d, pending_ref %d, conflicts auto-resolved %d (db: %d, file: %d), failed %d',
            $report['applied'],
            $report['exported'],
            $report['skipped'],
            $report['skipped_locked'],
            $report['deleted'],
            $report['pending_delete'],
            $report['pending_ref'],
            $report['conflicts_db'] + $report['conflicts_file'],
            $report['conflicts_db'],
            $report['conflicts_file'],
            $report['failed']
        ));

        if ($report['degraded'] > 0) {
            \WP_CLI::warning(sprintf('applied-degraded: %d (regeneração de metadata falhou — retentável via wp media regenerate, §A.5.6)', $report['degraded']));
        }
        if ($report['self_healed'] > 0) {
            \WP_CLI::log(sprintf('Self-heal: %d binário(s) re-materializado(s) (§A.5.3).', $report['self_healed']));
        }
        if (null !== $report['snapshot']) {
            \WP_CLI::log(sprintf('Snapshot pré-apply: %s (wp sync restore %s)', $report['snapshot'], $report['snapshot']));
        }
    }

    /** Mensagem pós-resolução obrigatória (§7.5) — verbatim. */
    private function postResolutionMessage(array $conflict): void
    {
        $label  = $conflict['label'];
        $winner = $conflict['winner'];
        $env    = $conflict['environment'];

        \WP_CLI::warning(sprintf('conflito em %s — resolvido: %s vence (política: %s)', $label, $winner, $env));

        if (null !== $conflict['loser_ref']) {
            \WP_CLI::log(sprintf('  → lado %s preservado: conflito #%d (wp sync conflict show %d)', 'db' === $winner ? 'arquivo' : 'banco', $conflict['loser_ref'], $conflict['loser_ref']));
        }

        $revision = $this->latestRevision($conflict['entity']);
        if (null !== $revision) {
            \WP_CLI::log(sprintf('  → estado anterior do banco: revision #%d (Compare revisions na UI)', $revision));
        }

        if ('db' === $winner && null !== $conflict['reexported_file']) {
            $file = $conflict['reexported_file'];
            \WP_CLI::log(sprintf('  → o arquivo foi re-exportado: %s', $file));
            \WP_CLI::log('  → ATENÇÃO: o working tree agora diverge do HEAD neste arquivo.');
            \WP_CLI::log(sprintf('    Revisar:        git diff %s', $file));
            \WP_CLI::log(sprintf('    Aceitar o repo: git checkout -- %s && wp sync apply', $file));
            \WP_CLI::log(sprintf('    Manter o banco: git add %s && git commit', $file));
        }
    }

    /** Última revision do post (estado anterior recuperável, §7.4 camada 1). */
    private function latestRevision(string $tuple): ?int
    {
        $parts = explode(':', $tuple, 3);
        if (3 !== count($parts) || 'post' !== $parts[0]) {
            return null;
        }
        $record = $this->c->state->get(\CVSync\Engine\EntityRef::post($parts[1], $parts[2]));
        if (null === $record?->dbId) {
            return null;
        }
        $revisions = wp_get_post_revisions($record->dbId, ['numberposts' => 1]);
        $latest    = reset($revisions);

        return $latest instanceof \WP_Post ? (int) $latest->ID : null;
    }

    /**
     * Gatilho efetivo para o audit log (§11.1): o comando é sempre invocado
     * via CLI; o hook de git e o pipeline chamam este mesmo comando — a
     * distinção vem da env CVSYNC_TRIGGER (documentada nos hooks/deploy).
     */
    private function detectTrigger(): string
    {
        $trigger = getenv('CVSYNC_TRIGGER');

        return is_string($trigger) && in_array($trigger, ['cli', 'git-hook', 'deploy', 'cron', 'passive'], true)
            ? $trigger
            : 'cli';
    }
}
