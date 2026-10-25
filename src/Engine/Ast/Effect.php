<?php

declare(strict_types=1);

namespace Storyteller\Engine\Ast;

use Storyteller\Engine\Exception\UnknownOperatorException;
use Storyteller\Engine\Support\ArrayReader;

/**
 * A node of the effect AST.
 *
 * Parsing is structural, exactly as for conditions: `add` and `sub` require a
 * JSON number here, but whether that number may be written into the variable
 * the node names is the applier's question, because only it has the schema.
 */
abstract readonly class Effect
{
    abstract public function op(): EffectOp;

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * @param array<array-key, mixed> $node
     * @param string                  $path breadcrumb used in error messages
     */
    final public static function fromArray(array $node, string $path = 'effect'): self
    {
        $reader = new ArrayReader($node, $path);
        $rawOp = $reader->requireString('op');
        $op = EffectOp::tryFrom($rawOp) ?? throw UnknownOperatorException::effect($rawOp);

        return match ($op) {
            EffectOp::Set => SetVar::parse($reader, $op),
            EffectOp::Add, EffectOp::Sub => Arithmetic::parse($reader, $op),
            EffectOp::SetFlag, EffectOp::ClearFlag => FlagEffect::parse($reader, $op),
            EffectOp::GiveItem, EffectOp::RemoveItem => ItemEffect::parse($reader, $op),
            EffectOp::GoTo => GoToScene::parse($reader, $op),
        };
    }

    /**
     * Parses an ordered effect list — `on_enter` and `choices[].effects` are
     * both one of these. Order is preserved because it is semantically
     * significant: a `goto` ends the list.
     *
     * @param  list<array<array-key, mixed>> $nodes
     * @return list<Effect>
     */
    final public static function listFromArray(array $nodes, string $path): array
    {
        $effects = [];

        foreach ($nodes as $index => $node) {
            $effects[] = self::fromArray($node, $path . '[' . $index . ']');
        }

        return $effects;
    }

    /**
     * Protected rather than private so the dispatcher above can call it from
     * the parent's scope.
     */
    abstract protected static function parse(ArrayReader $reader, EffectOp $op): self;
}
