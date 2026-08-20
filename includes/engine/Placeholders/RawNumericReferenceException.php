<?php

declare(strict_types=1);

namespace CVSync\Engine\Placeholders;

use CVSync\Engine\Exception\EngineException;

/**
 * Anti-regression validation (spec §6.2): the file contains raw numeric
 * references ({"ref":<n>} etc.) — a legacy/handcrafted artifact. The Importer
 * rejects the entity with a clear error ("non-normalized numeric reference;
 * re-export from the origin"). No origin ID ever crosses the environment border.
 */
final class RawNumericReferenceException extends EngineException
{
    /**
     * @param list<string> $occurrences Human-readable occurrences ("ref:123 at offset N").
     */
    public function __construct(
        public readonly array $occurrences,
        string $message = '',
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : 'Raw numeric reference(s) found: ' . implode('; ', $occurrences),
        );
    }
}
