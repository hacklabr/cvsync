<?php
/**
 * Contexto de um lote de apply (arquivo → banco).
 *
 * Montado por P5 (CLI/gatilhos) a partir das flags do comando e da matriz de
 * ambientes (P6). O Importer (P3) apenas consome — storage é mecanismo puro e
 * a política de ambiente não vive aqui.
 *
 * @package CVSync
 */

declare(strict_types=1);

namespace CVSync;

defined('ABSPATH') || exit;

final readonly class ImportContext
{
    /**
     * @param string      $trigger     'cli'|'git-hook'|'deploy'|'cron'|'passive' (§11.1).
     * @param string      $environment 'local'|'staging'|'homolog'|'prod' (resolvido por P6).
     * @param bool        $dryRun      Valida tudo, não grava nada (wp sync apply --dry-run).
     * @param bool        $forceLocks  --force-locks: somente CLI interativo com TTY (§8.4).
     * @param bool        $allowDelete --delete explícito (§5.5 — nunca passivo).
     * @param string|null $gitHead     HEAD no momento do apply (audit log / conflicts).
     */
    public function __construct(
        public string $trigger = 'cli',
        public string $environment = 'local',
        public bool $dryRun = false,
        public bool $forceLocks = false,
        public bool $allowDelete = false,
        public ?string $gitHead = null,
    ) {
    }
}
