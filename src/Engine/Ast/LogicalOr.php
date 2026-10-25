<?php

declare(strict_types=1);

namespace Storyteller\Engine\Ast;

use Storyteller\Engine\Support\ArrayReader;

/**
 * `or`: at least one child must hold.
 */
final readonly class LogicalOr extends Condition
{
    /**
     * @param non-empty-list<Condition> $of
     */
    public function __construct(public array $of)
    {
    }

    public function op(): ConditionOp
    {
        return ConditionOp::OrOp;
    }

    /**
     * @return array{op: string, of: non-empty-list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'op' => ConditionOp::OrOp->value,
            'of' => array_map(static fn (Condition $child): array => $child->toArray(), $this->of),
        ];
    }

    protected static function parse(ArrayReader $reader, ConditionOp $op, string $path): self
    {
        $reader->rejectUnknownKeys('op', 'of');

        return new self(self::parseChildren($reader, $path));
    }
}
