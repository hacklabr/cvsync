<?php
/**
 * ToolsPage — tela em Ferramentas > CVSync (§10.1: manage_options).
 * Read-only: lista os últimos registros do audit log e os conflitos
 * pendentes, com apontadores para os comandos CLI (§8.3). Nenhuma mutação
 * via admin — resolução é sempre via CLI (trust de shell, §10.1).
 *
 * Refatoração UX (F7/F8 + Guardian fase 1): seções em cards, empty states
 * com próximo passo, handoffs CLI com botão de copiar (a mutação continua
 * exclusivamente no CLI — normativo).
 *
 * @package CVSync\Admin
 */

declare(strict_types=1);

namespace CVSync\Admin;

use CVSync\Storage\AuditLog;
use CVSync\Storage\ConflictStore;

defined('ABSPATH') || exit;

final class ToolsPage
{
    private const MENU_SLUG   = 'cvsync';
    private const LOG_LIMIT   = 50;
    private const CONFLICT_LIMIT = 50;

    public function __construct(
        private readonly AuditLog $log,
        private readonly ConflictStore $conflicts,
    ) {
    }

    /** Registra a página (chamado pelo bootstrap, P6) — e a aba Configuração. */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [SettingsPage::class, 'enqueueAssets']);

        // Aba "Configuração" (SettingsPage) — mesma tela pai (Ferramentas),
        // option única `cvsync_settings` (contrato central).
        ( new SettingsPage() )->register();
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'tools.php',
            __('CVSync', 'cvsync'),
            __('CVSync', 'cvsync'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Sem permissão.', 'cvsync'));
        }

        echo '<div class="wrap cvsync-admin">';
        printf('<h1>%s</h1>', esc_html__('CVSync — Log e Conflitos', 'cvsync'));
        SettingsPage::renderTabs('log');

        $this->renderConflicts();
        $this->renderLog();
        $this->renderCopyScript();

        echo '</div>';
    }

    // ------------------------------------------------------------------
    // Seções
    // ------------------------------------------------------------------

    private function renderConflicts(): void
    {
        echo '<section class="cvsync-card" aria-labelledby="cvsync-h-conflicts">';
        printf('<h2 class="cvsync-card__title" id="cvsync-h-conflicts">%s</h2>', esc_html__('Conflitos pendentes', 'cvsync'));

        try {
            $conflicts = $this->conflicts->listUnresolved(null, self::CONFLICT_LIMIT);
        } catch (\Throwable) {
            printf('<p class="cvsync-empty">%s</p>', esc_html__('Tabela de conflitos indisponível (schema pendente?) — rode `wp sync verify` ou a ativação do plugin para instalar o schema.', 'cvsync'));
            echo '</section>';

            return;
        }

        if ([] === $conflicts) {
            printf(
                '<p class="cvsync-empty">%s</p>',
                esc_html__('Nenhum conflito pendente — banco e repositório convergentes no último checkpoint. Divergências registradas aparecem aqui até a resolução via CLI.', 'cvsync')
            );
            echo '</section>';

            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        foreach (['ID', 'Entidade', 'Perdedor', 'Vencedor', 'Gatilho', 'Actor', 'Data'] as $th) {
            printf('<th>%s</th>', esc_html($th));
        }
        echo '</tr></thead><tbody>';

        foreach ($conflicts as $conflict) {
            printf(
                '<tr><td>%d</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                (int) $conflict->id,
                esc_html($conflict->ref->toTupleString()),
                esc_html($conflict->loserSide),
                esc_html($conflict->winner),
                esc_html($conflict->trigger),
                esc_html($conflict->actor),
                esc_html(wp_date(
                    get_option('date_format') . ' ' . get_option('time_format'),
                    $conflict->createdAt->getTimestamp()
                ))
            );
        }

        echo '</tbody></table>';
        printf('<p class="cvsync-hint">%s</p>', esc_html__('Inspeção e resolução via CLI (§7.4):', 'cvsync'));
        $this->renderCopyableCommand('wp sync conflicts');
        $this->renderCopyableCommand('wp sync conflict show <id>');
        $this->renderCopyableCommand('wp sync resolve <entity> --keep=db|file');
        echo '</section>';
    }

    private function renderLog(): void
    {
        echo '<section class="cvsync-card" aria-labelledby="cvsync-h-log">';
        printf('<h2 class="cvsync-card__title" id="cvsync-h-log">%s</h2>', esc_html__('Últimos registros do audit log', 'cvsync'));

        try {
            $entries = $this->log->recent(self::LOG_LIMIT);
        } catch (\Throwable) {
            printf('<p class="cvsync-empty">%s</p>', esc_html__('Tabela de log indisponível (schema pendente?) — rode a ativação do plugin ou `wp sync verify` para instalar o schema.', 'cvsync'));
            echo '</section>';

            return;
        }

        if ([] === $entries) {
            printf(
                '<p class="cvsync-empty">%s</p>',
                esc_html__('Nenhum registro ainda — rode um apply/export pelo painel ou CLI (wp sync apply / wp sync export) e o audit trail aparece aqui.', 'cvsync')
            );
            echo '</section>';

            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        foreach (['Data', 'Entidade', 'Direção', 'Gatilho', 'Resultado', 'Actor', 'Arquivo', 'Erro'] as $th) {
            printf('<th>%s</th>', esc_html($th));
        }
        echo '</tr></thead><tbody>';

        foreach ($entries as $entry) {
            printf(
                '<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',
                esc_html(wp_date(
                    get_option('date_format') . ' ' . get_option('time_format'),
                    $entry->createdAt->getTimestamp()
                )),
                esc_html($entry->ref->toTupleString()),
                esc_html(null !== $entry->direction ? $entry->direction->value : '—'),
                esc_html($entry->trigger),
                esc_html($entry->result->value),
                esc_html($entry->actor),
                esc_html(null !== $entry->filePath ? $entry->filePath : ''),
                esc_html(null !== $entry->error ? $entry->error : '')
            );
        }

        echo '</tbody></table>';
        printf('<p class="cvsync-hint">%s</p>', esc_html__('Mais contexto via CLI (§11.1):', 'cvsync'));
        $this->renderCopyableCommand('wp sync log --last=50');
        $this->renderCopyableCommand('wp sync blame <post_type:slug>');
        $this->renderCopyableCommand('wp sync status');
        echo '</section>';
    }

    // ------------------------------------------------------------------
    // Copy-button (handoff CLI — mutação permanece exclusivamente no CLI)
    // ------------------------------------------------------------------

    private function renderCopyableCommand(string $command): void
    {
        printf(
            '<p class="cvsync-copy"><code>%s</code> <button type="button" class="button button-small" data-cvsync-copy="%s" aria-label="%s">%s</button></p>',
            esc_html($command),
            esc_attr($command),
            sprintf(
                /* translators: %s: comando CLI. */
                esc_html__('Copiar comando %s', 'cvsync'),
                esc_html($command)
            ),
            esc_html__('Copiar', 'cvsync')
        );
    }

    /** JS mínimo: copiar comandos do handoff (clipboard API, sem dependências). */
    private function renderCopyScript(): void
    {
        ?>
        <script>
        (function () {
            'use strict';
            document.querySelectorAll('[data-cvsync-copy]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var cmd = btn.getAttribute('data-cvsync-copy');
                    var done = function () {
                        var old = btn.textContent;
                        btn.textContent = '<?php echo esc_js(__('Copiado!', 'cvsync')); ?>';
                        window.setTimeout(function () { btn.textContent = old; }, 1500);
                    };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(cmd).then(done, done);
                    } else {
                        var ta = document.createElement('textarea');
                        ta.value = cmd;
                        document.body.appendChild(ta);
                        ta.select();
                        document.execCommand('copy');
                        document.body.removeChild(ta);
                        done();
                    }
                });
            });
        })();
        </script>
        <?php
    }
}
