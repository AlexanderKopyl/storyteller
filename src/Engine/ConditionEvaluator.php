<?php

declare(strict_types=1);

namespace Storyteller\Engine;

use Storyteller\Engine\Ast\Comparison;
use Storyteller\Engine\Ast\Condition;
use Storyteller\Engine\Ast\ConditionOp;
use Storyteller\Engine\Ast\FlagCheck;
use Storyteller\Engine\Ast\HasItem;
use Storyteller\Engine\Ast\LogicalAnd;
use Storyteller\Engine\Ast\LogicalNot;
use Storyteller\Engine\Ast\LogicalOr;
use Storyteller\Engine\Ast\Visited;
use Storyteller\Engine\Exception\TypeMismatchException;
use Storyteller\Engine\Exception\UnknownOperatorException;
use Storyteller\Engine\Exception\UnknownVariableException;
use Storyteller\Engine\State\GameState;
use Storyteller\Engine\State\VariableSchema;

/**
 * Interprets a condition AST against a game state.
 *
 * Three rules do all the work and all three live in the schema rather than
 * here: a variable the schema does not declare is an error, a declared
 * variable the state says nothing about resolves to its default, and a literal
 * must match the variable's declared type. The last one is why a wrong-typed
 * comparison throws instead of quietly returning `false` — a story that
 * compares `hp` against `"ten"` is broken, and saying so is more useful than
 * evaluating it.
 *
 * Equality is `===` throughout, floats included: no epsilon is applied,
 * because an epsilon is a policy nobody authored.
 */
final readonly class ConditionEvaluator
{
    public function __construct(private VariableSchema $schema)
    {
    }

    /**
     * @throws UnknownVariableException  if a named variable is not declared
     * @throws TypeMismatchException     if a literal or an ordering does not fit the declaration
     * @throws UnknownOperatorException  if a node carries an operator the evaluator has no rule for
     */
    public function evaluate(Condition $condition, GameState $state): bool
    {
        return match (true) {
            $condition instanceof Comparison => $this->compare($condition, $state),
            $condition instanceof FlagCheck => $this->checkFlag($condition, $state),
            $condition instanceof HasItem => $state->itemQty($condition->item) >= $condition->qty,
            $condition instanceof Visited => $state->visitCount($condition->scene) >= $condition->times,
            $condition instanceof LogicalAnd => $this->all($condition->of, $state),
            $condition instanceof LogicalOr => $this->any($condition->of, $state),
            $condition instanceof LogicalNot => !$this->evaluate($condition->of, $state),
            // The AST is an open hierarchy: a node class nobody taught the
            // evaluator about must fail loudly, never evaluate to false.
            default => throw UnknownOperatorException::condition($condition->op()->value),
        };
    }

    private function compare(Comparison $comparison, GameState $state): bool
    {
        $op = $comparison->op();
        $name = $comparison->var;

        $this->schema->assertAccepts($name, $comparison->value);

        $type = $this->schema->typeOf($name);

        if ($op->isOrdering() && !$type->isNumeric()) {
            throw TypeMismatchException::notNumeric($name, $type, $op->value);
        }

        $current = $this->schema->resolve($name, $state);

        return match ($op) {
            ConditionOp::Eq => $current === $comparison->value,
            ConditionOp::Neq => $current !== $comparison->value,
            ConditionOp::Lt => $current < $comparison->value,
            ConditionOp::Lte => $current <= $comparison->value,
            ConditionOp::Gt => $current > $comparison->value,
            ConditionOp::Gte => $current >= $comparison->value,
            default => throw UnknownOperatorException::condition($op->value),
        };
    }

    private function checkFlag(FlagCheck $check, GameState $state): bool
    {
        $isSet = $state->hasFlag($check->flag);

        return $check->op() === ConditionOp::Flag ? $isSet : !$isSet;
    }

    /**
     * @param non-empty-list<Condition> $children
     */
    private function all(array $children, GameState $state): bool
    {
        foreach ($children as $child) {
            if (!$this->evaluate($child, $state)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param non-empty-list<Condition> $children
     */
    private function any(array $children, GameState $state): bool
    {
        foreach ($children as $child) {
            if ($this->evaluate($child, $state)) {
                return true;
            }
        }

        return false;
    }
}
