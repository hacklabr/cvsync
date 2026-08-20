<?php
/**
 * Named locks MariaDB (GET_LOCK/RELEASE_LOCK) — mecanismo ÚNICO de mutex do
 * cvsync (spec §5.8, cláusula obrigatória).
 *
 * Propriedades (§5.8):
 *  - Auto-release na morte da conexão (processo morto nunca deixa trava órfã);
 *    cross-processo e cross-container (PHP-FPM × WP-CLI × cron);
 *  - Política assimétrica: export (lock por entidade) FAIL-OPEN (null);
 *    apply (lock de batch) FAIL-CLOSED (LockNotAcquiredException);
 *  - INVARIANTE ESTRUTURAL: uma sessão MariaDB mantém apenas UM named lock —
 *    um segundo GET_LOCK libera o anterior SILENCIOSAMENTE. Este pacote
 *    enforce a regra em PHP (LockViolationException), porque no servidor a
 *    violação é invisível. Consequência: dentro da batch lock, a serialização
 *    por entidade é SELECT ... FOR UPDATE (StateStore::withLockedRow) — nunca
 *    named lock por entidade.
 *
 * Nomes de lock: limite de 64 chars no MariaDB. entity_key tem até 191 chars
 * (UUID, slug, '{stylesheet}:{location}') → sufixo hasheado. Formato:
 *   batch:   cvsync:{blog_id}:batch
 *   entidade: cvsync:{blog_id}:e:{kind}:{sha256(key)[0..23]}
 * Pior caso: 7 + 10 + 3 + 13 ('menu_location') + 1 + 24 = 58 ≤ 64.
 * (assert estrutural no código — estouro vira LogicException, nunca lock errada.)
 *
 * @package CVSync\Storage
 */

declare(strict_types=1);

namespace CVSync\Storage;

use CVSync\Engine\EntityRef;

defined('ABSPATH') || exit;

/**
 * Handle de uma named lock adquirida. release() é idempotente; o destrutor
 * libera como rede de segurança (a morte da conexão também libera — mas o
 * release explícito em finally é o mecanismo primário).
 */
interface LockHandle
{
    public function name(): string;

    public function release(): void;

    public function released(): bool;
}

final class Locks
{
    /** Lock atualmente presa por esta instância (invariante um-por-sessão). */
    private ?LockHandle $held = null;

    public function __construct(
        private readonly \wpdb $db,
        private readonly ?int $blogId = null
    ) {
    }

    /** Nome da lock de batch (≤ 64 chars). */
    public static function batchLockName(int $blogId): string
    {
        return sprintf('cvsync:%d:batch', $blogId);
    }

    /**
     * Nome da lock por entidade (≤ 64 chars — sufixo sha256 truncado).
     * Determinístico: mesma tupla → mesmo nome (observável em
     * performance_schema.metadata_locks).
     */
    public static function entityLockName(int $blogId, EntityRef $ref): string
    {
        $name = sprintf(
            'cvsync:%d:e:%s:%s',
            $blogId,
            $ref->kind,
            substr(hash('sha256', $ref->key), 0, 24)
        );

        if (strlen($name) > 64) {
            // Inalcançável com os kinds conhecidos (máx. 58) — guard estrutural.
            throw new \LogicException(sprintf('Lock name excede 64 chars: %s', $name));
        }

        return $name;
    }

    /**
     * Lock ÚNICA de batch do apply (§5.8). FAIL-CLOSED.
     *
     * @throws LockNotAcquiredException Timeout — o apply aborta ruidoso.
     * @throws LockViolationException Já existe named lock presa nesta sessão.
     * @throws StorageException GET_LOCK retornou erro (NULL) ou falha de query.
     */
    public function acquireBatch(int $timeoutSeconds = 5): LockHandle
    {
        $name   = self::batchLockName($this->blogId());
        $handle = $this->acquire($name, $timeoutSeconds);

        if (null === $handle) {
            throw new LockNotAcquiredException(
                sprintf('Batch lock "%s" não adquirida em %ds — apply abortado (fail-closed, §5.8).', $name, $timeoutSeconds)
            );
        }

        return $handle;
    }

    /**
     * Lock por entidade do export (§5.8). FAIL-OPEN: timeout ⇒ null (a entidade
     * permanece dirty_db e o próximo ciclo reprocessa). NUNCA chamar com a
     * batch lock presa (o invariante abaixo rejeita antes de tocar o servidor).
     *
     * @throws LockViolationException Já existe named lock presa nesta sessão.
     * @throws StorageException GET_LOCK retornou erro (NULL) ou falha de query.
     */
    public function tryAcquireEntity(EntityRef $ref, int $timeoutSeconds = 3): ?LockHandle
    {
        return $this->acquire(self::entityLockName($this->blogId(), $ref), $timeoutSeconds);
    }

    /** Lock atualmente presa (null se nenhuma). */
    public function heldLock(): ?LockHandle
    {
        if (null !== $this->held && $this->held->released()) {
            $this->held = null;
        }

        return $this->held;
    }

    private function blogId(): int
    {
        return $this->blogId ?? (int) get_current_blog_id();
    }

    /**
     * @throws LockViolationException
     * @throws StorageException
     */
    private function acquire(string $name, int $timeoutSeconds): ?LockHandle
    {
        if (null !== $this->heldLock()) {
            throw new LockViolationException(
                sprintf(
                    'Um named lock por sessão (MariaDB, §5.8): já presa "%s"; recusando "%s" (um segundo GET_LOCK liberaria a primeira silenciosamente).',
                    $this->heldLock()->name(),
                    $name
                )
            );
        }

        $result = $this->db->get_var(
            $this->db->prepare('SELECT GET_LOCK(%s, %d)', $name, $timeoutSeconds)
        );

        if ('' !== $this->db->last_error) {
            throw new StorageException(sprintf('GET_LOCK(%s): %s', $name, $this->db->last_error));
        }

        if (null === $result) {
            // GET_LOCK retorna NULL em erro de servidor (ex.: nome inválido).
            throw new StorageException(sprintf('GET_LOCK(%s) retornou NULL (erro de servidor).', $name));
        }

        if (1 !== (int) $result) {
            return null; // timeout
        }

        $this->held = new MariaDbLockHandle($this->db, $name);

        return $this->held;
    }
}

/**
 * Handle concreto sobre $wpdb. release() nunca lança (falha de RELEASE_LOCK é
 * inócua: a conexão libera ao morrer; logar é responsabilidade do chamador
 * se desejar).
 *
 * @internal Instanciado exclusivamente por Locks.
 */
final class MariaDbLockHandle implements LockHandle
{
    private bool $released = false;

    public function __construct(
        private readonly \wpdb $db,
        private readonly string $lockName
    ) {
    }

    public function name(): string
    {
        return $this->lockName;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;

        $this->db->query($this->db->prepare('SELECT RELEASE_LOCK(%s)', $this->lockName));
    }

    public function released(): bool
    {
        return $this->released;
    }

    public function __destruct()
    {
        $this->release();
    }
}
