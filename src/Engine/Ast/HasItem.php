<?php

declare(strict_types=1);

namespace Storyteller\Engine\Ast;

use Storyteller\Engine\Support\ArrayReader;

/**
 * `has_item`: true when the inventory holds at least `qty` of the item.
 */
final readonly class HasItem extends Condition
{
    public function __construct(public string $item, public int $qty = 1)
    {
    }

    public function op(): ConditionOp
    {
        return ConditionOp::HasItem;
    }

    /**
     * @return array{op: string, item: string, qty: int}
     */
    public function toArray(): array
    {
        return ['op' => ConditionOp::HasItem->value, 'item' => $this->item, 'qty' => $this->qty];
    }

    protected static function parse(ArrayReader $reader, ConditionOp $op, string $path): self
    {
        $reader->rejectUnknownKeys('op', 'item', 'qty');
        $reader->rejectNull('qty', 'int');

        return new self($reader->requireString('item'), $reader->optionalPositiveInt('qty', 1));
    }
}
