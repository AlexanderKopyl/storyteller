<?php

declare(strict_types=1);

namespace Storyteller\Engine\Ast;

use Storyteller\Engine\Support\ArrayReader;

/**
 * `set_flag` and `clear_flag`.
 */
final readonly class FlagEffect extends Effect
{
    public function __construct(private EffectOp $op, public string $flag)
    {
    }

    public function op(): EffectOp
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

    protected static function parse(ArrayReader $reader, EffectOp $op): self
    {
        $reader->rejectUnknownKeys('op', 'flag');

        return new self($op, $reader->requireString('flag'));
    }
}
