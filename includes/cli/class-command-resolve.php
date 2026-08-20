<?php
/**
 * `wp sync resolve <entity> --keep=db|file` — resolução manual de conflito
 * (§7.4, §8.3). Aplica o vencedor escolhido e fecha administrativamente os
 * conflitos pendentes da entidade (markResolved).
 *
 *  --keep=db   → re-export a partir do banco (lossless; working tree diverge
 *                do HEAD — mensagem §7.5);
 *  --keep=file → import do arquivo (revisions sempre, §10.3).
 *
 * Comando de mutação: respeita a matriz §7.3 (prod: triplo fator via --force).
 * Exit: 0 sucesso; 1 erro/sem conflito pendente.
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

use CVSync\Environment;
use CVSync\ImportContext;
use CVSync\Storage\LogResult;

defined('ABSPATH') || exit;

final class CommandResolve extends CommandBase
{
    public function __invoke(array $args, array $assocArgs): void
    {
        $this->warnInvalidConstants();

        $entity = (string) ($args[0] ?? '');
        $keep   = (string) ($assocArgs['keep'] ?? '');
        if ('' === $entity || ! in_array($keep, ['db', 'file'], true)) {
            \WP_CLI::error('Uso: wp sync resolve <entity> --keep=db|file [--force]');
        }
        if (null !== ($refusal = $this->mutationRefusal((bool) ($assocArgs['force'] ?? false)))) {
            \WP_CLI::error($refusal);
        }

        $ref = $this->parseEntityArg($entity);
        if (null === $ref) {
            \WP_CLI::error(sprintf('Entidade não encontrada: %s', $entity));
        }

        $pending = $this->c->conflicts->listUnresolved($ref);
        if ([] === $pending) {
            \WP_CLI::error(sprintf('Nenhum conflito pendente para %s.', $ref->toTupleString()));
        }

        $ctx = new ImportContext(trigger: 'cli', environment: Environment::current());

        if ('db' === $keep) {
            try {
                $batch = $this->c->locks->acquireBatch();
            } catch (\Throwable $e) {
                \WP_CLI::error($e->getMessage());
            }
            try {
                $result = (new ApplyRunner($this->c))->reExport($ref, 'cli');
            } finally {
                $batch->release();
            }
            if (LogResult::Error === $result) {
                \WP_CLI::error(sprintf('Re-export de %s falhou.', $ref->toTupleString()));
            }

            $path = $this->c->adapters->forRef($ref)?->locateFile($ref);
            \WP_CLI::warning(sprintf('conflito em %s — resolvido: db vence (política: manual, %s)', $entity, Environment::current()));
            if (null !== $path) {
                \WP_CLI::log(sprintf('  → o arquivo foi re-exportado: %s', $path));
                \WP_CLI::log('  → ATENÇÃO: o working tree agora diverge do HEAD neste arquivo.');
                \WP_CLI::log(sprintf('    Revisar:        git diff %s', $path));
                \WP_CLI::log(sprintf('    Aceitar o repo: git checkout -- %s && wp sync apply', $path));
                \WP_CLI::log(sprintf('    Manter o banco: git add %s && git commit', $path));
            }
        } else {
            $adapter = $this->c->adapters->forRef($ref);
            $path    = $adapter?->locateFile($ref);
            if (null === $path) {
                \WP_CLI::error(sprintf('Arquivo de %s não encontrado no repo.', $ref->toTupleString()));
            }

            try {
                $batch = $this->c->locks->acquireBatch();
            } catch (\Throwable $e) {
                \WP_CLI::error($e->getMessage());
            }
            try {
                $result = $this->c->importer->importFile($path, $ctx);
                $this->c->importer->fixupParents($ctx);
            } finally {
                $batch->release();
            }
            if (! in_array($result->outcome, [LogResult::Applied, LogResult::PendingRef], true)) {
                \WP_CLI::error(sprintf('Import de %s falhou: %s', $ref->toTupleString(), $result->error));
            }
            \WP_CLI::log(sprintf('conflito em %s — resolvido: file vence (importado).', $entity));
        }

        // Fechamento administrativo (§7.4): o vencedor já foi aplicado acima.
        foreach ($pending as $conflict) {
            if (null !== $conflict->id) {
                $this->c->conflicts->markResolved($conflict->id);
            }
        }
        \WP_CLI::log(sprintf('%d conflito(s) marcado(s) como resolvido(s).', count($pending)));
        \WP_CLI::halt(0);
    }
}
