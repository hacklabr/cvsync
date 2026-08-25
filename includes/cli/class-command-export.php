<?php
/**
 * `wp sync export` — export bulk/inicial/recuperação (contrato §8.3, §A.5.5).
 *
 * Flags: --post-type=<type>, --scope=referenced|all (attachments; default
 * referenced — §A.5.5), --batch=50 (chunking retomável por idempotência),
 * --out=<dir> (destino alternativo — ex.: captura de mídia de prod em dir
 * temporário, com credenciais do operador), --check (CI: falha se gerar diff,
 * §12.3 idempotência), --format=json.
 *
 * Export CLI em prod é LIVRE (read-only no banco; §7.3) — é a porta de
 * entrada para capturar conteúdo do cliente de volta ao repo.
 *
 * Exit: 0 sucesso; 1 erro ou (--check) diff gerado.
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

use CVSync\Engine\EntityRef;
use CVSync\Exporter;
use CVSync\Media\AttachmentAdapter;
use CVSync\PathGuard;
use CVSync\Storage\LogResult;

defined('ABSPATH') || exit;

final class CommandExport extends CommandBase
{
    public function __invoke(array $args, array $assocArgs): void
    {
        $this->warnInvalidConstants();

        $postType = isset($assocArgs['post-type']) ? (string) $assocArgs['post-type'] : null;
        $taxonomy = isset($assocArgs['taxonomy']) ? (string) $assocArgs['taxonomy'] : null;
        $scope    = (string) ($assocArgs['scope'] ?? 'referenced');
        $batch    = max(1, (int) ($assocArgs['batch'] ?? 50));
        $out      = isset($assocArgs['out']) ? (string) $assocArgs['out'] : null;
        $check    = (bool) ($assocArgs['check'] ?? false);
        $json     = $this->isJson($assocArgs);

        if (! in_array($scope, ['referenced', 'all'], true)) {
            \WP_CLI::error('--scope deve ser referenced|all.');
        }
        if (null !== $taxonomy && null !== $postType) {
            \WP_CLI::error('--taxonomy e --post-type são mutuamente exclusivos.');
        }
        $versionedTaxonomies = $this->versionedTaxonomies();
        if (null !== $taxonomy && ! in_array($taxonomy, $versionedTaxonomies, true)) {
            \WP_CLI::error(sprintf(
                'Taxonomia "%s" não é versionada (filtro cvsync/taxonomies, Apêndice B.1.1). Versionadas: %s',
                $taxonomy,
                [] === $versionedTaxonomies ? '(nenhuma)' : implode(', ', $versionedTaxonomies)
            ));
        }

        $exporter = $this->c->exporter;
        $outPaths = null;
        if (null !== $out) {
            // Destino alternativo (§A.5.5 — captura prod → dir temporário).
            $outPaths = new PathGuard($out);
            $exporter = new Exporter($this->c->adapters, $this->c->state, $this->c->locks, $outPaths, $this->c->log);
        }

        $summary = ['exported' => 0, 'skipped' => 0, 'oversized' => 0, 'errors' => 0, 'written' => 0];

        foreach ($this->targetPostTypes($postType) as $type) {
            if ('attachment' === $type) {
                $this->exportAttachments($scope, $batch, $summary, $json, $outPaths);
                continue;
            }
            $this->exportPostType($type, $batch, $exporter, $summary, $json);
        }

        if (null === $postType || in_array($postType, ['nav_menu', 'wp_global_styles', 'branding'], true)) {
            $this->exportNonPostEntities($batch, $exporter, $summary, $json);
        }

        // Termos de taxonomia (Apêndice B.7.1): --taxonomy=<tax> exporta UMA;
        // sem --post-type nem --taxonomy, todas as versionadas.
        $taxonomiesToExport = null !== $taxonomy ? [$taxonomy]
            : (null === $postType ? $versionedTaxonomies : []);
        foreach ($taxonomiesToExport as $taxonomyName) {
            $this->exportTaxonomyTerms($taxonomyName, $batch, $summary, $json);
        }

        if ($json) {
            $this->jsonLine($summary);
        } else {
            \WP_CLI::log(sprintf(
                'Export: %d exportados, %d idempotentes, %d oversized, %d erros.',
                $summary['exported'],
                $summary['skipped'],
                $summary['oversized'],
                $summary['errors']
            ));
        }

        if ($summary['errors'] > 0) {
            \WP_CLI::halt(1);
        }
        // --check (§12.3): qualquer escrita = diff = falha de idempotência.
        if ($check && $summary['written'] > 0) {
            \WP_CLI::error(sprintf('--check: o export gerou %d escrita(s) — o repo está fora de sync com o banco.', $summary['written']));
        }
        \WP_CLI::halt(0);
    }

    // ------------------------------------------------------------------

    /** @return list<string> */
    private function targetPostTypes(?string $only): array
    {
        $types = $this->c->adapters->versionedPostTypes();
        if (null === $only) {
            return $types;
        }
        if ('attachment' === $only) {
            return ['attachment'];
        }

        return in_array($only, $types, true) ? [$only] : [];
    }

    /**
     * Export bulk de termos de UMA taxonomia versionada (B.7.1): mirror do
     * --post-type — bulk com --batch, state incremental (idempotência por
     * hash; interrompível e retomável).
     *
     * @param array<string, int> $summary
     */
    private function exportTaxonomyTerms(string $taxonomy, int $batch, array &$summary, bool $json): void
    {
        $entities = $this->enumerateVersionedTerms($taxonomy);

        foreach (array_chunk($entities, $batch) as $chunk) {
            foreach ($chunk as [$ref, $adapter]) {
                $outcome = $this->termOutcome($this->exportTermOnce($ref, $adapter));
                $this->tally($summary, $outcome, $json, $ref);
            }
        }
    }

    private function exportPostType(string $postType, int $batch, Exporter $exporter, array &$summary, bool $json): void
    {
        $adapter = $this->c->adapters->forPostType($postType);
        if (null === $adapter) {
            return;
        }

        $page = 1;
        do {
            $ids = get_posts([
                'post_type'      => $postType,
                'post_status'    => $adapter->statuses(),
                'posts_per_page' => $batch,
                'paged'          => $page,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);
            foreach ($ids as $id) {
                $ref    = EntityRef::post($postType, $adapter->ensureUuid((int) $id));
                $result = $exporter->export($ref, 'cli');
                $this->tally($summary, $result?->outcome, $json, $ref);
            }
            $page++;
        } while (count($ids) === $batch);
    }

    /**
     * Export de anexos (§A.5.5): escopo referenced (default) × all.
     *
     * Com --out (🟡10 do r7): o adapter é instanciado com o PathGuard/MediaStore
     * do diretório de destino — sidecar e blob CAS vão para <out>/media/, sem
     * tocar o content dir real (runbook de captura prod → dir temporário).
     */
    private function exportAttachments(string $scope, int $batch, array &$summary, bool $json, ?PathGuard $outPaths = null): void
    {
        $adapter = $this->c->adapters->forPostType('attachment');
        if (! $adapter instanceof AttachmentAdapter || null === $this->c->referenceGraph) {
            \WP_CLI::warning('Pacote de mídia (P4) indisponível — attachments pulados.');

            return;
        }

        if (null !== $outPaths) {
            $adapter = new AttachmentAdapter(
                $this->c->state,
                $this->c->resolver,
                $outPaths,
                new \CVSync\Media\MediaStore($outPaths),
                $this->c->materializer ?? throw new \LogicException('Materializer indisponível com P4 presente.'),
                $this->c->log,
                $this->c->locks // R3 da r9: lock por entidade fail-open (§5.8)
            );
        }

        if ('all' === $scope) {
            \WP_CLI::warning('--scope=all exporta a biblioteca completa (backup-git da biblioteca é escopo de projeto, §A.5.5).');
            $ids = get_posts([
                'post_type'      => 'attachment',
                'post_status'    => ['inherit'],
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);
        } else {
            $ids = $this->c->referenceGraph->referencedAttachmentIds();
        }

        foreach (array_chunk(array_map('intval', $ids), $batch) as $chunk) {
            foreach ($chunk as $id) {
                $ref     = EntityRef::post('attachment', $adapter->ensureUuid($id));
                $outcome = $adapter->exportAttachment($ref, 'cli');
                $this->tally($summary, $outcome, $json, $ref);
            }
        }
    }

    /** Menus, global styles e branding (kinds não-post). */
    private function exportNonPostEntities(int $batch, Exporter $exporter, array &$summary, bool $json): void
    {
        foreach (wp_get_nav_menus() as $menu) {
            $ref    = EntityRef::of('nav_menu', $menu->slug);
            $result = $exporter->export($ref, 'cli');
            $this->tally($summary, $result?->outcome, $json, $ref);
        }

        $stylesheet = get_stylesheet();
        foreach ([EntityRef::of('branding', $stylesheet . ':custom_logo'), EntityRef::of('branding', 'core:site_icon')] as $ref) {
            $result = $exporter->export($ref, 'cli');
            $this->tally($summary, $result?->outcome, $json, $ref);
        }

        // Global styles do tema ativo: a entidade existe quando há post
        // wp_global_styles para o stylesheet.
        $globalStyles = get_posts([
            'post_type'      => 'wp_global_styles',
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        $gsAdapter = $this->c->adapters->forPostType('wp_global_styles');
        foreach ($globalStyles as $id) {
            if (null === $gsAdapter) {
                break;
            }
            $ref    = EntityRef::post('wp_global_styles', $gsAdapter->ensureUuid((int) $id));
            $result = $exporter->export($ref, 'cli');
            $this->tally($summary, $result?->outcome, $json, $ref);
        }
    }

    private function tally(array &$summary, ?LogResult $outcome, bool $json, EntityRef $ref): void
    {
        if (null === $outcome) {
            $summary['skipped']++; // lock fail-open (§5.8)

            return;
        }

        match ($outcome) {
            LogResult::Applied                          => $summary['exported']++,
            LogResult::SkippedOversized                 => $summary['oversized']++,
            LogResult::Error, LogResult::Rejected       => $summary['errors']++,
            default                                     => $summary['skipped']++,
        };

        if (LogResult::Applied === $outcome) {
            $summary['written']++;
        }

        if ($json) {
            $this->jsonLine(['entity' => $ref->toTupleString(), 'result' => $outcome->value]);
        }
    }
}
