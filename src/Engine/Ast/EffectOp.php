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
     */
    public function isArithmetic(): bool
    {
        return match ($this) {
            self::Add, self::Sub => true,
            default => false,
        };
    }
}
