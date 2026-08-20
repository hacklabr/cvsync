<?php

declare(strict_types=1);

namespace CVSync\Engine\Frontmatter;

use CVSync\Engine\Exception\EngineException;

/**
 * Malformed document, invalid YAML, rejected tag ('!'), duplicate key,
 * non-pure data (object), CRLF in frontmatter, or unsupported construct.
 */
final class FrontmatterException extends EngineException
{
}
