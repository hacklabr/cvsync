<?php

declare(strict_types=1);

namespace CVSync\Engine\Exception;

/**
 * Base exception for all CVSync engine errors.
 *
 * The engine is pure PHP (no WordPress, no I/O). Every failure is loud
 * and typed — callers (P3/P4/P5) catch EngineException subtypes.
 */
class EngineException extends \RuntimeException
{
}
