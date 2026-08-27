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
 * Jornada da tela (refatoração UX F1–F7 + Guardian P-1..P-13 fases 1–2):
 * estado (ambiente + saúde) → ações → transferência (.zip) → escopo.
 * Ações mutantes pedem confirmação e desabilitam o botão no submit; a
 * severidade dos resultados é honesta (counts: failed/conflitos = error,
 * skipped-locked/pending = warning); import exibe o ponto de restauração
 * (backup/snapshot) — a rede de segurança nunca é invisível.
 *
 * Import/export .zip e ações manuais: APENAS a marcação dos forms (action
 * admin-post.php) — os handlers vivem em class-io-handlers.php (DevOps).
 *
 * @package CVSync\Admin
 */

declare(strict_types=1);

namespace CVSync\Admin;

use CVSync\Environment;
use CVSync\Storage\Schema;
use CVSync\Triggers;

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
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
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

    /**
     * CSS admin do plugin — SOMENTE nas superfícies do plugin: as duas abas
     * em Ferramentas e as telas de edição (metabox de blame usa as classes).
     * Version = versão do plugin (cache-bust a cada release).
     */
    public static function enqueueAssets(string $hook): void
    {
        if (! in_array($hook, ['tools_page_cvsync', 'tools_page_' . self::PAGE_SLUG, 'post.php', 'post-new.php'], true)) {
            return;
        }

        $file = dirname(__DIR__, 2) . '/cvsync.php';
        $version = '1.0.0';
        if (function_exists('get_plugin_data')) {
            $data = get_plugin_data($file, false, false);
            $version = (string) ($data['Version'] ?? $version);
        }

        wp_enqueue_style(
            'cvsync-admin',
            plugins_url('assets/admin.css', $file),
            [],
            $version
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

        echo '<nav class="nav-tab-wrapper">';
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
    // Render — jornada: estado → ações → transferência → escopo (F1)
    // ------------------------------------------------------------------

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Sem permissão.', 'cvsync'));
        }

        $settings = self::settings();

        echo '<div class="wrap cvsync-admin">';
        printf('<h1>%s</h1>', esc_html__('CVSync — Configuração', 'cvsync'));
        self::renderTabs('settings');

        $this->renderImportResult();
        $this->renderActionResult();
        $this->renderUpdatedNotice();

        $this->renderEnvironmentCard();
        $this->renderActionButtons();
        $this->renderIoForms();

        echo '<form method="post" class="cvsync-card" action="' . esc_url(admin_url('tools.php?page=' . self::PAGE_SLUG)) . '">';
        printf('<h2 class="cvsync-card__title">%s</h2>', esc_html__('Escopo versionado', 'cvsync'));
        wp_nonce_field(self::SAVE_ACTION, self::SAVE_NONCE);
        printf('<input type="hidden" name="action" value="%s">', esc_attr(self::SAVE_ACTION));

        $this->renderToggles($settings);
        $this->renderTaxonomies($settings);
        $this->renderPostTypes($settings);

        submit_button(__('Salvar configuração', 'cvsync'), 'primary', 'cvsync_btn_save', false);
        echo '</form>';

        $this->renderFormScript();

        echo '</div>';
    }

    // ------------------------------------------------------------------
    // 1. Ambiente — card de ESTADO com badge (F2) + saúde (P-6)
    // ------------------------------------------------------------------

    private function renderEnvironmentCard(): void
    {
        $env    = Environment::current();
        $policy = Environment::policy();
        $isProd = Environment::PROD === $env;

        printf(
            '<section class="cvsync-card cvsync-env%s" aria-labelledby="cvsync-h-env">',
            $isProd ? ' cvsync-env--prod' : ''
        );
        printf('<h2 class="cvsync-card__title" id="cvsync-h-env">%s</h2>', esc_html__('Ambiente', 'cvsync'));

        printf(
            '<p class="cvsync-env__line"><span class="cvsync-badge cvsync-badge--%1$s">%1$s</span>' .
            '<span>%2$s: <strong>%3$s</strong> · %4$s: <strong>%5$s</strong></span></p>',
            esc_attr($env),
            esc_html__('Apply/import automáticos', 'cvsync'),
            $policy['apply_auto'] ? esc_html__('ON', 'cvsync') : esc_html__('OFF', 'cvsync'),
            esc_html__('Export automático', 'cvsync'),
            $policy['export_auto'] ? esc_html__('ON', 'cvsync') : esc_html__('OFF', 'cvsync')
        );

        if ($isProd) {
            printf(
                '<p class="cvsync-hint"><span class="dashicons dashicons-lock" aria-hidden="true"></span> %s</p>',
                esc_html__('FAIL-CLOSED: produção — apply/import automáticos OFF por norma (§7.3); apply manual exige triplo fator no CLI (--force + TTY + CVSYNC_ALLOW_PROD_APPLY).', 'cvsync')
            );
        }

        $this->renderHealthLine();

        printf(
            '<p class="cvsync-hint">%s</p>',
            esc_html__('Não configurável nesta tela: defina WP_ENVIRONMENT_TYPE ou CVSYNC_ENVIRONMENT no wp-config.php. Desconhecido resolve para prod (fail-closed, §7.1).', 'cvsync')
        );
        echo '</section>';
    }

    /** Saúde compacta (P-6): schema, HEAD do repo legível, último apply. */
    private function renderHealthLine(): void
    {
        $items = [];

        try {
            $needsMigration = Schema::needsMigration();
            $items[] = sprintf(
                '<span class="cvsync-health__item--%s" title="%s">%s</span>',
                $needsMigration ? 'bad' : 'ok',
                esc_attr__('Tabela de estado do cvsync', 'cvsync'),
                sprintf(
                    /* translators: %s: versão do schema instalada. */
                    esc_html__('schema %s %s', 'cvsync'),
                    (string) Schema::installedVersion(),
                    $needsMigration ? esc_html__('(migration pendente)', 'cvsync') : '✓'
                )
            );
        } catch (\Throwable) {
            $items[] = '<span class="cvsync-health__item--bad">' . esc_html__('schema indisponível', 'cvsync') . '</span>';
        }

        $health = Triggers::headHealth();
        if (! empty($health['head_readable'])) {
            $items[] = '<span class="cvsync-health__item--ok">' . esc_html__('repositório git legível ✓', 'cvsync') . '</span>';

            $last = $health['last_applied'];
            $items[] = null !== $last
                ? sprintf('<span>%s</span>', sprintf(
                    /* translators: %s: hash HEAD do último apply. */
                    esc_html__('último apply: %s', 'cvsync'),
                    '<code>' . esc_html((string) substr((string) $last, 0, 10)) . '</code>'
                ))
                : sprintf('<span>%s</span>', esc_html__('nenhum apply registrado ainda', 'cvsync'));

            if (! empty($health['diverged'])) {
                $items[] = '<span class="cvsync-health__item--bad">' . esc_html__('há mudanças não aplicadas', 'cvsync') . '</span>';
            }
        } else {
            $items[] = '<span class="cvsync-health__item--bad" title="' . esc_attr__('Sem .git visível ao container — check passivo e applies via hook não operam', 'cvsync') . '">' . esc_html__('repositório git não legível', 'cvsync') . '</span>';
        }

        printf('<p class="cvsync-health">%s</p>', implode(' · ', $items));
    }

    // ------------------------------------------------------------------
    // Ações manuais — linhas de ação (F3) + copy desambiguada (F4)
    // ------------------------------------------------------------------

    private function renderActionButtons(): void
    {
        $settings   = self::settings();
        $isProd     = Environment::PROD === Environment::current();
        $applyLocked = $isProd || $settings['lock_imports'];

        echo '<section class="cvsync-card" aria-labelledby="cvsync-h-actions">';
        printf('<h2 class="cvsync-card__title" id="cvsync-h-actions">%s</h2>', esc_html__('Ações', 'cvsync'));
        printf(
            '<p class="cvsync-hint">%s</p>',
            esc_html__('Execução pontual dos fluxos — mesmos gates da matriz de ambientes. O resultado aparece no topo da tela; operações grandes podem levar até 2 minutos.', 'cvsync')
        );

        // Aplicar agora (repo → banco) — mutante: confirm + disable-on-submit.
        echo '<div class="cvsync-action"><div class="cvsync-action__main">';
        printf(
            '<p class="cvsync-action__name"><strong>%s</strong> <span class="cvsync-action__dir">%s</span></p>',
            esc_html__('Aplicar agora', 'cvsync'),
            esc_html__('repo → banco', 'cvsync')
        );
        printf(
            '<p class="cvsync-hint" id="cvsync-why-apply">%s</p>',
            $applyLocked
                ? ($isProd
                    ? esc_html__('Desabilitado em produção (§7.3) — apply manual apenas via CLI com triplo fator.', 'cvsync')
                    : esc_html__('Bloqueado — "Bloquear importações neste ambiente" está ativo nesta tela.', 'cvsync'))
                : esc_html__('Sobrepõe o banco com o conteúdo do repositório. Pode demorar; o resultado aparece no topo.', 'cvsync')
        );
        echo '</div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="cvsync-action__ctl" data-cvsync-run>';
        printf('<input type="hidden" name="action" value="%s">', esc_attr(self::RUN_APPLY));
        printf('<input type="hidden" name="_wpnonce" value="%s">', esc_attr(wp_create_nonce(self::RUN_NONCE)));
        submit_button(
            __('Aplicar agora', 'cvsync'),
            'primary',
            'cvsync_btn_apply',
            false,
            array_merge(
                $applyLocked ? ['disabled' => true, 'aria-describedby' => 'cvsync-why-apply'] : [],
                $applyLocked ? [] : ['onclick' => sprintf(
                    'return confirm(%s);',
                    wp_json_encode(__('Aplicar o conteúdo do repositório no banco agora? Isso pode levar até 2 minutos.', 'cvsync'))
                )]
            )
        );
        echo '</form></div>';

        // Exportar para o repositório (banco → working tree).
        echo '<div class="cvsync-action"><div class="cvsync-action__main">';
        printf(
            '<p class="cvsync-action__name"><strong>%s</strong> <span class="cvsync-action__dir">%s</span></p>',
            esc_html__('Exportar para o repositório', 'cvsync'),
            esc_html__('banco → arquivos', 'cvsync')
        );
        printf(
            '<p class="cvsync-hint">%s</p>',
            esc_html__('Grava o conteúdo atual do banco nos arquivos canônicos de content/. Para levar a outro ambiente, commit + push.', 'cvsync')
        );
        echo '</div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="cvsync-action__ctl" data-cvsync-run>';
        printf('<input type="hidden" name="action" value="%s">', esc_attr(self::RUN_EXPORT));
        printf('<input type="hidden" name="_wpnonce" value="%s">', esc_attr(wp_create_nonce(self::RUN_NONCE)));
        submit_button(__('Exportar para o repositório', 'cvsync'), 'secondary', 'cvsync_btn_export', false);
        echo '</form></div>';

        // Verificar agora (read-only).
        echo '<div class="cvsync-action"><div class="cvsync-action__main">';
        printf(
            '<p class="cvsync-action__name"><strong>%s</strong> <span class="cvsync-action__dir">%s</span></p>',
            esc_html__('Verificar agora', 'cvsync'),
            esc_html__('diagnóstico', 'cvsync')
        );
        printf(
            '<p class="cvsync-hint">%s</p>',
            esc_html__('Diagnóstico read-only: recalcula os hashes dos dois lados e reporta divergências. Drift no banco pede export; drift nos arquivos pede apply.', 'cvsync')
        );
        echo '</div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="cvsync-action__ctl" data-cvsync-run>';
        printf('<input type="hidden" name="action" value="%s">', esc_attr(self::RUN_VERIFY));
        printf('<input type="hidden" name="_wpnonce" value="%s">', esc_attr(wp_create_nonce(self::RUN_NONCE)));
        submit_button(__('Verificar agora', 'cvsync'), 'secondary', 'cvsync_btn_verify', false);
        echo '</form></div>';

        echo '</section>';
    }

    // ------------------------------------------------------------------
    // Transferência (.zip) — copy desambiguada (F4) + a11y (F6)
    // ------------------------------------------------------------------

    private function renderIoForms(): void
    {
        $isProd = Environment::PROD === Environment::current();

        echo '<section class="cvsync-card" aria-labelledby="cvsync-h-io">';
        printf('<h2 class="cvsync-card__title" id="cvsync-h-io">%s</h2>', esc_html__('Transferência de conteúdo (.zip)', 'cvsync'));
        printf(
            '<p class="cvsync-hint">%s</p>',
            esc_html__('Empacota o conteúdo versionado para levar a outro ambiente (ou traz de volta um zip validado). Os handlers rodam em admin-post.php com as travas da matriz §7.3.', 'cvsync')
        );

        echo '<div class="cvsync-io">';

        // Baixar (.zip) — liberado em qualquer ambiente (read-only no banco).
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        printf('<input type="hidden" name="action" value="%s">', esc_attr(self::IO_EXPORT));
        printf('<input type="hidden" name="_wpnonce" value="%s">', esc_attr(wp_create_nonce(self::IO_NONCE)));
        submit_button(__('Baixar conteúdo (.zip)', 'cvsync'), 'secondary', 'cvsync_btn_zip_export', false);
        echo '</form>';

        // Importar (.zip) — mutante: confirm (menciona o backup) + disable.
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" data-cvsync-run>';
        printf('<input type="hidden" name="action" value="%s">', esc_attr(self::IO_IMPORT));
        printf('<input type="hidden" name="_wpnonce" value="%s">', esc_attr(wp_create_nonce(self::IO_NONCE)));
        printf(
            '<label class="cvsync-file"><span class="cvsync-file__label">%s</span>' .
            '<input type="file" name="cvsync_zip" accept=".zip"%s aria-describedby="cvsync-why-import" required></label>',
            esc_html__('Arquivo .zip do conteúdo', 'cvsync'),
            $isProd ? ' disabled' : ''
        );
        submit_button(
            __('Importar conteúdo (.zip)', 'cvsync'),
            'primary',
            'cvsync_btn_zip_import',
            false,
            $isProd ? ['disabled' => true, 'aria-describedby' => 'cvsync-why-import'] : ['onclick' => sprintf(
                'return confirm(%s);',
                wp_json_encode(__('Importar este zip? O conteúdo atual recebe backup automático antes da troca; a operação pode levar alguns minutos.', 'cvsync'))
            )]
        );
        echo '</form>';

        echo '</div>';

        if ($isProd) {
            printf(
                '<p class="cvsync-hint" id="cvsync-why-import">%s</p>',
                esc_html__('Importação desabilitada em produção (matriz §7.3) — o conteúdo chega a prod pelo pipeline. Baixar permanece liberado (read-only).', 'cvsync')
            );
        }
        echo '</section>';
    }

    // ------------------------------------------------------------------
    // Escopo: toggles (com P-2), taxonomias (F5/F6/F7), post types
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
                '<p class="cvsync-hint">%s</p>',
                esc_html__('Em produção o toggle fica desabilitado: a matriz §7.3 já bloqueia importações — o valor atual é preservado.', 'cvsync')
            );
        }
        printf(
            '<p class="cvsync-hint">%s</p>',
            esc_html__('Quando ativo, comandos e handlers de import (apply/import .zip) recusam execução neste ambiente. Só sabe restringir: se a option viajar num dump de banco, o destino fica bloqueado — nunca liberado.', 'cvsync')
        );

        // auto_import — check passivo HEAD-hash + reconcile; P-2: honestidade
        // operacional via Triggers::headHealth().
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
                '<p class="cvsync-hint">%s</p>',
                esc_html__('Em produção a importação automática fica desabilitada (a matriz manda OFF; a option é inócua mesmo que viaje num dump).', 'cvsync')
            );
        }
        printf(
            '<p class="cvsync-hint">%s</p>',
            esc_html__('Habilita o check passivo de HEAD-hash e o reconcile agendado: mudanças no repositório são aplicadas ao banco automaticamente neste ambiente.', 'cvsync')
        );

        if ($auto && ! $isProd) {
            $health = Triggers::headHealth();
            if (empty($health['head_readable'])) {
                printf(
                    '<div class="cvsync-warn"><p><strong>%s</strong> %s</p><p><code>%s</code></p></div>',
                    esc_html__('Importação automática inoperante:', 'cvsync'),
                    esc_html__('o repositório git não está visível ao container — o check passivo nunca encontra o HEAD e nada será aplicado. Monte o .git do repo no container:', 'cvsync'),
                    esc_html('./.git:/var/www/html/.git:ro')
                );
            } else {
                printf(
                    '<p class="cvsync-hint">%s</p>',
                    sprintf(
                        /* translators: 1: HEAD atual, 2: HEAD do último apply. */
                        esc_html__('Check ativo — HEAD atual: %1$s · último aplicado: %2$s. %3$s', 'cvsync'),
                        '<code>' . esc_html((string) substr((string) $health['head'], 0, 10)) . '</code>',
                        null !== $health['last_applied']
                            ? '<code>' . esc_html((string) substr((string) $health['last_applied'], 0, 10)) . '</code>'
                            : esc_html__('(nunca)', 'cvsync'),
                        ! empty($health['diverged'])
                            ? '<strong>' . esc_html__('Há mudanças não aplicadas.', 'cvsync') . '</strong>'
                            : esc_html__('Em sincronia.', 'cvsync')
                    )
                );
            }
        }
    }

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
        $selected  = $settings['taxonomies'];
        $selectabe = $this->selectableTaxonomies();

        echo '<h2 class="title">' . esc_html__('Taxonomias sincronizadas', 'cvsync') . '</h2>';
        printf(
            '<p class="cvsync-hint">%s</p>',
            esc_html__('Termos das taxonomias marcadas são versionados como entidades (content/terms/). A lista do filtro cvsync/taxonomies do código continua valendo (união com o marcado aqui).', 'cvsync')
        );

        if ([] === $selectabe) {
            printf(
                '<p class="cvsync-empty">%s</p>',
                esc_html__('Nenhuma taxonomia pública selecionável neste site. Para começar: registre a taxonomia (pública) ou declare-a via filtro cvsync/taxonomies no código.', 'cvsync')
            );
        } else {
            echo '<fieldset class="cvsync-checklist"><legend class="screen-reader-text">' . esc_html__('Taxonomias públicas selecionáveis', 'cvsync') . '</legend><ul>';
            foreach ($selectabe as $slug => $taxonomy) {
                printf(
                    '<li><label><input type="checkbox" name="cvsync_taxonomies[]" value="%s"%s> %s <code>%s</code></label></li>',
                    esc_attr($slug),
                    checked(in_array($slug, $selected, true), true, false),
                    esc_html($taxonomy->labels->name ?? $slug),
                    esc_html($slug)
                );
            }
            echo '</ul></fieldset>';
        }

        // Deny-list: visível, desabilitada, com motivo (F5: slug uma vez).
        echo '<details class="cvsync-hint"><summary>' . esc_html__('Excluídas por norma (Apêndice B, deny-list)', 'cvsync') . '</summary>';
        echo '<ul class="cvsync-checklist cvsync-checklist--locked">';
        foreach (self::DENY_TAXONOMIES as $slug => $reason) {
            printf(
                /* translators: 1: slug da taxonomia, 2: motivo da exclusão. */
                '<li><label><input type="checkbox" disabled aria-hidden="true"> <code>%1$s</code> <span class="cvsync-reason">%2$s</span></label></li>',
                esc_html($slug),
                esc_html($reason)
            );
        }
        echo '</ul></details>';
    }

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
            '<p class="cvsync-hint">%s</p>',
            esc_html__('A estrutura de site abaixo é sempre versionada. Os CPTs marcados entram no mesmo pipeline (pré-condição: suporte a revisions). A lista do filtro cvsync/post_types do código continua valendo (união com o marcado aqui).', 'cvsync')
        );

        echo '<fieldset class="cvsync-checklist cvsync-checklist--locked"><legend class="screen-reader-text">' . esc_html__('Post types versionados por default', 'cvsync') . '</legend><ul>';
        foreach (self::DEFAULT_POST_TYPES as $slug) {
            printf(
                '<li><label><input type="checkbox" checked disabled aria-describedby="cvsync-reason-default"> <code>%s</code></label>' .
                '<span class="cvsync-reason" id="cvsync-reason-default">%s</span></li>',
                esc_html($slug),
                esc_html__('Versionado por default (estrutura de site)', 'cvsync')
            );
        }
        echo '</ul></fieldset>';

        $others = [];
        $noRevisions = [];
        foreach (get_post_types(['public' => true], 'objects') as $slug => $postType) {
            if (in_array($slug, self::DEFAULT_POST_TYPES, true) || 'attachment' === $slug) {
                continue;
            }
            if (post_type_supports($slug, 'revisions')) {
                $others[$slug] = $postType;
            } else {
                $noRevisions[$slug] = $postType;
            }
        }

        if ([] === $others && [] === $noRevisions) {
            printf(
                '<p class="cvsync-empty">%s</p>',
                esc_html__('Nenhum CPT público adicional neste site. Ao registrar um CPT com suporte a revisions, ele aparece aqui como opção.', 'cvsync')
            );
        } else {
            echo '<fieldset class="cvsync-checklist"><legend class="screen-reader-text">' . esc_html__('Post types públicos opcionais', 'cvsync') . '</legend><ul>';
            foreach ($others as $slug => $postType) {
                printf(
                    '<li><label><input type="checkbox" name="cvsync_post_types[]" value="%s"%s> %s <code>%s</code></label></li>',
                    esc_attr($slug),
                    checked(in_array($slug, $selected, true), true, false),
                    esc_html($postType->labels->name ?? $slug),
                    esc_html($slug)
                );
            }
            foreach ($noRevisions as $slug => $postType) {
                printf(
                    '<li><label class="cvsync-locked"><input type="checkbox" disabled aria-describedby="cvsync-reason-revisions"> %s <code>%s</code></label>' .
                    '<span class="cvsync-reason" id="cvsync-reason-revisions">%s</span></li>',
                    esc_html($postType->labels->name ?? $slug),
                    esc_html($slug),
                    esc_html__('Requer suporte a revisions (§3.2): registre o CPT com supports ou use add_post_type_support().', 'cvsync')
                );
            }
            echo '</ul></fieldset>';
        }

        printf(
            '<p class="cvsync-hint">%s</p>',
            esc_html__('Anexos de mídia não se configuram aqui: são entidade própria (Apêndice A, escopo referenced — ver constantes CVSYNC_ATTACHMENT_*).', 'cvsync')
        );
    }

    // ------------------------------------------------------------------
    // Resultados — severidade honesta por counts (P-5/P-13) + redes de
    // segurança visíveis (P-4: backup/snapshot como ponto de restauração)
    // ------------------------------------------------------------------

    /**
     * Resultado das ações manuais. Contrato (handler): transient
     * cvsync_action_result = ['action','ok','summary','detail'[]] + 'counts'
     * opcional (applied/exported/skipped/conflicts/failed/skipped_locked/
     * pending_ref/duration_s/snapshot).
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
        $counts  = isset($result['counts']) && is_array($result['counts']) ? $result['counts'] : [];

        $conflicts     = (int) ($counts['conflicts'] ?? 0);
        $failed        = (int) ($counts['failed'] ?? 0);
        $skippedLocked = (int) ($counts['skipped_locked'] ?? 0);
        $pendingRef    = (int) ($counts['pending_ref'] ?? 0);

        // P-5/P-13 — severidade honesta: falhas/conflitos = error; retrabalho
        // (locks/pendências) = warning; verde só quando não há ressalvas.
        $class = (! $ok || $failed > 0 || $conflicts > 0)
            ? 'notice-error'
            : (($skippedLocked > 0 || $pendingRef > 0) ? 'notice-warning' : 'notice-success');

        $labels = ['apply' => __('Aplicar', 'cvsync'), 'export' => __('Exportar', 'cvsync'), 'verify' => __('Verificar', 'cvsync')];

        printf('<div class="notice %s is-dismissible"><p><strong>%s:</strong> %s</p>', esc_attr($class), esc_html($labels[$action] ?? __('Ação', 'cvsync')), esc_html($summary));

        if ([] !== $detail) {
            echo '<ul class="cvsync-notice-detail">';
            foreach ($detail as $line) {
                printf('<li>%s</li>', esc_html($line));
            }
            echo '</ul>';
        }

        // P-13 — snapshot do apply como ponto de restauração visível.
        if ('apply' === $action) {
            $snapshot = $counts['snapshot'] ?? null;
            if (is_string($snapshot) && '' !== $snapshot) {
                printf(
                    '<p class="cvsync-hint">%s <code>%s</code> — %s</p>',
                    esc_html__('Ponto de restauração (snapshot pré-apply):', 'cvsync'),
                    esc_html($snapshot),
                    '<code>wp sync restore ' . esc_html($snapshot) . '</code>'
                );
            } else {
                printf(
                    '<p class="cvsync-hint">%s</p>',
                    esc_html__('Sem snapshot pré-apply neste canal (request web) — o apply via CLI (wp sync apply) gera snapshot antes de gravar.', 'cvsync')
                );
            }
        }

        // P-7 (parcial) — roteamento de próximos passos no verify.
        if ('verify' === $action) {
            printf(
                '<p class="cvsync-hint">%s</p>',
                esc_html__('Próximos passos: drift-db = o banco mudou (exporte/commite) · drift-file = o arquivo mudou (aplique) · conflict = resolva via CLI (wp sync conflicts) · pending_ref = entidade referenciada ainda não importada.', 'cvsync')
            );
        }

        if ($conflicts > 0) {
            printf(
                '<p><a href="%s">%s</a> %s</p>',
                esc_url(admin_url('tools.php?page=cvsync')),
                esc_html__('Há conflitos preservados — veja em Log e Conflitos', 'cvsync'),
                esc_html__('e resolva via CLI (wp sync conflicts / wp sync resolve).', 'cvsync')
            );
        }

        echo '</div>';
    }

    /**
     * Resultado do import .zip. Contrato (handler): transient
     * cvsync_import_result = ['applied','skipped','conflicts','errors'[],
     * 'ok', 'backup'?, 'snapshot'?].
     */
    private function renderImportResult(): void
    {
        if (self::IMPORT_FLAG !== ($_GET[self::IMPORT_FLAG] ?? '')) {
            return;
        }

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

        $class = (! $ok || [] !== $errors) ? 'notice-error' : ($conflicts > 0 ? 'notice-warning' : 'notice-success');

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
            echo '<ul class="cvsync-notice-detail">';
            foreach ($errors as $message) {
                printf('<li><code>%s</code></li>', esc_html($message));
            }
            echo '</ul>';
        }

        // P-4 — a rede de segurança nunca é invisível: backup pré-swap e
        // snapshot do apply como pontos de restauração.
        $restore = [];
        if (isset($report['backup']) && is_string($report['backup']) && '' !== $report['backup']) {
            $restore[] = sprintf(
                /* translators: %s: caminho do backup pré-swap. */
                esc_html__('backup pré-swap: %s', 'cvsync'),
                '<code>' . esc_html($report['backup']) . '</code>'
            );
        }
        if (isset($report['snapshot']) && is_string($report['snapshot']) && '' !== $report['snapshot']) {
            $restore[] = sprintf(
                /* translators: %s: timestamp do snapshot. */
                esc_html__('snapshot pré-apply: %s', 'cvsync'),
                '<code>wp sync restore ' . esc_html($report['snapshot']) . '</code>'
            );
        }
        if ([] !== $restore) {
            printf('<p class="cvsync-hint">%s %s</p>', esc_html__('Ponto de restauração:', 'cvsync'), implode(' · ', $restore));
        }

        if ($conflicts > 0) {
            printf(
                '<p><a href="%s">%s</a> %s</p>',
                esc_url(admin_url('tools.php?page=cvsync')),
                esc_html__('Há conflitos preservados — veja em Log e Conflitos', 'cvsync'),
                esc_html__('e resolva via CLI (wp sync conflicts / wp sync resolve).', 'cvsync')
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

    // ------------------------------------------------------------------
    // JS mínimo (F3/P-1 barato): disable-on-submit nos forms marcados
    // data-cvsync-run — zero dependências, inline, sem build.
    // ------------------------------------------------------------------

    private function renderFormScript(): void
    {
        ?>
        <script>
        (function () {
            'use strict';
            document.querySelectorAll('form[data-cvsync-run]').forEach(function (form) {
                form.addEventListener('submit', function () {
                    form.querySelectorAll('button, input[type="submit"]').forEach(function (b) {
                        b.disabled = true;
                    });
                });
            });
        })();
        </script>
        <?php
    }
}
