<?php
/**
 * Vocabulário da coluna status de wp_cvsync_state (spec §5.2/§9.1).
 *
 * Dono: P2 (vocabulário de persistência). O engine (P1) emite a decisão
 * (CVSync\Engine\Decision); a tradução ação→status ocorre no orquestrador e a
 * persistência valida via EntityStatus::from() — nunca persistência cega
 * (spec §9: VARCHAR com validação na aplicação, já que dbDelta não gerencia
 * ENUM/CHECK).
 *
 * Nota: 'applied-degraded' NÃO é valor de status (§A.5.6) — degradação é
 * qualidade de ambiente, não estado de sync; vive apenas no audit log.
 *
 * @package CVSync\Storage
 */

declare(strict_types=1);

namespace CVSync\Storage;

defined('ABSPATH') || exit;

enum EntityStatus: string
{
    case Ok            = 'ok';
    case DirtyDb       = 'dirty_db';
    case DirtyFile     = 'dirty_file';
    case PendingRef    = 'pending_ref';
    case Conflict      = 'conflict';
    case Tombstone     = 'tombstone';
    case PendingDelete = 'pending-delete'; // spec §5.5
}
