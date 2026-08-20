<?php
/**
 * ApplyRunner — orquestração do lote de apply (spec §2.4, §5.8, §5.9, §7,
 * §A.5.7), reutilizável pelo comando WP-CLI e pelo gatilho passivo (§8.2).
 *
 * Ordem normativa do lote:
 *  1. Gate de migration (§5.9): Schema::assertNoPendingMigration() — fail-fast;
 *  2. Batch lock ÚNICA (Locks::acquireBatch) — fail-closed; dentro dela a
 *     serialização por entidade é SELECT ... FOR UPDATE (StateStore::
 *     withLockedRow), NUNCA named lock por entidade (§5.8);
 *  3. Plano por entidade via DecisionEngine (P1 puro), na ordem de estágios
 *     §A.5.7 (AdapterRegistry::byStage);
 *  4. Snapshot pré-apply das entidades afetadas (§11.2 — rede 3);
 *  5. Execução: Import (P3 Importer), Export/re-export batch-safe (sem named
 *     lock — ver nota abaixo), Conflict pela matriz §7.3 (com preservação do
 *     perdedor na ConflictStore + hook cvsync_conflict_registered),
 *     DeletePolicy (§5.5 — nunca passiva; --delete explícito), AutoResolveDb
 *     (§5.7 — re-export lossless + log worktree_regression_auto_resolved);
 *  6. Self-heal binário (§A.5.3) para pending_payload.missing_binary;
 *  7. Fim de lote: parent-fixup idempotente (§A.5.2.8), regeneração
 *     coalescida + action cvsync_files_materialized (P4), updateLastAppliedHead;
 *  8. Resumo estruturado + hooks de alerting §11.1 (cvsync_applied/failed).
 *
 * NOTA DE INTEGRAÇÃO (re-export dentro do batch): o Exporter do P3 adquire
 * named lock por entidade, proibida com a batch lock presa (uma named lock
 * por sessão MariaDB, §5.8). O re-export DENTRO do apply (casos 2/7/9 e
 * conflito com winner=db) usa reExport() desta classe — mesmos passos
 * canônicos do Exporter (readCanonical → hash → idempotência byte-a-byte →
 * escrita atômica → recordSync), sem named lock. Expectativa registrada para
 * o P3 expor futuramente um caminho público equivalente.
 *
 * Regressão de worktree (§5.7): consome CVSync\WorktreeRegression (pacote do
 * git-workflow-master) via captureContext()/isRegressed()/getHead() — ver
 * gitFacts(). Ausente/indisponível → assinatura pulada (o git-master loga
 * 'regression_check_unavailable: no-git') e a entidade cai na política normal
 * do ambiente (§5.7 — seguro).
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

use CVSync\Engine\Decision;
use CVSync\Engine\DecisionEngine;
use CVSync\Engine\DecisionInput;
use CVSync\Engine\EntityRef;
use CVSync\Engine\Frontmatter\FrontmatterWriter;
use CVSync\Engine\Hasher;
use CVSync\Environment;
use CVSync\ImportContext;
use CVSync\Storage\ConflictRecord;
use CVSync\Storage\EntityStatus;
use CVSync\Storage\LogEntry;
use CVSync\Storage\LogResult;
use CVSync\Storage\Schema;
use CVSync\Storage\StateRecord;
use CVSync\Storage\SyncDirection;
use CVSync\Triggers;

defined('ABSPATH') || exit;

final class ApplyRunner
{
    public function __construct(private readonly Container $c)
    {
    }

    /**
     * Executa (ou simula, em dry-run) o lote de apply.
     *
     * @return array<string, mixed> Relatório estruturado (ver CommandApply para
     *         a renderização texto/JSON e os exit codes do contrato §8.3).
     */
    public function run(ImportContext $ctx, bool $forceDelete = false): array
    {
        $report = [
            'trigger'            => $ctx->trigger,
            'environment'        => $ctx->environment,
            'dry_run'            => $ctx->dryRun,
            'applied'            => 0,
            'exported'           => 0,
            'skipped'            => 0,
            'skipped_locked'     => 0,
            'deleted'            => 0,
            'pending_delete'     => 0,
            'pending_ref'        => 0,
            'conflicts'          => [],
            'conflicts_db'       => 0,
            'conflicts_file'     => 0,
            'failed'             => 0,
            'degraded'           => 0,
            'purged_tombstones'  => 0,
            'self_healed'        => 0,
            'materialized_files' => [],
            'snapshot'           => null,
            'regression_check'   => 'unavailable',
            'items'              => [],
            'errors'             => [],
            'config_warnings'    => [],
        ];

        // 0. Divergência cvsync.json × CVSYNC_* (§A.13.10) — warning, NUNCA falha.
        $configWarnings = $this->configDivergenceWarnings();
        $report['config_warnings'] = $configWarnings;
        foreach ($configWarnings as $warning) {
            \WP_CLI::warning($warning);
        }

        // 1. Gate de migration (§5.9) — fail-fast, antes de qualquer lock.
        try {
            Schema::assertNoPendingMigration();
        } catch (\Throwable $e) {
            $report['errors'][] = $e->getMessage();
            $report['failed']++;

            return $report;
        }

        // 2. Fatos git (HEAD em PHP puro; assinatura §5.7 via git-master quando há).
        $git  = $this->gitFacts();
        $head = $git['head'];
        $report['regression_check'] = $git['available'] ? 'ok' : 'unavailable';
        if (null !== $head) {
            $ctx = new ImportContext(
                trigger: $ctx->trigger,
                environment: $ctx->environment,
                dryRun: $ctx->dryRun,
                forceLocks: $ctx->forceLocks,
                allowDelete: $ctx->allowDelete,
                gitHead: $head,
            );
        }

        // 3. Plano (DecisionEngine puro, ordem de estágios §A.5.7).
        $lastAppliedHead = $this->c->state->lastAppliedHead();
        $plan            = $this->computePlan($ctx, $head, $lastAppliedHead, $git['regression']);
        $report['items'] = array_map(
            static fn (array $item): array => [
                'entity'   => $item['ref']->toTupleString(),
                'path'     => $item['path'],
                'decision' => $item['outcome']->decision->value,
                'case'     => $item['outcome']->case,
                'reason'   => $item['outcome']->reason,
            ],
            $plan
        );

        if ($ctx->dryRun) {
            foreach ($plan as $item) {
                $this->tallyDryRun($report, $item['outcome']->decision);
            }

            return $report;
        }

        // 4. Batch lock fail-closed (§5.8).
        try {
            $batch = $this->c->locks->acquireBatch();
        } catch (\Throwable $e) {
            $report['errors'][] = $e->getMessage();
            $report['failed']++;

            return $report;
        }

        try {
            // 5. Snapshot pré-apply (§11.2 — entidades que serão tocadas).
            $affected = array_values(array_map(
                static fn (array $item): EntityRef => $item['ref'],
                array_filter(
                    $plan,
                    static fn (array $item): bool => in_array(
                        $item['outcome']->decision,
                        [Decision::Import, Decision::Conflict, Decision::DeletePolicy, Decision::AutoResolveDb],
                        true
                    )
                )
            ));
            if ([] !== $affected) {
                try {
                    $snap = $this->c->snapshot->create($affected, $head);
                    $report['snapshot'] = $snap['timestamp'];
                } catch (\Throwable $e) {
                    $report['errors'][] = 'snapshot pré-apply falhou: ' . $e->getMessage();
                }
            }

            // 6. Execução por entidade.
            $touched = [];
            foreach ($plan as $item) {
                $this->execute($item, $ctx, $forceDelete, $report);
                $touched[] = $item['ref'];
            }

            // 7. Self-heal binário (§A.5.3) — pendências missing_binary.
            $report['self_healed'] = $this->selfHeal($ctx, $report);

            // 8. Fim de lote.
            $this->c->importer->fixupParents($ctx);

            if (null !== $this->c->materializer) {
                // Lido ANTES de regeneratePending() (que dispara a action
                // cvsync_files_materialized e limpa o buffer — §A.10.3).
                $report['materialized_files'] = $this->c->materializer->materializedFiles();
                foreach ($this->c->materializer->regeneratePending() as $status) {
                    if ('degraded' === $status) {
                        $report['degraded']++;
                    }
                }
            }

            if (null !== $head && [] !== $touched) {
                try {
                    $this->c->state->updateLastAppliedHead($head, $touched);
                } catch (\Throwable) {
                    // HEAD não gravado: o check passivo reagenda — nunca aborta o lote.
                }
            }
        } finally {
            $batch->release();
        }

        // 9. Hooks de alerting (§11.1) — o plugin não inventa canal.
        if ($report['failed'] > 0) {
            do_action('cvsync_failed', $report);
        } else {
            do_action('cvsync_applied', $report);
        }

        return $report;
    }

    // ------------------------------------------------------------------
    // Plano
    // ------------------------------------------------------------------

    /**
     * Plano completo: candidatos = arquivos do repo + linhas de state sem
     * arquivo (deleções repo→banco, caso 5) + posts do escopo sem state
     * (entidades novas do admin, caso 7).
     *
     * @param object|null $regression Contexto §5.7 (CVSync\WorktreeRegression) ou null.
     * @return list<array{ref: EntityRef, path: string|null, outcome: \CVSync\Engine\DecisionOutcome, file_bytes: string|null}>
     */
    public function computePlan(ImportContext $ctx, ?string $head, ?string $lastAppliedHead, ?object $regression): array
    {
        $plan    = [];
        $covered = [];

        foreach ($this->c->adapters->byStage() as $adapters) {
            foreach ($adapters as $adapter) {
                foreach ($this->c->paths->listFiles($adapter->baseDirectory()) as $relative) {
                    if (! str_ends_with($relative, $adapter->fileExtension())) {
                        continue;
                    }

                    $bytes = $this->c->paths->read($relative);
                    if (null === $bytes) {
                        continue;
                    }

                    try {
                        $doc      = $adapter->parseDocument($bytes);
                        $ref      = $doc->ref;
                        $fileHash = Hasher::hashDocument($doc, $adapter->keyOrder());
                    } catch (\Throwable $e) {
                        $plan[] = [
                            'ref'        => EntityRef::of('file', $relative),
                            'path'       => $relative,
                            'outcome'    => new \CVSync\Engine\DecisionOutcome(Decision::Conflict, 0, 'conflict', 'unparseable file: ' . $e->getMessage()),
                            'file_bytes' => null,
                        ];
                        continue;
                    }

                    $covered[$ref->toTupleString()] = true;
                    $plan[] = $this->planItem($ref, $relative, $fileHash, $bytes, $head, $lastAppliedHead, $regression);
                }
            }
        }

        // Linhas de state sem arquivo no repo (caso 5: deleção veio do repo) e
        // entidades db-deleted já tombstonadas (caso 8/purge).
        foreach ($this->allStateRecords() as $record) {
            $key = $record->ref->toTupleString();
            if (isset($covered[$key])) {
                continue;
            }
            $covered[$key] = true;
            $plan[] = $this->planItem($record->ref, null, null, null, $head, $lastAppliedHead, $regression);
        }

        return $plan;
    }

    /**
     * @return array{ref: EntityRef, path: string|null, outcome: \CVSync\Engine\DecisionOutcome, file_bytes: string|null}
     */
    private function planItem(
        EntityRef $ref,
        ?string $path,
        ?string $fileHash,
        ?string $fileBytes,
        ?string $head,
        ?string $lastAppliedHead,
        ?object $regression
    ): array {
        $adapter = $this->c->adapters->forRef($ref);
        $record  = $this->c->state->get($ref);

        $dbExists = null !== $adapter && $adapter->exists($ref);
        $dbHash   = null;
        if ($dbExists) {
            try {
                $doc    = $adapter->readCanonical($ref);
                $dbHash = null !== $doc ? Hasher::hashDocument($doc, $adapter->keyOrder()) : null;
            } catch (\Throwable) {
                $dbHash = null; // fail-closed: engine trata hash nulo como conflito
            }
        }

        $fileHashHex = null !== $fileHash ? $this->hex($fileHash) : null;
        $dbHashHex   = null !== $dbHash ? $this->hex($dbHash) : null;
        $sync        = $record?->lastSyncHash;

        // Assinatura de regressão §5.7 (avaliada pelo pacote do git-master; o
        // engine nunca toca git): file ≠ synced ∧ arquivo limpo vs HEAD ∧
        // HEAD == last_applied_head. Indisponível → false (queda na política
        // normal do ambiente — seguro, §5.7).
        $regressed = false;
        if (null !== $regression
            && null !== $fileHashHex
            && null !== $sync
            && null !== $lastAppliedHead
            && null !== $path
            && $fileHashHex !== $sync
            && method_exists($regression, 'isRegressed')
        ) {
            try {
                $regressed = (bool) $regression->isRegressed(
                    $this->repoRelative($path),
                    $fileHashHex,
                    $sync,
                    $lastAppliedHead
                );
            } catch (\Throwable) {
                $regressed = false; // assinatura indisponível → política normal
            }
        }

        $outcome = DecisionEngine::decide(new DecisionInput(
            dbPostExists: $dbExists,
            dbHash: $dbHashHex,
            fileExists: null !== $path,
            fileHash: $fileHashHex,
            lastSyncHash: $sync,
            stateStatus: $record?->status->value,
            stateHasDbId: null !== $record?->dbId,
            worktreeRegression: $regressed,
        ));

        return ['ref' => $ref, 'path' => $path, 'outcome' => $outcome, 'file_bytes' => $fileBytes];
    }

    // ------------------------------------------------------------------
    // Execução
    // ------------------------------------------------------------------

    private function execute(array $item, ImportContext $ctx, bool $forceDelete, array &$report): void
    {
        $ref     = $item['ref'];
        $outcome = $item['outcome'];

        if (0 === $outcome->case) {
            // Arquivo não-parseável (caso sintético do plano): falha ruidosa,
            // nunca entra na política de conflito da matriz.
            $report['failed']++;
            $report['errors'][] = sprintf('%s: %s', (string) $item['path'], $outcome->reason);

            return;
        }

        try {
            match ($outcome->decision) {
                Decision::Skip          => $report['skipped']++,
                Decision::Import        => $this->doImport($item, $ctx, $report),
                Decision::Export        => $this->doExport($item, $ctx, $report),
                Decision::Conflict      => $this->doConflict($item, $ctx, $report),
                Decision::DeletePolicy  => $this->doDelete($item, $ctx, $forceDelete, $report),
                Decision::PurgeTombstone => $this->doPurgeTombstone($item, $report),
                Decision::AutoResolveDb => $this->doAutoResolveDb($item, $ctx, $report),
            };
        } catch (\Throwable $e) {
            $report['failed']++;
            $report['errors'][] = sprintf('%s: %s', $ref->toTupleString(), $e->getMessage());
            $this->appendLog($ref, $ctx, LogResult::Error, $e->getMessage());
        }
    }

    private function doImport(array $item, ImportContext $ctx, array &$report): void
    {
        $result = $this->c->importer->importFile((string) $item['path'], $ctx);

        match ($result->outcome) {
            LogResult::Applied       => $report['applied']++,
            LogResult::SkippedLocked => $report['skipped_locked']++,
            LogResult::PendingRef    => $report['pending_ref']++,
            default                  => $report['failed']++,
        };

        if (null !== $result->error && LogResult::Applied !== $result->outcome) {
            $report['errors'][] = sprintf('%s: %s', $item['ref']->toTupleString(), $result->error);
        }
    }

    /**
     * Export dentro do apply (casos 2/7: banco mudou / entidade nova do admin
     * descoberta no checkpoint). Batch-safe: sem named lock por entidade.
     */
    private function doExport(array $item, ImportContext $ctx, array &$report): void
    {
        $result = $this->reExport($item['ref'], $ctx->trigger);

        match ($result) {
            LogResult::Applied          => $report['exported']++,
            LogResult::SkippedIdempotent, LogResult::SkippedFsReadonly => $report['skipped']++,
            default                     => $report['failed']++,
        };
    }

    /**
     * Conflito real (caso 4): a matriz §7.3 decide; o perdedor é SEMPRE
     * preservado (§7.4) e o conflito NUNCA sobe exit code por si só (§8.3).
     */
    private function doConflict(array $item, ImportContext $ctx, array &$report): void
    {
        $ref    = $item['ref'];
        $winner = Environment::conflictWinner();
        $loser  = 'db' === $winner ? 'file' : 'db';

        // Preservação do perdedor (§7.4): payload canônico do lado perdedor.
        $loserPayload = 'file' === $loser
            ? (string) ($item['file_bytes'] ?? '')
            : $this->canonicalDump($ref);

        $conflictId = null;
        try {
            $conflictId = $this->c->conflicts->record(new ConflictRecord(
                null,
                $ref,
                $loser,
                '' !== $loserPayload ? $loserPayload : '(indisponível)',
                $winner,
                $ctx->trigger,
                $this->actor(),
                $ctx->gitHead,
                new \DateTimeImmutable('now', wp_timezone()),
                null
            ));
            do_action('cvsync_conflict_registered', $conflictId, $ref, $winner);
        } catch (\Throwable $e) {
            $report['errors'][] = sprintf('conflito em %s sem preservação: %s', $ref->toTupleString(), $e->getMessage());
        }

        if ('file' === $winner) {
            $this->doImport($item, $ctx, $report);
            $report['conflicts_file']++;
        } else {
            $this->reExport($ref, $ctx->trigger);
            $report['conflicts_db']++;
            $report['exported']++;
        }

        $report['conflicts'][] = [
            'entity'          => $ref->toTupleString(),
            'label'           => $this->entityLabel($ref),
            'winner'          => $winner,
            'loser_ref'       => $conflictId,
            'reexported_file' => 'db' === $winner ? $this->locatePath($ref) : null,
            'environment'     => $ctx->environment,
            'reason'          => $item['outcome']->reason,
        ];

        $this->state($ref, EntityStatus::Ok);
        $this->appendLog($ref, $ctx, LogResult::ConflictAutoResolved, 'conflict_auto_resolved: ' . $winner);
    }

    /**
     * Caso 5 (deleção veio do repo): NUNCA automática em gatilho passivo —
     * vira pending-delete + relatório; aplicação efetiva somente com --delete
     * explícito; destino = trash (wp_trash_post via adapter); --force-delete
     * recusado onde a política é trash-only; em prod a política é 'never'.
     */
    private function doDelete(array $item, ImportContext $ctx, bool $forceDelete, array &$report): void
    {
        $ref    = $item['ref'];
        $policy = Environment::policy()['deletion'];

        if ('never' === $policy) {
            $this->state($ref, EntityStatus::PendingDelete);
            $report['pending_delete']++;
            $report['errors'][] = sprintf('%s: deleção bloqueada pela política do ambiente (never, §5.5)', $ref->toTupleString());

            return;
        }

        if (! $ctx->allowDelete) {
            $this->state($ref, EntityStatus::PendingDelete);
            $report['pending_delete']++;

            return;
        }

        if ($forceDelete && 'trash-report' === $policy) {
            $report['errors'][] = sprintf('%s: --force-delete recusado em homolog (trash-only, §5.5)', $ref->toTupleString());
            $forceDelete = false;
        }

        $adapter = $this->c->adapters->forRef($ref);
        if (null === $adapter) {
            $report['failed']++;
            $report['errors'][] = 'Sem adapter para deletar ' . $ref->toTupleString();

            return;
        }

        $this->c->guard->run(static function () use ($adapter, $ref, $forceDelete): void {
            $adapter->delete($ref, $forceDelete);
        });
        $this->c->state->tombstone($ref);

        $report['deleted']++;
        $this->appendLog($ref, $ctx, LogResult::Applied, 'deleted via --delete (' . ($forceDelete ? 'force' : 'trash') . ')');
    }

    /** Caso 8: purge de tombstone respeitando o TTL (§5.5, default 90d). */
    private function doPurgeTombstone(array $item, array &$report): void
    {
        $record = $this->c->state->get($item['ref']);
        if (null !== $record && EntityStatus::Tombstone === $record->status && null !== $record->tombstoneAt) {
            $ttl    = (int) Environment::constant('CVSYNC_TOMBSTONE_TTL_DAYS');
            $cutoff = (new \DateTimeImmutable('now', wp_timezone()))->modify("-{$ttl} days");
            if ($record->tombstoneAt > $cutoff) {
                $report['skipped']++; // dentro do TTL: janela anti-ressurreição

                return;
            }
        }

        $this->c->state->deleteRow($item['ref']);
        $report['purged_tombstones']++;
    }

    /**
     * Caso 9 (§5.7): regressão de working tree — db vence, re-export lossless,
     * log 'worktree_regression_auto_resolved: db'.
     */
    private function doAutoResolveDb(array $item, ImportContext $ctx, array &$report): void
    {
        $this->reExport($item['ref'], $ctx->trigger);
        $report['exported']++;
        $report['conflicts_db']++;
        $report['conflicts'][] = [
            'entity'          => $item['ref']->toTupleString(),
            'label'           => $this->entityLabel($item['ref']),
            'winner'          => 'db',
            'loser_ref'       => null,
            'reexported_file' => $this->locatePath($item['ref']),
            'environment'     => $ctx->environment,
            'reason'          => 'worktree_regression_auto_resolved: db',
        ];
        $this->appendLog($item['ref'], $ctx, LogResult::WorktreeRegressionAutoResolved, 'worktree_regression_auto_resolved: db');
    }

    // ------------------------------------------------------------------
    // Re-export batch-safe (sem named lock — ver nota do cabeçalho)
    // ------------------------------------------------------------------
    /**
     * Re-export batch-safe: delega a Exporter::exportWithinBatch() (P3, r6) —
     * mesmos passos canônicos do Exporter, SEM named lock por entidade (a
     * batch lock já está presa; §5.8 — uma named lock por sessão MariaDB).
     * A duplicação anterior foi eliminada na integração (r6 item 3).
     */
    public function reExport(EntityRef $ref, string $trigger): LogResult
    {
        return $this->c->exporter->exportWithinBatch($ref, $trigger);
    }

    // ------------------------------------------------------------------
    // Self-heal (§A.5.3)
    // ------------------------------------------------------------------

    private function selfHeal(ImportContext $ctx, array &$report): int
    {
        if (null === $this->c->materializer) {
            return 0;
        }

        $healed = 0;
        foreach ($this->c->state->pendingRefs() as $record) {
            if ('attachment' !== $record->ref->postType) {
                continue;
            }
            if (true !== ($record->pendingPayload['missing_binary'] ?? null)) {
                continue;
            }

            try {
                $result = $this->c->materializer->selfHeal($record->ref, $ctx);
                if (LogResult::BinaryRematerialized === $result->outcome) {
                    $healed++;
                } elseif (LogResult::Error === $result->outcome || LogResult::BinaryHashMismatch === $result->outcome) {
                    $report['errors'][] = sprintf('self-heal %s: %s', $record->ref->toTupleString(), $result->error);
                }
            } catch (\Throwable $e) {
                $report['errors'][] = sprintf('self-heal %s: %s', $record->ref->toTupleString(), $e->getMessage());
            }
        }

        return $healed;
    }

    // ------------------------------------------------------------------
    // Fatos git (WorktreeRegression do git-master, com fallback gracioso)
    // ------------------------------------------------------------------

    /**
     * Consome CVSync\WorktreeRegression (pacote do git-workflow-master) com
     * fallback gracioso. API real consumida (conferida contra a entrega dele):
     *
     *   WorktreeRegression::captureContext(?string $contentDir): ?self
     *     — roda O ÚNICO comando git do apply (git status --porcelain -z,
     *       GIT_OPTIONAL_LOCKS=0), SAPI CLI apenas; null + log
     *       'regression_check_unavailable: no-git' quando indisponível;
     *   $ctx->isRegressed(string $path, string $fileHash, string $lastSynced,
     *                     string $lastAppliedHead): bool  — zero subprocessos;
     *   $ctx->getHead(): string — HEAD lido em PHP puro por ele.
     *
     * @return array{head: ?string, regression: ?object, available: bool}
     */
    public function gitFacts(): array
    {
        $head = Triggers::readHead(Triggers::repoRoot());

        $class = 'CVSync\\WorktreeRegression';
        if (class_exists($class) && method_exists($class, 'captureContext')) {
            try {
                $regression = $class::captureContext(Environment::contentDir());
                if (null !== $regression) {
                    if (method_exists($regression, 'getHead')) {
                        $head = $regression->getHead() ?: $head;
                    }

                    return ['head' => $head, 'regression' => $regression, 'available' => true];
                }

                return ['head' => $head, 'regression' => null, 'available' => false];
            } catch (\Throwable) {
                return ['head' => $head, 'regression' => null, 'available' => false];
            }
        }

        // Sem o pacote do git-master: assinatura pulada (§5.7 — seguro).
        return ['head' => $head, 'regression' => null, 'available' => false];
    }

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    /**
     * Leitura direta da state table (P5 é o orquestrador; o store não expõe
     * scan completo por design). SELECT read-only, tabela própria do plugin.
     *
     * @return list<StateRecord>
     */
    /**
     * Scan read-only da state table via StateStore::all() (P2 — r6 item 5;
     * o scan direto com $wpdb foi eliminado na integração).
     *
     * @return list<StateRecord>
     */
    private function allStateRecords(): array
    {
        return $this->c->state->all();
    }

    /** Dump canônico do lado banco (payload do perdedor, §7.4). */
    private function canonicalDump(EntityRef $ref): string
    {
        $adapter = $this->c->adapters->forRef($ref);
        if (null === $adapter) {
            return '';
        }
        try {
            $doc = $adapter->readCanonical($ref);
            if (null === $doc) {
                return '';
            }
            $hash = Hasher::hashDocument($doc, $adapter->keyOrder());

            return $adapter->serializeDocument($doc, $hash);
        } catch (\Throwable) {
            return '';
        }
    }

    private function locatePath(EntityRef $ref): ?string
    {
        return $this->c->adapters->forRef($ref)?->locateFile($ref);
    }

    /**
     * Warning de divergência cvsync.json × constantes CVSYNC_* efetivas
     * (§A.13.10): o lint de CI lê a config do repo; o runtime lê constantes —
     * duas superfícies de config com warning de divergência no apply.
     * NUNCA falha o apply: JSON inválido/ausente → lista vazia.
     *
     * @return list<string>
     */
    private function configDivergenceWarnings(): array
    {
        // cvsync.json vive na raiz do repo (pai do content dir, §4.1/§A.9.5).
        $jsonPath = dirname($this->c->paths->contentDir()) . '/cvsync.json';
        if (!is_file($jsonPath)) {
            return [];
        }

        $config = json_decode((string) file_get_contents($jsonPath), true);
        if (!is_array($config)) {
            return [];
        }

        $warnings = [];

        $checks = [
            'attachment_max_bytes' => [
                'effective' => (int) (\CVSync\Environment::constant('CVSYNC_ATTACHMENT_MAX_BYTES') ?? 10 * 1024 * 1024),
                'normalize' => static fn (mixed $v): int => (int) $v,
            ],
            'attachment_allow_svg' => [
                'effective' => defined('CVSYNC_ATTACHMENT_ALLOW_SVG') && constant('CVSYNC_ATTACHMENT_ALLOW_SVG') === true,
                'normalize' => static fn (mixed $v): bool => (bool) $v,
            ],
            'max_megapixels' => [
                'effective' => 50, // teto §A.5.1.4 (MediaValidator)
                'normalize' => static fn (mixed $v): int => (int) $v,
            ],
        ];

        foreach ($checks as $key => $check) {
            if (!array_key_exists($key, $config)) {
                continue;
            }
            if ($check['normalize']($config[$key]) !== $check['effective']) {
                $warnings[] = sprintf(
                    'cvsync.json × runtime: "%s" = %s no repo, %s nas constantes CVSYNC_* (§A.13.10).',
                    $key,
                    wp_json_encode($config[$key]),
                    wp_json_encode($check['effective'])
                );
            }
        }

        // attachment_mime_types: comparação como conjunto (ordem irrelevante).
        if (isset($config['attachment_mime_types']) && is_array($config['attachment_mime_types'])) {
            $effective = defined('CVSYNC_ATTACHMENT_MIME_TYPES')
                ? array_map('trim', explode(',', (string) constant('CVSYNC_ATTACHMENT_MIME_TYPES')))
                : ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
            $fromRepo = array_map('strval', $config['attachment_mime_types']);
            sort($effective);
            sort($fromRepo);
            if ($effective !== $fromRepo) {
                $warnings[] = sprintf(
                    'cvsync.json × runtime: "attachment_mime_types" diverge (repo: %s; runtime: %s) (§A.13.10).',
                    implode(',', $fromRepo),
                    implode(',', $effective)
                );
            }
        }

        if ($warnings !== []) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[cvsync] divergência cvsync.json × CVSYNC_*: ' . implode(' | ', $warnings));
        }

        return $warnings;
    }

    /** Rótulo 'post_type/slug' para a mensagem §7.5. */
    private function entityLabel(EntityRef $ref): string
    {
        $slug = $ref->key;
        $record = $this->c->state->get($ref);
        if (null !== $record?->dbId && 'post' === $ref->kind) {
            $post = get_post($record->dbId);
            if ($post instanceof \WP_Post && '' !== $post->post_name) {
                $slug = $post->post_name;
            }
        }

        return ('' !== $ref->postType ? $ref->postType : $ref->kind) . '/' . $slug;
    }

    /**
     * Path do arquivo relativo à RAIZ DO REPO (o dirty set do git fala essa
     * língua). Prefixo computado contra Triggers::repoRoot() — basename()
     * quebraria com CVSYNC_CONTENT_DIR aninhado (ex.: <repo>/data/content),
     * gerando falso positivo de regressão §5.7 (🟡11 do r7).
     */
    private function repoRelative(string $contentRelative): string
    {
        $contentDir = rtrim(Environment::contentDir(), '/');
        $repoRoot   = rtrim(Triggers::repoRoot(), '/');

        if (str_starts_with($contentDir, $repoRoot . '/')) {
            $prefix = substr($contentDir, strlen($repoRoot) + 1);
        } else {
            // Content dir fora da raiz do repo: a assinatura §5.7 não se
            // aplica a esses arquivos (o git não os rastreia) — o prefixo
            // basename é o melhor esforço e isRegressed() devolve false para
            // paths fora do repo.
            $prefix = basename($contentDir);
        }

        return $prefix . '/' . $contentRelative;
    }

    private function state(EntityRef $ref, EntityStatus $status): void
    {
        try {
            $this->c->state->setStatus($ref, $status);
        } catch (\Throwable) {
            // state indisponível não mascara o resultado do lote
        }
    }

    private function actor(): string
    {
        $user = wp_get_current_user();

        return '' !== $user->user_login ? $user->user_login : 'cvsync-cli';
    }

    private function appendLog(EntityRef $ref, ImportContext $ctx, LogResult $result, ?string $error): void
    {
        try {
            $this->c->log->append(new LogEntry(
                null,
                $ref,
                $ref->postType,
                null,
                $ctx->trigger,
                $this->actor(),
                null,
                null,
                null,
                null,
                $result,
                $error,
                null,
                new \DateTimeImmutable('now', wp_timezone())
            ));
        } catch (\Throwable) {
            // Audit log nunca derruba o apply.
        }
    }

    private function tallyDryRun(array &$report, Decision $decision): void
    {
        match ($decision) {
            Decision::Skip          => $report['skipped']++,
            Decision::Import        => $report['applied']++,
            Decision::Export        => $report['exported']++,
            Decision::Conflict      => $report['conflicts_db']++,
            Decision::DeletePolicy  => $report['pending_delete']++,
            Decision::PurgeTombstone => $report['purged_tombstones']++,
            Decision::AutoResolveDb => $report['exported']++,
        };
    }

    /** State table guarda o HEX (CHAR(64)); o prefixo 'sha256:' é da forma de arquivo. */
    private function hex(string $hash): string
    {
        return str_starts_with($hash, Hasher::PREFIX) ? substr($hash, strlen(Hasher::PREFIX)) : $hash;
    }
}
