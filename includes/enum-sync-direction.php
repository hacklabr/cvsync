<?php
/**
 * Direção de um ciclo de sync convergente (coluna last_sync_direction, §9.1;
 * coluna direction do audit log, §9.3).
 *
 * @package CVSync\Storage
 */

declare(strict_types=1);

namespace CVSync\Storage;

defined('ABSPATH') || exit;

enum SyncDirection: string
{
    case DbToFile = 'db_to_file';
    case FileToDb = 'file_to_db';
}
