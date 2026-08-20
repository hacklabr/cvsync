<?php

declare(strict_types=1);

namespace CVSync\Engine;

/**
 * Decision table — spec §5.2 (9 cases) + deletion disambiguation (§5.5)
 * + working-tree regression case (§5.7).
 *
 * PURE function: no side effects, no I/O, no WordPress, no git. Deterministic:
 * same inputs → same outcome. The 9 cases map to §14.6 unit tests one-to-one.
 *
 * Out of scope by design:
 *  - environment conflict policy (§7.3): the engine emits Conflict; P6 decides;
 *  - tombstone TTL (90d, §5.5): evaluated by P5 before acting on PurgeTombstone;
 *  - the §5.7 git signature: arrives evaluated as DecisionInput::$worktreeRegression;
 *  - attachment missing-binary (§A.4.1): never reaches the engine (P4→P2 direct).
 *
 * Case mapping (db = dbPostExists/dbHash, file = fileExists/fileHash,
 * sync = lastSyncHash):
 *
 *  | # | condition                                             | outcome            |
 *  |---|-------------------------------------------------------|--------------------|
 *  | 1 | db ∧ file ∧ dbHash === fileHash                       | Skip / ok          |
 *  | 2 | both ∧ diverge ∧ file=sync ∧ db≠sync                  | Export / ok        |
 *  | 3 | both ∧ diverge ∧ file≠sync ∧ db=sync ∧ ¬regression    | Import / ok        |
 *  | 4 | both ∧ diverge ∧ both ≠sync ∧ ¬regression             | Conflict / conflict|
 *  | 5 | db ∧ ¬file ∧ stateHasDbId ∧ db=sync                   | DeletePolicy / pending-delete |
 *  | 6 | ¬db ∧ file ∧ ¬stateHasDbId                            | Import / ok        |
 *  | 7 | db ∧ ¬file ∧ (no state ∨ db≠sync)                     | Export / ok        |
 *  | 8 | ¬db ∧ ¬file                                           | PurgeTombstone     |
 *  | 9 | like 3/4 but worktreeRegression                       | AutoResolveDb / ok |
 *
 * §5.5 extra row (not numbered in §5.2): ¬db ∧ file ∧ stateHasDbId (deleted in
 * db, file alive) → Export with reason 'db-deleted' and status 'tombstone' —
 * the caller translates "export of a missing entity" into "remove file +
 * record tombstone" (admin is authority in dev). Documented so no case is orphan.
 */
final class DecisionEngine
{
    public static function decide(DecisionInput $in): DecisionOutcome
    {
        $sync = $in->lastSyncHash;

        if ($in->dbPostExists && $in->fileExists) {
            // Fail-closed: an existing side that cannot be hashed is never
            // silently treated as convergent or divergent.
            if ($in->dbHash === null || $in->fileHash === null) {
                return new DecisionOutcome(
                    Decision::Conflict,
                    4,
                    'conflict',
                    'unhashable side — fail-closed',
                );
            }

            if ($in->dbHash === $in->fileHash) {
                return new DecisionOutcome(Decision::Skip, 1, 'ok', 'converged');
            }

            // §5.7: file regressed to the last applied state (stash / checkout -- .)
            // while db holds human work — re-export lossless, never import backwards.
            if ($in->worktreeRegression && $in->fileHash !== $sync) {
                return new DecisionOutcome(
                    Decision::AutoResolveDb,
                    9,
                    'ok',
                    'worktree_regression_auto_resolved: db',
                );
            }

            $fileEqSync = $sync !== null && $in->fileHash === $sync;
            $dbEqSync = $sync !== null && $in->dbHash === $sync;

            if ($fileEqSync && !$dbEqSync) {
                return new DecisionOutcome(Decision::Export, 2, 'ok', 'db diverged from synced');
            }
            if (!$fileEqSync && $dbEqSync) {
                return new DecisionOutcome(Decision::Import, 3, 'ok', 'file diverged from synced');
            }
            if (!$fileEqSync && !$dbEqSync) {
                return new DecisionOutcome(
                    Decision::Conflict,
                    4,
                    'conflict',
                    'both sides diverged from synced',
                );
            }

            // Both equal sync but differ from each other: impossible for a single
            // hash function — degenerate state, fail-closed.
            return new DecisionOutcome(Decision::Conflict, 4, 'conflict', 'degenerate hash state');
        }

        if (!$in->dbPostExists && $in->fileExists) {
            if ($in->stateHasDbId) {
                // §5.5 row 3: deleted in db, file alive → admin wins in dev;
                // export removes the file and records the tombstone.
                return new DecisionOutcome(
                    Decision::Export,
                    7,
                    'tombstone',
                    'db-deleted — export removes file, records tombstone',
                );
            }

            return new DecisionOutcome(Decision::Import, 6, 'ok', 'new entity from repo');
        }

        if ($in->dbPostExists && !$in->fileExists) {
            if ($in->stateHasDbId && $sync !== null && $in->dbHash !== null && $in->dbHash === $sync) {
                return new DecisionOutcome(
                    Decision::DeletePolicy,
                    5,
                    'pending-delete',
                    'deleted in repo — deletion policy applies (never passive)',
                );
            }

            return new DecisionOutcome(Decision::Export, 7, 'ok', 'new entity from admin');
        }

        // Neither side exists.
        if ($in->stateStatus === 'tombstone') {
            return new DecisionOutcome(
                Decision::PurgeTombstone,
                8,
                'tombstone',
                'tombstone purge candidate — caller checks TTL',
            );
        }

        return new DecisionOutcome(Decision::PurgeTombstone, 8, 'ok', 'orphan state row — remove');
    }
}
