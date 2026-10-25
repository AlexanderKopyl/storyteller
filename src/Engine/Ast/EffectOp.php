<?php

declare(strict_types=1);

namespace Storyteller\Engine\Ast;

/**
 * The eight effect operators.
 *
 * As with {@see ConditionOp}, the backing values are the `op` strings a
 * compiled story carries, so this list is a published format.
 */
enum EffectOp: string
{
    case Set = 'set';
    case Add = 'add';
    case Sub = 'sub';
    case SetFlag = 'set_flag';
    case ClearFlag = 'clear_flag';
    case GiveItem = 'give_item';
    case RemoveItem = 'remove_item';
    case GoTo = 'goto';

    /**
     * Operates on the current value of a numeric variable.
     *
     * m2: no production call site inside the Engine — the applier dispatches
     * on node class, and `Arithmetic` already knows which of the two it is.
     * Kept for `src/Content` for the same reason as
     * {@see ConditionOp::isComparison()}: a compiler holding an `op` string
     * needs to know whether the variable it names must be numeric before any
     * node exists to ask.
     */
    public function isArithmetic(): bool
    {
        return match ($this) {
            self::Add, self::Sub => true,
            default => false,
        };
    }
}
