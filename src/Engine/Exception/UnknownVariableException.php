<?php

declare(strict_types=1);

namespace Storyteller\Engine\Exception;

/**
 * A variable name the schema does not declare.
 *
 * The schema is authoritative: a name absent from it is an error even when the
 * state happens to carry a value for it.
 */
final class UnknownVariableException extends EngineException
{
    public static function named(string $name): self
    {
        return new self(sprintf('Unknown variable "%s".', $name));
    }
}
