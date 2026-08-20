<?php

declare(strict_types=1);

namespace CVSync\Engine;

/**
 * Outcome of the decision table.
 *
 * newStatus is a string from the P2 EntityStatus vocabulary ('ok' | 'dirty_db' |
 * 'dirty_file' | 'pending_ref' | 'conflict' | 'tombstone' | 'pending-delete').
 * The engine stays pure (no P2 enum import); P2 validates at its write boundary
 * via EntityStatus::from() (reconciled r1-t2, DBA D1.1).
 */
final readonly class DecisionOutcome
{
    /**
     * @param Decision $decision Action to execute.
     * @param int      $case     Case number of the §5.2 table (1–9) — auditability/log.
     * @param string   $newStatus Value for state.status to persist (P2 writes blindly
     *        after domain validation).
     * @param string   $reason    Short stable phrase for the audit log
     *        (e.g. 'db diverged from synced').
     */
    public function __construct(
        public Decision $decision,
        public int $case,
        public string $newStatus,
        public string $reason,
    ) {
    }
}
