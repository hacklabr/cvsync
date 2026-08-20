<?php

declare(strict_types=1);

namespace CVSync\Engine;

/**
 * Decision vocabulary of the sync engine (spec §5.2) — the ACTION dimension.
 * Persistence vocabulary (state table 'status' column) is EntityStatus, owned
 * by P2; the engine emits newStatus as a plain string from that vocabulary and
 * P2 validates it at its write boundary (reconciled r1-t2, DBA D1.1).
 *
 * Environment conflict policy (§7.3) is NOT here: the engine emits Conflict;
 * P6 decides the winner.
 */
enum Decision: string
{
    /** Cases 1 and convergent 8 — nothing to do. */
    case Skip = 'skip';

    /** Cases 2, 7 (db changed / new from admin) and "deleted in db, file alive"
     *  (§5.5: export removes the file, records tombstone — P3/P5 translate). */
    case Export = 'export';

    /** Cases 3, 6 (file changed / new from repo). */
    case Import = 'import';

    /** Case 4 — real conflict, environment matrix decides (outside P1). */
    case Conflict = 'conflict';

    /** Case 5 — deletion came from the repo; §5.5 policy applies (P5/P6). */
    case DeletePolicy = 'delete_policy';

    /** Case 8 — orphan state row or expired tombstone (TTL evaluated by P5). */
    case PurgeTombstone = 'purge_tombstone';

    /** Case 9 — working-tree regression signature (§5.7): db wins, re-export
     *  lossless; caller logs 'worktree_regression_auto_resolved: db'. */
    case AutoResolveDb = 'auto_resolve_db';
}
