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

    public static function register(): void
    {
        add_action('admin_post_cvsync_export_zip', [self::class, 'handleExport']);
        add_action('admin_post_cvsync_import_zip', [self::class, 'handleImport']);
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
            'errors'    => $errors,
            'ok'        => $report['failed'] === 0,
            'detail'    => sprintf('Zip: %d arquivos (%d entidades). Backup pré-swap: %s', $extracted['files'], $extracted['entities'], $extracted['backup']),
        ]);
        self::redirectBack();
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /** capability + nonce — recusa ruidosa (wp_die) em violação. */
    private static function assertAuthorized(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Sem permissão para operar o cvsync.', 'hacklabr'), 'cvsync', ['response' => 403]);
        }
        // Contrato canônico (Z1): action `cvsync_io`, campo default `_wpnonce`
        // — o form emite wp_nonce_field('cvsync_io').
        check_admin_referer(self::NONCE);
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
