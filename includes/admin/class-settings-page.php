<?php
/**
 * SettingsPage — aba "Configuração" da tela Ferramentas > CVSync (§10.1:
 * manage_options). Surface admin para a option única `cvsync_settings`
 * (contrato central):
 *
 *   ['taxonomies' => [...slugs...], 'post_types' => [...slugs...],
 *    'lock_imports' => bool, 'auto_import' => bool]
 *
 *  - taxonomies/post_types: o registry (CMS) lê da option e faz a UNIÃO com
 *    os filtros `cvsync/taxonomies` / `cvsync/post_types` do código;
 *  - lock_imports/auto_import: o io-handler (DevOps) lê da option.
 *
 * Limites honestos da tela:
 *  - o AMBIENTE (local/staging/homolog/prod) NÃO é configurável aqui — é
 *    fail-closed por wp-config/env (§7.1/§7.3) e apenas exibido;
 *  - resolução de conflitos e mutação de conteúdo continuam só via CLI;
 *    aqui se configura ESCOPO e comportamento de import.
 *
 * Import/export .zip: APENAS a marcação dos forms (action admin-post.php,
 * nonce `cvsync_io`) — os handlers `cvsync_export_zip`/`cvsync_import_zip`
 * vivem em class-io-handlers.php (DevOps).
 *
 * @package CVSync\Admin
 */

declare(strict_types=1);

namespace CVSync\Admin;

use CVSync\Environment;

defined('ABSPATH') || exit;

final class SettingsPage
{
    public const OPTION        = 'cvsync_settings';
    public const PAGE_SLUG     = 'cvsync-settings';
    private const SAVE_ACTION  = 'cvsync_save_settings';
    private const SAVE_NONCE   = 'cvsync_settings_nonce';
    private const IO_NONCE     = 'cvsync_io';
    private const IO_EXPORT    = 'cvsync_export_zip';
    private const IO_IMPORT    = 'cvsync_import_zip';
    private const IMPORT_FLAG  = 'cvsync_import';
    private const IMPORT_TRANSIENT = 'cvsync_import_result';

    private const RUN_NONCE    = 'cvsync_run';
    private const RUN_APPLY    = 'cvsync_run_apply';
    private const RUN_EXPORT   = 'cvsync_run_export';
    private const RUN_VERIFY   = 'cvsync_run_verify';
    private const RUN_FLAG     = 'cvsync_action';
    private const RUN_TRANSIENT = 'cvsync_action_result';

    /** Post types versionados por default (AdapterRegistry::postTypeConfig) — sempre on. */
    private const DEFAULT_POST_TYPES = ['page', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation'];

    /** Deny-list do Apêndice B (B.1.2) — motivo exibido na tela. */
    private const DENY_TAXONOMIES = [
        'nav_menu'              => 'já é entidade própria (menus, §4.4)',
        'wp_theme'              => 'termo identitário de templates (valor único por ambiente)',
        'wp_pattern_category'   => 'termo identitário de padrões (viaja no payload do pattern)',
        'wp_template_part_area' => 'termo identitário de template parts',
        'link_category'         => 'estrutural do core, não-editorial',
        'post_format'           => 'estrutural do core, não-editorial',
    ];

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_init', [$this, 'handleSave']);
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'tools.php',
            __('CVSync — Configuração', 'cvsync'),
            __('CVSync Configuração', 'cvsync'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    /** Defaults do contrato (listas vazias, toggles off). */
    public static function defaults(): array
    {
        return [
            'taxonomies'    => [],
            'post_types'    => [],
            'lock_imports'  => false,
            'auto_import'   => false,
        ];
    }

    /** Leitura saneada da option (formato do contrato, sempre). */
    public static function settings(): array
    {
        $raw     = get_option(self::OPTION, []);
        $decoded = is_array($raw) ? $raw : [];

        return [
            'taxonomies'   => isset($decoded['taxonomies']) && is_array($decoded['taxonomies'])
                ? array_values(array_filter(array_map('strval', $decoded['taxonomies']))) : [],
            'post_types'   => isset($decoded['post_types']) && is_array($decoded['post_types'])
                ? array_values(array_filter(array_map('strval', $decoded['post_types']))) : [],
            'lock_imports' => ! empty($decoded['lock_imports']),
            'auto_import'  => ! empty($decoded['auto_import']),
        ];
    }

    // ------------------------------------------------------------------
    // Abas (compartilhadas com a tela de log/conflitos)
    // ------------------------------------------------------------------

    /** Barra de abas da tela Ferramentas > CVSync (usada também pelo ToolsPage). */
    public static function renderTabs(string $active): void
    {
        $base = static fn (string $slug): string => admin_url('tools.php?page=' . $slug);

        echo '<nav class="nav-tab-wrapper" style="margin-top:12px;">';
        printf(
            '<a class="nav-tab%s" href="%s">%s</a>',
            'log' === $active ? ' nav-tab-active' : '',
            esc_url($base('cvsync')),
            esc_html__('Log e Conflitos', 'cvsync')
        );
        printf(
            '<a class="nav-tab%s" href="%s">%s</a>',
            'settings' === $active ? ' nav-tab-active' : '',
            esc_url($base(self::PAGE_SLUG)),
            esc_html__('Configuração', 'cvsync')
        );
        echo '</nav>';
    }

    // ------------------------------------------------------------------
    // Form handler (option única — contrato central)
    // ------------------------------------------------------------------

    public function handleSave(): void
    {
        if (self::SAVE_ACTION !== ($_POST['action'] ?? '')) {
            return;
        }

        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Sem permissão.', 'cvsync'));
        }
        check_admin_referer(self::SAVE_ACTION, self::SAVE_NONCE);

        $settings = self::settings();

        // Toggles (prod: inputs disabled — preservar valor via hidden).
        $settings['lock_imports'] = ! empty($_POST['cvsync_lock_imports']) || '1' === ($_POST['cvsync_lock_imports_fixed'] ?? '');
        $settings['auto_import']  = ! empty($_POST['cvsync_auto_import']) || '1' === ($_POST['cvsync_auto_import_fixed'] ?? '');

        // Taxonomias: apenas slugs da lista permitida (públicas, fora da deny-list).
        $allowedTax = $this->selectableTaxonomies();
        $settings['taxonomies'] = array_values(array_intersect(
            array_map('sanitize_key', (array) ($_POST['cvsync_taxonomies'] ?? [])),
            array_keys($allowedTax)
        ));

        // Post types: públicos, fora dos defaults/attachment, COM revisions (§3.2).
        $allowedTypes = $this->selectablePostTypes();
        $settings['post_types'] = array_values(array_intersect(
            array_map('sanitize_key', (array) ($_POST['cvsync_post_types'] ?? [])),
            array_keys($allowedTypes)
        ));

        update_option(self::OPTION, $settings);

        wp_safe_redirect(add_query_arg('updated', '1', admin_url('tools.php?page=' . self::PAGE_SLUG)));
        exit;
    }

    // ------------------------------------------------------------------
    // Render
    // ------------------------------------------------------------------

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Sem permissão.', 'cvsync'));
        }

        $settings = self::settings();

        echo '<div class="wrap">';
        printf('<h1>%s</h1>', esc_html__('CVSync — Configuração', 'cvsync'));
        self::renderTabs('settings');

        $this->renderImportResult();
        $this->renderActionResult();
        $this->renderUpdatedNotice();
        $this->renderEnvironmentBox();

        echo '<form method="post" action="' . esc_url(admin_url('tools.php?page=' . self::PAGE_SLUG)) . '">';
        wp_nonce_field(self::SAVE_ACTION, self::SAVE_NONCE);
        printf('<input type="hidden" name="action" value="%s">', esc_attr(self::SAVE_ACTION));

        $this->renderToggles($settings);
        $this->renderTaxonomies($settings);
        $this->renderPostTypes($settings);

        submit_button(__('Salvar configuração', 'cvsync'));
        echo '</form>';

        $this->renderActionButtons();
        $this->renderIoForms();

        echo '</div>';
    }

    // ------------------------------------------------------------------
    // 1. Ambiente (read-only — não configurável aqui)
    // ------------------------------------------------------------------

    private function renderEnvironmentBox(): void
    {
        $env    = Environment::current();
        $policy = Environment::policy();
        $isProd = Environment::PROD === $env;

        printf(
            '<div class="notice %s" style="padding:12px 16px; margin:16px 0;">',
            $isProd ? 'notice-error' : 'notice-info'
        );
        printf(
            '<p><strong>%s</strong> <code>%s</code></p>',
            esc_html__('Ambiente efetivo:', 'cvsync'),
            esc_html($env)
        );
        printf(
            '<p>%s: <strong>%s</strong> · %s: <strong>%s</strong></p>',
            esc_html__('Apply/import automáticos', 'cvsync'),
            $policy['apply_auto'] ? esc_html__('ON', 'cvsync') : esc_html__('OFF', 'cvsync'),
            esc_html__('Export automático', 'cvsync'),
            $policy['export_auto'] ? esc_html__('ON', 'cvsync') : esc_html__('OFF', 'cvsync')
        );

        if ($isProd) {
            printf(
                '<p><strong>%s</strong> %s</p>',
                esc_html__('FAIL-CLOSED:', 'cvsync'),
                esc_html__('ambiente de produção — apply/import automáticos ficam OFF por norma (matriz §7.3); apply manual exige triplo fator (--force + TTY + CVSYNC_ALLOW_PROD_APPLY); a tela abaixo reflete essas travas.', 'cvsync')
            );
        }

        printf(
            '<p class="description">%s</p>',
            esc_html__('O ambiente não é configurável nesta tela: defina WP_ENVIRONMENT_TYPE (core) no wp-config.php ou CVSYNC_ENVIRONMENT (constante/variável de ambiente). Desconhecido resolve para prod (fail-closed, §7.1).', 'cvsync')
        );
        echo '</div>';
    }

    // ------------------------------------------------------------------
    // 2/3. Toggles
    // ------------------------------------------------------------------

    private function renderToggles(array $settings): void
    {
        $isProd = Environment::PROD === Environment::current();

        echo '<h2 class="title">' . esc_html__('Importações', 'cvsync') . '</h2>';

        // lock_imports — fail-closed por direção: no pior caso (option viaja
        // num dump) bloqueia, nunca libera.
        $locked = $settings['lock_imports'];
        echo '<p><label>';
        printf(
            '<input type="checkbox" name="cvsync_lock_imports" value="1"%s%s> <strong>%s</strong>',
            checked($locked, true, false),
            $isProd ? ' disabled' : '',
            esc_html__('Bloquear importações neste ambiente', 'cvsync')
        );
        echo '</label></p>';
        if ($isProd) {
            printf('<input type="hidden" name="cvsync_lock_imports_fixed" value="%s">', $locked ? '1' : '');
            printf(
                '<p class="description">%s</p>',
                esc_html__('Em produção o toggle fica desabilitado: a matriz §7.3 já bloqueia importações — o valor atual é preservado.', 'cvsync')
            );
        }
        printf(
            '<p class="description">%s</p>',
            esc_html__('Quando ativo, comandos e handlers de import (apply/import .zip) recusam execução neste ambiente. Só sabe restringir: se a option viajar num dump de banco, o destino fica bloqueado — nunca liberado.', 'cvsync')
        );

        // auto_import — check passivo HEAD-hash + reconcile.
        $auto = $settings['auto_import'];
        echo '<p><label>';
        printf(
            '<input type="checkbox" name="cvsync_auto_import" value="1"%s%s> <strong>%s</strong>',
            checked($auto, true, false),
            $isProd ? ' disabled' : '',
            esc_html__('Importação automática ao detectar mudanças no repositório', 'cvsync')
        );
        echo '</label></p>';
        if ($isProd) {
            printf('<input type="hidden" name="cvsync_auto_import_fixed" value="%s">', $auto ? '1' : '');
            printf(
                '<p class="description">%s</p>',
                esc_html__('Em produção a importação automática fica desabilitada (a matriz manda OFF; a option é inócua mesmo que viaje num dump).', 'cvsync')
            );
        }
        printf(
            '<p class="description">%s</p>',
            esc_html__('Habilita o check passivo de HEAD-hash e o reconcile agendado: mudanças no repositório são aplicadas ao banco automaticamente neste ambiente.', 'cvsync')
        );
    }

    // ------------------------------------------------------------------
    // 4. Taxonomias
    // ------------------------------------------------------------------

    /** @return array<string,\WP_Taxonomy> Públicas, fora da deny-list B.1.2. */
    private function selectableTaxonomies(): array
    {
        $all = get_taxonomies(['public' => true], 'objects');
        $out = [];
        foreach ($all as $slug => $taxonomy) {
            if (! isset(self::DENY_TAXONOMIES[$slug])) {
                $out[$slug] = $taxonomy;
            }
        }

        return $out;
    }

    private function renderTaxonomies(array $settings): void
    {
        $selected = $settings['taxonomies'];

        echo '<h2 class="title">' . esc_html__('Taxonomias sincronizadas', 'cvsync') . '</h2>';
        printf(
            '<p class="description">%s</p>',
            esc_html__('Termos das taxonomias marcadas são versionados como entidades (content/terms/). A lista do filtro `cvsync/taxonomies` do código continua valendo (união com o marcado aqui).', 'cvsync')
        );

        echo '<fieldset><ul style="margin:0;">';
        foreach ($this->selectableTaxonomies() as $slug => $taxonomy) {
            printf(
                '<li><label><input type="checkbox" name="cvsync_taxonomies[]" value="%s"%s> %s <code>%s</code></label></li>',
                esc_attr($slug),
                checked(in_array($slug, $selected, true), true, false),
                esc_html($taxonomy->labels->name ?? $slug),
                esc_html($slug)
            );
        }
        echo '</ul></fieldset>';

        // Deny-list: visível, desabilitada, com motivo.
        echo '<p style="margin-top:12px;"><em>' . esc_html__('Excluídas por norma (Apêndice B, deny-list):', 'cvsync') . '</em></p>';
        echo '<ul style="margin-top:4px;color:#787c82;">';
        foreach (self::DENY_TAXONOMIES as $slug => $reason) {
            printf(
                '<li title="%s"><label><input type="checkbox" disabled> %s <code>%s</code> — %s</label></li>',
                esc_attr($reason),
                esc_html($slug),
                esc_html($slug),
                esc_html($reason)
            );
        }
        echo '</ul>';
    }

    // ------------------------------------------------------------------
    // 5. Post types
    // ------------------------------------------------------------------

    /** @return array<string,\WP_Post_Type> Públicos, fora dos defaults/attachment, com revisions (§3.2). */
    private function selectablePostTypes(): array
    {
        $out = [];
        foreach (get_post_types(['public' => true], 'objects') as $slug => $postType) {
            if (in_array($slug, self::DEFAULT_POST_TYPES, true) || 'attachment' === $slug) {
                continue;
            }
            if (post_type_supports($slug, 'revisions')) {
                $out[$slug] = $postType;
            }
        }

        return $out;
    }

    private function renderPostTypes(array $settings): void
    {
        $selected = $settings['post_types'];

        echo '<h2 class="title">' . esc_html__('Post types sincronizados', 'cvsync') . '</h2>';
        printf(
            '<p class="description">%s</p>',
            esc_html__('A estrutura de site abaixo é sempre versionada. Os CPTs marcados entram no mesmo pipeline (pré-condição: suporte a revisions). A lista do filtro `cvsync/post_types` do código continua valendo (união com o marcado aqui).', 'cvsync')
        );

        echo '<fieldset><ul style="margin:0;color:#787c82;">';
        foreach (self::DEFAULT_POST_TYPES as $slug) {
            printf(
                '<li><label title="%s"><input type="checkbox" checked disabled> %s <code>%s</code></label></li>',
                esc_attr('Versionado por default (estrutura de site)'),
                esc_html($slug),
                esc_html($slug)
            );
        }
        echo '</ul></fieldset>';

        echo '<fieldset><ul style="margin:8px 0 0;">';
        foreach (get_post_types(['public' => true], 'objects') as $slug => $postType) {
            if (in_array($slug, self::DEFAULT_POST_TYPES, true) || 'attachment' === $slug) {
                continue;
            }
            $hasRevisions = post_type_supports($slug, 'revisions');
            if ($hasRevisions) {
                printf(
                    '<li><label><input type="checkbox" name="cvsync_post_types[]" value="%s"%s> %s <code>%s</code></label></li>',
                    esc_attr($slug),
                    checked(in_array($slug, $selected, true), true, false),
                    esc_html($postType->labels->name ?? $slug),
                    esc_html($slug)
                );
            } else {
                printf(
                    '<li style="color:#787c82;"><label title="%s"><input type="checkbox" disabled> %s <code>%s</code> — %s</label></li>',
                    esc_attr('Pré-condição §3.2: registre o CPT com suporte a revisions ou use add_post_type_support().'),
                    esc_html($postType->labels->name ?? $slug),
                    esc_html($slug),
                    esc_html__('requer suporte a revisions (§3.2)', 'cvsync')
                );
            }
        }
        echo '</ul></fieldset>';

        printf(
            '<p class="description">%s</p>',
            esc_html__('Anexos de mídia não se configuram aqui: são entidade própria (Apêndice A, escopo referenced — ver constantes CVSYNC_ATTACHMENT_*).', 'cvsync')
        );
    }

    // ------------------------------------------------------------------
    // Ações manuais (handlers admin-post cvsync_run_* — DevOps)
    // ------------------------------------------------------------------

    private function renderActionButtons(): void
    {
        $settings = self::settings();
        $isProd   = Environment::PROD === Environment::current();

        echo '<h2 class="title">' . esc_html__('Ações', 'cvsync') . '</h2>';
        printf(
            '<p class="description">%s</p>',
            esc_html__('Execução pontual dos fluxos do cvsync a partir da tela — mesmos gates da matriz de ambientes. O resultado aparece no topo da tela após a conclusão.', 'cvsync')
        );

        // Aplicar agora (repo → banco) — prod: matriz; lock_imports: recusa.
        $applyLocked = $isProd || $settings['lock_imports'];
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:24px;vertical-align:top;">';
        printf('<input type="hidden" name="action" value="%s">', esc_attr(self::RUN_APPLY));
        wp_nonce_field(self::RUN_NONCE);
        submit_button(__('Aplicar agora', 'cvsync'), 'primary', 'submit', false, $applyLocked ? ['disabled' => true] : []);
        echo '</form>';
        printf(
            '<p class="description">%s</p>',
            $applyLocked
                ? ($isProd
                    ? esc_html__('Aplicar agora: desabilitado em produção (matriz §7.3 — apply manual exige CLI com triplo fator: --force + TTY + CVSYNC_ALLOW_PROD_APPLY).', 'cvsync')
                    : esc_html__('Aplicar agora: bloqueado — "Bloquear importações neste ambiente" está ativo.', 'cvsync'))
                : esc_html__('Aplicar agora: aplica o conteúdo do repositório no banco (repo → banco).', 'cvsync')
        );

        // Exportar agora (banco → repo).
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:24px;vertical-align:top;">';
        printf('<input type="hidden" name="action" value="%s">', esc_attr(self::RUN_EXPORT));
        wp_nonce_field(self::RUN_NONCE);
        submit_button(__('Exportar agora', 'cvsync'), 'secondary', 'submit', false);
        echo '</form>';
        printf(
            '<p class="description">%s</p>',
            esc_html__('Exportar agora: exporta o conteúdo do banco para os arquivos canônicos (banco → repo).', 'cvsync')
        );

        // Verificar agora (read-only).
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;vertical-align:top;">';
        printf('<input type="hidden" name="action" value="%s">', esc_attr(self::RUN_VERIFY));
        wp_nonce_field(self::RUN_NONCE);
        submit_button(__('Verificar agora', 'cvsync'), 'secondary', 'submit', false);
        echo '</form>';
        printf(
            '<p class="description">%s</p>',
            esc_html__('Verificar agora: diagnóstico read-only — recalcula hashes dos dois lados e reporta divergências (o verify do CLI, sem escrever).', 'cvsync')
        );
    }

    /**
     * Resultado das ações manuais (contrato canônico): transient
     * cvsync_action_result = ['action' => 'apply'|'export'|'verify',
     * 'ok' => bool, 'summary' => string, 'detail' => list<string>].
     */
    private function renderActionResult(): void
    {
        if ('1' !== ($_GET[self::RUN_FLAG] ?? '')) {
            return;
        }

        $result = get_transient(self::RUN_TRANSIENT);
        delete_transient(self::RUN_TRANSIENT);

        if (! is_array($result)) {
            return; // sem resultado (redirect direto) — nada a renderizar
        }

        $action  = isset($result['action']) ? (string) $result['action'] : '';
        $ok      = ! empty($result['ok']);
        $summary = isset($result['summary']) ? (string) $result['summary'] : '';
        $detail  = isset($result['detail']) && is_array($result['detail'])
            ? array_values(array_filter(array_map('strval', $result['detail']))) : [];

        $labels  = ['apply' => __('Aplicar', 'cvsync'), 'export' => __('Exportar', 'cvsync'), 'verify' => __('Verificar', 'cvsync')];
        $class   = $ok ? 'notice-success' : 'notice-warning';

        printf('<div class="notice %s is-dismissible"><p><strong>%s:</strong> %s</p>', esc_attr($class), esc_html($labels[$action] ?? __('Ação', 'cvsync')), esc_html($summary));

        if ([] !== $detail) {
            echo '<ul style="margin:6px 0 6px 18px;list-style:disc;">';
            foreach ($detail as $line) {
                printf('<li>%s</li>', esc_html($line));
            }
            echo '</ul>';
        }

        echo '</div>';
    }

    // ------------------------------------------------------------------
    // 6. Export/import .zip (marcação dos forms — handlers são do DevOps)
    // ------------------------------------------------------------------

    private function renderIoForms(): void
    {
        $isProd = Environment::PROD === Environment::current();

        echo '<h2 class="title">' . esc_html__('Transferência de conteúdo (.zip)', 'cvsync') . '</h2>';
        printf(
            '<p class="description">%s</p>',
            esc_html__('Empacota/importa o conteúdo versionado entre ambientes. Os handlers rodam em admin-post.php com as travas de ambiente da matriz §7.3.', 'cvsync')
        );

        // Export — liberado em qualquer ambiente (read-only).
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:24px;">';
        printf('<input type="hidden" name="action" value="%s">', esc_attr(self::IO_EXPORT));
        wp_nonce_field(self::IO_NONCE);
        submit_button(__('Exportar conteúdo (.zip)', 'cvsync'), 'secondary', 'submit', false);
        echo '</form>';

        // Import — prod: desabilitado (matriz).
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
        printf('<input type="hidden" name="action" value="%s">', esc_attr(self::IO_IMPORT));
        wp_nonce_field(self::IO_NONCE);
        printf(
            '<input type="file" name="cvsync_zip" accept=".zip"%s required>',
            $isProd ? ' disabled' : ''
        );
        echo ' ';
        submit_button(__('Importar conteúdo (.zip)', 'cvsync'), 'primary', 'submit', false, $isProd ? ['disabled' => true] : []);
        echo '</form>';

        if ($isProd) {
            printf(
                '<p class="description">%s</p>',
                esc_html__('Importação desabilitada em produção (matriz §7.3) — apply manual apenas via CLI com triplo fator. Exportação permanece liberada (read-only).', 'cvsync')
            );
        }
    }

    // ------------------------------------------------------------------
    // 7. Resultado do import (render do redirect_with_result do handler)
    // ------------------------------------------------------------------

    private function renderImportResult(): void
    {
        if (self::IMPORT_FLAG !== ($_GET[self::IMPORT_FLAG] ?? '')) {
            return;
        }

        // CONTRATO CANÔNICO (handler DevOps): transient cvsync_import_result =
        // ['applied' => int, 'skipped' => int, 'conflicts' => int,
        //  'errors' => list<string>, 'ok' => bool].
        $report = get_transient(self::IMPORT_TRANSIENT);
        delete_transient(self::IMPORT_TRANSIENT);

        if (! is_array($report)) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html__('Import finalizado — detalhes no audit log (wp sync log).', 'cvsync')
            );

            return;
        }

        $applied   = (int) ($report['applied'] ?? 0);
        $skipped   = (int) ($report['skipped'] ?? 0);
        $conflicts = (int) ($report['conflicts'] ?? 0);
        $ok        = ! empty($report['ok']);
        $errors    = isset($report['errors']) && is_array($report['errors'])
            ? array_values(array_filter(array_map('strval', $report['errors']))) : [];

        $class = ! $ok || [] !== $errors ? 'notice-error' : ($conflicts > 0 ? 'notice-warning' : 'notice-success');

        printf('<div class="notice %s is-dismissible"><p><strong>%s</strong> ', esc_attr($class), esc_html__('Resultado do import:', 'cvsync'));
        printf(
            /* translators: 1: aplicados, 2: ignorados, 3: conflitos, 4: erros. */
            esc_html__('%1$d aplicados, %2$d ignorados, %3$d conflitos, %4$d erros.', 'cvsync'),
            $applied,
            $skipped,
            $conflicts,
            count($errors)
        );
        echo '</p>';

        if ([] !== $errors) {
            echo '<ul style="margin:6px 0 6px 18px;list-style:disc;">';
            foreach ($errors as $message) {
                printf('<li><code>%s</code></li>', esc_html($message));
            }
            echo '</ul>';
        }
        if ($conflicts > 0) {
            printf(
                '<p>%s</p>',
                esc_html__('Há conflitos preservados — inspecione em Log e Conflitos e resolva via CLI (wp sync conflicts / wp sync resolve).', 'cvsync')
            );
        }
        echo '</div>';
    }

    private function renderUpdatedNotice(): void
    {
        if ('1' !== ($_GET['updated'] ?? '')) {
            return;
        }

        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html__('Configuração salva.', 'cvsync')
        );
    }
}
