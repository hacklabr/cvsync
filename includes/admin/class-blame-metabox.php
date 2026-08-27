<?php
/**
 * BlameMetabox — metabox discreto na tela de edição das entidades versionadas
 * (spec §11.1): "Origem: repositório, aplicado em <data> por <gatilho>".
 *
 *  - Condicionado a edit_post do post (§10.1 capabilities);
 *  - Dados via AuditLog::blame() do P2 (idx_blame — sem scan);
 *  - Toda saída com esc_html()/esc_url();
 *  - Nunca quebra a tela de edição: falha de leitura (tabela ausente,
 *    migration pendente §5.9) degrada para mensagem neutra.
 *
 * @package CVSync\Admin
 */

declare(strict_types=1);

namespace CVSync\Admin;

use CVSync\Adapters\AdapterRegistry;
use CVSync\Engine\EntityRef;
use CVSync\Storage\AuditLog;
use CVSync\Storage\LogEntry;
use CVSync\Storage\LogResult;
use CVSync\Storage\SyncDirection;

defined('ABSPATH') || exit;

final class BlameMetabox
{
    /** Meta com o UUID estável da entidade (§6 — IDs nunca cruzam a fronteira). */
    private const UUID_META = '_cvsync_uuid';

    public function __construct(
        private readonly AdapterRegistry $adapters,
        private readonly AuditLog $log,
    ) {
    }

    /** Registra o metabox (chamado pelo bootstrap, P6). */
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'registerMetaboxes']);
    }

    /** Um metabox por post type versionado (inclui attachment, se P4 ativo). */
    public function registerMetaboxes(): void
    {
        foreach ($this->adapters->versionedPostTypes() as $postType) {
            add_meta_box(
                'cvsync-blame',
                __('CVSync — Origem', 'cvsync'),
                [$this, 'render'],
                $postType,
                'side',
                'low' // discreto (§11.1)
            );
        }
    }

    public function render(\WP_Post $post): void
    {
        if (! current_user_can('edit_post', $post->ID)) {
            return;
        }

        $uuid = (string) get_post_meta($post->ID, self::UUID_META, true);
        if ('' === $uuid) {
            printf(
                '<p class="description">%s</p>',
                esc_html__('Ainda não versionado pelo CVSync (sem UUID atribuído).', 'cvsync')
            );
            return;
        }

        $applied = $this->lastAppliedFromRepository($post->post_type, $uuid);

        if (null === $applied) {
            printf(
                '<p class="description">%s</p>',
                esc_html__('Nenhuma aplicação a partir do repositório registrada para esta entidade.', 'cvsync')
            );
            return;
        }

        printf(
            '<p class="description">%s</p>',
            esc_html(
                sprintf(
                    /* translators: 1: data/hora da aplicação, 2: gatilho (cli|git-hook|deploy|cron|save-hook|passive). */
                    __('Origem: repositório, aplicado em %1$s por %2$s.', 'cvsync'),
                    wp_date(
                        get_option('date_format') . ' ' . get_option('time_format'),
                        $applied->createdAt->getTimestamp()
                    ),
                    $applied->trigger
                )
            )
        );

        if ('' !== $applied->actor) {
            printf(
                '<p class="description">%s</p>',
                esc_html(
                    sprintf(
                        /* translators: %s: usuário técnico do import (actor do audit log). */
                        __('Actor: %s.', 'cvsync'),
                        $applied->actor
                    )
                )
            );
        }

        if (null !== $applied->filePath && '' !== $applied->filePath) {
            printf(
                '<p class="description cvsync-code-wrap"><code>%s</code></p>',
                esc_html($applied->filePath)
            );
        }
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /**
     * Última linha do audit log com direção file→db aplicada (a resposta de
     * "por que minha página mudou?", §11.1).
     *
     */
    private function lastAppliedFromRepository(string $postType, string $uuid): ?LogEntry
    {
        try {
            $entries = $this->log->blame(EntityRef::post($postType, $uuid), 20);
        } catch (\Throwable) {
            return null; // tabela ausente/migration pendente — degrada neutro
        }

        foreach ($entries as $entry) {
            if (SyncDirection::FileToDb === $entry->direction
                && in_array($entry->result, [LogResult::Applied, LogResult::AppliedDegraded], true)
            ) {
                return $entry;
            }
        }

        return null;
    }
}
