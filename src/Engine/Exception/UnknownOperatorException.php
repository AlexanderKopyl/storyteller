<?php

declare(strict_types=1);

namespace Storyteller\Engine\Exception;

/**
 * An `op` string that is not one of the declared condition or effect
 * operators. Adding an operator means adding an enum case, not loosening this.
 */
final class UnknownOperatorException extends EngineException
{
    public static function condition(string $op): self
    {
        return new self(sprintf('Unknown condition operator "%s".', $op));
    }

    public static function effect(string $op): self
    {
        return new self(sprintf('Unknown effect operator "%s".', $op));
    }
}
