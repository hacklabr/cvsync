<?php
/**
 * Exceções do pacote storage (P2).
 *
 * Contrato de erro (r1): $wpdb não lança exceções — o P2 converte
 * $wpdb->last_error / retornos false em exceções tipadas na própria fronteira.
 * Consumidores (P3/P4/P5) programam contra exceções, nunca contra last_error.
 *
 * Família em arquivo único de propósito: são exceções de domínio curtas e
 * sempre carregadas juntas; o bootstrap (P6) pode dar require direto.
 *
 * @package CVSync\Storage
 */

declare(strict_types=1);

namespace CVSync\Storage;

defined('ABSPATH') || exit;

/** Erro de persistência nas tabelas do cvsync (wrap de $wpdb->last_error). */
class StorageException extends \RuntimeException
{
}

/** Gate §5.9: apply recusado porque há migration de schema pendente. */
class MigrationPendingException extends StorageException
{
}

/** Lock de batch não adquirida dentro do timeout — apply aborta (fail-closed, §5.8). */
class LockNotAcquiredException extends StorageException
{
}

/**
 * Violação do invariante um-named-lock-por-sessão (restrição MariaDB §5.8):
 * um segundo GET_LOCK na mesma conexão liberaria o anterior silenciosamente.
 */
class LockViolationException extends StorageException
{
}
