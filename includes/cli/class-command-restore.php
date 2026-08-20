<?php
/**
 * `wp sync restore <timestamp>` — re-aplica um snapshot pré-apply (§11.2,
 * rede 3). Reimporta o estado preservado em uploads/cvsync-backups/<ts>/ e
 * re-materializa binários ausentes (aditivo — nunca sobrescreve byte alheio).
 *
 * Comando de mutação: respeita a matriz §7.3 (prod: triplo fator via --force).
 * Exit: 0 sucesso; 1 falha/snapshot inexistente.
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

use CVSync\Environment;
use CVSync\ImportContext;
use CVSync\PathGuard;
use CVSync\Storage\LogResult;

defined('ABSPATH') || exit;

final class CommandRestore extends CommandBase
{
    public function __invoke(array $args, array $assocArgs): void
    {
        $this->warnInvalidConstants();

        $timestamp = (string) ($args[0] ?? '');
        if ('' === $timestamp) {
            $available = $this->c->snapshot->list();
            \WP_CLI::error('Uso: wp sync restore <timestamp>. Disponíveis: ' . ('' === implode('', $available) ? '(nenhum)' : implode(', ', $available)));
        }
        if (null !== ($refusal = $this->mutationRefusal((bool) ($assocArgs['force'] ?? false)))) {
            \WP_CLI::error($refusal);
        }

        try {
            $resolved = $this->c->snapshot->resolve($timestamp);
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }

        try {
            $batch = $this->c->locks->acquireBatch();
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }

        $applied = 0;
        $failed  = 0;

        try {
            $ctx      = new ImportContext(trigger: 'cli', environment: Environment::current());
            $snapshotPaths = new PathGuard($resolved['content_dir']);

            foreach ($snapshotPaths->listFiles('') as $relative) {
                $result = $this->restoreFile($relative, $snapshotPaths, $ctx);
                match ($result) {
                    LogResult::Applied, LogResult::PendingRef, LogResult::SkippedIdempotent => $applied++,
                    default => $failed++,
                };
            }

            $this->c->importer->fixupParents($ctx);
            if (null !== $this->c->materializer) {
                $this->c->materializer->regeneratePending();
            }
        } finally {
            $batch->release();
        }

        // Binários ausentes do repo com o mesmo hash (os únicos copiados, §A.10.4).
        $binaries = $this->c->snapshot->restoreBinaries($timestamp);

        \WP_CLI::log(sprintf(
            'restore %s: %d entidade(s) re-aplicada(s), %d falha(s), %d binário(s) re-materializado(s).',
            $timestamp,
            $applied,
            $failed,
            count($binaries)
        ));
        \WP_CLI::halt($failed > 0 ? 1 : 0);
    }

    /**
     * Reimporta UM arquivo do snapshot: lê pelo guard do snapshot e aplica via
     * o adapter dono do path (o conteúdo é a forma canônica do export).
     */
    private function restoreFile(string $relative, PathGuard $snapshotPaths, ImportContext $ctx): LogResult
    {
        $adapter = $this->c->adapters->adapterForPath($relative);
        $bytes   = $snapshotPaths->read($relative);

        if (null === $adapter || null === $bytes) {
            return LogResult::Error;
        }

        try {
            $doc = $adapter->parseDocument($bytes);
            $adapter->validateFrontmatter($doc->frontmatter);
        } catch (\Throwable) {
            return LogResult::Rejected;
        }

        // Re-aplicação via caminho normal do Importer exigiria o arquivo no
        // repo; aqui aplicamos o documento diretamente pelo adapter dentro do
        // envelope §10.2 + transação por entidade (§5.9).
        try {
            $apply = $this->c->guard->run(
                fn (): \CVSync\ApplyResult => $this->c->state->withLockedRow(
                    $doc->ref,
                    static fn (): \CVSync\ApplyResult => $adapter->apply($doc, $ctx)
                )
            );
        } catch (\Throwable) {
            return LogResult::Error;
        }

        if ($apply->hasStructuralBlockers()) {
            return LogResult::PendingRef;
        }
        if (null !== $apply->dbId) {
            $this->c->state->upsert($doc->ref, ['db_id' => $apply->dbId]);
        }

        return LogResult::Applied;
    }
}
