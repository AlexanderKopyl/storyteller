<?php

declare(strict_types=1);

namespace Storyteller\Engine\Ast;

use Storyteller\Engine\Support\ArrayReader;

/**
 * `visited`: true when the scene has been entered at least `times` times.
 *
 * A scene is marked visited *after* its own `on_enter` effects have run, so
 * `{"op":"visited","scene":"X","times":1}` evaluated inside scene X's own
 * `on_enter` is false on the first entry and true on every later one. That is
 * what makes "first time here" expressible as a `not` of this node.
 */
final readonly class Visited extends Condition
{
    public function __construct(public string $scene, public int $times = 1)
    {
    }

    public function op(): ConditionOp
    {
        return ConditionOp::Visited;
    }

    /**
     * @return array{op: string, scene: string, times: int}
     */
    public function toArray(): array
    {
        return ['op' => ConditionOp::Visited->value, 'scene' => $this->scene, 'times' => $this->times];
    }

    protected static function parse(ArrayReader $reader, ConditionOp $op, string $path): self
    {
        $reader->rejectUnknownKeys('op', 'scene', 'times');
        $reader->rejectNull('times', 'int');

        return new self($reader->requireString('scene'), $reader->optionalPositiveInt('times', 1));
    }
}
