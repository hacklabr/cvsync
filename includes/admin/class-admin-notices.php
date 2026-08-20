<?php
/**
 * AdminNotices — notices persistentes do plugin (spec §7.3, §10.1, §11.1,
 * §A.9.2). Restritos a manage_options; saída sempre escapada.
 *
 * Cobertura:
 *  1. FALHA da sonda PHP-off de uploads (§A.9.2) — crítico, persistente, NÃO
 *     dismissível (risco de execução de PHP em uploads/); o resultado é
 *     persistido em option pela activation (P6 escreve; esta classe lê);
 *  2. Sonda INDETERMINATE — warning dismissível (não-verificabilidade nunca
 *     trava operação);
 *  3. Drift em prod (§11.1: "log + admin notice informativo, sem agir") —
 *     HEAD ≠ last_applied_head → notice informativo apontando wp sync verify;
 *  4. Conflitos pendentes em homolog (§7.3: "notice persistente") — aponta a
 *     tela Ferramentas > CVSync e wp sync conflicts;
 *  5. skipped-fs-readonly recorrente (§10.7 — degradação graciosa que virou
 *     padrão: threshold no ring buffer do audit log).
 *
 * Os warnings de valores inválidos em constantes CVSYNC_* (§10.1) são
 * renderizados pelo próprio Environment::registerAdminNotices() (P5) — não
 * duplicados aqui.
 *
 * @package CVSync\Admin
 */

declare(strict_types=1);

namespace CVSync\Admin;

use CVSync\Environment;
use CVSync\Storage\AuditLog;
use CVSync\Storage\ConflictStore;
use CVSync\Storage\LogResult;
use CVSync\Storage\StateStore;
use CVSync\Triggers;

defined('ABSPATH') || exit;

final class AdminNotices
{
    /** Option com o último resultado da sonda PHP-off (escrita na activation). */
    public const OPTION_PROBE = 'cvsync_php_exec_probe';

    /** Option com as chaves de notices dismissíveis já dispensados. */
    private const OPTION_DISMISSED = 'cvsync_dismissed_notices';

    /**
     * Recorrência de skipped-fs-readonly que liga o notice: ocorrências no
     * ring buffer recente do audit log (§11.1).
     */
    private const FS_READONLY_THRESHOLD = 5;
    private const FS_READONLY_SAMPLE    = 200;

    public function __construct(
        private readonly StateStore $state,
        private readonly ConflictStore $conflicts,
        private readonly AuditLog $log,
    ) {
    }

    /** Registra o renderer e o handler de dismiss (chamado pelo bootstrap, P6). */
    public function register(): void
    {
        add_action('admin_init', [$this, 'maybeDismiss']);
        add_action('admin_notices', [$this, 'render']);
    }

    /** Dismiss persistido em option, com nonce e manage_options. */
    public function maybeDismiss(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $key = isset($_GET['cvsync-dismiss']) ? sanitize_key((string) wp_unslash($_GET['cvsync-dismiss'])) : '';
        if ('' === $key) {
            return;
        }
        $nonce = isset($_GET['_wpnonce']) ? (string) wp_unslash($_GET['_wpnonce']) : '';
        if (! wp_verify_nonce($nonce, 'cvsync-dismiss-' . $key)) {
            return;
        }

        $dismissed = get_option(self::OPTION_DISMISSED, []);
        $dismissed = is_array($dismissed) ? $dismissed : [];
        $dismissed[$key] = time();
        update_option(self::OPTION_DISMISSED, $dismissed, false);

        wp_safe_redirect(remove_query_arg(['cvsync-dismiss', '_wpnonce']));
        exit;
    }

    /** Renderiza os notices aplicáveis ao contexto atual. */
    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $this->renderProbeNotices();
        $this->renderProdDriftNotice();
        $this->renderHomologConflictsNotice();
        $this->renderFsReadonlyNotice();
    }

    // ------------------------------------------------------------------
    // Notices
    // ------------------------------------------------------------------

    /** Sonda PHP-off (§A.9.2): FAIL = crítico persistente; INDETERMINATE = warning dismissível. */
    private function renderProbeNotices(): void
    {
        $probe = get_option(self::OPTION_PROBE, null);
        if (! is_array($probe) || ! isset($probe['status'])) {
            return;
        }

        $detail = isset($probe['detail']) ? (string) $probe['detail'] : '';

        if ('fail' === $probe['status']) {
            printf(
                '<div class="notice notice-error"><p><strong>cvsync:</strong> %s<br><code>%s</code></p></div>',
                esc_html__(
                    'CRÍTICO: a sonda PHP-off detectou que PHP EXECUTA em uploads/ (§A.9.2). Desabilite a execução de PHP no diretório de uploads no servidor web e re-verifique com `wp sync verify`.',
                    'cvsync'
                ),
                esc_html($detail)
            );
            return;
        }

        if ('indeterminate' === $probe['status'] && ! $this->isDismissed('probe-indeterminate')) {
            printf(
                '<div class="notice notice-warning"><p><strong>cvsync:</strong> %s %s %s</p></div>',
                esc_html__(
                    'A sonda PHP-off de uploads (§A.9.2) ficou indeterminada na ativação (a sonda HTTP só roda em CLI).',
                    'cvsync'
                ),
                '' !== $detail ? '<br><code>' . esc_html($detail) . '</code>' : '',
                wp_kses_post($this->dismissLink('probe-indeterminate'))
            );
        }
    }

    /** Drift em prod (§11.1): HEAD ≠ last_applied_head → informativo, sem agir. */
    private function renderProdDriftNotice(): void
    {
        if (Environment::current() !== Environment::PROD) {
            return;
        }

        try {
            $head = Triggers::readHead(Triggers::repoRoot());
            if (null === $head) {
                return; // artefato deployado sem .git — nada a comparar
            }
            $lastApplied = $this->state->lastAppliedHead();
            if ($head === $lastApplied) {
                return; // convergente
            }
        } catch (\Throwable) {
            return; // tabela ausente/migration pendente: o gate §5.9 é a superfície disso
        }

        printf(
            '<div class="notice notice-info"><p><strong>cvsync:</strong> %s</p></div>',
            esc_html__(
                'O repositório diverge do último apply registrado neste ambiente (prod). Nenhuma ação automática será tomada — verifique o drift com `wp sync verify` e, se aplicável, `wp sync apply --force` (triplo fator §7.3).',
                'cvsync'
            )
        );
    }

    /** Conflitos pendentes em homolog (§7.3): notice persistente com apontadores. */
    private function renderHomologConflictsNotice(): void
    {
        if (Environment::current() !== Environment::HOMOLOG) {
            return;
        }

        try {
            $pending = $this->conflicts->listUnresolved(null, 1);
        } catch (\Throwable) {
            return;
        }
        if ([] === $pending) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p><strong>cvsync:</strong> %s <a href="%s">%s</a> %s <code>wp sync conflicts</code>.</p></div>',
            esc_html__('Há conflitos de sincronização pendentes neste ambiente (homolog, §7.3). Revise em', 'cvsync'),
            esc_url(admin_url('tools.php?page=cvsync')),
            esc_html__('Ferramentas > CVSync', 'cvsync'),
            esc_html__('ou resolva via', 'cvsync')
        );
    }

    /** skipped-fs-readonly recorrente (§10.7): degradação que virou padrão. */
    private function renderFsReadonlyNotice(): void
    {
        try {
            $recent = $this->log->recent(self::FS_READONLY_SAMPLE);
        } catch (\Throwable) {
            return;
        }

        $count = 0;
        foreach ($recent as $entry) {
            if (LogResult::SkippedFsReadonly === $entry->result) {
                $count++;
            }
        }
        if ($count < self::FS_READONLY_THRESHOLD) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p><strong>cvsync:</strong> %s</p></div>',
            esc_html__(
                'Exports recorrentes foram pulados por filesystem read-only (skipped-fs-readonly). O content dir não é gravável pelo webserver — verifique permissões/montagem ou rode o export via CLI. Detalhes em Ferramentas > CVSync ou `wp sync log`.',
                'cvsync'
            )
        );
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private function isDismissed(string $key): bool
    {
        $dismissed = get_option(self::OPTION_DISMISSED, []);

        return is_array($dismissed) && isset($dismissed[$key]);
    }

    private function dismissLink(string $key): string
    {
        $url = wp_nonce_url(
            add_query_arg('cvsync-dismiss', $key),
            'cvsync-dismiss-' . $key
        );

        return sprintf(
            '<a href="%s">%s</a>',
            esc_url($url),
            esc_html__('Dispensar', 'cvsync')
        );
    }
}
