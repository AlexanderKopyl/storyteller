<?php

declare(strict_types=1);

namespace Storyteller\Engine\State;

/**
 * The four types a story variable may be declared as.
 *
 * `accepts()` is strict in one direction and deliberately asymmetric in the
 * other: **`Int` does not accept a float**, because narrowing loses
 * information and no author asked for it, but **`Float` does accept an int**,
 * because widening loses nothing — and because JSON gives us no choice.
 * `json_encode(1.0)` emits `1` and `json_decode('1')` hands back an `int`, so
 * a float variable holding an integral value arrives back from the
 * `story_version` snapshot or the `play_session` column as an int. Refusing it
 * would mean a schema default of `1.0`, a `{"op":"set","value":1.0}` node and
 * an `eq` against `1.0` all behaving differently before and after a save.
 * (PHP itself draws the line in the same place: under `strict_types=1` an
 * `int` argument still satisfies a `float` parameter.)
 *
 * Acceptance alone is not enough, which is what {@see self::canonicalise()}
 * is for: an accepted `int` is widened to a real `float` so that two
 * semantically equal values are also `===`. Every schema-aware read and write
 * in the Engine goes through it — see {@see VariableSchema::resolve()} and
 * {@see VariableSchema::canonicalise()}.
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
            self::Float => is_float($value) || is_int($value),
            self::String => is_string($value),
            self::Bool => is_bool($value),
        };
    }

    /**
     * The single normalisation point for a value entering or leaving a
     * declared variable.
     *
     * Only one case does anything: an `int` under a `Float` declaration
     * becomes a `float`. Nothing is ever narrowed — a float stays a float even
     * for an `Int` declaration, so {@see self::accepts()} can still refuse it
     * — and no string or bool is ever coerced.
     */
    public function canonicalise(int|float|string|bool $value): int|float|string|bool
    {
        if ($this === self::Float && is_int($value)) {
            return (float) $value;
        }

        return $value;
    }

    /**
     * Ordering comparisons and arithmetic are restricted to these two.
     */
    public function isNumeric(): bool
    {
        return $this === self::Int || $this === self::Float;
    }
}
