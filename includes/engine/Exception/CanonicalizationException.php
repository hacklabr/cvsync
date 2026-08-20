<?php

declare(strict_types=1);

namespace CVSync\Engine\Exception;

/**
 * Thrown when a value cannot be represented in canonical form
 * (objects, resources, NaN/INF floats, invalid JSON, __PHP_Incomplete_Class).
 */
final class CanonicalizationException extends EngineException
{
}
