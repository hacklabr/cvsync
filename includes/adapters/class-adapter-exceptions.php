<?php
/**
 * Exceções do pacote de adapters (P3).
 *
 * Família em arquivo único de propósito (mesmo precedente do P2 em
 * class-storage-exception.php): exceções de domínio curtas, sempre carregadas
 * juntas; o bootstrap (P6) pode dar require direto ou via classmap.
 *
 * @package CVSync\Adapters
 */

declare(strict_types=1);

namespace CVSync\Adapters;

defined('ABSPATH') || exit;

/** Erro de domínio de um adapter de entidade. */
class AdapterException extends \RuntimeException
{
}

/**
 * Entidade rejeitada na validação pré-insert (§10.2): schema de frontmatter
 * inválido, round-trip de blocos instável, post_type divergente do arquivo,
 * tema ativo divergente (global styles). O lote continua; a entidade é logada.
 */
class RejectedEntityException extends AdapterException
{
}

/**
 * Sequestro de UUID (§6.3, cláusula obrigatória): o uuid do arquivo casa com
 * um db_id cujo post_type ou slug diverge do esperado → conflito, NUNCA apply.
 */
class UuidOwnershipMismatchException extends AdapterException
{
    public function __construct(
        string $message,
        public readonly string $uuid,
        public readonly string $expectedSlug,
        public readonly string $foundSlug,
        public readonly string $expectedPostType,
        public readonly string $foundPostType,
    ) {
        parent::__construct($message);
    }
}
