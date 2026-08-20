<?php
/**
 * `wp sync log` e `wp sync blame <entity>` — audit trail (contrato §8.3,
 * §11.1). Exit 0 sempre.
 *
 * blame responde "por que esta página mudou?": última aplicação, gatilho,
 * arquivo, hash antes/depois, actor.
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

use CVSync\Storage\LogEntry;

defined('ABSPATH') || exit;

final class CommandLog extends CommandBase
{
    public function __invoke(array $args, array $assocArgs): void
    {
        $last    = max(1, (int) ($assocArgs['last'] ?? 50));
        $entries = $this->c->log->recent($last);

        $this->renderEntries($entries, (bool) $this->isJson($assocArgs));
        \WP_CLI::halt(0);
    }

    /** @param list<LogEntry> $entries */
    public function renderEntries(array $entries, bool $json): void
    {
        foreach ($entries as $entry) {
            $payload = [
                'id'        => $entry->id,
                'created_at' => $entry->createdAt->format('Y-m-d H:i:s'),
                'entity'    => $entry->ref->toTupleString(),
                'direction' => $entry->direction?->value,
                'trigger'   => $entry->trigger,
                'actor'     => $entry->actor,
                'result'    => $entry->result->value,
                'file'      => $entry->filePath,
                'before'    => $entry->beforeHash,
                'after'     => $entry->afterHash,
                'error'     => $entry->error,
            ];
            if ($json) {
                $this->jsonLine($payload);
            } else {
                \WP_CLI::log(sprintf(
                    '#%d %s [%-28s] %-38s %-12s %-10s %s%s',
                    $entry->id ?? 0,
                    $payload['created_at'],
                    (string) $entry->trigger,
                    $entry->ref->toTupleString(),
                    (string) ($entry->direction?->value ?? '-'),
                    $entry->result->value,
                    (string) ($entry->actor),
                    null !== $entry->error ? ' — ' . $entry->error : ''
                ));
            }
        }
    }
}

final class CommandBlame extends CommandBase
{
    public function __invoke(array $args, array $assocArgs): void
    {
        $entity = (string) ($args[0] ?? '');
        if ('' === $entity) {
            \WP_CLI::error('Uso: wp sync blame <entity> (post_type:slug | kind:post_type:key | uuid)');
        }

        $ref = $this->parseEntityArg($entity);
        if (null === $ref) {
            \WP_CLI::error(sprintf('Entidade não encontrada: %s', $entity));
        }

        $last    = max(1, (int) ($assocArgs['last'] ?? 20));
        $entries = $this->c->log->blame($ref, $last);
        $json    = $this->isJson($assocArgs);

        if ([] === $entries) {
            \WP_CLI::log(sprintf('Sem histórico para %s.', $ref->toTupleString()));
            \WP_CLI::halt(0);
        }

        if (! $json) {
            \WP_CLI::log(sprintf('Blame de %s (últimos %d):', $ref->toTupleString(), count($entries)));
        }

        $log = new CommandLog($this->c);
        $log->renderEntries($entries, $json);
        \WP_CLI::halt(0);
    }
}
