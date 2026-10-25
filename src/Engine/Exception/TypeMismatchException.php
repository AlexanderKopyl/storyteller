<?php

declare(strict_types=1);

namespace Storyteller\Engine\Exception;

use Storyteller\Engine\State\VariableType;

/**
 * An evaluation-time type violation: a literal that does not match a
 * variable's declared type, or an ordering/arithmetic operator applied to a
 * variable that is not numeric.
 *
 * Structural problems in an authored node are a parse-time concern and raise
 * {@see MalformedNodeException} instead; this one needs the schema in hand,
 * which the parser does not have.
 */
final class TypeMismatchException extends EngineException
{
    public static function value(string $variable, VariableType $expected, string $actual): self
    {
        return new self(sprintf(
            'Variable "%s" is declared as %s, but the value is of type %s.',
            $variable,
            $expected->value,
            $actual,
        ));
    }

    public static function notNumeric(string $variable, VariableType $actual, string $op): self
    {
        return new self(sprintf(
            'Operator "%s" needs a numeric variable, but "%s" is declared as %s.',
            $op,
            $variable,
            $actual->value,
        ));
    }
}
