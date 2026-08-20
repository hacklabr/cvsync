<?php
/**
 * ToolsPage — tela mínima em Ferramentas > CVSync (§10.1: manage_options).
 * Read-only: lista os últimos registros do audit log e os conflitos
 * pendentes, com apontadores para os comandos CLI (§8.3). Nenhuma mutação
 * via admin — resolução é sempre via CLI (trust de shell, §10.1).
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

    /** Registra a página (chamado pelo bootstrap, P6). */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
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

        echo '<div class="wrap">';
        printf('<h1>%s</h1>', esc_html__('CVSync — Log e Conflitos', 'cvsync'));

        $this->renderConflicts();
        $this->renderLog();

        echo '</div>';
    }

    // ------------------------------------------------------------------
    // Seções
    // ------------------------------------------------------------------

    private function renderConflicts(): void
    {
        printf('<h2>%s</h2>', esc_html__('Conflitos pendentes', 'cvsync'));

        try {
            $conflicts = $this->conflicts->listUnresolved(null, self::CONFLICT_LIMIT);
        } catch (\Throwable) {
            printf('<p>%s</p>', esc_html__('Tabela de conflitos indisponível (schema pendente?).', 'cvsync'));
            return;
        }

        if ([] === $conflicts) {
            printf('<p>%s</p>', esc_html__('Nenhum conflito pendente.', 'cvsync'));
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
        printf(
            '<p class="description">%s</p>',
            esc_html__('Inspeção e resolução via CLI: `wp sync conflicts` → `wp sync conflict show <id>` → `wp sync resolve <entity> --keep=db|file` (§7.4).', 'cvsync')
        );
    }

    private function renderLog(): void
    {
        printf('<h2>%s</h2>', esc_html__('Últimos registros do audit log', 'cvsync'));

        try {
            $entries = $this->log->recent(self::LOG_LIMIT);
        } catch (\Throwable) {
            printf('<p>%s</p>', esc_html__('Tabela de log indisponível (schema pendente?).', 'cvsync'));
            return;
        }

        if ([] === $entries) {
            printf('<p>%s</p>', esc_html__('Nenhum registro.', 'cvsync'));
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
        printf(
            '<p class="description">%s</p>',
            esc_html__('Mais contexto via CLI: `wp sync log --last=N`, `wp sync blame <id|slug>`, `wp sync status` (§11.1).', 'cvsync')
        );
    }
}
