<?php

declare(strict_types=1);

namespace Storyteller\Engine\Ast;

/**
 * The thirteen condition operators.
 *
 * The backing values are the `op` strings an authored story uses, so this enum
 * is a published format rather than an implementation detail: renaming a case
 * value invalidates every story version compiled against it.
 */
enum ConditionOp: string
{
    case Eq = 'eq';
    case Neq = 'neq';
    case Lt = 'lt';
    case Lte = 'lte';
    case Gt = 'gt';
    case Gte = 'gte';
    case Flag = 'flag';
    case NotFlag = 'not_flag';
    case HasItem = 'has_item';
    case Visited = 'visited';
    case AndOp = 'and';
    case OrOp = 'or';
    case NotOp = 'not';

    /**
     * Compares a variable against a literal.
     *
     * m2: no production call site inside the Engine, which dispatches on node
     * class rather than on `op`. Kept for `src/Content`: a compiler reading
     * authored YAML has an `op` string and no node yet, and this is how it
     * decides whether a `var`/`value` pair is required before it builds one.
     * {@see self::isOrdering()}, its sibling, *is* used by the evaluator.
     */
    public function isComparison(): bool
    {
        return match ($this) {
            self::Eq, self::Neq, self::Lt, self::Lte, self::Gt, self::Gte => true,
            default => false,
        };
    }

    /**
     * Orders two values, which only numeric variables can do.
     */
    public function isOrdering(): bool
    {
        return match ($this) {
            self::Lt, self::Lte, self::Gt, self::Gte => true,
            default => false,
        };
    }
}
