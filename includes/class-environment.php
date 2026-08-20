<?php
/**
 * Environment — resolução de ambiente (spec §7.1), matriz normativa §7.3 e
 * leitura das constantes CVSYNC_* (§10.1).
 *
 * Esta classe é a ÚNICA fonte de leitura de constantes do plugin (P6 faz
 * apenas o define dos defaults no bootstrap):
 *
 *  - Precedência (§10.1): constante em wp-config.php > variável de ambiente
 *    (mesmo nome) > default. NENHUMA flag vive no banco (uma option viajaria
 *    com dumps — o desastre clássico é dump de staging restaurado em prod
 *    trazendo environment=staging);
 *  - Valor inválido em qualquer constante → DEFAULT FAIL-SAFE + warning admin
 *    (typo em wp-config nunca vira comportamento default silencioso);
 *  - Fallback fail-closed de ambiente: desconhecido → 'prod' (§7.1);
 *  - 'homolog' só é alcançável via CVSYNC_ENVIRONMENT explícita (não é
 *    environment type do core) — configuração consciente;
 *  - Gate triplo fator de prod (§7.3): --force + posix_isatty(STDIN) +
 *    CVSYNC_ALLOW_PROD_APPLY — stdin não-TTY RECUSA, nunca pula o prompt.
 *
 * API estática (contrato com P6 e com os comandos CLI):
 *   Environment::current(): string           'local'|'staging'|'homolog'|'prod'
 *   Environment::policy(): array             linha da matriz §7.3 do ambiente
 *   Environment::constant(string): mixed     leitura validada (const > env > default)
 *   Environment::conflictWinner(): 'db'|'file'
 *   Environment::deployGate(): 'warn'|'halt'
 *   Environment::prodApplyAllowed(bool $force): bool  — triplo fator
 *   Environment::warnings(): list<string>    warnings de valores inválidos
 *
 * @package CVSync
 */

declare(strict_types=1);

namespace CVSync;

defined('ABSPATH') || exit;

final class Environment
{
    public const LOCAL   = 'local';
    public const STAGING = 'staging';
    public const HOMOLOG = 'homolog';
    public const PROD    = 'prod';

    /**
     * Matriz normativa §7.3 como dados (projeção por ambiente do princípio
     * "existe trabalho humano não recuperável neste ambiente?").
     *
     *  - apply_auto:     o ambiente aceita apply automático (hook/pipeline/passivo);
     *  - export_auto:    o ambiente aceita export automático (save-hook/shutdown);
     *  - conflict_winner: default do ambiente (CVSYNC_CONFLICT_WINNER sobrescreve);
     *  - deploy_gate:    default do ambiente (CVSYNC_DEPLOY_GATE sobrescreve);
     *  - deletion:       política §5.5 ('trash' | 'trash-report' | 'never').
     */
    private const MATRIX = [
        self::LOCAL => [
            'apply_auto'      => true,   // git hook + HEAD-hash passivo
            'export_auto'     => true,   // save-hook, debounce, shutdown
            'conflict_winner' => 'db',   // + preservação; regressão: auto-db (§5.7)
            'deploy_gate'     => 'warn', // n/a — CLI interativo pergunta
            'deletion'        => 'trash',
        ],
        self::STAGING => [
            'apply_auto'      => true,   // pipeline
            'export_auto'     => true,
            'conflict_winner' => 'file', // job define CVSYNC_CONFLICT_WINNER=file
            'deploy_gate'     => 'warn',
            'deletion'        => 'trash',
        ],
        self::HOMOLOG => [
            'apply_auto'      => true,   // pipeline
            'export_auto'     => true,
            'conflict_winner' => 'db',   // + relatório no pipeline + notice persistente
            'deploy_gate'     => 'warn', // halt opcional
            'deletion'        => 'trash-report', // trash-only + relatório
        ],
        self::PROD => [
            'apply_auto'      => false,  // OFF — manual com triplo fator
            'export_auto'     => false,  // OFF — CLI manual livre (read-only no banco)
            'conflict_winner' => 'db',   // n/a (apply off) — fail-safe
            'deploy_gate'     => 'warn',
            'deletion'        => 'never', // drift via wp sync verify
        ],
    ];

    /**
     * Tabela normativa de constantes §10.1: nome => [default, validador].
     * O validador recebe o valor cru (string) e devolve o valor normalizado
     * ou null (= inválido → default fail-safe + warning).
     *
     * @var array<string, array{0: mixed, 1: callable(string): mixed}>
     */
    private static function registry(): array
    {
        return [
            'CVSYNC_ENVIRONMENT' => [
                null,
                static fn (string $v): ?string => in_array($v, [self::LOCAL, self::STAGING, self::HOMOLOG, self::PROD], true) ? $v : null,
            ],
            'CVSYNC_CONFLICT_WINNER' => [
                null, // default por ambiente (matriz §7.3)
                static fn (string $v): ?string => in_array($v, ['db', 'file'], true) ? $v : null,
            ],
            'CVSYNC_DEPLOY_GATE' => [
                'warn',
                static fn (string $v): ?string => in_array($v, ['warn', 'halt'], true) ? $v : null,
            ],
            'CVSYNC_ALLOW_PROD_APPLY' => [
                false,
                static fn (string $v): ?bool => in_array(strtolower($v), ['1', 'true', 'yes'], true) ? true : (in_array(strtolower($v), ['0', 'false', 'no'], true) ? false : null),
            ],
            'CVSYNC_IMPORT_USER' => [
                null,
                static fn (string $v): ?string => $v !== '' ? $v : null,
            ],
            'CVSYNC_CONTENT_DIR' => [
                null, // default derivado: <repo-root>/content
                static fn (string $v): ?string => $v !== '' && !str_contains($v, "\0") ? $v : null,
            ],
            'CVSYNC_FILE_MODE' => [
                0664,
                static fn (string $v): ?int => preg_match('/^0?[0-7]{3,4}$/', $v) === 1 ? octdec($v) : null,
            ],
            'CVSYNC_DIR_MODE' => [
                0775,
                static fn (string $v): ?int => preg_match('/^0?[0-7]{3,4}$/', $v) === 1 ? octdec($v) : null,
            ],
            'CVSYNC_ATTACHMENT_MAX_BYTES' => [
                10 * 1024 * 1024, // §A.5.4 — teto único 10 MB
                static fn (string $v): ?int => ctype_digit($v) && (int) $v > 0 ? (int) $v : null,
            ],
            'CVSYNC_SNAPSHOT_KEEP' => [
                10, // §11.2 — retenção últimos N snapshots
                static fn (string $v): ?int => ctype_digit($v) && (int) $v > 0 ? (int) $v : null,
            ],
            'CVSYNC_SNAPSHOT_MAX_BYTES' => [
                512 * 1024 * 1024, // §A.10.4 — teto de disco com purge LRU
                static fn (string $v): ?int => ctype_digit($v) && (int) $v > 0 ? (int) $v : null,
            ],
            'CVSYNC_TOMBSTONE_TTL_DAYS' => [
                90, // §5.5
                static fn (string $v): ?int => ctype_digit($v) && (int) $v > 0 ? (int) $v : null,
            ],
            // Constantes de mídia (🟡9 do r7): registradas aqui para que a
            // leitura seja única, validada e com warning — os consumidores do
            // P4 trocam defined()/constant() cru por Environment::constant().
            'CVSYNC_ATTACHMENT_MIME_TYPES' => [
                'image/jpeg,image/png,image/webp,image/gif,application/pdf', // §A.5.1.1
                static function (string $v): ?string {
                    $mimes = array_filter(array_map('trim', explode(',', $v)));
                    foreach ($mimes as $mime) {
                        if (preg_match('#^[a-z0-9][a-z0-9\-\.]*/[a-z0-9][a-z0-9\-\.\+]*$#i', $mime) !== 1) {
                            return null; // entrada malformada → default fail-safe + warning
                        }
                    }

                    return [] !== $mimes ? implode(',', $mimes) : null;
                },
            ],
            'CVSYNC_ATTACHMENT_ALLOW_SVG' => [
                false, // §A.9.3 — default-deny; opt-in exige sanitizador
                static fn (string $v): ?bool => in_array(strtolower($v), ['1', 'true', 'yes'], true) ? true : (in_array(strtolower($v), ['0', 'false', 'no'], true) ? false : null),
            ],
            'CVSYNC_ATTACHMENT_SCOPE' => [
                'referenced', // §A.5.5 — typo ('referneced') NUNCA vira 'all' silencioso
                static fn (string $v): ?string => in_array($v, ['referenced', 'all'], true) ? $v : null,
            ],
        ];
    }

    /** @var array<string, mixed> Cache de constantes já resolvidas. */
    private static array $resolved = [];

    /** @var list<string> Warnings de valores inválidos (admin notice §10.1). */
    private static array $warnings = [];

    /** @var string|null Cache do ambiente resolvido. */
    private static ?string $current = null;

    // ------------------------------------------------------------------
    // Ambiente (§7.1)
    // ------------------------------------------------------------------

    /**
     * Ambiente efetivo. Override por CVSYNC_ENVIRONMENT (única porta para
     * 'homolog'); fonte de verdade wp_get_environment_type(); desconhecido →
     * prod (fail-closed).
     */
    public static function current(): string
    {
        if (null !== self::$current) {
            return self::$current;
        }

        $override = self::constant('CVSYNC_ENVIRONMENT');
        if (is_string($override)) {
            return self::$current = $override;
        }

        $core = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';

        return self::$current = match ($core) {
            'local', 'development' => self::LOCAL,
            'staging'              => self::STAGING,
            'production'           => self::PROD,
            default                => self::PROD, // fail-closed (§7.1)
        };
    }

    /**
     * Linha da matriz §7.3 do ambiente efetivo, com overrides de constantes
     * aplicados (conflict_winner, deploy_gate).
     *
     * @return array{apply_auto: bool, export_auto: bool, conflict_winner: string, deploy_gate: string, deletion: string}
     */
    public static function policy(): array
    {
        $policy = self::MATRIX[self::current()];

        $winner = self::constant('CVSYNC_CONFLICT_WINNER');
        if (is_string($winner)) {
            $policy['conflict_winner'] = $winner;
        }

        $gate = self::constant('CVSYNC_DEPLOY_GATE');
        if (is_string($gate)) {
            $policy['deploy_gate'] = $gate;
        }

        return $policy;
    }

    /** Vencedor de conflito efetivo: constante > default do ambiente. */
    public static function conflictWinner(): string
    {
        return self::policy()['conflict_winner'];
    }

    /** Deploy gate efetivo: constante > default do ambiente. */
    public static function deployGate(): string
    {
        return self::policy()['deploy_gate'];
    }

    // ------------------------------------------------------------------
    // Gate triplo fator de prod (§7.3)
    // ------------------------------------------------------------------

    /**
     * Apply em prod exige os TRÊS fatores simultâneos (automação em prod exige
     * dois erros de configuração simultâneos, não um):
     *  1. --force explícito;
     *  2. TTY interativo — posix_isatty(STDIN); stdin não-TTY RECUSA (nunca
     *     "pula o prompt");
     *  3. Constante CVSYNC_ALLOW_PROD_APPLY=true.
     *
     * @return array{0: bool, 1: list<string>} [permitido, fatores ausentes]
     */
    public static function prodApplyGate(bool $force): array
    {
        $missing = [];

        if (! $force) {
            $missing[] = '--force';
        }
        if (! self::stdinIsTty()) {
            $missing[] = 'TTY interativo (stdin não-TTY → recusa, §7.3)';
        }
        if (self::constant('CVSYNC_ALLOW_PROD_APPLY') !== true) {
            $missing[] = 'CVSYNC_ALLOW_PROD_APPLY';
        }

        return [$missing === [], $missing];
    }

    /** TTY interativo: posix_isatty quando disponível; sem a extensão, STREAM_ISATTY (PHP 8.1+). */
    public static function stdinIsTty(): bool
    {
        if (! defined('STDIN')) {
            return false;
        }
        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDIN);
        }
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDIN);
        }

        return false; // indeterminável → fail-closed
    }

    // ------------------------------------------------------------------
    // Constantes §10.1 (única fonte de leitura)
    // ------------------------------------------------------------------

    /**
     * Leitura validada de uma constante CVSYNC_*: constante > env > default.
     * Valor inválido → default fail-safe + warning admin (registrado para o
     * notice; nunca silencioso).
     *
     * @throws \InvalidArgumentException Constante fora da tabela normativa.
     */
    public static function constant(string $name): mixed
    {
        if (array_key_exists($name, self::$resolved)) {
            return self::$resolved[$name];
        }

        $registry = self::registry();
        if (! isset($registry[$name])) {
            throw new \InvalidArgumentException(sprintf('Constante fora da tabela §10.1: %s', $name));
        }

        [$default, $validator] = $registry[$name];

        $raw = null;
        if (defined($name)) {
            $raw = constant($name);
        } else {
            $env = getenv($name);
            if (false !== $env && '' !== $env) {
                $raw = $env;
            }
        }

        if (null === $raw) {
            return self::$resolved[$name] = $default;
        }

        // Validação por tipo explícita (🔵3 do r7 — o ternário anterior era
        // tautológico e deixava bool passar sem validação):
        //  - bool no wp-config só é aceito onde o default é bool;
        //  - int idem; demais tipos caem no validador de string.
        if (is_bool($raw)) {
            if (is_bool($default)) {
                return self::$resolved[$name] = $raw;
            }
            self::$warnings[] = sprintf(
                '%s definida como bool (%s) onde o default não é bool — usando default fail-safe (%s).',
                $name,
                var_export($raw, true),
                var_export($default, true)
            );

            return self::$resolved[$name] = $default;
        }

        if (is_int($raw)) {
            if (is_int($default)) {
                return self::$resolved[$name] = $raw;
            }
            $raw = (string) $raw; // validador decide
        }

        $validated = $validator((string) $raw);
        if (null === $validated) {
            self::$warnings[] = sprintf(
                '%s com valor inválido (%s) — usando default fail-safe (%s).',
                $name,
                is_scalar($raw) ? var_export($raw, true) : gettype($raw),
                var_export($default, true)
            );

            return self::$resolved[$name] = $default;
        }

        return self::$resolved[$name] = $validated;
    }

    /** Diretório de conteúdo efetivo (§4.1): CVSYNC_CONTENT_DIR ou <repo-root>/content. */
    public static function contentDir(): string
    {
        $dir = self::constant('CVSYNC_CONTENT_DIR');

        return is_string($dir) && '' !== $dir ? $dir : dirname(ABSPATH) . '/content';
    }

    /**
     * Warnings acumulados de valores inválidos (§10.1). O bootstrap (P6)
     * renderiza como admin notice (manage_options).
     *
     * @return list<string>
     */
    public static function warnings(): array
    {
        return self::$warnings;
    }

    /**
     * Registra o renderer de admin notice dos warnings §10.1. Chamado pelo
     * bootstrap (P6) — idempotente e restrito a telas admin com manage_options.
     */
    public static function registerAdminNotices(): void
    {
        add_action('admin_notices', static function (): void {
            if (! current_user_can('manage_options')) {
                return;
            }
            foreach (self::warnings() as $warning) {
                printf(
                    '<div class="notice notice-warning"><p><strong>cvsync:</strong> %s</p></div>',
                    esc_html($warning)
                );
            }
        });
    }

    /** Reset de caches estáticos (testes). */
    public static function reset(): void
    {
        self::$resolved = [];
        self::$warnings = [];
        self::$current  = null;
    }
}
