<?php
/**
 * Schema do plugin cvsync: DDL consolidada das 3 tabelas (spec §9) + delta de
 * mídia (Apêndice A, §A.4.2), versionamento de schema e gate de migration
 * pendente (spec §5.9).
 *
 * Emendas ratificadas na r1 (contracts):
 *  - A1: post_type VARCHAR(20) NOT NULL DEFAULT '' (a spec §9.1 dizia NULL;
 *    NULL em coluna de UNIQUE KEY nunca colide no MariaDB — a unicidade da
 *    tupla §6.3 não valeria para nav_menu/menu_location/branding).
 *  - A3: coluna `trigger` renomeada para `trigger_src` (palavra reservada no
 *    MariaDB; $wpdb->insert() não emite backticks).
 *
 * Apêndice B (§B.5, delta 2→3): entity_key VARCHAR(191) → VARCHAR(255) nas
 * TRÊS tabelas — a chave composta '{taxonomy}:{slug}' chega a 32+1+200=233 no
 * pior caso do core; 191 estouraria em strict mode num termo legítimo.
 * Conflicts/log gravam a mesma chave — alargar só a state criaria falha de
 * append justamente no evento raro.
 *
 * Convenções dbDelta: dois espaços após "PRIMARY KEY", KEY (não INDEX), um
 * campo por linha, sem CHECK/ENUM (validação de domínio na aplicação — enums
 * deste pacote), sem FKs (padrão WP). Nenhum índice em tabelas do core.
 *
 * @package CVSync\Storage
 */

declare(strict_types=1);

namespace CVSync\Storage;

defined('ABSPATH') || exit;

final class Schema
{
    /**
     * 1 = 3 tabelas base (spec §9); 2 = delta mídia §A.4.2 (bin_hash/bin_size/
     * bin_mtime + idx_binhash); 3 = delta termos §B.5 (entity_key 191→255 nas
     * três tabelas). A primeira instalação deste código já nasce na versão
     * atual (deltas consolidados na DDL); a numeração serve ao gate §5.9 e a
     * upgrades futuros.
     */
    public const SCHEMA_VERSION = 3;

    /** Option com a versão instalada (autoload=no). */
    public const OPTION_NAME = 'cvsync_schema_version';

    /**
     * Nome completo da tabela com o prefixo do site.
     *
     * @param string $suffix 'state' | 'conflicts' | 'log'.
     */
    public static function table(string $suffix): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cvsync_' . $suffix;
    }

    /**
     * DDL consolidada (map suffix => CREATE TABLE) — fonte do dbDelta e de
     * testes de schema.
     *
     * @return array<string, string>
     */
    public static function ddl(): array
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();
        $state          = self::table('state');
        $conflicts      = self::table('conflicts');
        $log            = self::table('log');

        return [
            // spec §9.1 + delta §A.4.2 consolidado + emenda A1.
            'state' => "CREATE TABLE {$state} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_kind VARCHAR(32) NOT NULL,
  post_type VARCHAR(20) NOT NULL DEFAULT '',
  entity_key VARCHAR(255) NOT NULL,
  db_id BIGINT UNSIGNED NULL,
  db_hash CHAR(64) NULL,
  db_modified DATETIME NULL,
  file_hash CHAR(64) NULL,
  file_mtime BIGINT UNSIGNED NULL,
  bin_hash CHAR(64) NULL,
  bin_size BIGINT UNSIGNED NULL,
  bin_mtime BIGINT UNSIGNED NULL,
  last_sync_hash CHAR(64) NULL,
  last_sync_direction VARCHAR(16) NULL,
  last_sync_at DATETIME NULL,
  last_applied_head VARCHAR(64) NULL,
  format_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  status VARCHAR(16) NOT NULL DEFAULT 'ok',
  tombstone_at DATETIME NULL,
  pending_payload MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_entity (entity_kind, post_type, entity_key),
  KEY idx_db (entity_kind, post_type, db_id),
  KEY idx_status (status, entity_kind),
  KEY idx_tombstone (status, tombstone_at),
  KEY idx_binhash (bin_hash)
) ENGINE=InnoDB {$charsetCollate};",

            // spec §7.4 detalhada + emenda A3 (trigger_src).
            'conflicts' => "CREATE TABLE {$conflicts} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_kind VARCHAR(32) NOT NULL,
  entity_key VARCHAR(255) NOT NULL,
  loser_side VARCHAR(8) NOT NULL,
  loser_payload MEDIUMTEXT NOT NULL,
  winner VARCHAR(8) NOT NULL,
  trigger_src VARCHAR(32) NOT NULL DEFAULT '',
  actor VARCHAR(191) NOT NULL DEFAULT '',
  git_head VARCHAR(64) NULL,
  created_at DATETIME NOT NULL,
  resolved_at DATETIME NULL,
  PRIMARY KEY  (id),
  KEY idx_entity (entity_kind, entity_key, resolved_at)
) ENGINE=InnoDB {$charsetCollate};",

            // spec §9.3 detalhada + §A.10.5 (bytes) + emenda A3 (trigger_src).
            'log' => "CREATE TABLE {$log} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_kind VARCHAR(32) NOT NULL DEFAULT '',
  entity_key VARCHAR(255) NOT NULL DEFAULT '',
  post_type VARCHAR(20) NOT NULL DEFAULT '',
  direction VARCHAR(16) NOT NULL DEFAULT '',
  trigger_src VARCHAR(32) NOT NULL DEFAULT '',
  actor VARCHAR(191) NOT NULL DEFAULT '',
  file_path VARCHAR(255) NULL,
  before_hash CHAR(64) NULL,
  after_hash CHAR(64) NULL,
  bytes BIGINT UNSIGNED NULL,
  result VARCHAR(48) NOT NULL DEFAULT '',
  error TEXT NULL,
  duration_ms INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_blame (entity_kind, entity_key, created_at),
  KEY idx_created (created_at)
) ENGINE=InnoDB {$charsetCollate};",
        ];
    }

    /**
     * Activation/upgrade hook do schema: dbDelta() idempotente das 3 tabelas +
     * migration manual do delta B.5 + bump da option de versão. Chamado por
     * register_activation_hook() em cvsync.php (P6) e pelo passo de migrations
     * do pipeline (pré-apply, §12.3).
     *
     * Nunca lança em ambiente sem privilégio DDL: retorna false e loga; a
     * recusa dura de operação fica no gate assertNoPendingMigration() (§5.9).
     */
    public static function install(): bool
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach (self::ddl() as $sql) {
            dbDelta($sql);
        }

        if ('' !== $wpdb->last_error) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[cvsync] schema install failed: ' . $wpdb->last_error);
            return false;
        }

        if (! self::migrateEntityKeyWidth()) {
            return false;
        }

        update_option(self::OPTION_NAME, self::SCHEMA_VERSION, '', false);

        return true;
    }

    /**
     * Delta B.5 (2→3): entity_key VARCHAR(191) → VARCHAR(255) nas três tabelas.
     *
     * dbDelta EMITE CHANGE COLUMN quando o token de tipo diverge
     * (varchar(191) ≠ varchar(255)) — é um dos poucos ALTERs que ele cobre.
     * Este passo manual é o cinto-e-suspenders: verifica o COLUMN_TYPE real em
     * INFORMATION_SCHEMA e emite o MODIFY apenas se necessário — idempotente
     * por construção (rodar 2× = zero diff na 2ª; critério B.8.12) e imune a
     * qualquer edge case de parsing do dbDelta em upgrades 2→3.
     *
     * ROLLBACK (runbook de downgrade 3→2 — transcrição §B.5):
     *   0. Pré-condição em cada UMA das três tabelas (falha aborta o downgrade):
     *        SELECT MAX(LENGTH(entity_key)) <= 191 FROM {tabela};
     *   1. ALTER TABLE {$wpdb->prefix}cvsync_state
     *        MODIFY entity_key VARCHAR(191) NOT NULL;
     *      ALTER TABLE {$wpdb->prefix}cvsync_conflicts
     *        MODIFY entity_key VARCHAR(191) NOT NULL;
     *      ALTER TABLE {$wpdb->prefix}cvsync_log
     *        MODIFY entity_key VARCHAR(191) NOT NULL DEFAULT '';
     *   2. Bump manual da option cvsync_schema_version para 2.
     *
     * @return bool false em erro de DDL (logado; o gate §5.9 continua recusando).
     */
    private static function migrateEntityKeyWidth(): bool
    {
        global $wpdb;

        $definitions = [
            // suffix => definição completa da coluna no alvo
            'state'     => 'VARCHAR(255) NOT NULL',
            'conflicts' => 'VARCHAR(255) NOT NULL',
            'log'       => "VARCHAR(255) NOT NULL DEFAULT ''",
        ];

        foreach ($definitions as $suffix => $columnDef) {
            $table = self::table($suffix);

            $columnType = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'entity_key'",
                    $table
                )
            );

            // Tabela inexistente (instalação fresca) ou já migrada → no-op.
            if (null === $columnType || 'varchar(255)' === strtolower((string) $columnType)) {
                continue;
            }

            $wpdb->query(
                $wpdb->prepare("ALTER TABLE %i MODIFY entity_key {$columnDef}", $table)
            );

            if ('' !== $wpdb->last_error) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log(sprintf('[cvsync] B.5 migration failed on %s: %s', $table, $wpdb->last_error));
                return false;
            }
        }

        return true;
    }

    /** Versão instalada; 0 = schema ausente. */
    public static function installedVersion(): int
    {
        return (int) get_option(self::OPTION_NAME, 0);
    }

    public static function needsMigration(): bool
    {
        return self::installedVersion() < self::SCHEMA_VERSION;
    }

    /**
     * Gate §5.9: o apply chama isto na primeira linha e recusa-se a rodar com
     * migration pendente (DDL comita implicitamente — a transação por entidade
     * não pode conviver com schema em transição).
     *
     * @throws MigrationPendingException Quando há migration pendente.
     */
    public static function assertNoPendingMigration(): void
    {
        if (self::needsMigration()) {
            throw new MigrationPendingException(
                sprintf(
                    'cvsync: schema version %d installed, %d required — run the plugin migration step before apply.',
                    self::installedVersion(),
                    self::SCHEMA_VERSION
                )
            );
        }
    }
}
