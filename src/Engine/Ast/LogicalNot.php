<?php

declare(strict_types=1);

namespace Storyteller\Engine\Ast;

use Storyteller\Engine\Support\ArrayReader;

/**
 * `not`: the negation of a single child.
 *
 * `of` is one object here, not a list — the shape mirrors the arity, so an
 * author cannot write a `not` over two conditions and be left guessing which
 * one the Engine negated.
 */
final readonly class LogicalNot extends Condition
{
    public function __construct(public Condition $of)
    {
    }

    public function op(): ConditionOp
    {
        return ConditionOp::NotOp;
    }

    /**
     * @return array{op: string, of: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['op' => ConditionOp::NotOp->value, 'of' => $this->of->toArray()];
    }

    protected static function parse(ArrayReader $reader, ConditionOp $op, string $path): self
    {
        $reader->rejectUnknownKeys('op', 'of');

        return new self(Condition::fromArray($reader->requireArray('of'), $path . '.of'));
    }
}
