<?php

declare(strict_types=1);

namespace Storyteller\Engine;

use Storyteller\Engine\Ast\Arithmetic;
use Storyteller\Engine\Ast\Effect;
use Storyteller\Engine\Ast\EffectOp;
use Storyteller\Engine\Ast\FlagEffect;
use Storyteller\Engine\Ast\GoToScene;
use Storyteller\Engine\Ast\ItemEffect;
use Storyteller\Engine\Ast\SetVar;
use Storyteller\Engine\Exception\TypeMismatchException;
use Storyteller\Engine\Exception\UnknownOperatorException;
use Storyteller\Engine\Exception\UnknownVariableException;
use Storyteller\Engine\State\GameState;
use Storyteller\Engine\State\StateDiff;
use Storyteller\Engine\State\VariableSchema;

/**
 * Applies an ordered effect list to a state and reports what changed.
 *
 * The list is ordered because order is meaning: a `goto` ends it where it
 * stands and the effects after it never run, which is how an author writes
 * "leave immediately" without a separate control structure. The state is
 * threaded through immutably, so the state the phase started with is still
 * available at the end to diff against.
 *
 * This is the only place a declared variable type is enforced on a write.
 * `set` refuses a value the declaration does not accept; `add` and `sub`
 * refuse a non-numeric variable outright — no string concatenation, no boolean
 * arithmetic — and check the *result* as well, so a fractional step into an
 * int, and an int overflow that PHP quietly turned into a float, both fail
 * instead of corrupting the type.
 */
final readonly class EffectApplier
{
    public function __construct(private VariableSchema $schema)
    {
    }

    /**
     * @param  list<Effect> $effects
     * @throws UnknownVariableException if an effect names a variable the schema does not declare
     * @throws TypeMismatchException    if a write does not fit the declaration
     * @throws UnknownOperatorException if an effect carries an operator the applier has no rule for
     */
    public function apply(array $effects, GameState $state): EffectResult
    {
        $before = $state;

        foreach ($effects as $effect) {
            if ($effect instanceof GoToScene) {
                return new EffectResult($state, $effect->scene, StateDiff::between($before, $state, $this->schema));
            }

            $state = $this->applyOne($effect, $state);
        }

        return new EffectResult($state, null, StateDiff::between($before, $state, $this->schema));
    }

    private function applyOne(Effect $effect, GameState $state): GameState
    {
        return match (true) {
            $effect instanceof SetVar => $this->set($effect, $state),
            $effect instanceof Arithmetic => $this->arithmetic($effect, $state),
            $effect instanceof FlagEffect => $this->flag($effect, $state),
            $effect instanceof ItemEffect => $this->item($effect, $state),
            // The AST is an open hierarchy, and `goto` never reaches here —
            // `apply()` returns on it. Anything else is an operator nobody
            // taught the applier, and silently ignoring it would lose a write.
            default => throw UnknownOperatorException::effect($effect->op()->value),
        };
    }

    private function set(SetVar $effect, GameState $state): GameState
    {
        $this->schema->assertAccepts($effect->var, $effect->value);

        return $state->withVar($effect->var, $effect->value);
    }

    private function arithmetic(Arithmetic $effect, GameState $state): GameState
    {
        $name = $effect->var;
        $type = $this->schema->typeOf($name);

        if (!$type->isNumeric()) {
            throw TypeMismatchException::notNumeric($name, $type, $effect->op()->value);
        }

        $current = $this->schema->resolve($name, $state);

        // The state carries no schema of its own, so a value built elsewhere
        // can disagree with the declaration. Refuse it rather than coerce it.
        if (!is_int($current) && !is_float($current)) {
            throw TypeMismatchException::value($name, $type, get_debug_type($current));
        }

        $result = $effect->op() === EffectOp::Add
            ? $current + $effect->value
            : $current - $effect->value;

        $this->schema->assertAccepts($name, $result);

        return $state->withVar($name, $result);
    }

    private function flag(FlagEffect $effect, GameState $state): GameState
    {
        return $effect->op() === EffectOp::SetFlag
            ? $state->withFlag($effect->flag)
            : $state->withoutFlag($effect->flag);
    }

    private function item(ItemEffect $effect, GameState $state): GameState
    {
        $current = $state->itemQty($effect->item);

        $qty = $effect->op() === EffectOp::GiveItem
            ? $current + $effect->qty
            : max(0, $current - $effect->qty);

        return $state->withItem($effect->item, $qty);
    }
}
