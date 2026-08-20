<?php
/**
 * ImportGuard — guard de contexto de import (spec §10.2, cláusula obrigatória;
 * estendido por §A.5.2.7 para big_image).
 *
 * TODO import executa dentro deste envelope, TUDO em try/finally:
 *  1. Flag in-request CVSYNC_IMPORTING (suprime os hooks de export — o import
 *     não enfileira export, §5.4);
 *  2. Usuário técnico explícito: CVSYNC_IMPORT_USER (login ou ID) com fallback
 *     determinístico (primeiro administrador por ID) — em contexto CLI/cron sem
 *     usuário autenticado, wp_insert_post() aplicaria wp_filter_post_kses() e
 *     mutilaria silenciosamente core/html com <iframe>/<script>/embeds
 *     legítimos, corrompendo conteúdo e quebrando o invariante de hash para
 *     sempre; o usuário técnico também dá actor real ao audit log,
 *     post_author coerente e capabilities funcionais para hooks de terceiros;
 *  3. kses_remove_filters() — restaurado com kses_init_filters() no finally;
 *  4. big_image_size_threshold desligado (§A.5.2.7) — filtro registrado DENTRO
 *     do guard e removido no finally (escopo por lote, nunca global); sem
 *     isso, imagens >2560px seriam re-escaladas para -scaled no destino e
 *     divergiriam do blob versionado para sempre.
 *
 * Aninhamento é proibido (LogicException). Ordem normativa (r1-t2): o guard é
 * o envelope EXTERNO; StateStore::withLockedRow() é o interno — os hooks
 * disparados por wp_update_post() dentro da transação devem encontrar o
 * contexto de import ativo.
 *
 * Nota sobre a flag: define() é permanente no processo. Num lote CLI isso é o
 * comportamento desejado (o processo de apply nunca dispara export); o
 * aninhamento é controlado pela flag de instância $active.
 *
 * @package CVSync
 */

declare(strict_types=1);

namespace CVSync;

defined('ABSPATH') || exit;

final class ImportGuard
{
    /** Guard ativo NESTE envelope (anti-aninhamento). */
    private bool $active = false;

    /** Usuário anterior, restaurado no finally. */
    private ?int $previousUserId = null;

    /**
     * Executa $callback sob o envelope §10.2/§A.5.2.7.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws \LogicException Guard aninhado.
     * @throws \RuntimeException Usuário técnico indisponível.
     */
    public function run(callable $callback): mixed
    {
        if ($this->active) {
            throw new \LogicException('ImportGuard aninhado é proibido (escopo por entidade/lote).');
        }

        $this->active = true;
        if (!defined('CVSYNC_IMPORTING')) {
            define('CVSYNC_IMPORTING', true);
        }

        $this->previousUserId = get_current_user_id();
        wp_set_current_user($this->technicalUserId());

        kses_remove_filters();
        add_filter('big_image_size_threshold', '__return_false', PHP_INT_MAX);

        try {
            return $callback();
        } finally {
            remove_filter('big_image_size_threshold', '__return_false', PHP_INT_MAX);
            kses_init_filters();
            wp_set_current_user($this->previousUserId ?? 0);
            $this->active = false;
        }
    }

    /**
     * true durante um import (guard ativo ou flag já definida neste processo).
     * Usado pelos Hooks para suprimir o dirty-mark durante o apply (§5.4).
     */
    public function isImporting(): bool
    {
        return $this->active || (defined('CVSYNC_IMPORTING') && constant('CVSYNC_IMPORTING') === true);
    }

    /**
     * Usuário técnico do import (§10.2): CVSYNC_IMPORT_USER (login ou ID
     * numérico); fallback determinístico = primeiro administrador por ID.
     *
     * @throws \RuntimeException Nenhum administrador encontrado.
     */
    public function technicalUserId(): int
    {
        if (defined('CVSYNC_IMPORT_USER')) {
            $configured = constant('CVSYNC_IMPORT_USER');
            $user = is_numeric($configured)
                ? get_userdata((int) $configured)
                : get_user_by('login', (string) $configured);
            if ($user instanceof \WP_User) {
                return (int) $user->ID;
            }
        }

        $admins = get_users([
            'role'    => 'administrator',
            'orderby' => 'ID',
            'order'   => 'ASC',
            'number'  => 1,
            'fields'  => 'ids',
        ]);

        if ($admins === []) {
            throw new \RuntimeException(
                'ImportGuard: nenhum usuário técnico (CVSYNC_IMPORT_USER inválido e nenhum administrador no site).'
            );
        }

        return (int) $admins[0];
    }

    /** Login do usuário técnico, para o campo actor do audit log. */
    public function technicalActor(): string
    {
        $user = get_userdata($this->technicalUserId());

        return $user instanceof \WP_User ? $user->user_login : 'cvsync-import';
    }
}
