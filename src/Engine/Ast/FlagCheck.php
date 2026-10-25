<?php

declare(strict_types=1);

namespace Storyteller\Engine\Ast;

use Storyteller\Engine\Support\ArrayReader;

/**
 * `flag` and `not_flag`.
 *
 * Flags are an open set — they are not declared in the variable schema — so
 * nothing here consults it.
 */
final readonly class FlagCheck extends Condition
{
    public function __construct(private ConditionOp $op, public string $flag)
    {
    }

    public function op(): ConditionOp
    {
        return $this->op;
    }

    /**
     * @return array{op: string, flag: string}
     */
    public function toArray(): array
    {
        return ['op' => $this->op->value, 'flag' => $this->flag];
    }

    protected static function parse(ArrayReader $reader, ConditionOp $op, string $path): self
    {
        $reader->rejectUnknownKeys('op', 'flag');

        return new self($op, $reader->requireString('flag'));
    }
}
