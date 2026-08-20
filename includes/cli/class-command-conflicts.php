<?php
/**
 * `wp sync conflicts`, `wp sync conflict show <id>` e `wp sync conflicts
 * prune` — visibilidade e housekeeping da tabela de perdedores (§7.4, §8.3).
 *
 * Exit: 0 sucesso; 1 erro (id inexistente, falha de escrita do --out).
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

defined('ABSPATH') || exit;

/** `wp sync conflicts [prune]` — lista pendentes / housekeeping. */
final class CommandConflicts extends CommandBase
{
    public function __invoke(array $args, array $assocArgs): void
    {
        if ('prune' === ($args[0] ?? '')) {
            $this->prune($assocArgs);

            return;
        }

        $json     = $this->isJson($assocArgs);
        $pending  = $this->c->conflicts->listUnresolved();

        if ($json) {
            foreach ($pending as $conflict) {
                $this->jsonLine([
                    'id'         => $conflict->id,
                    'entity'     => $conflict->ref->toTupleString(),
                    'loser_side' => $conflict->loserSide,
                    'winner'     => $conflict->winner,
                    'trigger'    => $conflict->trigger,
                    'actor'      => $conflict->actor,
                    'git_head'   => $conflict->gitHead,
                    'created_at' => $conflict->createdAt->format('Y-m-d H:i:s'),
                ]);
            }
        } else {
            foreach ($pending as $conflict) {
                \WP_CLI::log(sprintf(
                    '#%d %-40s winner=%s loser=%s (%s, %s, %s)',
                    $conflict->id ?? 0,
                    $conflict->ref->toTupleString(),
                    $conflict->winner,
                    $conflict->loserSide,
                    $conflict->trigger,
                    $conflict->actor,
                    $conflict->createdAt->format('Y-m-d H:i:s')
                ));
            }
            \WP_CLI::log(sprintf('%d conflito(s) pendente(s).', count($pending)));
        }

        \WP_CLI::halt(0);
    }

    /** Housekeeping (§7.4): purge de resolvidas após N dias (default 90). */
    private function prune(array $assocArgs): void
    {
        $all  = (bool) ($assocArgs['all-resolved'] ?? false);
        $days = $all ? 0 : $this->olderThanDays($assocArgs); // 0 = cutoff "agora" (todas as resolvidas)

        $removed = $this->c->conflicts->pruneResolved($days);
        \WP_CLI::log(sprintf('conflicts prune: %d registro(s) resolvido(s) removido(s) (older-than=%s).', $removed, $all ? 'todas' : $days . 'd'));
        \WP_CLI::halt(0);
    }
}

/** `wp sync conflict show <id> [--out=<path>]` — despeja o payload do perdedor. */
final class CommandConflict extends CommandBase
{
    public function __invoke(array $args, array $assocArgs): void
    {
        $sub = (string) ($args[0] ?? '');
        if ('show' !== $sub) {
            \WP_CLI::error('Uso: wp sync conflict show <id> [--out=<path>]');
        }

        $id = (int) ($args[1] ?? 0);
        if ($id <= 0) {
            \WP_CLI::error('ID de conflito inválido.');
        }

        $conflict = $this->c->conflicts->get($id);
        if (null === $conflict) {
            \WP_CLI::error(sprintf('Conflito #%d não encontrado.', $id));
        }

        $out = isset($assocArgs['out']) ? (string) $assocArgs['out'] : null;
        if (null !== $out) {
            // Escrito pelo CLI com as credenciais do operador — o webserver
            // NUNCA escreve artefatos de recuperação (§7.4).
            if (file_put_contents($out, $conflict->loserPayload) === false) {
                \WP_CLI::error(sprintf('Falha ao escrever em %s', $out));
            }
            \WP_CLI::log(sprintf('Payload do perdedor (%s) escrito em %s', $conflict->loserSide, $out));
            \WP_CLI::halt(0);
        }

        if ($this->isJson($assocArgs)) {
            $this->jsonLine([
                'id'           => $conflict->id,
                'entity'       => $conflict->ref->toTupleString(),
                'loser_side'   => $conflict->loserSide,
                'winner'       => $conflict->winner,
                'trigger'      => $conflict->trigger,
                'actor'        => $conflict->actor,
                'git_head'     => $conflict->gitHead,
                'created_at'   => $conflict->createdAt->format('Y-m-d H:i:s'),
                'resolved_at'  => $conflict->resolvedAt?->format('Y-m-d H:i:s'),
                'loser_payload' => $conflict->loserPayload,
            ]);
        } else {
            \WP_CLI::log(sprintf('Conflito #%d — %s (winner: %s, loser: %s, %s)', $conflict->id ?? 0, $conflict->ref->toTupleString(), $conflict->winner, $conflict->loserSide, $conflict->createdAt->format('Y-m-d H:i:s')));
            \WP_CLI::log('--- loser_payload ---');
            \WP_CLI::log($conflict->loserPayload);
        }

        \WP_CLI::halt(0);
    }
}
