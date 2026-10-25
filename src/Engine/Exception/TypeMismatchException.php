<?php

declare(strict_types=1);

namespace Storyteller\Engine\Exception;

/**
 * An evaluation-time type violation: a literal that does not match a
 * variable's declared type, or an ordering/arithmetic operator applied to a
 * variable that is not numeric.
 *
 * The named constructors `value()` and `notNumeric()` take a
 * `Storyteller\Engine\State\VariableType` and therefore land together with
 * that enum in the variable-schema step; the class itself ships here so the
 * whole error family is introduced in one place.
 */
final class TypeMismatchException extends EngineException
{
}
