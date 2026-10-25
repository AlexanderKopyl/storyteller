<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\Event\ChoiceMade;
use Storyteller\Engine\Event\SceneEntered;
use Storyteller\Engine\State\GameState;
use Storyteller\Engine\TransitionResult;

/**
 * The wire shape Play persists for a turn.
 */
#[CoversClass(TransitionResult::class)]
final class TransitionResultTest extends TestCase
{
    public function testToArrayNestsStateSceneIsEndingAndEvents(): void
    {
        $result = new TransitionResult(
            GameState::initial()->withVisit('cave'),
            'cave',
            true,
            [new ChoiceMade('crossroads', 'north', 7), new SceneEntered('cave', 0, 7)],
        );

        self::assertSame(['state', 'scene', 'is_ending', 'events'], array_keys($result->toArray()));
        self::assertSame(
            [
                'state' => [
                    'v' => 1,
                    'vars' => [],
                    'flags' => [],
                    'inventory' => [],
                    'visited' => ['cave' => 1],
                    'history' => ['cave'],
                ],
                'scene' => 'cave',
                'is_ending' => true,
                'events' => [
                    ['event' => 'choice_made', 'scene' => 'crossroads', 'choice' => 'north', 'turn' => 7],
                    ['event' => 'scene_entered', 'scene' => 'cave', 'redirects' => 0, 'turn' => 7],
                ],
            ],
            $result->toArray(),
        );
    }

    public function testAnEmptyEventListSerializesAsAnEmptyList(): void
    {
        $result = new TransitionResult(GameState::initial(), 'start', false, []);

        self::assertSame([], $result->toArray()['events']);
        self::assertFalse($result->isEnding);
        self::assertSame('start', $result->sceneKey);
    }
}
