<?php

declare(strict_types=1);

namespace Storyteller\Engine\Ast;

use Storyteller\Engine\Support\ArrayReader;

/**
 * `set`: assign a literal to a variable.
 *
 * Any scalar is accepted at parse time; the applier rejects one the variable's
 * declared type does not accept.
 */
final readonly class SetVar extends Effect
{
    public function __construct(public string $var, public int|float|string|bool $value)
    {
    }

    public function op(): EffectOp
    {
        return EffectOp::Set;
    }

    /**
     * @return array{op: string, var: string, value: int|float|string|bool}
     */
    public function toArray(): array
    {
        return ['op' => EffectOp::Set->value, 'var' => $this->var, 'value' => $this->value];
    }

    protected static function parse(ArrayReader $reader, EffectOp $op): self
    {
        $reader->rejectUnknownKeys('op', 'var', 'value');

        return new self($reader->requireString('var'), $reader->requireScalar('value'));
    }
}
