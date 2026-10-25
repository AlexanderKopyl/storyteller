<?php

declare(strict_types=1);

namespace Storyteller\Engine\Ast;

use Storyteller\Engine\Support\ArrayReader;

/**
 * `add` and `sub`.
 *
 * The operand must be a JSON number at parse time — a numeric string is not
 * one — which is the parse-time half of "no string concatenation and no
 * boolean arithmetic". The other half, that the *variable* is numeric and that
 * the result still fits its declared type, is enforced by the applier.
 */
final readonly class Arithmetic extends Effect
{
    public function __construct(private EffectOp $op, public string $var, public int|float $value)
    {
    }

    public function op(): EffectOp
    {
        return $this->op;
    }

    /**
     * @return array{op: string, var: string, value: int|float}
     */
    public function toArray(): array
    {
        return ['op' => $this->op->value, 'var' => $this->var, 'value' => $this->value];
    }

    protected static function parse(ArrayReader $reader, EffectOp $op): self
    {
        $reader->rejectUnknownKeys('op', 'var', 'value');

        return new self($op, $reader->requireString('var'), $reader->requireNumber('value'));
    }
}
