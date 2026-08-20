<?php
/**
 * MediaHooks — hooks do ciclo de vida de attachments (§A.2.4), estendendo a
 * camada de detecção §8.1 do P3 (que cobre conteúdo/menus/branding).
 *
 *  - Upload novo: 'add_attachment' (dispara só após arquivo gravado e post
 *    inserido — seguro por construção);
 *  - Edição editorial (title/alt/caption/description): 'attachment_updated' +
 *    meta da whitelist curta (_wp_attached_file, _wp_attachment_image_alt) —
 *    coberto pelo Hooks do P3 via whitelist do adapter registrado;
 *  - E2: 'wp_update_attachment_metadata' NUNCA é registrado (regeneração não
 *    é edição — inclui a regeneração do próprio apply);
 *  - Deleção: 'delete_attachment' → tombstone (§A.7 — metadado; bytes ficam
 *    para o GC físico manual);
 *  - Guards E3: exclusivamente wp_is_post_autosave()/wp_is_post_revision() +
 *    flags de contexto — NUNCA filtro por post_status cru ('inherit' é o
 *    status natural do attachment);
 *  - Escopo referenced (default, §A.5.5): upload novo NÃO exporta
 *    imediatamente; a referência por entidade versionada enfileira o anexo.
 *    O export efetivo é o fluxo DEDICADO AttachmentAdapter::exportAttachment()
 *    (sidecar+blob) — mantido por paridade: setBinaryMeta (§A.4.2) e
 *    skipped-oversized (§A.5.4), que o Exporter genérico não cobre (r6).
 *
 * @package CVSync\Media
 */

declare(strict_types=1);

namespace CVSync\Media;

use CVSync\Adapters\ReferenceResolver;
use CVSync\Engine\EntityRef;
use CVSync\ImportGuard;
use CVSync\Storage\StateStore;

defined('ABSPATH') || exit;

final class MediaHooks
{
    /** @var list<int> Attachments a exportar no shutdown (fila in-request). */
    private array $exportQueue = [];

    public function __construct(
        private readonly AttachmentAdapter $adapter,
        private readonly ReferenceGraph $graph,
        private readonly StateStore $state,
        private readonly ImportGuard $guard,
    ) {
    }

    /** Registra os hooks do ciclo de vida (chamado pelo bootstrap, P6). */
    public function register(): void
    {
        add_action('add_attachment', [$this, 'onAddAttachment']);
        add_action('attachment_updated', [$this, 'onAttachmentUpdated']);
        add_action('delete_attachment', [$this, 'onDeleteAttachment']);

        // Meta editorial da whitelist curta (§A.2.4): _wp_attached_file e
        // _wp_attachment_image_alt — o Hooks do P3 exclui attachments (🟡1);
        // alt/substituição de arquivo entram por aqui.
        add_action('added_post_meta', [$this, 'onAttachmentMetaChanged'], 10, 3);
        add_action('updated_post_meta', [$this, 'onAttachmentMetaChanged'], 10, 3);
        add_action('deleted_post_meta', [$this, 'onAttachmentMetaChanged'], 10, 3);

        // Escopo referenced: salvar entidade versionada enfileira os anexos
        // que ela referencia (§A.5.5) — prioridade tardia, após o P3.
        add_action('save_post', [$this, 'onReferenceCarrierSaved'], 200, 2);

        // Export dedicado no shutdown (sidecar + blob), após o flush do P3.
        add_action('shutdown', [$this, 'onShutdown'], 20);

        // E2: 'wp_update_attachment_metadata' NUNCA é registrado.
    }

    public function onAddAttachment(int $postId): void
    {
        if ($this->isSuppressed($postId)) {
            return;
        }
        if ($this->graph->scope() === 'referenced') {
            return; // §A.5.5: exporta quando uma entidade versionada o referencia
        }

        $this->enqueue($postId);
    }

    public function onAttachmentUpdated(int $postId): void
    {
        if ($this->isSuppressed($postId)) {
            return;
        }

        $this->enqueue($postId);
    }

    /**
     * Meta da whitelist curta de attachments (§A.2.4): alt e substituição de
     * arquivo (plugins "replace media" mudam _wp_attached_file). Regeneração
     * de metadata NÃO passa aqui (E2 — o hook dela nunca é registrado).
     * Assinatura real: ($meta_id, $object_id, $meta_key).
     */
    public function onAttachmentMetaChanged(mixed $metaId, int $objectId, string $metaKey): void
    {
        if (!in_array($metaKey, ['_wp_attached_file', '_wp_attachment_image_alt'], true)) {
            return;
        }
        if ($this->isSuppressed($objectId)) {
            return;
        }
        $post = get_post($objectId);
        if (!$post instanceof \WP_Post || $post->post_type !== 'attachment') {
            return;
        }

        $this->enqueue($objectId);
    }

    public function onDeleteAttachment(int $postId): void
    {
        if ($this->guard->isImporting()) {
            return;
        }
        // Tombstone é operação de metadado (§A.7); o sidecar é removido pelo
        // export dedicado (admin é autoridade em dev); bytes ficam para o GC.
        $post = get_post($postId);
        if ($post instanceof \WP_Post && $post->post_type === 'attachment') {
            $uuid = (string) get_post_meta($postId, '_cvsync_uuid', true);
            if ($uuid !== '') {
                $this->state->tombstone(EntityRef::post('attachment', $uuid));
                $path = 'media/' . $post->post_name . '.attachment.yml';
                // Remove SOMENTE o sidecar; o blob fica para o GC (§A.7).
                try {
                    $this->adapter->deleteFile($path);
                } catch (\Throwable) {
                    // Path fora do padrão não bloqueia a deleção do post.
                }
            }
        }
    }

    /** Portador de referências salvo → enfileira os anexos referenciados. */
    public function onReferenceCarrierSaved(int $postId, \WP_Post $post): void
    {
        if ($this->isSuppressed($postId) || $post->post_type === 'attachment') {
            return;
        }
        if ($this->graph->scope() !== 'referenced') {
            return; // escopo all: uploads já se auto-enfileiram
        }

        foreach ($this->graph->referencedAttachmentIdsForPost($postId) as $attachmentId) {
            $this->enqueue($attachmentId);
        }
    }

    /** Flush do shutdown: export dedicado (sidecar + blob) da fila in-request. */
    public function onShutdown(): void
    {
        if ($this->guard->isImporting() || $this->exportQueue === []) {
            return;
        }

        foreach (array_unique($this->exportQueue) as $attachmentId) {
            $uuid = (string) get_post_meta($attachmentId, '_cvsync_uuid', true);
            if ($uuid === '') {
                $uuid = $this->adapter->ensureUuid($attachmentId);
            }
            try {
                $this->adapter->exportAttachment(EntityRef::post('attachment', $uuid), 'save-hook');
            } catch (\Throwable $e) {
                // Falha de mídia nunca derruba o request web; o verify reporta (🔵5 r7).
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log(sprintf('[cvsync] export de attachment %d falhou no shutdown: %s', $attachmentId, $e->getMessage()));
            }
        }
        $this->exportQueue = [];
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private function enqueue(int $attachmentId): void
    {
        $this->exportQueue[] = $attachmentId;
    }

    /** Guards E3 — idênticos aos do P3: autosave/revision + flags, NUNCA status cru. */
    private function isSuppressed(int $postId): bool
    {
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return true;
        }
        if (defined('DOING_AUTOSAVE') && constant('DOING_AUTOSAVE')) {
            return true;
        }
        if (defined('WP_IMPORTING') && constant('WP_IMPORTING')) {
            return true;
        }

        return $this->guard->isImporting();
    }
}
