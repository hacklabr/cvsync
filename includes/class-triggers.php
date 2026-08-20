<?php
/**
 * Triggers — gatilho passivo HEAD-hash em admin_init (spec §8.2) e leitura de
 * .git/HEAD em PHP PURO (sem invocar o binário git — o plugin NUNCA roda git
 * no runtime web; §5.7 confina o binário ao SAPI CLI).
 *
 * Fluxo do check passivo (rede de segurança do ambiente local):
 *  1. admin_init → lê o SHA do HEAD em O(1) — leitura de 2 arquivos texto
 *     (HEAD + ref solto; packed-refs só quando o ref está empacotado);
 *  2. Compara com MAX(last_applied_head) da state table (StateStore::
 *     lastAppliedHead — índice natural, sem scan);
 *  3. Divergiu → wp_schedule_single_event('cvsync_passive_reconcile') —
 *     NUNCA aplica dentro do request que detectou (§8.2);
 *  4. O handler do evento roda o reconcile SOMENTE se a matriz §7.3 permitir
 *     apply automático no ambiente (prod: nunca). WP-Cron de apply está
 *     rebaixado pela spec: recomendado DISABLE_WP_CRON + cron de sistema
 *     chamando WP-CLI; este evento é o fallback de convergência — com
 *     DISABLE_WP_CRON ele nunca dispara em request de visitante.
 *
 * Crontab de sistema documentado (§8.2, staging):
 *   * * * * * cd /var/www/site && wp sync apply --format=json >> /var/log/cvsync.log 2>&1
 *   (convergência de fallback; pré-filtro por file_mtime no apply)
 *
 * Hooks de alerting registrados neste pacote (§11.1) — disparados pelo
 * ApplyRunner (CLI), documentados aqui como contrato público do plugin:
 *   do_action('cvsync_applied', array $summary)              — lote convergido
 *   do_action('cvsync_failed', array $summary)               — lote com failed>0
 *   do_action('cvsync_conflict_registered', ConflictRecord)  — perdedor preservado
 *   do_action('cvsync_files_materialized', list<string>)     — §A.10.3 (P4)
 * O plugin não inventa canal de notificação — cada projeto pluga seu notifier.
 *
 * @package CVSync
 */

declare(strict_types=1);

namespace CVSync;

use CVSync\Storage\StateStore;

defined('ABSPATH') || exit;

final class Triggers
{
    /** Hook do evento agendado pelo check passivo (§8.2). */
    public const RECONCILE_HOOK = 'cvsync_passive_reconcile';

    /**
     * @param callable $reconcile Runner do apply (ApplyRunner::run) — injetado
     *        pelo bootstrap/CLI para não acoplar este pacote ao WP-CLI.
     */
    public function __construct(
        private readonly StateStore $state,
        private readonly mixed $reconcile = null,
    ) {
    }

    /**
     * Registra o check passivo + o handler do evento agendado. Chamado pelo
     * bootstrap (P6) — nunca registra WP-Cron de apply recorrente (rebaixado
     * pela spec §8.2; cron de sistema é o caminho documentado).
     */
    public function register(): void
    {
        add_action('admin_init', [$this, 'onAdminInit']);
        add_action(self::RECONCILE_HOOK, [$this, 'onScheduledReconcile']);
    }

    /**
     * Check passivo O(1): HEAD ≠ last_applied_head → agenda reconcile fora do
     * request. Custo: 1–2 file reads + 1 query indexada; nunca hasheia conteúdo.
     */
    public function onAdminInit(): void
    {
        // O check é rede de segurança do fluxo local (git hooks são o primário);
        // em ambientes sem apply automático (prod) não há o que agendar.
        if (! Environment::policy()['apply_auto']) {
            return;
        }

        $head = self::readHead(self::repoRoot());
        if (null === $head) {
            return; // sem .git (artefato deployado) — nada a comparar
        }

        $lastApplied = $this->state->lastAppliedHead();
        if ($head === $lastApplied) {
            return; // convergente
        }

        if (! wp_next_scheduled(self::RECONCILE_HOOK)) {
            wp_schedule_single_event(time(), self::RECONCILE_HOOK);
        }
    }

    /**
     * Handler do evento agendado. Roda FORA do request detector (§8.2) e só
     * quando a matriz permite apply automático — prod é fail-closed mesmo aqui.
     */
    public function onScheduledReconcile(): void
    {
        if (! Environment::policy()['apply_auto']) {
            return;
        }
        if (! is_callable($this->reconcile)) {
            return; // runner não injetado (bootstrap parcial) — fail-open, próximo checkpoint reprocessa
        }

        ($this->reconcile)('passive');
    }

    // ------------------------------------------------------------------
    // Leitura de HEAD em PHP puro (O(1), sem binário git — §5.7/§8.2)
    // ------------------------------------------------------------------

    /**
     * Raiz do repositório: caminha do content dir para cima procurando `.git`
     * (diretório ou gitfile — worktrees). Fallback: pai do content dir
     * (§4.1 — content/ vive na raiz do repo). Não basta dirname(): com
     * CVSYNC_CONTENT_DIR aninhado (ex.: <repo>/data/content) o pai não é a
     * raiz git e o dirty-set do `git status` falaria outra língua (🟡11 do r7).
     */
    public static function repoRoot(): string
    {
        $dir = rtrim(Environment::contentDir(), '/');

        // O content dir pode não existir ainda (clone fresco).
        while ('' !== $dir && '/' !== $dir && ! is_dir($dir)) {
            $dir = dirname($dir);
        }

        for ($i = 0; $i < 32 && '' !== $dir && '/' !== $dir; $i++) {
            if (is_dir($dir . '/.git') || is_file($dir . '/.git')) {
                return $dir;
            }
            $dir = dirname($dir);
        }

        return dirname(rtrim(Environment::contentDir(), '/'));
    }

    /**
     * SHA do HEAD atual, em PHP puro. Suporta:
     *  - .git como diretório (clone normal);
     *  - .git como arquivo 'gitdir: <path>' (worktree/submódulo) — com
     *    'commondir' para refs empacotados compartilhados;
     *  - ref solto (.git/refs/heads/<branch>) e packed-refs.
     *
     * @return string|null SHA (40/64 hex) ou null quando indisponível/ilegível.
     */
    public static function readHead(string $repoRoot): ?string
    {
        $gitDir = self::resolveGitDir($repoRoot);
        if (null === $gitDir) {
            return null;
        }

        $headFile = $gitDir . '/HEAD';
        if (! is_file($headFile) || ! is_readable($headFile)) {
            return null;
        }

        $head = trim((string) file_get_contents($headFile));
        if ('' === $head) {
            return null;
        }

        // HEAD detached: SHA direto.
        if (preg_match('/^[0-9a-f]{40,64}$/', $head) === 1) {
            return $head;
        }

        if (str_starts_with($head, 'ref: ')) {
            $ref = trim(substr($head, 5));

            // Ref solto.
            $refFile = $gitDir . '/' . $ref;
            if (is_file($refFile) && is_readable($refFile)) {
                $sha = trim((string) file_get_contents($refFile));
                if (preg_match('/^[0-9a-f]{40,64}$/', $sha) === 1) {
                    return $sha;
                }
            }

            // Ref empacotado (packed-refs vive no commondir em worktrees).
            $commonDir = self::resolveCommonDir($gitDir);
            $packed    = $commonDir . '/packed-refs';
            if (is_file($packed) && is_readable($packed)) {
                foreach ((array) file($packed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if ('' === $line || '#' === $line[0] || '^' === $line[0]) {
                        continue;
                    }
                    $parts = explode(' ', $line, 2);
                    if (2 === count($parts) && trim($parts[1]) === $ref) {
                        $sha = trim($parts[0]);
                        if (preg_match('/^[0-9a-f]{40,64}$/', $sha) === 1) {
                            return $sha;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resolve o gitdir efetivo: .git diretório ou arquivo 'gitdir: <path>'
     * (worktree). Path relativo no gitdir é relativo ao diretório do arquivo .git.
     */
    private static function resolveGitDir(string $repoRoot): ?string
    {
        $dotGit = $repoRoot . '/.git';

        if (is_dir($dotGit)) {
            return $dotGit;
        }

        if (is_file($dotGit) && is_readable($dotGit)) {
            $content = trim((string) file_get_contents($dotGit));
            if (str_starts_with($content, 'gitdir:')) {
                $path = trim(substr($content, 7));
                if (! str_starts_with($path, '/')) {
                    $path = $repoRoot . '/' . $path;
                }
                $real = realpath($path);

                return false !== $real && is_dir($real) ? $real : null;
            }
        }

        return null;
    }

    /** commondir de um worktree (refs compartilhados); default = o próprio gitdir. */
    private static function resolveCommonDir(string $gitDir): string
    {
        $commonFile = $gitDir . '/commondir';
        if (is_file($commonFile) && is_readable($commonFile)) {
            $path = trim((string) file_get_contents($commonFile));
            if ('' !== $path) {
                if (! str_starts_with($path, '/')) {
                    $path = $gitDir . '/' . $path;
                }
                $real = realpath($path);
                if (false !== $real && is_dir($real)) {
                    return $real;
                }
            }
        }

        return $gitDir;
    }
}
