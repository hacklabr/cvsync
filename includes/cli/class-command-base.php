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

    // ------------------------------------------------------------------
    // Termos de taxonomia (Apêndice B — B.1.1/B.2.1/B.7.1)
    // ------------------------------------------------------------------

    /**
     * Gate de migration pendente (§5.9) com saída de destaque (fix ibiomas):
     * a recusa é a PRIMEIRA linha da saída, como ERRO (não warning), com a
     * ação prescritiva — nunca precedida de resumo "Applied 0 … failed 1",
     * que o dev lê como "apply não faz nada". Exit code dedicado 3.
     *
     * Chamado na primeira linha de apply/plan/export/bootstrap (comandos que
     * o gate bloqueia). O gate interno do ApplyRunner permanece como
     * defense-in-depth para o caminho passivo (sem WP_CLI).
     */
    protected function refuseIfMigrationPending(): void
    {
        if (! \CVSync\Storage\Schema::needsMigration()) {
            return;
        }

        \WP_CLI::error(sprintf(
            "cvsync: operação RECUSADA — migration de schema pendente (fail-closed §5.9): schema v%d instalado, v%d requerido (Apêndice B).\n" .
            "Ação: reative o plugin (wp plugin deactivate cvsync && wp plugin activate cvsync) ou rode a migration no pipeline.",
            \CVSync\Storage\Schema::installedVersion(),
            \CVSync\Storage\Schema::SCHEMA_VERSION
        ), 3);
    }

    /**
     * Taxonomias versionadas: filtro `cvsync/taxonomies` (default vazio, B.1.1).
     * Consome `AdapterRegistry::versionedTaxonomies()` quando o CMS o expuser;
     * senão lê o filtro diretamente (a config é do ambiente, não de classe).
     *
     * @return list<string>
     */
    protected function versionedTaxonomies(): array
    {
        // Fonte única: o registry (B.1.1 — mesmo filtro cvsync/taxonomies; o
        // fallback de leitura direta foi removido na integração do Apêndice B).
        return $this->c->adapters->versionedTaxonomies();
    }

    /**
     * Enumera os termos de uma taxonomia versionada como entidades
     * kind='term', entity_key='{taxonomy}:{slug}' (B.2.1). ensureUuid recebe
     * o term_taxonomy_id (db_id=tt_id — term_id nunca é persistido).
     *
     * @return list<array{0: EntityRef, 1: \CVSync\Adapters\EntityAdapter|null}>
     */
    protected function enumerateVersionedTerms(string $taxonomy): array
    {
        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => 0,
            'fields'     => 'all',
        ]);

        if (is_wp_error($terms)) {
            \WP_CLI::warning(sprintf('taxonomia %s ilegível: %s', $taxonomy, $terms->get_error_message()));

            return [];
        }

        $found = [];
        foreach ($terms as $term) {
            $ref     = EntityRef::of('term', $taxonomy . ':' . $term->slug);
            $adapter = $this->c->adapters->forRef($ref);
            if (null !== $adapter) {
                $adapter->ensureUuid((int) $term->term_taxonomy_id); // termmeta _cvsync_uuid (B.2.3)
            }
            $found[] = [$ref, $adapter];
        }

        return $found;
    }

    /**
     * Exporta UM termo (B.7.1): o Exporter GENÉRICO é o fluxo canônico — termo
     * é YAML-integral SEM blob (B.3/B.4; o risco R1 de sidecar-sem-binário não
     * existe nesta entidade), com lock fail-open, idempotência e FS read-only
     * nativos. O probe `exportTerm()` permanece como ponto de extensão caso
     * um fluxo dedicado surgja (padrão exportAttachment) — hoje é no-op.
     *
     * @return \CVSync\Storage\LogResult|\CVSync\ExportResult|null LogResult do
     *         fluxo dedicado (?LogResult), ExportResult do genérico, ou null.
     */
    protected function exportTermOnce(EntityRef $ref, ?\CVSync\Adapters\EntityAdapter $adapter): \CVSync\Storage\LogResult|\CVSync\ExportResult|null
    {
        if (null !== $adapter && method_exists($adapter, 'exportTerm')) {
            return $adapter->exportTerm($ref, 'cli');
        }

        return $this->c->exporter->export($ref, 'cli');
    }

    /** Normaliza exportTermOnce() para o LogResult (ou null = lock fail-open). */
    protected function termOutcome(\CVSync\Storage\LogResult|\CVSync\ExportResult|null $result): ?\CVSync\Storage\LogResult
    {
        if ($result instanceof \CVSync\Storage\LogResult) {
            return $result;
        }

        return $result?->outcome;
    }
}
