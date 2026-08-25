<?php
/**
 * Importer — arquivo → banco (spec §2.4, §10.2, §8.4, §6.2).
 *
 * Fluxo por entidade (P5 adquire a batch lock e calcula o plano; este fluxo
 * é chamado por arquivo, na ordem de estágios §A.5.7 do AdapterRegistry):
 *
 *  1. Adapter pelo path; parse + schema do frontmatter (Rejected → lote
 *     continua, entidade logada);
 *  2. Validação pré-insert independente de KSES (§10.2): round-trip de
 *     blocos em ponto-fixo + ANTI-REGRESSÃO §6.2 (assertNoRawNumericRefs com
 *     a lista filtrada 'cvsync/placeholder_attributes') — um {"ref":123} cru
 *     rejeita a entidade com erro claro; nenhum ID de origem entra no banco;
 *  3. Editor lock (§8.4): wp_check_post_lock() ativo → 'skipped-locked'
 *     (a menos de --force-locks interativo) — retry natural no próximo
 *     checkpoint (a entidade permanece divergente no state);
 *  4. Aplicação: ImportGuard::run() EXTERNO + StateStore::withLockedRow()
 *     INTERNO (r1-t2) → adapter->apply() via APIs públicas (revisions sempre,
 *     §10.3 — nunca supressão de hooks);
 *  5. Refs estruturais não resolvidas → NADA foi gravado pelo adapter:
 *     pending_ref + pending_payload {"refs":[slugs]} e reprocessamento
 *     automático após cada import bem-sucedido da referência (§6.2/§A.5.7);
 *  6. Sucesso → recordSync (invariante §5.4); pendências não-estruturais
 *     mantêm status pending_ref sobre os hashes gravados;
 *  7. Parent-fixup idempotente ao final do lote (compara antes de escrever).
 *
 * Sequestro de UUID (§6.3): UuidOwnershipMismatchException → status conflict,
 * NUNCA apply.
 *
 * @package CVSync
 */

declare(strict_types=1);

namespace CVSync;

use CVSync\Adapters\AdapterRegistry;
use CVSync\Adapters\EntityAdapter;
use CVSync\Adapters\RejectedEntityException;
use CVSync\Adapters\UuidOwnershipMismatchException;
use CVSync\Engine\CanonicalDocument;
use CVSync\Engine\Hasher;
use CVSync\Engine\Placeholders\PlaceholderCodec;
use CVSync\Storage\AuditLog;
use CVSync\Storage\EntityStatus;
use CVSync\Storage\LogEntry;
use CVSync\Storage\LogResult;
use CVSync\Storage\StateStore;
use CVSync\Storage\SyncDirection;

defined('ABSPATH') || exit;

final class Importer
{
    /** Pais pendentes de resolução no lote: list<array{0:string,1:string,2:string,3:string}> (searchType — 'any' p/ attachments, uuid, parentSlug, entityType). */
    private array $pendingParents = [];

    /** Apêndice B.6.4: list<array{0:string,1:string,2:string}> (taxonomy, entity_key, parentSlug). */
    private array $pendingTermParents = [];

    public function __construct(
        private readonly AdapterRegistry $adapters,
        private readonly StateStore $state,
        private readonly ImportGuard $guard,
        private readonly PathGuard $paths,
        private readonly AuditLog $log,
        private readonly \CVSync\Adapters\ReferenceResolver $resolver,
    ) {
    }

    /**
     * Aplica UMA entidade a partir do seu arquivo no repo.
     * Pré-condições do caller (P5): batch lock presa, migrations ok (§5.9),
     * plano calculado pelo engine.
     */
    public function importFile(string $relativePath, ImportContext $ctx): ImportResult
    {
        $adapter = $this->adapters->adapterForPath($relativePath);
        if ($adapter === null) {
            return new ImportResult(LogResult::Rejected, null, null, [], 'Sem adapter para o path: ' . $relativePath);
        }

        $bytes = $this->paths->read($relativePath);
        if ($bytes === null) {
            return new ImportResult(LogResult::Error, null, null, [], 'Arquivo ilegível: ' . $relativePath);
        }

        try {
            $doc = $adapter->parseDocument($bytes);
            $adapter->validateFrontmatter($doc->frontmatter);
            $this->validateBody($adapter, $doc);
        } catch (RejectedEntityException $e) {
            return new ImportResult(LogResult::Rejected, null, null, [], $e->getMessage());
        } catch (\Throwable $e) {
            return new ImportResult(LogResult::Rejected, null, null, [], 'Documento inválido: ' . $e->getMessage());
        }

        // Editor lock (§8.4) — antes de qualquer write.
        $lockedBy = $this->editorLockedBy($doc);
        if ($lockedBy !== null && !$ctx->forceLocks) {
            $this->appendLog($doc, $ctx, LogResult::SkippedLocked, 'Editor lock ativo (user ' . $lockedBy . ')');

            return new ImportResult(LogResult::SkippedLocked, null, null, [], 'wp_check_post_lock ativo');
        }

        if ($ctx->dryRun) {
            return new ImportResult(LogResult::Applied, null, Hasher::hashDocument($doc, $adapter->keyOrder()), [], 'dry-run');
        }

        try {
            return $this->applyDocument($adapter, $doc, $ctx);
        } catch (RejectedEntityException $e) {
            // Rejeição de domínio no apply (ex.: cadeia §A.5.1 violada, tema
            // divergente) — rejeição, NÃO falha de operação (§6.2; r6 item 2).
            $this->appendLog($doc, $ctx, LogResult::Rejected, $e->getMessage());

            return new ImportResult(LogResult::Rejected, null, null, [], $e->getMessage());
        } catch (UuidOwnershipMismatchException $e) {
            // §6.3: conflito de posse — NUNCA apply.
            try {
                $this->state->setStatus($doc->ref, EntityStatus::Conflict);
            } catch (\Throwable) {
                // state indisponível não mascara o conflito
            }
            $this->appendLog($doc, $ctx, LogResult::Error, 'uuid-ownership-mismatch: ' . $e->getMessage());

            return new ImportResult(LogResult::Error, null, null, [], $e->getMessage());
        } catch (\Throwable $e) {
            $this->appendLog($doc, $ctx, LogResult::Error, $e->getMessage());

            return new ImportResult(LogResult::Error, null, null, [], $e->getMessage());
        }
    }

    /**
     * Reprocessamento de pending_ref (§6.2/§A.5.7): após import bem-sucedido
     * de uma referência, re-tenta as entidades cuja pendência menciona o slug.
     *
     * @return int Quantas convergiram (applied sem pendências).
     */
    public function reprocessPendingRefs(string $slug, ImportContext $ctx): int
    {
        $this->resolver->flushCaches(); // 🟡8: o slug recém-importado pode estar null-cacheado

        $converged = 0;
        // Matching do P2 (B.5): pendingMentions() casa o argumento exato contra
        // refs[] E term_refs[] (qualificado '{taxonomy}:{slug}'), e slug puro
        // também como sufixo ':{slug}' das qualificadas. Aceitamos os DOIS
        // formatos aqui (slug do post/attachment e forma qualificada de termo).
        foreach ($this->state->findPendingReferencing($slug) as $record) {
            $adapter = $this->adapters->forRef($record->ref);
            if ($adapter === null) {
                continue;
            }
            $path = $adapter->locateFile($record->ref);
            if ($path === null) {
                continue;
            }
            $result = $this->importFile($path, $ctx);
            if ($result->outcome === LogResult::Applied && $result->pendingRefs === []) {
                $converged++;
            }
        }

        return $converged;
    }

    /**
     * Parent-fixup ao final do lote (idempotente por cláusula — §A.5.2.8 e
     * B.6.4 para terms): compara o parent atual com o resolvido antes de qualquer write.
     *
     * @return int Parents corrigidos.
     */
    public function fixupParents(ImportContext $ctx): int
    {
        if ($this->pendingParents === [] && $this->pendingTermParents === []) {
            return 0;
        }

        // 🟡8: pais importados neste mesmo lote podem estar null-cacheados no resolver.
        $this->resolver->flushCaches();

        $fixed = 0;
        $queue = $this->pendingParents;
        $this->pendingParents = [];
        $termQueue = $this->pendingTermParents;
        $this->pendingTermParents = [];

        $this->guard->run(function () use ($queue, $termQueue, &$fixed): void {
            foreach ($queue as [$searchType, $uuid, $parentSlug, $entityType]) {
                $parentId = $this->resolver->postIdForSlug($searchType, $parentSlug);
                if ($parentId === null) {
                    continue; // permanece para o próximo lote
                }
                $record = $this->state->get(\CVSync\Engine\EntityRef::post($entityType, $uuid));
                if ($record === null || $record->dbId === null) {
                    continue;
                }
                $post = get_post($record->dbId);
                if (!$post instanceof \WP_Post || (int) $post->post_parent === $parentId) {
                    continue; // idempotente: igual → zero write
                }
                wp_update_post(wp_slash(['ID' => $post->ID, 'post_parent' => $parentId]), true);
                $fixed++;
            }

            // Apêndice B.6.4 — parent de TERMOS: resolve por (taxonomy, slug),
            // compara term->parent antes de escrever (idempotente).
            foreach ($termQueue as [$taxonomy, $entityKey, $parentSlug]) {
                $parent = get_term_by('slug', $parentSlug, $taxonomy);
                if (!$parent instanceof \WP_Term) {
                    continue; // pai ainda ausente — próximo lote
                }
                $record = $this->state->get(\CVSync\Engine\EntityRef::of('term', $entityKey));
                if ($record === null || $record->dbId === null) {
                    continue;
                }
                $term = get_term_by('term_taxonomy_id', $record->dbId, $taxonomy);
                if (!$term instanceof \WP_Term || (int) $term->parent === (int) $parent->term_id) {
                    continue; // idempotente: igual → zero write
                }
                wp_update_term((int) $term->term_id, $taxonomy, ['parent' => (int) $parent->term_id]);
                $fixed++;
            }
        });

        return $fixed;
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private function applyDocument(EntityAdapter $adapter, CanonicalDocument $doc, ImportContext $ctx): ImportResult
    {
        $hash = Hasher::hashDocument($doc, $adapter->keyOrder());
        $relative = $adapter->relativePath($doc);

        // Registra parent pendente para o fixup de fim de lote. Attachments
        // têm pai de QUALQUER tipo versionado (§A.5.2.8) — busca cross-type.
        $parentSlug = $doc->frontmatter['parent'] ?? null;
        if (is_string($parentSlug) && $parentSlug !== '' && $adapter->postType() !== null) {
            $searchType = $adapter->postType() === 'attachment' ? 'any' : $adapter->postType();
            $this->pendingParents[] = [$searchType, $doc->uuid(), $parentSlug, $adapter->postType()];
        }

        // Apêndice B.6.4 — parent de termo para o fixup de fim de lote.
        if ($adapter instanceof \CVSync\Adapters\TermAdapter
            && is_string($parentSlug) && $parentSlug !== ''
        ) {
            $this->pendingTermParents[] = [(string) ($doc->frontmatter['taxonomy'] ?? ''), $doc->ref->key, $parentSlug];
        }

        /**
         * Guard EXTERNO × tx INTERNA (r1-t2) — e a state table comita na MESMA
         * transação do conteúdo (§2.2.4/§5.9; r8, 🟡7): apply + upsert(db_id) +
         * recordSync + pendências acontecem dentro do callback do withLockedRow.
         * Falha em qualquer ponto → ROLLBACK de conteúdo E state juntos.
         *
         * @var ApplyResult $apply
         */
        $apply = $this->guard->run(
            fn(): ApplyResult => $this->state->withLockedRow(
                $doc->ref,
                function (?object $locked) use ($adapter, $doc, $ctx, $hash, $relative): ApplyResult {
                    $apply = $adapter->apply($doc, $ctx);

                    if ($apply->hasStructuralBlockers()) {
                        // §6.2 estrutural: nada foi gravado — pending_ref + payload.
                        $this->state->setPendingPayload($doc->ref, self::pendingPayloadOf($apply));
                        $this->state->setStatus($doc->ref, EntityStatus::PendingRef);

                        return $apply;
                    }

                    if ($apply->dbId !== null) {
                        $this->state->upsert($doc->ref, ['db_id' => $apply->dbId]);
                    }

                    $this->state->recordSync(
                        $doc->ref,
                        SyncDirection::FileToDb,
                        self::hashHex($hash),
                        null,
                        $this->paths->mtime($relative)
                    );

                    if ($apply->hasPendencies()) {
                        // Importado com pendências (placeholder literal inerte §6.2;
                        // term_refs[] qualificadas B.6.3) — pending_ref.
                        $this->state->setPendingPayload($doc->ref, self::pendingPayloadOf($apply));
                        $this->state->setStatus($doc->ref, EntityStatus::PendingRef);
                    }

                    return $apply;
                }
            )
        );

        if ($apply->hasStructuralBlockers()) {
            $slugs = $apply->pendingSlugs();
            $this->appendLog($doc, $ctx, LogResult::PendingRef, 'refs estruturais pendentes: ' . implode(', ', $slugs));

            return new ImportResult(LogResult::PendingRef, null, $hash, $slugs);
        }

        $pendencies = $apply->pendingSlugs();

        $this->appendLog($doc, $ctx, LogResult::Applied, null, $apply->dbId, $hash);

        // §A.5.7: a referência recém-importada pode destravar pendências de outros.
        if ($doc->slug() !== '') {
            $this->reprocessPendingRefs($doc->slug(), $ctx);
        }

        // Apêndice B.6.3: termo recém-aplicado destrava posts pendentes por
        // term_refs[] — reprocessa também pela forma QUALIFICADA.
        if ($doc->ref->kind === 'term') {
            $taxonomy = (string) ($doc->frontmatter['taxonomy'] ?? '');
            if ($taxonomy !== '' && $doc->slug() !== '') {
                $this->reprocessPendingRefs($taxonomy . ':' . $doc->slug(), $ctx);
            }
        }

        return new ImportResult(LogResult::Applied, $apply->dbId, $hash, [...$pendencies, ...$apply->pendingTermRefs]);
    }

    /**
     * Payload normativo de pendências (§6.2 + B.5/B.6.3): chaves só quando
     * não-vazias — refs[] (BC, slugs planos) e term_refs[] (qualificadas).
     *
     * @param ApplyResult $apply
     * @return array<string, list<string>>
     */
    private static function pendingPayloadOf(ApplyResult $apply): array
    {
        $payload = [];
        if ($apply->pendingSlugs() !== []) {
            $payload['refs'] = $apply->pendingSlugs();
        }
        if ($apply->pendingTermRefs !== []) {
            $payload['term_refs'] = $apply->pendingTermRefs;
        }

        return $payload;
    }

    /**
     * Validação pré-insert independente de KSES (§10.2): round-trip de blocos
     * em ponto-fixo + anti-regressão §6.2.
     *
     * Nota de implementação: a spec pede serialize_blocks(parse_blocks($c)) === $c
     * "na forma canônica". A serialização do core NÃO é byte-estável em geral
     * (§4.2.1 já o antecipa); o teste implementado é o ponto-fixo
     * S(S(x)) == S(x) — idempotência do serializador sobre o próprio output.
     *
     * @throws RejectedEntityException
     */
    private function validateBody(EntityAdapter $adapter, CanonicalDocument $doc): void
    {
        if (!$adapter->hasBlockBody()) {
            return;
        }

        PlaceholderCodec::assertNoRawNumericRefs($doc->body, $this->rawIdAttributes());

        $once = serialize_blocks(parse_blocks($doc->body));
        $twice = serialize_blocks(parse_blocks($once));
        if ($once !== $twice) {
            throw new RejectedEntityException('Round-trip de blocos instável (serialize∘parse não é ponto-fixo).');
        }
        if (trim($doc->body) !== '' && str_contains($doc->body, '<!-- wp:') && trim($once) === '') {
            throw new RejectedEntityException('Markup de blocos não parseável.');
        }
    }

    /** Editor lock §8.4: user ID que detém o lock, ou null. */
    private function editorLockedBy(CanonicalDocument $doc): ?int
    {
        if ($doc->ref->kind !== 'post') {
            return null;
        }
        if (!function_exists('wp_check_post_lock')) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }

        $record = $this->state->get($doc->ref);
        if ($record === null || $record->dbId === null) {
            return null;
        }

        $lockedBy = wp_check_post_lock($record->dbId);

        return $lockedBy !== false ? (int) $lockedBy : null;
    }

    /** @return list<string> */
    private function rawIdAttributes(): array
    {
        return $this->resolver->rawIdAttributes();
    }

    private function appendLog(
        CanonicalDocument $doc,
        ImportContext $ctx,
        LogResult $result,
        ?string $error,
        ?int $dbId = null,
        ?string $hash = null
    ): void {
        try {
            $this->log->append(new LogEntry(
                null,
                $doc->ref,
                $doc->ref->postType ?? '',
                SyncDirection::FileToDb,
                $ctx->trigger,
                $this->guard->technicalActor(),
                null,
                null,
                $hash !== null ? self::hashHex($hash) : null,
                null,
                $result,
                $error,
                null,
                new \DateTimeImmutable('now', wp_timezone())
            ));
        } catch (\Throwable) {
            // Audit log nunca derruba o apply.
        }
    }

    /** State table guarda o HEX (CHAR(64)); o prefixo 'sha256:' é da forma de arquivo. */
    private static function hashHex(string $hash): string
    {
        return str_starts_with($hash, Hasher::PREFIX) ? substr($hash, strlen(Hasher::PREFIX)) : $hash;
    }
}
