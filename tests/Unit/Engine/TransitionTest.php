<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\ConditionEvaluator;
use Storyteller\Engine\EffectApplier;
use Storyteller\Engine\Event\EngineEvent;
use Storyteller\Engine\Exception\ChoiceUnavailableException;
use Storyteller\Engine\Exception\NoNextSceneException;
use Storyteller\Engine\Exception\RedirectLoopException;
use Storyteller\Engine\Exception\UnknownSceneException;
use Storyteller\Engine\NextSceneResolver;
use Storyteller\Engine\State\GameState;
use Storyteller\Engine\State\VariableDefinition;
use Storyteller\Engine\State\VariableSchema;
use Storyteller\Engine\State\VariableType;
use Storyteller\Engine\Transition;
use Storyteller\Engine\TransitionResult;
use Storyteller\Tests\Unit\Engine\Double\InMemoryStoryGraph;

/**
 * One turn, end to end.
 *
 * Three decisions are pinned here because they are the ones a reader would
 * otherwise have to guess: the redirect budget is one counter per `run()`
 * with a limit of five, the choice-effect `goto` included; the visit is
 * recorded **after** the target's `on_enter`, so a scene redirected out of
 * its own `on_enter` was transited rather than entered and appears in neither
 * `visited` nor `history`; and `SceneEntered` is emitted once, for the scene
 * the turn came to rest on.
 */
#[CoversClass(Transition::class)]
final class TransitionTest extends TestCase
{
    public function testHappyPathAppliesChoiceEffectsResolvesNextRunsOnEnterAndUpdatesVisited(): void
    {
        $result = $this->transition([
            [
                'key' => 'crossroads',
                'choices' => [[
                    'key' => 'north',
                    'condition' => ['op' => 'has_item', 'item' => 'lantern'],
                    'effects' => [['op' => 'sub', 'var' => 'hp', 'value' => 1]],
                ]],
                'next' => 'cave',
            ],
            ['key' => 'cave', 'on_enter' => [['op' => 'give_item', 'item' => 'torch']]],
        ])->run($this->state(), 'crossroads', 'north', 7);

        self::assertSame('cave', $result->sceneKey);
        self::assertFalse($result->isEnding);
        self::assertSame(
            [
                'v' => 1,
                'vars' => ['hp' => 94],
                'flags' => [],
                'inventory' => ['lantern' => 1, 'torch' => 1],
                'visited' => ['cave' => 1],
                'history' => ['cave'],
            ],
            $result->state->toArray(),
        );
    }

    public function testEmittedEventsAreInTheDocumentedOrder(): void
    {
        $result = $this->transition([
            [
                'key' => 'crossroads',
                'choices' => [['key' => 'north', 'effects' => [['op' => 'sub', 'var' => 'hp', 'value' => 1]]]],
                'next' => 'the_end',
            ],
            ['key' => 'the_end', 'on_enter' => [['op' => 'set_flag', 'flag' => 'done']]],
        ], ['the_end'])->run($this->state(), 'crossroads', 'north', 7);

        self::assertTrue($result->isEnding);
        self::assertSame(
            [
                ['event' => 'choice_made', 'scene' => 'crossroads', 'choice' => 'north', 'turn' => 7],
                [
                    'event' => 'effects_applied',
                    'scene' => 'crossroads',
                    'phase' => 'choice',
                    'diff' => ['vars' => ['hp' => ['from' => 95, 'to' => 94]]],
                    'turn' => 7,
                ],
                [
                    'event' => 'effects_applied',
                    'scene' => 'the_end',
                    'phase' => 'on_enter',
                    'diff' => ['flags' => ['added' => ['done']]],
                    'turn' => 7,
                ],
                ['event' => 'scene_entered', 'scene' => 'the_end', 'redirects' => 0, 'turn' => 7],
                ['event' => 'ending_reached', 'scene' => 'the_end', 'turn' => 7],
            ],
            $result->toArray()['events'],
        );
    }

    public function testEffectsAppliedIsEmittedOncePerNonEmptyPhase(): void
    {
        $both = $this->transition([
            ['key' => 'a', 'choices' => [['key' => 'go', 'effects' => [['op' => 'set_flag', 'flag' => 'x']]]], 'next' => 'b'],
            ['key' => 'b', 'on_enter' => [['op' => 'set_flag', 'flag' => 'y']]],
        ])->run($this->state(), 'a', 'go', 1);

        $onlyOnEnter = $this->transition([
            ['key' => 'a', 'choices' => [['key' => 'go']], 'next' => 'b'],
            ['key' => 'b', 'on_enter' => [['op' => 'set_flag', 'flag' => 'y']]],
        ])->run($this->state(), 'a', 'go', 1);

        $neither = $this->transition([
            ['key' => 'a', 'choices' => [['key' => 'go']], 'next' => 'b'],
            ['key' => 'b'],
        ])->run($this->state(), 'a', 'go', 1);

        self::assertSame(
            ['choice_made', 'effects_applied', 'effects_applied', 'scene_entered'],
            self::names($both),
        );
        self::assertSame(['choice_made', 'effects_applied', 'scene_entered'], self::names($onlyOnEnter));
        self::assertSame(['choice_made', 'scene_entered'], self::names($neither));
    }

    /**
     * An effect that changes nothing changes nothing in the log either: the
     * diff is empty, so no event is emitted.
     */
    public function testAPhaseWhoseEffectsChangeNothingEmitsNoEvent(): void
    {
        $result = $this->transition([
            ['key' => 'a', 'choices' => [['key' => 'go', 'effects' => [['op' => 'clear_flag', 'flag' => 'never_set']]]], 'next' => 'b'],
            ['key' => 'b', 'on_enter' => [['op' => 'set', 'var' => 'hp', 'value' => 95]]],
        ])->run($this->state(), 'a', 'go', 1);

        self::assertSame(['choice_made', 'scene_entered'], self::names($result));
    }

    public function testAChoiceWhoseConditionIsFalseThrows(): void
    {
        $this->expectException(ChoiceUnavailableException::class);
        $this->expectExceptionMessage('north');

        $this->transition([
            [
                'key' => 'a',
                'choices' => [['key' => 'north', 'condition' => ['op' => 'flag', 'flag' => 'brave']]],
                'next' => 'b',
            ],
            ['key' => 'b'],
        ])->run($this->state(), 'a', 'north', 1);
    }

    public function testAChoiceKeyAbsentFromTheSceneThrows(): void
    {
        $this->expectException(ChoiceUnavailableException::class);
        $this->expectExceptionMessage('west');

        $this->transition([['key' => 'a', 'choices' => [['key' => 'north']], 'next' => 'a']])
            ->run($this->state(), 'a', 'west', 1);
    }

    public function testAnUnknownCurrentSceneThrows(): void
    {
        $this->expectException(UnknownSceneException::class);
        $this->expectExceptionMessage('nowhere');

        $this->transition([['key' => 'a', 'choices' => [['key' => 'go']], 'next' => 'a']])
            ->run($this->state(), 'nowhere', 'go', 1);
    }

    public function testAnUnknownResolvedSceneThrows(): void
    {
        $danglingNext = $this->transition([['key' => 'a', 'choices' => [['key' => 'go']], 'next' => 'ghost']]);
        $danglingGoto = $this->transition([[
            'key' => 'a',
            'choices' => [['key' => 'go', 'effects' => [['op' => 'goto', 'scene' => 'ghost']]]],
            'next' => 'a',
        ]]);
        $danglingOnEnterGoto = $this->transition([
            ['key' => 'a', 'choices' => [['key' => 'go']], 'next' => 'b'],
            ['key' => 'b', 'on_enter' => [['op' => 'goto', 'scene' => 'ghost']]],
        ]);

        foreach ([$danglingNext, $danglingGoto, $danglingOnEnterGoto] as $transition) {
            try {
                $transition->run($this->state(), 'a', 'go', 1);
                self::fail('Expected an UnknownSceneException for a dangling target.');
            } catch (UnknownSceneException $exception) {
                self::assertStringContainsString('ghost', $exception->getMessage());
            }
        }
    }

    public function testAGotoInTheChoiceEffectsOverridesTheDeclaredNext(): void
    {
        $result = $this->transition([
            [
                'key' => 'a',
                'choices' => [['key' => 'go', 'effects' => [['op' => 'goto', 'scene' => 'cave']]]],
                'next' => 'never',
            ],
            ['key' => 'cave'],
            ['key' => 'never'],
        ])->run($this->state(), 'a', 'go', 1);

        self::assertSame('cave', $result->sceneKey);
        self::assertSame(['cave'], $result->state->toArray()['history']);
        self::assertSame(1, self::sceneEntered($result)['redirects']);
    }

    public function testAGotoInOnEnterRedirectsAgain(): void
    {
        $result = $this->transition([
            ['key' => 'a', 'choices' => [['key' => 'go']], 'next' => 'b'],
            ['key' => 'b', 'on_enter' => [['op' => 'goto', 'scene' => 'c']]],
            ['key' => 'c', 'on_enter' => [['op' => 'goto', 'scene' => 'd']]],
            ['key' => 'd'],
        ])->run($this->state(), 'a', 'go', 1);

        self::assertSame('d', $result->sceneKey);
        self::assertSame(2, self::sceneEntered($result)['redirects']);
        self::assertSame(['d'], $result->state->toArray()['history']);
    }

    public function testFiveRedirectsSucceed(): void
    {
        $result = $this->transition(self::redirectChain(5))->run($this->state(), 'a', 'go', 1);

        self::assertSame(5, Transition::MAX_REDIRECTS);
        self::assertSame('h5', $result->sceneKey);
        self::assertSame(5, self::sceneEntered($result)['redirects']);
    }

    public function testSixRedirectsThrowRedirectLoopException(): void
    {
        $this->expectException(RedirectLoopException::class);
        $this->expectExceptionMessage('h6');

        $this->transition(self::redirectChain(6))->run($this->state(), 'a', 'go', 1);
    }

    /**
     * The choice-effect `goto` is the first redirect, not a free one: with it
     * spent, only four `on_enter` redirects remain inside the budget.
     */
    public function testTheChoiceEffectGotoCountsTowardTheRedirectBudget(): void
    {
        $scenes = [
            [
                'key' => 'a',
                'choices' => [['key' => 'go', 'effects' => [['op' => 'goto', 'scene' => 'h0']]]],
                'next' => 'a',
            ],
        ];

        for ($i = 0; $i < 5; ++$i) {
            $scenes[] = ['key' => 'h' . $i, 'on_enter' => [['op' => 'goto', 'scene' => 'h' . ($i + 1)]]];
        }

        $scenes[] = ['key' => 'h5'];

        $this->expectException(RedirectLoopException::class);

        $this->transition($scenes)->run($this->state(), 'a', 'go', 1);
    }

    /**
     * A scene the turn only passed through was transited, not entered: its
     * `on_enter` effects really happened and are logged, but it is absent from
     * both `visited` and `history`, and it gets no `SceneEntered`.
     */
    public function testASceneRedirectedThroughIsNotMarkedVisitedButItsEffectsStillApply(): void
    {
        $result = $this->transition([
            ['key' => 'a', 'choices' => [['key' => 'go']], 'next' => 'hall'],
            [
                'key' => 'hall',
                'on_enter' => [['op' => 'set_flag', 'flag' => 'passed_hall'], ['op' => 'goto', 'scene' => 'cave']],
            ],
            ['key' => 'cave'],
        ])->run($this->state(), 'a', 'go', 1);

        self::assertSame('cave', $result->sceneKey);
        self::assertTrue($result->state->hasFlag('passed_hall'));
        self::assertSame(0, $result->state->visitCount('hall'));
        self::assertSame(['cave'], $result->state->toArray()['history']);
        self::assertSame(['cave' => 1], $result->state->toArray()['visited']);
        self::assertSame(
            ['choice_made', 'effects_applied', 'scene_entered'],
            self::names($result),
        );
        self::assertSame('hall', $result->toArray()['events'][1]['scene']);
    }

    /**
     * The visit is recorded after the target's `on_enter`, so during that
     * phase the scene has not been entered yet: the entry that brought the
     * player here counts from the *next* turn on.
     */
    public function testASceneIsNotVisitedUntilAfterItsOwnOnEnter(): void
    {
        $story = [
            ['key' => 'a', 'choices' => [['key' => 'go']], 'next' => 'x'],
            [
                'key' => 'x',
                'on_enter' => [['op' => 'set_flag', 'flag' => 'entered_x']],
                'choices' => [
                    ['key' => 'again', 'condition' => ['op' => 'visited', 'scene' => 'x', 'times' => 1]],
                    ['key' => 'third', 'condition' => ['op' => 'visited', 'scene' => 'x', 'times' => 3]],
                ],
                'next' => 'x',
            ],
        ];

        $first = $this->transition($story)->run($this->state(), 'a', 'go', 1);

        // on_enter ran before the visit was recorded: its event precedes SceneEntered.
        self::assertSame(['choice_made', 'effects_applied', 'scene_entered'], self::names($first));
        self::assertSame(1, $first->state->visitCount('x'));

        // The entry that brought the player here is visible from the next turn on.
        $second = $this->transition($story)->run($first->state, 'x', 'again', 2);

        self::assertSame('x', $second->sceneKey);
        self::assertSame(2, $second->state->visitCount('x'));
        self::assertSame(['x', 'x'], $second->state->toArray()['history']);

        // ... but only as far as it has actually happened.
        $this->expectException(ChoiceUnavailableException::class);
        $this->expectExceptionMessage('third');

        $this->transition($story)->run($second->state, 'x', 'third', 3);
    }

    public function testIsEndingIsTrueExactlyWhenTheGraphSaysSo(): void
    {
        $story = [
            ['key' => 'a', 'choices' => [['key' => 'stop'], ['key' => 'on']], 'next' => [
                ['scene' => 'the_end', 'if' => ['op' => 'flag', 'flag' => 'finish']],
                ['scene' => 'b'],
            ]],
            ['key' => 'b'],
            ['key' => 'the_end'],
        ];

        $ending = $this->transition($story, ['the_end'])
            ->run($this->state()->withFlag('finish'), 'a', 'stop', 1);
        $notEnding = $this->transition($story, ['the_end'])->run($this->state(), 'a', 'on', 1);

        self::assertTrue($ending->isEnding);
        self::assertSame('the_end', $ending->sceneKey);
        self::assertContains('ending_reached', self::names($ending));

        self::assertFalse($notEnding->isEnding);
        self::assertSame('b', $notEnding->sceneKey);
        self::assertNotContains('ending_reached', self::names($notEnding));
    }

    public function testNoNextSceneExceptionPropagatesFromTheResolver(): void
    {
        $this->expectException(NoNextSceneException::class);
        $this->expectExceptionMessage('dead_end');

        $this->transition([['key' => 'dead_end', 'choices' => [['key' => 'go']]]])
            ->run($this->state(), 'dead_end', 'go', 1);
    }

    /**
     * @param  list<array<array-key, mixed>> $scenes
     * @param  list<string>                  $endings
     */
    private function transition(array $scenes, array $endings = []): Transition
    {
        $schema = VariableSchema::of([new VariableDefinition('hp', VariableType::Int, 100)]);
        $evaluator = new ConditionEvaluator($schema);

        return new Transition(
            InMemoryStoryGraph::fromArray($scenes, $endings),
            $evaluator,
            new EffectApplier($schema),
            new NextSceneResolver($evaluator),
        );
    }

    private function state(): GameState
    {
        return GameState::create(['hp' => 95], [], ['lantern' => 1], [], []);
    }

    /**
     * A chain of `$hops` `on_enter` redirects, reached from `a` without
     * spending any of the budget on the choice itself.
     *
     * @return list<array<array-key, mixed>>
     */
    private static function redirectChain(int $hops): array
    {
        $scenes = [['key' => 'a', 'choices' => [['key' => 'go']], 'next' => 'h0']];

        for ($i = 0; $i < $hops; ++$i) {
            $scenes[] = ['key' => 'h' . $i, 'on_enter' => [['op' => 'goto', 'scene' => 'h' . ($i + 1)]]];
        }

        $scenes[] = ['key' => 'h' . $hops];

        return $scenes;
    }

    /**
     * @return list<string>
     */
    private static function names(TransitionResult $result): array
    {
        return array_map(static fn (EngineEvent $event): string => $event->name(), $result->events);
    }

    /**
     * @return array<string, mixed>
     */
    private static function sceneEntered(TransitionResult $result): array
    {
        foreach ($result->toArray()['events'] as $event) {
            if ($event['event'] === 'scene_entered') {
                return $event;
            }
        }

        self::fail('No scene_entered event was emitted.');
    }
}
