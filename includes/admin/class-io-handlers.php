<?php
/**
 * IoHandlers — admin-post handlers do painel de configuração (export/import
 * de content/ como zip).
 *
 * CONTRATO CANÔNICO com a tela (Senior — form alinhado ao mesmo contrato):
 *   Forms apontando para admin-post.php com:
 *     action=cvsync_export_zip  (method post — sem corpo)
 *     action=cvsync_import_zip  (method post, enctype multipart/form-data,
 *                               input file name="cvsync_zip")
 *   Nonce em AMBOS: wp_nonce_field('cvsync_io') — action `cvsync_io`, campo
 *   default `_wpnonce` (o handler valida com check_admin_referer('cvsync_io')).
 *   Capability: manage_options (checada aqui — a tela já restringe o acesso).
 *   Options: cvsync_settings['lock_imports'] (bool — recusa o import com a
 *   mensagem do toggle) e ['auto_import'] (consumido pelo trigger passivo,
 *   class-triggers.php).
 *
 * RESULTADO (Z3): transient `cvsync_import_result` (shape ['applied'=>int,
 * 'skipped'=>int,'conflicts'=>int,'errors'=>string[],'ok'=>bool] + 'detail'
 * opcional) gravado ANTES do redirect; a tela consome + deleta. O redirect
 * volta com o marcador `cvsync_import=1` (sem query args de resultado).
 * Export não grava transient em sucesso (é um download); em falha, grava.
 *
 * Matriz §7.3: EXPORT é livre inclusive em prod (read-only no banco/escreve
 * tmp com credenciais do operador logado); IMPORT é MUTAÇÃO de conteúdo —
 * prod RECUSA (o caminho de prod é export CLI + PR; import via zip é
 * ferramenta de homolog/local).
 *
 * @package CVSync\Admin
 */

declare(strict_types=1);

namespace CVSync\Admin;

use CVSync\Cli\Cli;
use CVSync\ContentIoException;
use CVSync\Environment;
use CVSync\ImportContext;
use CVSync\ZipIo;
use CVSync\ZipValidationException;

defined('ABSPATH') || exit;

/**
 * Autoload das classes de includes/class-content-io.php: registrado no mapa
 * oficial CVSYNC_AUTOLOAD_EXCEPTIONS do cvsync.php (bootstrap) — o paliativo
 * spl_autoload_register local foi removido na integração (pendência devops).
 */

final class IoHandlers
{
    /** Nonce/action guard compartilhado pelos dois forms. */
    private const NONCE = 'cvsync_io';

    /** Nonce/action guard dos botões de ação manual (Aplicar/Exportar/Verificar agora). */
    private const RUN_NONCE = 'cvsync_run';

    /** Teto de entidades dos botões manuais (mesma regra do import zip). */
    private const MAX_ENTITIES = 50;

    public static function register(): void
    {
        add_action('admin_post_cvsync_export_zip', [self::class, 'handleExport']);
        add_action('admin_post_cvsync_import_zip', [self::class, 'handleImport']);
        add_action('admin_post_cvsync_run_apply', [self::class, 'handleRunApply']);
        add_action('admin_post_cvsync_run_export', [self::class, 'handleRunExport']);
        add_action('admin_post_cvsync_run_verify', [self::class, 'handleRunVerify']);
    }

    // ------------------------------------------------------------------
    // Botões de ação manual — Aplicar / Exportar / Verificar agora
    // (contrato canônico: transient cvsync_action_result, redirect com
    //  ?cvsync_action=1; formas idênticas aos handlers zip)
    // ------------------------------------------------------------------

    /**
     * `wp sync apply` pelo painel (trigger 'admin-action').
     *
     * Gates: prod RECUSA (matriz §7.3 — wp_die 403, defesa de servidor);
     * `lock_imports` RECUSA (transient ok=false); teto de 50 entidades de
     * trabalho no plano (acima → "use WP-CLI"). O runner cuida da batch lock,
     * snapshot pré-apply (em request web o Snapshot recusa por SAPI — erro
     * registrado no detail, lote prossegue) e do updateLastAppliedHead
     * (quando há .git/HEAD legível).
     */
    public static function handleRunApply(): void
    {
        self::assertAuthorized(self::RUN_NONCE);
        self::refuseProd();
        if (self::importsLocked()) {
            return; // transient já gravado
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $started = microtime(true);

        try {
            $container = Cli::container();
            $runner    = new \CVSync\Cli\ApplyRunner($container);

            // Teto: entidades de TRABALHO no plano (não skips/purges).
            $ctx  = new ImportContext(trigger: 'admin-action', environment: Environment::current());
            $git  = $runner->gitFacts();
            $plan = $runner->computePlan($ctx, $git['head'], $container->state->lastAppliedHead(), $git['regression']);
            $work = array_filter(
                $plan,
                static fn (array $item): bool => ! in_array($item['outcome']->decision, [
                    \CVSync\Engine\Decision::Skip,
                    \CVSync\Engine\Decision::PurgeTombstone,
                ], true)
            );
            if (count($work) > self::MAX_ENTITIES) {
                self::storeAction('apply', false, sprintf('%d entidades a processar — acima do teto da tela (%d).', count($work), self::MAX_ENTITIES), [
                    sprintf('Use WP-CLI para lotes grandes: docker compose exec -T wordpress wp sync apply'),
                ]);
                self::redirectBackAction();
            }

            $report = $runner->run($ctx);

            $ok = ($report['failed'] + $report['skipped_locked']) === 0;
            $detail = [
                // P-1 (parcial): escala do lote enquanto não há progresso real.
                sprintf('Concluído em %ds.', (int) round(microtime(true) - $started)),
            ];
            $detail = [...$detail, ...array_slice((array) $report['errors'], 0, 10)];
            if ($report['skipped_locked'] > 0) {
                $detail[] = sprintf('%d entidade(s) com editor lock ativo — puladas (retry natural; §8.4).', (int) $report['skipped_locked']);
            }
            if ($report['degraded'] > 0) {
                $detail[] = sprintf('%d aplicada(s) em modo degradado (regeneração de metadata falhou — retentável; §A.5.6).', (int) $report['degraded']);
            }

            self::storeAction(
                'apply',
                $ok,
                sprintf(
                    'Applied %d, exported %d, skipped %d, conflitos auto-resolvidos %d (db: %d, file: %d), failed %d.',
                    (int) $report['applied'],
                    (int) $report['exported'],
                    (int) $report['skipped'],
                    (int) $report['conflicts_db'] + (int) $report['conflicts_file'],
                    (int) $report['conflicts_db'],
                    (int) $report['conflicts_file'],
                    (int) $report['failed']
                ),
                $detail,
                // P-5/P-13 — números para a UI escolher o nível do notice;
                // snapshot pré-apply quando existir (null em SAPI web — a UI
                // deve avisar "sem snapshot neste canal").
                [
                    'applied'        => (int) $report['applied'],
                    'exported'       => (int) $report['exported'],
                    'skipped'        => (int) $report['skipped'],
                    'conflicts'      => (int) $report['conflicts_db'] + (int) $report['conflicts_file'],
                    'failed'         => (int) $report['failed'],
                    'skipped_locked' => (int) $report['skipped_locked'],
                    'pending_ref'    => (int) $report['pending_ref'],
                    'duration_s'     => (int) round(microtime(true) - $started),
                    'snapshot'       => $report['snapshot'],
                ]
            );
        } catch (\Throwable $e) {
            self::storeAction('apply', false, 'Falha no apply.', [$e->getMessage()]);
        }

        self::redirectBackAction();
    }

    /**
     * Export bulk pelo painel (trigger 'admin-action') — livre em qualquer
     * ambiente (read-only no banco; §7.3). Teto de 50 entidades (WP-CLI acima).
     */
    public static function handleRunExport(): void
    {
        self::assertAuthorized(self::RUN_NONCE);
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        try {
            $container  = Cli::container();
            $exported   = 0;
            $idempotent = 0;
            $errors     = [];
            $entities   = self::collectExportEntities($container);

            if (count($entities) > self::MAX_ENTITIES) {
                self::storeAction('export', false, sprintf('%d entidades a exportar — acima do teto da tela (%d).', count($entities), self::MAX_ENTITIES), [
                    'Use WP-CLI para lotes grandes: docker compose exec -T wordpress wp sync export',
                ]);
                self::redirectBackAction();
            }

            $attachmentAdapter = $container->adapters->forPostType('attachment');
            foreach ($entities as [$ref, $isAttachment]) {
                if ($isAttachment && $attachmentAdapter instanceof \CVSync\Media\AttachmentAdapter) {
                    $outcome = $attachmentAdapter->exportAttachment($ref, 'admin-action');
                } else {
                    $outcome = $container->exporter->export($ref, 'admin-action')?->outcome;
                }
                match ($outcome) {
                    \CVSync\Storage\LogResult::Applied => $exported++,
                    \CVSync\Storage\LogResult::Error, \CVSync\Storage\LogResult::Rejected => $errors[] = $ref->toTupleString(),
                    null => $idempotent++, // lock fail-open (§5.8)
                    default => $idempotent++,
                };
            }

            self::storeAction(
                'export',
                [] === $errors,
                sprintf('Export: %d exportados, %d idempotentes, %d erros (de %d entidades).', $exported, $idempotent, count($errors), count($entities)),
                array_slice($errors, 0, 10)
            );
        } catch (\Throwable $e) {
            self::storeAction('export', false, 'Falha no export.', [$e->getMessage()]);
        }

        self::redirectBackAction();
    }

    /**
     * Verify pelo painel — livre em qualquer ambiente (read-only). Relatório
     * do VerifyRunner (mesmo caminho do `wp sync verify`); `ok = divergências
     * == 0 && sonda != FAIL`. Sonda PHP-off autolimita-se a CLI → em request
     * web a sonda devolve INDETERMINADO (não trava, §A.9.2).
     */
    public static function handleRunVerify(): void
    {
        self::assertAuthorized(self::RUN_NONCE);
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        try {
            $result  = (new \CVSync\Cli\VerifyRunner(Cli::container()))->compute(false);
            $report  = $result['report'];
            $counts  = $report['counts'];

            $detail = [];
            foreach (array_slice($report['items'], 0, 20) as $item) {
                $detail[] = sprintf('[%s] %s — %s', $item['status'], $item['entity'], $item['detail']);
            }
            if (count($report['items']) > 20) {
                $detail[] = sprintf('… e mais %d item(ns).', count($report['items']) - 20);
            }
            $probe = $report['security']['uploads-php-exec'];
            if (\CVSync\Media\PhpExecProbe::INDETERMINATE === $probe['status']) {
                $detail[] = 'uploads-php-exec INDETERMINADO em request web — rode `wp sync verify` para a sonda completa (§A.9.2).';
            } elseif ($result['security_fail']) {
                $detail[] = 'SECURITY: uploads-php-exec FAIL — ' . $probe['detail'];
            }

            self::storeAction(
                'verify',
                $result['divergent'] === 0 && ! $result['security_fail'],
                sprintf(
                    'Verify: %s (ok %d · drift-db %d · drift-file %d · orphan %d · pending_ref %d · conflict %d · missing_binary %d).',
                    $result['divergent'] === 0 ? 'convergente' : sprintf('%d divergência(s)', $result['divergent']),
                    (int) $counts['ok'],
                    (int) $counts['drift-db'],
                    (int) $counts['drift-file'],
                    (int) $counts['orphan'],
                    (int) $counts['pending_ref'],
                    (int) $counts['conflict'],
                    (int) $counts['missing_binary']
                ),
                $detail
            );
        } catch (\Throwable $e) {
            self::storeAction('verify', false, 'Falha no verify.', [$e->getMessage()]);
        }

        self::redirectBackAction();
    }

    // ------------------------------------------------------------------
    // Export — download do zip de content/
    // ------------------------------------------------------------------


    public static function handleExport(): void
    {
        self::assertAuthorized(); // export livre em todos os ambientes (read-only)

        try {
            $zipPath = ZipIo::buildContentZip();
        } catch (ContentIoException $e) {
            self::storeResult(['applied' => 0, 'skipped' => 0, 'conflicts' => 0, 'errors' => [$e->getMessage()], 'ok' => false]);
            self::redirectBack();
        }

        $filename = basename($zipPath);

        // Unlink no shutdown — o download pode abortar no meio.
        add_action('shutdown', static function () use ($zipPath): void {
            if (file_exists($zipPath)) {
                unlink($zipPath);
            }
        });

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($zipPath));
        header('X-Content-Type-Options: nosniff');

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- stream de download binário.
        readfile($zipPath);
        exit;
    }

    // ------------------------------------------------------------------
    // Import — upload + validação + swap + apply (via runner PHP)
    // ------------------------------------------------------------------

    public static function handleImport(): void
    {
        self::assertAuthorized();

        // Matriz §7.3: import em prod RECUSA (não há triplo fator via request
        // web por construção — o caminho de prod é export CLI + PR).
        if (Environment::PROD === Environment::current()) {
            wp_die(
                esc_html('Import via zip é RECUSADO em produção (matriz §7.3) — o conteúdo chega a prod pelo pipeline: PR → review → deploy → wp sync apply.'),
                'cvsync',
                ['response' => 403]
            );
        }

        // Toggle da tela: imports travados.
        $settings = (array) get_option('cvsync_settings', []);
        if (! empty($settings['lock_imports'])) {
            self::storeResult(['applied' => 0, 'skipped' => 0, 'conflicts' => 0, 'errors' => ['Imports estão travados pelo toggle "Bloquear imports" nas configurações do cvsync — destrave para importar.'], 'ok' => false]);
            self::redirectBack();
        }

        // Upload válido? (campo canônico do contrato: cvsync_zip)
        $file = $_FILES['cvsync_zip'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validado abaixo
        if (! is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            self::storeResult(['applied' => 0, 'skipped' => 0, 'conflicts' => 0, 'errors' => ['Nenhum zip recebido (erro de upload).'], 'ok' => false]);
            self::redirectBack();
        }
        $tmpName = (string) $file['tmp_name'];
        if ('' === $tmpName || ! is_uploaded_file($tmpName)) {
            self::storeResult(['applied' => 0, 'skipped' => 0, 'conflicts' => 0, 'errors' => ['Upload inválido (não é um arquivo uploaded).'], 'ok' => false]);
            self::redirectBack();
        }

        // Request web: teto de execução explícito (default 30s é pouco para apply).
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $container = Cli::container();

        // Z6 (§5.8): o SWAP do content dir inteiro é mutação de lote — exige a
        // batch lock. Adquirida AQUI e liberada ANTES do runner: o ApplyRunner
        // adquire a própria batch lock internamente, e uma named lock por
        // sessão MariaDB (segundo GET_LOCK liberaria a primeira silenciosamente)
        // proíbe aninhar. A seção crítica protegida é a troca da árvore (vs
        // apply CLI / shutdown-export concorrentes lendo árvore em movimento).
        try {
            $batch = $container->locks->acquireBatch();
        } catch (\Throwable $e) {
            self::storeResult(['applied' => 0, 'skipped' => 0, 'conflicts' => 0, 'errors' => ['Sync em andamento (outra operação segurou a lock): ' . $e->getMessage()], 'ok' => false]);
            self::redirectBack();
        }

        // 1–3. Cadeia de validação + extração + swap (ZipIo) SOB a lock.
        // (release explícito em TODOS os caminhos — finally não roda com o
        // exit do redirect; o auto-release na morte da conexão é só a rede
        // de segurança do MariaDB.)
        try {
            $extracted = ZipIo::validateAndExtract($tmpName, maxEntities: 50);
        } catch (ZipValidationException $e) {
            $batch->release();
            self::storeResult(['applied' => 0, 'skipped' => 0, 'conflicts' => 0, 'errors' => ['Zip REJEITADO — nada foi alterado: ' . $e->getMessage()], 'ok' => false]);
            self::redirectBack();
        } catch (ContentIoException $e) {
            $batch->release();
            self::storeResult(['applied' => 0, 'skipped' => 0, 'conflicts' => 0, 'errors' => ['Falha no import (backup restaurado se aplicável): ' . $e->getMessage()], 'ok' => false]);
            self::redirectBack();
        }
        $batch->release();

        // 4. Apply via a API PHP do plugin — runner direto (nunca shell), com a
        // PRÓPRIA batch lock interna; o snapshot pré-apply já roda no runner.
        $runner = new \CVSync\Cli\ApplyRunner($container);
        $report = $runner->run(new ImportContext(
            trigger: 'admin-io',
            environment: Environment::current(),
        ));

        $errors = array_values(array_slice((array) $report['errors'], 0, 10));
        self::storeResult([
            'applied'   => (int) $report['applied'] + (int) $report['exported'],
            'skipped'   => (int) $report['skipped'],
            'conflicts' => (int) $report['conflicts_db'] + (int) $report['conflicts_file'],
            'failed'    => (int) $report['failed'],
            'skipped_locked' => (int) $report['skipped_locked'],
            'pending_ref'    => (int) $report['pending_ref'],
            'errors'    => $errors,
            'ok'        => $report['failed'] === 0,
            'detail'    => sprintf('Zip: %d arquivos (%d entidades). Backup pré-swap: %s', $extracted['files'], $extracted['entities'], $extracted['backup']),
            // P-4: backup como CHAVE própria (path relativo ao pai do content
            // dir — a UI renderiza o caminho de recuperação, hoje invisível).
            'backup'    => $extracted['backup'],
            'snapshot'  => $report['snapshot'],
        ]);
        self::redirectBack();
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /** capability + nonce — recusa ruidosa (wp_die) em violação. */
    private static function assertAuthorized(?string $nonce = null): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Sem permissão para operar o cvsync.', 'hacklabr'), 'cvsync', ['response' => 403]);
        }
        // Contrato canônico: zip usa action `cvsync_io` (campo default
        // `_wpnonce`); botões de ação usam action `cvsync_run`.
        check_admin_referer($nonce ?? self::NONCE);
    }

    /** Mutação de conteúdo em prod: RECUSA de servidor (matriz §7.3). */
    private static function refuseProd(): void
    {
        if (Environment::PROD !== Environment::current()) {
            return;
        }
        wp_die(
            esc_html('Operação RECUSADA em produção (matriz §7.3) — o conteúdo chega a prod pelo pipeline: PR → review → deploy → wp sync apply.'),
            'cvsync',
            ['response' => 403]
        );
    }

    /** Toggle lock_imports: grava transient de recusa e redireciona (true = travado). */
    private static function importsLocked(): bool
    {
        $settings = (array) get_option('cvsync_settings', []);
        if (empty($settings['lock_imports'])) {
            return false;
        }

        self::storeAction('apply', false, 'Apply bloqueado.', ['O toggle "Bloquear imports" está ativo nas configurações do cvsync — destrave para aplicar.']);
        self::redirectBackAction();

        return true; // inalcançável (redirect exits)
    }

    /**
     * Resultado dos botões de ação (contrato canônico): transient
     * `cvsync_action_result`, shape ['action','ok','summary','detail'[]] +
     * números opcionais em 'counts' (P-5/P-13 — a UI escolhe o nível do
     * notice por eles), TTL 120s — a tela consome + deleta.
     *
     * @param list<string> $detail
     * @param array<string, mixed>|null $counts
     */
    private static function storeAction(string $action, bool $ok, string $summary, array $detail, ?array $counts = null): void
    {
        $payload = [
            'action'  => $action,
            'ok'      => $ok,
            'summary' => $summary,
            'detail'  => $detail,
        ];
        if (null !== $counts) {
            $payload['counts'] = $counts;
        }

        set_transient('cvsync_action_result', $payload, 120);
    }

    /** Redirect dos botões de ação com o marker `?cvsync_action=1`. */
    private static function redirectBackAction(): void
    {
        $referer = wp_get_referer() ?: admin_url('tools.php?page=cvsync');
        wp_safe_redirect(add_query_arg(['cvsync_action' => '1'], $referer));
        exit;
    }

    /**
     * Entidades do export bulk do painel: posts versionados + attachments
     * referenciados (escopo referenced, §A.5.5) + menus + branding + termos
     * das taxonomias versionadas (B). Cada item: [EntityRef, isAttachment].
     *
     * @param \CVSync\Cli\Container $container
     * @return list<array{0: \CVSync\Engine\EntityRef, 1: bool}>
     */
    private static function collectExportEntities(\CVSync\Cli\Container $container): array
    {
        $entities = [];

        foreach ($container->adapters->versionedPostTypes() as $postType) {
            if ('attachment' === $postType) {
                if (null !== $container->referenceGraph) {
                    $attachmentAdapter = $container->adapters->forPostType('attachment');
                    foreach ($container->referenceGraph->referencedAttachmentIds() as $attachmentId) {
                        if (null === $attachmentAdapter) {
                            continue;
                        }
                        $entities[] = [\CVSync\Engine\EntityRef::post('attachment', $attachmentAdapter->ensureUuid((int) $attachmentId)), true];
                    }
                }
                continue;
            }
            $adapter = $container->adapters->forPostType($postType);
            if (null === $adapter) {
                continue;
            }
            $ids = get_posts([
                'post_type'      => $postType,
                'post_status'    => $adapter->statuses(),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);
            foreach ($ids as $id) {
                $entities[] = [\CVSync\Engine\EntityRef::post($postType, $adapter->ensureUuid((int) $id)), false];
            }
        }

        foreach (wp_get_nav_menus() as $menu) {
            $entities[] = [\CVSync\Engine\EntityRef::of('nav_menu', $menu->slug), false];
        }
        foreach ([get_stylesheet() . ':custom_logo', 'core:site_icon'] as $key) {
            $entities[] = [\CVSync\Engine\EntityRef::of('branding', $key), false];
        }

        // Termos (Apêndice B): via AdapterRegistry quando expõe; senão filtro.
        $taxonomies = method_exists($container->adapters, 'versionedTaxonomies')
            ? $container->adapters->versionedTaxonomies()
            : array_map('strval', array_keys((array) apply_filters('cvsync/taxonomies', [])));
        foreach ($taxonomies as $taxonomy) {
            $termAdapter = $container->adapters->forRef(\CVSync\Engine\EntityRef::of('term', $taxonomy . ':x'));
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 0]);
            if (is_wp_error($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                if (null !== $termAdapter) {
                    $termAdapter->ensureUuid((int) $term->term_taxonomy_id);
                }
                $entities[] = [\CVSync\Engine\EntityRef::of('term', $taxonomy . ':' . $term->slug), false];
            }
        }

        return $entities;
    }

    /**
     * Resultado do import para a tela (Z3): transient `cvsync_import_result`
     * com shape canônico ['applied'=>int,'skipped'=>int,'conflicts'=>int,
     * 'errors'=>string[],'ok'=>bool] (+ 'detail' informativo opcional). A
     * tela CONSOME e DELETA após renderizar; TTL curto de 2 min (resíduo de
     * redirect não consumido não vira estado permanente).
     *
     * @param array<string, mixed> $shape
     */
    private static function storeResult(array $shape): void
    {
        set_transient('cvsync_import_result', $shape, 120);
    }

    /**
     * Redirect de volta para a tela (fallback: tools.php?page=cvsync) com o
     * marcador `cvsync_import=1` — o resultado viaja no transient (Z3),
     * nunca em query args.
     */
    private static function redirectBack(): void
    {
        $referer = wp_get_referer() ?: admin_url('tools.php?page=cvsync');
        wp_safe_redirect(add_query_arg(['cvsync_import' => '1'], $referer));
        exit;
    }
}
