<?php
/**
 * Vocabulário da coluna result de wp_cvsync_log (spec §11.1 + §A.10.5).
 *
 * Dono: P2. Compartilhado com os outcomes de ExportResult/ImportResult (P3) —
 * vocabulário único (R9 do CMS, r1). Exceção consciente: o outcome
 * 'skipped-lock-failopen' do export NÃO gera linha de log (r1-t2 do CMS:
 * lock fail-open não é evento de sync — a entidade permanece dirty_db e
 * reaparece no próximo ciclo; logar seria ruído em rajada de saves).
 *
 * @package CVSync\Storage
 */

declare(strict_types=1);

namespace CVSync\Storage;

defined('ABSPATH') || exit;

enum LogResult: string
{
    case Applied                        = 'applied';
    case SkippedIdempotent              = 'skipped-idempotent';
    case SkippedLocked                  = 'skipped-locked';
    case SkippedProdFlag                = 'skipped-prod-flag';
    case SkippedFsReadonly              = 'skipped-fs-readonly';
    case ConflictAutoResolved           = 'conflict-auto-resolved';
    case PendingRef                     = 'pending_ref';
    case Rejected                       = 'rejected';
    case Error                          = 'error';
    // Apêndice A (§A.10.5):
    case Sideloaded                     = 'sideloaded';
    case BinaryHashMismatch             = 'binary-hash-mismatch';
    case BinaryRematerialized           = 'binary_rematerialized';
    case AppliedDegraded                = 'applied-degraded';
    case SkippedOversized               = 'skipped-oversized';
    case LfsPointerDetected             = 'lfs-pointer-detected';
    case WorktreeRegressionAutoResolved = 'worktree_regression_auto_resolved';
}
