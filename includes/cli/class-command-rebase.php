<?php
/**
 * `wp sync rebase --from=db|files` — recalcula o state SEM aplicar mudanças
 * (contrato §8.3). Não importa nem exporta; apenas re-alinha os hashes
 * observados do lado escolhido à realidade (ex.: após rename de tema — o
 * arquivo de global styles namespaced pelo stylesheet antigo vira órfão,
 * §11.3).
 *
 * Divergências resultantes aparecem no `wp sync plan`/`verify` seguintes.
 * Exit: 0 sucesso; 1 erros.
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

use CVSync\Engine\Hasher;

defined('ABSPATH') || exit;

final class CommandRebase extends CommandBase
{
    public function __invoke(array $args, array $assocArgs): void
    {
        $this->warnInvalidConstants();

        $from = (string) ($assocArgs['from'] ?? '');
        if (! in_array($from, ['db', 'files'], true)) {
            \WP_CLI::error('Uso: wp sync rebase --from=db|files');
        }

        $updated = 0;
        $errors  = 0;

        if ('db' === $from) {
            [$updated, $errors] = $this->rebaseFromDb();
        } else {
            [$updated, $errors] = $this->rebaseFromFiles();
        }

        if ($this->isJson($assocArgs)) {
            $this->jsonLine(['from' => $from, 'updated' => $updated, 'errors' => $errors]);
        } else {
            \WP_CLI::log(sprintf('rebase --from=%s: %d linha(s) de state realinhada(s), %d erro(s).', $from, $updated, $errors));
        }

        \WP_CLI::halt($errors > 0 ? 1 : 0);
    }

    /** @return array{0: int, 1: int} */
    private function rebaseFromDb(): array
    {
        $updated = 0;
        $errors  = 0;

        // Scan read-only via StateStore::all() (P2 — r6 item 5).
        $rows = $this->c->state->all();

        foreach ($rows as $record) {
            $adapter = $this->c->adapters->forRef($record->ref);
            if (null === $adapter || ! $adapter->exists($record->ref)) {
                continue;
            }
            try {
                $doc = $adapter->readCanonical($record->ref);
                if (null === $doc) {
                    continue;
                }
                $this->c->state->upsert($record->ref, [
                    'db_hash'     => $this->hex(Hasher::hashDocument($doc, $adapter->keyOrder())),
                    'db_modified' => new \DateTimeImmutable('now', wp_timezone()),
                ]);
                $updated++;
            } catch (\Throwable) {
                $errors++;
            }
        }

        return [$updated, $errors];
    }

    /** @return array{0: int, 1: int} */
    private function rebaseFromFiles(): array
    {
        $updated = 0;
        $errors  = 0;

        foreach ($this->c->adapters->all() as $adapter) {
            foreach ($this->c->paths->listFiles($adapter->baseDirectory()) as $relative) {
                if (! str_ends_with($relative, $adapter->fileExtension())) {
                    continue;
                }
                $bytes = $this->c->paths->read($relative);
                if (null === $bytes) {
                    continue;
                }
                try {
                    $doc = $adapter->parseDocument($bytes);
                    $this->c->state->touchFileMeta(
                        $doc->ref,
                        $this->hex(Hasher::hashDocument($doc, $adapter->keyOrder())),
                        $this->c->paths->mtime($relative)
                    );
                    $updated++;
                } catch (\Throwable) {
                    $errors++;
                }
            }
        }

        return [$updated, $errors];
    }

    private function hex(string $hash): string
    {
        return str_starts_with($hash, Hasher::PREFIX) ? substr($hash, strlen(Hasher::PREFIX)) : $hash;
    }
}
