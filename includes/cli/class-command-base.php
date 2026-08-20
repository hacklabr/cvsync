<?php
/**
 * CommandBase — infraestrutura comum dos comandos `wp sync` (§8.3):
 * saída JSON lines em stdout nos comandos de relatório, parsing do argumento
 * de entidade, gates de ambiente (matriz §7.3) e aviso de constantes
 * inválidas (§10.1).
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

use CVSync\Engine\EntityRef;
use CVSync\Environment;

defined('ABSPATH') || exit;

abstract class CommandBase
{
    public function __construct(protected readonly Container $c)
    {
    }

    /** --format=json presente? */
    protected function isJson(array $assocArgs): bool
    {
        return 'json' === (string) ($assocArgs['format'] ?? '');
    }

    /** JSON lines em stdout (§11.1 — capturável pelo pipeline como artefato). */
    protected function jsonLine(array $payload): void
    {
        \WP_CLI::log((string) wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Warnings de constantes inválidas (§10.1) — nunca silenciosos. */
    protected function warnInvalidConstants(): void
    {
        foreach (Environment::warnings() as $warning) {
            \WP_CLI::warning($warning);
        }
    }

    /**
     * Gate de mutação conforme a matriz §7.3.
     *
     * - Ambientes com apply automático: livre via CLI (confiança de shell);
     * - prod: triplo fator (--force + TTY + CVSYNC_ALLOW_PROD_APPLY) — stdin
     *   não-TTY RECUSA, nunca pula o prompt.
     *
     * @return string|null Mensagem de recusa (null = permitido).
     */
    protected function mutationRefusal(bool $force): ?string
    {
        if (Environment::PROD !== Environment::current()) {
            return null;
        }

        [$allowed, $missing] = Environment::prodApplyGate($force);
        if ($allowed) {
            return null;
        }

        return sprintf(
            'Ambiente PROD: mutação recusada (fail-closed §7.3). Fatores ausentes: %s.',
            implode(' + ', $missing)
        );
    }

    /**
     * --force-locks existe apenas em CLI INTERATIVO (§8.4): sem TTY, a flag é
     * recusada (nunca ignorada silenciosamente).
     *
     * @return string|null Mensagem de recusa (null = ok).
     */
    protected function forceLocksRefusal(bool $forceLocks): ?string
    {
        if (! $forceLocks) {
            return null;
        }
        if (! Environment::stdinIsTty()) {
            return '--force-locks exige CLI interativo com TTY (§8.4) — stdin não-TTY: recusado.';
        }

        \WP_CLI::warning('--force-locks: entidades com editor lock ativo serão sobrescritas (o editor perde o buffer).');

        return null;
    }

    /**
     * Parsing do argumento de entidade dos comandos blame/resolve:
     *  - tupla completa: 'kind:post_type:key' (ex.: 'post:page:018f…');
     *  - 'post_type:slug' (ex.: 'page:sobre-nos');
     *  - UUID ou slug isolado (busca em todos os adapters).
     */
    protected function parseEntityArg(string $arg): ?EntityRef
    {
        $parts = explode(':', $arg);

        if (3 === count($parts)) {
            [$kind, $postType, $key] = $parts;

            return 'post' === $kind ? EntityRef::post($postType, $key) : EntityRef::of($kind, $key);
        }

        if (2 === count($parts)) {
            [$postType, $slug] = $parts;
            $adapter = $this->c->adapters->forPostType($postType);

            return $adapter?->findBySlug($slug);
        }

        // UUID ou slug isolado: varre os adapters (escala alvo torna irrelevante).
        foreach ($this->c->adapters->all() as $adapter) {
            $ref = $adapter->findByUuid($arg) ?? $adapter->findBySlug($arg);
            if (null !== $ref) {
                return $ref;
            }
        }

        return null;
    }

    /** Flag --older-than=90d → dias inteiros (default 90, alinhado ao TTL §5.5). */
    protected function olderThanDays(array $assocArgs): int
    {
        $raw = (string) ($assocArgs['older-than'] ?? '90d');
        if (preg_match('/^(\d+)d?$/', $raw, $m) === 1) {
            return max(1, (int) $m[1]);
        }

        \WP_CLI::warning(sprintf('--older-than inválido (%s) — usando 90d.', $raw));

        return 90;
    }
}
