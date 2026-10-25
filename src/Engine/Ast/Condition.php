<?php

declare(strict_types=1);

namespace Storyteller\Engine\Ast;

use Storyteller\Engine\Exception\MalformedNodeException;
use Storyteller\Engine\Exception\UnknownOperatorException;
use Storyteller\Engine\Support\ArrayReader;

/**
 * A node of the condition AST.
 *
 * Conditions are data, not code: they are authored, compiled into a story
 * version and interpreted. Parsing checks *structure* only — required keys,
 * the right PHP types, no unknown keys. Whether a literal matches the declared
 * type of the variable it is compared against is an evaluation-time question,
 * because the schema need not be in hand when a node is parsed.
 */
abstract readonly class Condition
{
    abstract public function op(): ConditionOp;

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * @param array<array-key, mixed> $node
     * @param string                  $path breadcrumb used in error messages
     */
    final public static function fromArray(array $node, string $path = 'condition'): self
    {
        $reader = new ArrayReader($node, $path);
        $rawOp = $reader->requireString('op');
        $op = ConditionOp::tryFrom($rawOp) ?? throw UnknownOperatorException::condition($rawOp);

        return match ($op) {
            ConditionOp::Eq,
            ConditionOp::Neq,
            ConditionOp::Lt,
            ConditionOp::Lte,
            ConditionOp::Gt,
            ConditionOp::Gte => Comparison::parse($reader, $op, $path),
            ConditionOp::Flag, ConditionOp::NotFlag => FlagCheck::parse($reader, $op, $path),
            ConditionOp::HasItem => HasItem::parse($reader, $op, $path),
            ConditionOp::Visited => Visited::parse($reader, $op, $path),
            ConditionOp::AndOp => LogicalAnd::parse($reader, $op, $path),
            ConditionOp::OrOp => LogicalOr::parse($reader, $op, $path),
            ConditionOp::NotOp => LogicalNot::parse($reader, $op, $path),
        };
    }

    /**
     * Builds one node from an already-opened reader.
     *
     * Protected rather than private because the dispatcher above calls it from
     * the parent's scope, and it takes the path explicitly because a node that
     * owns children has to extend it for them.
     */
    abstract protected static function parse(ArrayReader $reader, ConditionOp $op, string $path): self;

    /**
     * Parses the `of` list shared by the n-ary `and` and `or`.
     *
     * An empty list is rejected: there is no identity element to pick that is
     * not a hidden policy decision, and an author who writes one meant
     * something they did not say.
     *
     * @return non-empty-list<self>
     */
    final protected static function parseChildren(ArrayReader $reader, string $path): array
    {
        $nodes = $reader->requireArrayList('of');

        if ($nodes === []) {
            throw MalformedNodeException::wrongType($path, 'of', 'non-empty list', 'empty list');
        }

        $children = [];

        foreach ($nodes as $index => $node) {
            $children[] = self::fromArray($node, $path . '.of[' . $index . ']');
        }

        return $children;
    }
}
