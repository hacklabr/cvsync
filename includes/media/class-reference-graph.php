<?php
/**
 * ReferenceGraph — escopo `referenced` do export de anexos (§A.5.5).
 *
 * Fecho do grafo: anexos referenciados por entidades versionadas (atributos
 * "id"/"ids" de blocos de mídia nos corpos) + featured images (_thumbnail_id)
 * + branding (custom_logo/site_icon). Em modo referenced (default), upload
 * novo NÃO exporta imediatamente — exporta quando uma entidade versionada
 * que o referencia é salva (higiene de repo). Anexo versionado que perde
 * todas as referências é MANTIDO (removê-lo seria deleção não solicitada) e
 * marcado orphaned no verify; limpeza só via GC manual (§A.7.1).
 *
 * @package CVSync\Media
 */

declare(strict_types=1);

namespace CVSync\Media;

use CVSync\Adapters\AdapterRegistry;

defined('ABSPATH') || exit;

final class ReferenceGraph
{
    public function __construct(private readonly AdapterRegistry $adapters)
    {
    }

    /** Escopo efetivo: 'referenced' (default) × 'all' — via registry do Environment (§10.1; R4 do r9). */
    public function scope(): string
    {
        $scope = (string) (\CVSync\Environment::constant('CVSYNC_ATTACHMENT_SCOPE') ?? 'referenced');

        $scope = (string) apply_filters('cvsync/attachment_scope', $scope);

        // Fail-safe (🟡9 r7): SOMENTE 'all' explícito habilita o escopo amplo;
        // typo/valor inválido cai no escopo seguro — referenced exporta MENOS
        // (todo upload exportar silenciosamente é o modo de falha proibido).
        return $scope === 'all' ? 'all' : 'referenced';
    }

    /**
     * IDs de anexos referenciados por UM post versionado (corpo + featured).
     *
     * @return list<int>
     */
    public function referencedAttachmentIdsForPost(int $postId): array
    {
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return [];
        }

        $ids = [];

        // Atributos "id":N e "ids":[N,...] em comentários de bloco (markup cru
        // do banco — IDs locais, ainda não placeholderizados).
        if (preg_match_all('/<!--\s+(?:\/)?wp:.*?-->/s', $post->post_content, $comments) !== false) {
            foreach ($comments[0] as $comment) {
                if (preg_match_all('/"id"\s*:\s*(\d+)/', $comment, $m) !== false) {
                    foreach ($m[1] as $id) {
                        $ids[] = (int) $id;
                    }
                }
                if (preg_match_all('/"ids"\s*:\s*\[([\d,\s]*)\]/', $comment, $m) !== false) {
                    foreach (explode(',', $m[1][0] ?? '') as $id) {
                        $id = trim($id);
                        if ($id !== '') {
                            $ids[] = (int) $id;
                        }
                    }
                }
            }
        }

        $thumbnail = (int) get_post_meta($postId, '_thumbnail_id', true);
        if ($thumbnail > 0) {
            $ids[] = $thumbnail;
        }

        // Só anexos reais entram no fecho.
        $ids = array_values(array_unique(array_filter($ids)));
        $valid = [];
        foreach ($ids as $id) {
            $candidate = get_post($id);
            if ($candidate instanceof \WP_Post && $candidate->post_type === 'attachment') {
                $valid[] = $id;
            }
        }

        return $valid;
    }

    /**
     * IDs de anexos referenciados por TODAS as entidades versionadas
     * (export bulk --post-type=attachment em escopo referenced).
     *
     * @return list<int>
     */
    public function referencedAttachmentIds(): array
    {
        $ids = [];
        $statusMap = $this->adapters->versionedStatuses();
        unset($statusMap['attachment']); // portadores de referência, não alvos

        foreach ($statusMap as $postType => $statuses) {
            $posts = get_posts([
                'post_type'      => $postType,
                'post_status'    => $statuses,
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);
            foreach ($posts as $postId) {
                $ids = array_merge($ids, $this->referencedAttachmentIdsForPost((int) $postId));
            }
        }

        // Branding (§A.6): custom_logo + site_icon.
        foreach ([get_theme_mod('custom_logo'), get_option('site_icon')] as $brandingId) {
            $brandingId = (int) $brandingId;
            if ($brandingId > 0) {
                $ids[] = $brandingId;
            }
        }

        return array_values(array_unique($ids));
    }
}
