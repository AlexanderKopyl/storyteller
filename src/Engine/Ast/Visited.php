<?php

declare(strict_types=1);

namespace Storyteller\Engine\Ast;

use Storyteller\Engine\Support\ArrayReader;

/**
 * `visited`: true when the scene has been entered at least `times` times.
 *
 * A scene is marked visited *after* its own `on_enter` effects have run, and
 * the place that difference is observable is the **next** turn: a choice
 * condition or a `next` guard on scene X, evaluated while the player is
 * standing in X, sees the entry that brought them there already counted. It
 * is **not** observable from inside `on_enter` — effects carry no conditions,
 * so nothing there can read this node at all. "First time here" is therefore
 * a `not` of this node written as a choice condition or a `next` guard, never
 * as something inside an `on_enter`.
 *
 * See `docs/engine/ast.md` → *The `visited` operator, precisely*, and
 * `TransitionTest::testASceneIsNotVisitedUntilAfterItsOwnOnEnter`.
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
