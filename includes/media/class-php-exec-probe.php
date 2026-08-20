<?php
/**
 * PhpExecProbe — sonda comportamental PHP-off em uploads (§A.9.2), check
 * 'uploads-php-exec' do `wp sync verify`. SOMENTE CLI — grava arquivo e faz
 * HTTP; nunca roda em runtime web.
 *
 * Avaliação (tabela normativa):
 *  - corpo contém CVSYNC_EXEC_{token} → FAIL (PHP executou — exit ≠ 0 + notice);
 *  - corpo contém '<?php' → PASS (código-fonte servido, inerte);
 *  - 403/404/410 → PASS (bloqueado no servidor);
 *  - timeout/5xx/redirect para login/corpo inesperado → INDETERMINATE
 *    (warning, exit 0 — nunca travar operação por não-verificabilidade).
 *
 * Limpeza: unlink do probe em finally, SEMPRE. Atalho Apache: .htaccess de
 * uploads legível com regra de desativação → passa sem sonda HTTP.
 *
 * @package CVSync\Media
 */

declare(strict_types=1);

namespace CVSync\Media;

defined('ABSPATH') || exit;

final class PhpExecProbe
{
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const INDETERMINATE = 'indeterminate';

    /**
     * Executa a sonda. Retorna [status, detalhe].
     *
     * @return array{status:string, detail:string}
     */
    public function check(): array
    {
        // Atalho Apache: .htaccess com regra de desativação → passa sem HTTP.
        $htaccess = $this->uploadsDir() . '/.htaccess';
        if (is_file($htaccess) && is_readable($htaccess)) {
            $rules = (string) file_get_contents($htaccess);
            if (preg_match('/php_(?:flag|value)\s+\S*\s*engine\s+off/i', $rules) === 1
                || preg_match('/RemoveHandler\s+\.php/i', $rules) === 1
                || preg_match('/SetHandler\s+(?:None|default-handler)/i', $rules) === 1
            ) {
                return ['status' => self::PASS, 'detail' => 'Apache .htaccess com engine off em uploads/'];
            }
        }

        if (php_sapi_name() !== 'cli') {
            return ['status' => self::INDETERMINATE, 'detail' => 'Sonda disponível apenas em CLI'];
        }

        $token = bin2hex(random_bytes(8));
        $probeName = 'cvsync-probe-' . bin2hex(random_bytes(8)) . '.php';
        $probePath = $this->uploadsDir() . '/' . $probeName;
        $probeUrl = $this->uploadsUrl() . '/' . $probeName;

        $written = file_put_contents($probePath, "<?php echo 'CVSYNC_EXEC_{$token}';");
        if ($written === false) {
            return ['status' => self::INDETERMINATE, 'detail' => 'Não foi possível gravar o probe em uploads/'];
        }

        try {
            $response = wp_remote_get($probeUrl, [
                'timeout'     => 5,
                'sslverify'   => false, // sonda local atrás de TLS autoassinado
                'redirection' => 0,
                'headers'     => ['Cache-Control' => 'no-cache'],
            ]);

            if (is_wp_error($response)) {
                return ['status' => self::INDETERMINATE, 'detail' => 'Sem resposta HTTP: ' . $response->get_error_message()];
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);

            if (str_contains($body, 'CVSYNC_EXEC_' . $token)) {
                return ['status' => self::FAIL, 'detail' => 'PHP EXECUTOU em uploads/ — desabilite a execução no servidor web (§A.9.2)'];
            }
            if (str_contains($body, '<?php')) {
                return ['status' => self::PASS, 'detail' => 'Código-fonte servido inerte'];
            }
            if (in_array($code, [403, 404, 410], true)) {
                return ['status' => self::PASS, 'detail' => sprintf('Bloqueado no servidor (HTTP %d)', $code)];
            }

            return ['status' => self::INDETERMINATE, 'detail' => sprintf('HTTP %d com corpo inesperado (CDN/Basic Auth?)', $code)];
        } finally {
            if (file_exists($probePath)) {
                unlink($probePath); // limpeza SEMPRE (§A.9.2.3)
            }
        }
    }

    private function uploadsDir(): string
    {
        return (string) (wp_upload_dir()['basedir'] ?? '');
    }

    private function uploadsUrl(): string
    {
        return (string) (wp_upload_dir()['baseurl'] ?? '');
    }
}
