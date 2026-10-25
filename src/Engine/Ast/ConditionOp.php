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
