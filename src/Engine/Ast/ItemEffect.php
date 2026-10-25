<?php

declare(strict_types=1);

namespace Storyteller\Engine\Ast;

use Storyteller\Engine\Support\ArrayReader;

/**
 * `give_item` and `remove_item`.
 *
 * `qty` defaults to 1 and must be a positive integer: a story says how many to
 * give or take, never how many to end up with, so zero and negatives are
 * authoring mistakes rather than instructions.
 */
final readonly class ItemEffect extends Effect
{
    public function __construct(private EffectOp $op, public string $item, public int $qty = 1)
    {
    }

    public function op(): EffectOp
    {
        return $this->op;
    }

    /**
     * @return array{op: string, item: string, qty: int}
     */
    public function toArray(): array
    {
        return ['op' => $this->op->value, 'item' => $this->item, 'qty' => $this->qty];
    }

    protected static function parse(ArrayReader $reader, EffectOp $op): self
    {
        $reader->rejectUnknownKeys('op', 'item', 'qty');

        return new self($op, $reader->requireString('item'), $reader->optionalPositiveInt('qty', 1));
    }
}
