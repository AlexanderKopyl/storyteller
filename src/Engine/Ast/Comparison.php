<?php

declare(strict_types=1);

namespace Storyteller\Engine\Ast;

use Storyteller\Engine\Support\ArrayReader;

/**
 * `eq`, `neq`, `lt`, `lte`, `gt` and `gte`: a variable against a literal.
 *
 * The literal is accepted here as any scalar. Whether it matches the
 * variable's declared type — and whether the variable may be ordered at all —
 * is decided by the evaluator, which has the schema.
 */
final readonly class Comparison extends Condition
{
    public function __construct(
        private ConditionOp $op,
        public string $var,
        public int|float|string|bool $value,
    ) {
    }

    public function op(): ConditionOp
    {
        return $this->op;
    }

    /**
     * @return array{op: string, var: string, value: int|float|string|bool}
     */
    public function toArray(): array
    {
        return ['op' => $this->op->value, 'var' => $this->var, 'value' => $this->value];
    }

    protected static function parse(ArrayReader $reader, ConditionOp $op, string $path): self
    {
        $reader->rejectUnknownKeys('op', 'var', 'value');

        return new self($op, $reader->requireString('var'), $reader->requireScalar('value'));
    }
}
