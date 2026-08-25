<?php
/**
 * `wp sync bootstrap` — seed inicial de state (contrato §8.3, spec §5.3).
 *
 * Ausência de state NUNCA é inferida — este comando é a porta explícita:
 *
 *  --from=files (default, ambiente novo): para cada arquivo do repo —
 *    · entidade ausente no banco → import (caso 6 — um banco novo + repo
 *      cheio importa tudo uma vez e converge);
 *    · db_hash == file_hash → linha recriada como ok SILENCIOSAMENTE
 *      (redescoberta);
 *    · divergência → status conflict, NUNCA adivinha;
 *  --from=db: exporta cada entidade do escopo (banco é a autoridade inicial).
 *
 * Exit: 0 sucesso; 1 falhas.
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

use CVSync\Engine\Hasher;
use CVSync\Environment;
use CVSync\ImportContext;
use CVSync\Storage\EntityStatus;
use CVSync\Storage\LogResult;
use CVSync\Storage\SyncDirection;

defined('ABSPATH') || exit;

final class CommandBootstrap extends CommandBase
{
    public function __invoke(array $args, array $assocArgs): void
    {
        $this->warnInvalidConstants();

        $from = (string) ($assocArgs['from'] ?? 'files');
        if (! in_array($from, ['files', 'db'], true)) {
            \WP_CLI::error('--from deve ser files|db (§5.3).');
        }

        if (null !== ($refusal = $this->mutationRefusal((bool) ($assocArgs['force'] ?? false)))) {
            \WP_CLI::error($refusal);
        }

        try {
            \CVSync\Storage\Schema::assertNoPendingMigration();
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }

        $summary = 'files' === $from ? $this->fromFiles() : $this->fromDb();

        if ($this->isJson($assocArgs)) {
            $this->jsonLine($summary);
        } else {
            \WP_CLI::log(sprintf(
                'Bootstrap --from=%s: %d importados, %d redescobertos (ok), %d conflitos, %d exportados, %d erros.',
                $from,
                $summary['imported'],
                $summary['rediscovered'],
                $summary['conflicts'],
                $summary['exported'],
                $summary['errors']
            ));
        }

        \WP_CLI::halt($summary['errors'] > 0 ? 1 : 0);
    }

    // ------------------------------------------------------------------

    /** @return array<string, int> */
    private function fromFiles(): array
    {
        $summary = ['imported' => 0, 'rediscovered' => 0, 'conflicts' => 0, 'exported' => 0, 'errors' => 0];

        try {
            $batch = $this->c->locks->acquireBatch();
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage()); // fail-closed (§5.8)
        }

        try {
            $ctx = new ImportContext(trigger: 'cli', environment: Environment::current());

            foreach ($this->c->adapters->byStage() as $adapters) {
                foreach ($adapters as $adapter) {
                    foreach ($this->c->paths->listFiles($adapter->baseDirectory()) as $relative) {
                        if (! str_ends_with($relative, $adapter->fileExtension())) {
                            continue;
                        }
                        $this->bootstrapFile($adapter, $relative, $ctx, $summary);
                    }
                }
            }

            $this->c->importer->fixupParents($ctx);
            if (null !== $this->c->materializer) {
                $this->c->materializer->regeneratePending();
            }
        } finally {
            $batch->release();
        }

        return $summary;
    }

    private function bootstrapFile(\CVSync\Adapters\EntityAdapter $adapter, string $relative, ImportContext $ctx, array &$summary): void
    {
        $bytes = $this->c->paths->read($relative);
        if (null === $bytes) {
            $summary['errors']++;

            return;
        }

        try {
            $doc      = $adapter->parseDocument($bytes);
            $adapter->validateFrontmatter($doc->frontmatter);
            $fileHash = $this->hex(Hasher::hashDocument($doc, $adapter->keyOrder()));
        } catch (\Throwable $e) {
            \WP_CLI::warning(sprintf('%s rejeitado: %s', $relative, $e->getMessage()));
            $summary['errors']++;

            return;
        }

        $ref = $doc->ref;

        // Adoção de legado (§9.1): scan único de postmeta + upsert do db_id.
        $adapter->findByUuid($ref->key);

        $dbHash = null;
        if ($adapter->exists($ref)) {
            try {
                $dbDoc  = $adapter->readCanonical($ref);
                $dbHash = null !== $dbDoc ? $this->hex(Hasher::hashDocument($dbDoc, $adapter->keyOrder())) : null;
            } catch (\Throwable) {
                $dbHash = null;
            }
        }

        if (! $adapter->exists($ref)) {
            // Entidade nova do repo → import (caso 6).
            $result = $this->c->importer->importFile($relative, $ctx);
            match ($result->outcome) {
                LogResult::Applied    => $summary['imported']++,
                LogResult::PendingRef => $summary['imported']++,
                default               => $summary['errors']++,
            };

            return;
        }

        if (null !== $dbHash && $dbHash === $fileHash) {
            // Redescoberta silenciosa (§5.3): hashes iguais → ok.
            $this->c->state->recordSync($ref, SyncDirection::FileToDb, $fileHash, null, $this->c->paths->mtime($relative));
            $summary['rediscovered']++;

            return;
        }

        // Divergência → conflict, NUNCA adivinha (§5.3).
        $this->c->state->upsert($ref, [
            'db_hash'    => $dbHash,
            'file_hash'  => $fileHash,
            'file_mtime' => $this->c->paths->mtime($relative),
            'status'     => EntityStatus::Conflict,
        ]);
        $summary['conflicts']++;
        \WP_CLI::warning(sprintf('conflito em %s (bootstrap não infere; resolva com wp sync resolve)', $ref->toTupleString()));
    }

    /** @return array<string, int> */
    private function fromDb(): array
    {
        $summary = ['imported' => 0, 'rediscovered' => 0, 'conflicts' => 0, 'exported' => 0, 'errors' => 0];

        foreach ($this->c->adapters->versionedPostTypes() as $postType) {
            if ('attachment' === $postType) {
                continue; // R1 da r9: attachments usam o fluxo dedicado (abaixo)
            }
            $adapter = $this->c->adapters->forPostType($postType);
            if (null === $adapter) {
                continue;
            }
            $ids = get_posts([
                'post_type'      => $postType,
                'post_status'    => $adapter->statuses(),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);
            foreach ($ids as $id) {
                $ref    = \CVSync\Engine\EntityRef::post($postType, $adapter->ensureUuid((int) $id));
                $this->c->state->upsert($ref, ['db_id' => (int) $id]);
                $result = $this->c->exporter->export($ref, 'cli');
                match ($result?->outcome) {
                    LogResult::Applied  => $summary['exported']++,
                    LogResult::Error    => $summary['errors']++,
                    default             => $summary['rediscovered']++,
                };
            }
        }

        // Attachments pelo fluxo DEDICADO (R1 da r9): o Exporter genérico
        // chamaria readCanonical() sem persistir o blob CAS (pós-🟡3 do r7) e
        // o sidecar sairia referenciando blob inexistente — falha ruidosa no
        // lint/apply. Escopo referenced (default §A.5.5).
        $attachmentAdapter = $this->c->adapters->forPostType('attachment');
        if ($attachmentAdapter instanceof \CVSync\Media\AttachmentAdapter && null !== $this->c->referenceGraph) {
            foreach ($this->c->referenceGraph->referencedAttachmentIds() as $attachmentId) {
                $ref     = \CVSync\Engine\EntityRef::post('attachment', $attachmentAdapter->ensureUuid((int) $attachmentId));
                $outcome = $attachmentAdapter->exportAttachment($ref, 'cli');
                match ($outcome) {
                    LogResult::Applied            => $summary['exported']++,
                    LogResult::Error, LogResult::Rejected => $summary['errors']++,
                    default                       => $summary['rediscovered']++, // idempotent/oversized/lock fail-open
                };
            }
        }

        // Termos das taxonomias versionadas (Apêndice B.7.1): padrão pós-r10 —
        // fluxo dedicado exportTerm() quando o TermAdapter o expõe; senão o
        // Exporter genérico (termo é YAML-integral sem blob — sem o risco R1).
        foreach ($this->versionedTaxonomies() as $taxonomy) {
            foreach ($this->enumerateVersionedTerms($taxonomy) as [$ref, $adapter]) {
                $outcome = $this->termOutcome($this->exportTermOnce($ref, $adapter));
                match ($outcome) {
                    LogResult::Applied                   => $summary['exported']++,
                    LogResult::Error, LogResult::Rejected => $summary['errors']++,
                    default                              => $summary['rediscovered']++, // idempotent/lock fail-open
                };
            }
        }

        // Kinds não-post (🟡5 do r7): menus clássicos e branding também entram
        // no seed "banco é autoridade" — sem isso ficam fora do repo até a
        // primeira edição.
        foreach (wp_get_nav_menus() as $menu) {
            $ref = \CVSync\Engine\EntityRef::of('nav_menu', $menu->slug);
            $this->exportOne($ref, $summary);
        }
        foreach ([get_stylesheet() . ':custom_logo', 'core:site_icon'] as $key) {
            $this->exportOne(\CVSync\Engine\EntityRef::of('branding', $key), $summary);
        }

        return $summary;
    }

    /** @param array<string, int> $summary */
    private function exportOne(\CVSync\Engine\EntityRef $ref, array &$summary): void
    {
        $result = $this->c->exporter->export($ref, 'cli');
        match ($result?->outcome) {
            LogResult::Applied  => $summary['exported']++,
            LogResult::Error    => $summary['errors']++,
            default             => $summary['rediscovered']++,
        };
    }

    private function hex(string $hash): string
    {
        return str_starts_with($hash, Hasher::PREFIX) ? substr($hash, strlen(Hasher::PREFIX)) : $hash;
    }
}
