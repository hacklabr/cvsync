<?php
/**
 * Value objects de resultado do fluxo de sync (P3).
 *
 * O outcome usa o enum LogResult do P2 — vocabulário único com o audit log
 * (R9, r1). Export fail-open por lock NÃO tem outcome: Exporter::export()
 * retorna ?ExportResult e null significa "lock não adquirida, entidade
 * permanece dirty_db" (r1-t2: não é evento de sync, não gera log).
 *
 * @package CVSync
 */

declare(strict_types=1);

namespace CVSync;

use CVSync\Engine\Placeholders\PlaceholderToken;
use CVSync\Storage\LogResult;

defined('ABSPATH') || exit;

/** Resultado do export de UMA entidade (banco → arquivo, §2.3). */
final readonly class ExportResult
{
    public function __construct(
        public LogResult $outcome,
        public ?string $filePath = null, // relativo a content/
        public ?string $hash = null,
        public ?string $error = null,
    ) {
    }
}

/** Resultado da aplicação de UMA entidade no banco, produzido pelo adapter. */
final readonly class ApplyResult
{
    /**
     * @param list<PlaceholderToken> $unresolvedStructural   §6.2: bloqueia o write.
     * @param list<PlaceholderToken> $unresolvedNonStructural §6.2: write com literal + pending_ref.
     * @param list<string>           $pendingMeta            Meta keys puladas por placeholder não resolvido.
     * @param list<string>           $pendingTermRefs        Apêndice B.6.3: termos versionados ausentes,
     *        qualificados '{taxonomy}:{slug}' — omitidos do wp_set_object_terms, pendência não-estrutural.
     */
    public function __construct(
        public ?int $dbId,
        public array $unresolvedStructural = [],
        public array $unresolvedNonStructural = [],
        public array $pendingMeta = [],
        public array $pendingTermRefs = [],
        public ?string $error = null,
    ) {
    }

    public function hasStructuralBlockers(): bool
    {
        return $this->unresolvedStructural !== [];
    }

    public function hasPendencies(): bool
    {
        return $this->unresolvedStructural !== []
            || $this->unresolvedNonStructural !== []
            || $this->pendingMeta !== []
            || $this->pendingTermRefs !== [];
    }

    /** Slugs pendentes (refs[] plano — contrato do StateStore::pendingRefs). */
    public function pendingSlugs(): array
    {
        $slugs = [];
        foreach ([...$this->unresolvedStructural, ...$this->unresolvedNonStructural] as $token) {
            $slug = $token->subject();
            if ($slug !== null) {
                $slugs[] = $slug;
            }
        }
        foreach ($this->pendingMeta as $slug) {
            $slugs[] = $slug;
        }

        return array_values(array_unique($slugs));
    }
}

/** Resultado do import de UMA entidade (arquivo → banco, §2.4). */
final readonly class ImportResult
{
    /**
     * @param list<string> $pendingRefs Slugs pendentes (alimentam pending_payload).
     */
    public function __construct(
        public LogResult $outcome,
        public ?int $dbId = null,
        public ?string $hash = null,
        public array $pendingRefs = [],
        public ?string $error = null,
    ) {
    }
}
