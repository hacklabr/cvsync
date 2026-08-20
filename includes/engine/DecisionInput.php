<?php

declare(strict_types=1);

namespace CVSync\Engine;

/**
 * Lean input tuple for the decision table (spec §5.2) + disambiguators
 * (§5.5 deletions, §5.7 regression).
 *
 * Deliberately NOT the P2 StateRecord (reconciled r1-t2, §5):
 *  - dbPostExists and worktreeRegression are RUNTIME facts, absent from the
 *    state table (the post exists NOW; the git verdict comes from P5);
 *  - pre-filters (db_modified, file_mtime, bin_*) and auxiliary payloads are
 *    FORBIDDEN as decision input — the lean tuple makes that restriction
 *    structural rather than disciplinary;
 *  - the 9 cases are unit-testable (§14.6) with 8 scalar fixtures.
 *
 * P3 maps StateRecord + runtime facts → DecisionInput (one trivial function).
 */
final readonly class DecisionInput
{
    /**
     * @param bool        $dbPostExists       The entity EXISTS in the database now
     *        (not the state row saying so). Trash counts as deleted (§5.5);
     *        for attachments, absence = deleted (§A.2.3c).
     * @param string|null $dbHash             Canonical hash of the db side ('sha256:…');
     *        null when not computable or entity absent. Attachments with missing
     *        binary never reach the engine (§A.4.1 — handled by P4→P2 directly).
     * @param bool        $fileExists         Canonical file exists in the repo.
     * @param string|null $fileHash           Hash of the file side (recomputed, not
     *        trusted from the frontmatter alone).
     * @param string|null $lastSyncHash       state.last_sync_hash; null = no baseline.
     * @param string|null $stateStatus        Current state row status ('tombstone', …);
     *        null = no state row.
     * @param bool        $stateHasDbId       state.db_id filled — disambiguates §5.5
     *        ("deleted in repo" × "new in repo"; "deleted in db" × "never imported").
     * @param bool        $worktreeRegression §5.7 signature ALREADY evaluated by P5
     *        (git, CLI SAPI, GIT_OPTIONAL_LOCKS=0). The engine never touches git.
     */
    public function __construct(
        public bool $dbPostExists,
        public ?string $dbHash,
        public bool $fileExists,
        public ?string $fileHash,
        public ?string $lastSyncHash,
        public ?string $stateStatus,
        public bool $stateHasDbId,
        public bool $worktreeRegression = false,
    ) {
    }
}
