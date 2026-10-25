<?php

declare(strict_types=1);

namespace Storyteller\Engine\State;

/**
 * The four types a story variable may be declared as.
 *
 * `accepts()` is intentionally strict — `Float` does not accept an int and
 * `Int` does not accept a float — so neither the evaluator nor the applier can
 * silently promote a value into a variable that was not declared for it.
 */
enum VariableType: string
{
    case Int = 'int';
    case Float = 'float';
    case String = 'string';
    case Bool = 'bool';

    public function accepts(int|float|string|bool $value): bool
    {
        return match ($this) {
            self::Int => is_int($value),
            self::Float => is_float($value),
            self::String => is_string($value),
            self::Bool => is_bool($value),
        };
    }

    /**
     * Ordering comparisons and arithmetic are restricted to these two.
     */
    public function isNumeric(): bool
    {
        return $this === self::Int || $this === self::Float;
    }
}
