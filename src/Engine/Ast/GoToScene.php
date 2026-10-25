<?php

declare(strict_types=1);

namespace Storyteller\Engine\Ast;

use Storyteller\Engine\Support\ArrayReader;

/**
 * `goto`: redirect to another scene.
 *
 * Named `GoToScene` because `Goto` is a reserved word. The node itself changes
 * no state; the applier stops the effect list where it stands and reports the
 * target, so anything written after a `goto` never runs.
 */
final readonly class GoToScene extends Effect
{
    public function __construct(public string $scene)
    {
    }

    public function op(): EffectOp
    {
        return EffectOp::GoTo;
    }

    /**
     * @return array{op: string, scene: string}
     */
    public function toArray(): array
    {
        return ['op' => EffectOp::GoTo->value, 'scene' => $this->scene];
    }

    protected static function parse(ArrayReader $reader, EffectOp $op): self
    {
        $reader->rejectUnknownKeys('op', 'scene');

        return new self($reader->requireString('scene'));
    }
}
