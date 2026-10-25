<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\ConditionEvaluator;
use Storyteller\Engine\EffectApplier;
use Storyteller\Engine\Event\EngineEvent;
use Storyteller\Engine\Exception\ChoiceUnavailableException;
use Storyteller\Engine\Exception\ChoiceUnavailableReason;
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

    /**
     * M5: a locked choice is a state conflict — the choice exists, the player
     * just may not take it right now — and a caller must be able to tell it
     * from "no such choice" by type rather than by matching English prose.
     */
    public function testAChoiceWhoseConditionIsFalseThrowsWithTheConditionFailedReason(): void
    {
        $exception = $this->expectChoiceUnavailable(fn (): TransitionResult => $this->transition([
            [
                'key' => 'a',
                'choices' => [['key' => 'north', 'condition' => ['op' => 'flag', 'flag' => 'brave']]],
                'next' => 'b',
            ],
            ['key' => 'b'],
        ])->run($this->state(), 'a', 'north', 1));

        self::assertSame(ChoiceUnavailableReason::ConditionFailed, $exception->reason);
        self::assertStringContainsString('north', $exception->getMessage());
    }

    public function testAChoiceKeyAbsentFromTheSceneThrowsWithTheNotFoundReason(): void
    {
        $exception = $this->expectChoiceUnavailable(
            fn (): TransitionResult => $this->transition([
                ['key' => 'a', 'choices' => [['key' => 'north']], 'next' => 'a'],
            ])->run($this->state(), 'a', 'west', 1),
        );

        self::assertSame(ChoiceUnavailableReason::NotFound, $exception->reason);
        self::assertStringContainsString('west', $exception->getMessage());
    }

    /**
     * The two reasons are not interchangeable: the same scene, two different
     * choice keys, two different reasons.
     */
    public function testTheTwoUnavailableReasonsAreNotTheSame(): void
    {
        $story = [
            [
                'key' => 'a',
                'choices' => [['key' => 'north', 'condition' => ['op' => 'flag', 'flag' => 'brave']]],
                'next' => 'a',
            ],
        ];

        $locked = $this->expectChoiceUnavailable(
            fn (): TransitionResult => $this->transition($story)->run($this->state(), 'a', 'north', 1),
        );
        $missing = $this->expectChoiceUnavailable(
            fn (): TransitionResult => $this->transition($story)->run($this->state(), 'a', 'west', 1),
        );

        self::assertNotSame($locked->reason, $missing->reason);
    }

    /**
     * @param Closure(): TransitionResult $run
     */
    private function expectChoiceUnavailable(Closure $run): ChoiceUnavailableException
    {
        try {
            $run();
        } catch (ChoiceUnavailableException $exception) {
            return $exception;
        }

        self::fail('Expected a ChoiceUnavailableException.');
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
     * **A `goto` to the scene currently being entered is a cycle, and the
     * Engine does not look for cycles — the budget is what ends it.**
     *
     * Both shapes below would run forever without the counter: a scene whose
     * `on_enter` sends the player back to itself, and a two-scene ping-pong.
     * Neither is diagnosed as a loop; each simply spends the five redirects
     * and is refused on the sixth, with the same message a straight chain of
     * six hops produces. That is D-REDIRECT working as designed, and it is
     * worth pinning because "no cycle detection" is otherwise only a sentence
     * in a docblock.
     */
    public function testAGotoBackIntoTheSceneBeingEnteredIsStoppedByTheBudgetNotByCycleDetection(): void
    {
        $selfLoop = $this->transition([
            ['key' => 'a', 'choices' => [['key' => 'go']], 'next' => 'b'],
            ['key' => 'b', 'on_enter' => [['op' => 'goto', 'scene' => 'b']]],
        ]);

        $pingPong = $this->transition([
            ['key' => 'a', 'choices' => [['key' => 'go']], 'next' => 'b'],
            ['key' => 'b', 'on_enter' => [['op' => 'goto', 'scene' => 'c']]],
            ['key' => 'c', 'on_enter' => [['op' => 'goto', 'scene' => 'b']]],
        ]);

        $messages = [];

        foreach (['self' => $selfLoop, 'ping-pong' => $pingPong] as $label => $transition) {
            try {
                $transition->run($this->state(), 'a', 'go', 1);
                self::fail(sprintf('The %s cycle did not terminate.', $label));
            } catch (RedirectLoopException $exception) {
                $messages[$label] = $exception->getMessage();
            }
        }

        self::assertSame(
            'Redirect budget of 5 exceeded; the last scene reached was "b".',
            $messages['self'],
        );
        // The sixth hop of b -> c -> b -> c -> b -> c is back to `b`, and `b`
        // is what the message names — the scene the turn was *about to* enter
        // when it was refused, which for this cycle happens to also be a scene
        // it did enter. The acyclic case below shows the difference: `h6` was
        // never reached at all (review m3).
        self::assertSame(
            'Redirect budget of 5 exceeded; the last scene reached was "b".',
            $messages['ping-pong'],
        );

        // The same message a six-hop acyclic chain produces: the counter, not
        // the repetition, is what refused the turn.
        try {
            $this->transition(self::redirectChain(6))->run($this->state(), 'a', 'go', 1);
            self::fail('A six-hop chain did not throw.');
        } catch (RedirectLoopException $exception) {
            self::assertSame(
                'Redirect budget of 5 exceeded; the last scene reached was "h6".',
                $exception->getMessage(),
            );
        }
    }

    /**
     * `visited` across four turns, with a scene that is redirected *through*
     * every single time.
     *
     * One turn already pins that a transited scene is not entered; the point
     * here is that the rule does not drift with repetition — `hall` is still
     * at zero after being passed through four times, while `cave` counts every
     * rest, `history` grows by exactly one entry per turn, and the `visited`
     * operator answers from the same numbers on the way past.
     */
    public function testVisitedCountsAcrossSeveralTurnsAndNeverCountsASceneRedirectedThrough(): void
    {
        $story = [
            ['key' => 'start', 'choices' => [['key' => 'go']], 'next' => 'hall'],
            [
                'key' => 'hall',
                'on_enter' => [['op' => 'goto', 'scene' => 'cave']],
            ],
            [
                'key' => 'cave',
                'choices' => [
                    ['key' => 'again'],
                    ['key' => 'deeper', 'condition' => ['op' => 'visited', 'scene' => 'cave', 'times' => 3]],
                    ['key' => 'through_hall', 'condition' => ['op' => 'visited', 'scene' => 'hall']],
                ],
                'next' => 'hall',
            ],
        ];

        $transition = $this->transition($story);
        $result = $transition->run($this->state(), 'start', 'go', 1);

        self::assertSame('cave', $result->sceneKey);
        self::assertSame(1, $result->state->visitCount('cave'));

        for ($turn = 2; $turn <= 4; ++$turn) {
            $result = $transition->run($result->state, 'cave', 'again', $turn);

            self::assertSame('cave', $result->sceneKey);
            self::assertSame($turn, $result->state->visitCount('cave'));
            self::assertSame(0, $result->state->visitCount('hall'));
            self::assertSame(1, self::sceneEntered($result)['redirects']);
        }

        self::assertSame(['cave', 'cave', 'cave', 'cave'], $result->state->toArray()['history']);
        self::assertSame(['cave' => 4], $result->state->toArray()['visited']);

        // The counts the operator reads are the same ones: a choice gated on
        // three visits to `cave` is now open ...
        $deeper = $transition->run($result->state, 'cave', 'deeper', 5);

        self::assertSame('cave', $deeper->sceneKey);

        // ... and one gated on a single visit to `hall` is still shut, after
        // four passes through it.
        $this->expectException(ChoiceUnavailableException::class);
        $this->expectExceptionMessage('through_hall');

        $transition->run($result->state, 'cave', 'through_hall', 6);
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

    /**
     * Scene and choice keys are authored identifiers and the Engine puts no
     * character class on them, so a story written in Japanese or Russian
     * addresses its graph in Japanese or Russian. Pinned end to end — lookup,
     * `next` resolution, visit, history and the event payloads — because the
     * keys travel into JSONB and back out again.
     */
    public function testSceneAndChoiceKeysMayBeUnicode(): void
    {
        $result = $this->transition([
            [
                'key' => '交差点',
                'choices' => [['key' => '北へ', 'effects' => [['op' => 'set_flag', 'flag' => 'пошёл']]]],
                'next' => [['scene' => '洞窟', 'if' => ['op' => 'flag', 'flag' => 'пошёл']]],
            ],
            ['key' => '洞窟'],
        ])->run($this->state(), '交差点', '北へ', 1);

        self::assertSame('洞窟', $result->sceneKey);
        self::assertSame(['洞窟' => 1], $result->state->toArray()['visited']);
        self::assertSame(['洞窟'], $result->state->toArray()['history']);
        self::assertSame(
            ['event' => 'choice_made', 'scene' => '交差点', 'choice' => '北へ', 'turn' => 1],
            $result->toArray()['events'][0],
        );

        $json = json_encode($result->toArray(), JSON_THROW_ON_ERROR);

        // `json_encode` escapes every non-ASCII byte by default, so the whole
        // payload is printable ASCII and survives any transport.
        self::assertMatchesRegularExpression('/^[\x20-\x7e]+$/', $json);
        self::assertSame($result->toArray(), json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(
            $result->state->toArray(),
            GameState::fromArray(json_decode(
                json_encode($result->state->toArray(), JSON_THROW_ON_ERROR),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ))->toArray(),
        );
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
     * M1: a story's opening scene is entered without a choice having been
     * made, so `run()` cannot reach it and its `on_enter` never ran. The
     * effects really apply, the scene is really visited and really appears in
     * `history` — and no `ChoiceMade` is emitted, because no choice was made.
     */
    public function testBeginRunsTheOpeningScenesOnEnterAndMarksItVisited(): void
    {
        $result = Transition::begin(
            InMemoryStoryGraph::fromArray([
                ['key' => 'start', 'on_enter' => [['op' => 'sub', 'var' => 'hp', 'value' => 5], ['op' => 'set_flag', 'flag' => 'woke_up']]],
            ]),
            self::schema(),
            $this->state(),
            'start',
            1,
        );

        self::assertSame('start', $result->sceneKey);
        self::assertFalse($result->isEnding);
        self::assertSame(['effects_applied', 'scene_entered'], self::names($result));
        self::assertSame(
            [
                [
                    'event' => 'effects_applied',
                    'scene' => 'start',
                    'phase' => 'on_enter',
                    'diff' => ['vars' => ['hp' => ['from' => 95, 'to' => 90]], 'flags' => ['added' => ['woke_up']]],
                    'turn' => 1,
                ],
                ['event' => 'scene_entered', 'scene' => 'start', 'redirects' => 0, 'turn' => 1],
            ],
            $result->toArray()['events'],
        );
        self::assertSame(['start' => 1], $result->state->toArray()['visited']);
        self::assertSame(['start'], $result->state->toArray()['history']);
    }

    /**
     * An opening scene with nothing in its `on_enter` is still entered: the
     * diff is empty, so no `EffectsApplied`, but the visit and `SceneEntered`
     * happen exactly as they do for a scene that does something.
     */
    public function testBeginOnASceneWithNoOnEnterStillEntersIt(): void
    {
        $result = Transition::begin(
            InMemoryStoryGraph::fromArray([['key' => 'start']]),
            self::schema(),
            $this->state(),
            'start',
            1,
        );

        self::assertSame(['scene_entered'], self::names($result));
        self::assertSame(['start' => 1], $result->state->toArray()['visited']);
    }

    /**
     * A `goto` in the opening scene's `on_enter` is allowed and behaves as it
     * does mid-story: the scene it leaves was transited, not entered, so it
     * appears in neither `visited` nor `history` — D-VISIT, reached through
     * `begin()` rather than through `run()`.
     */
    public function testBeginFollowsAGotoOutOfTheOpeningScenesOnEnter(): void
    {
        $result = Transition::begin(
            InMemoryStoryGraph::fromArray([
                ['key' => 'start', 'on_enter' => [['op' => 'set_flag', 'flag' => 'passed'], ['op' => 'goto', 'scene' => 'cave']]],
                ['key' => 'cave'],
            ]),
            self::schema(),
            $this->state(),
            'start',
            1,
        );

        self::assertSame('cave', $result->sceneKey);
        self::assertTrue($result->state->hasFlag('passed'));
        self::assertSame(['cave' => 1], $result->state->toArray()['visited']);
        self::assertSame(['cave'], $result->state->toArray()['history']);
        self::assertSame(1, self::sceneEntered($result)['redirects']);
    }

    /**
     * The same budget, the same counter, the same exception: five redirects
     * out of an opening scene settle, the sixth is refused.
     */
    public function testBeginSpendsTheSameRedirectBudgetAsARun(): void
    {
        $five = Transition::begin(
            InMemoryStoryGraph::fromArray(self::redirectChain(5)),
            self::schema(),
            $this->state(),
            'h0',
            1,
        );

        self::assertSame('h5', $five->sceneKey);
        self::assertSame(5, self::sceneEntered($five)['redirects']);

        $this->expectException(RedirectLoopException::class);
        $this->expectExceptionMessage('h6');

        Transition::begin(
            InMemoryStoryGraph::fromArray(self::redirectChain(6)),
            self::schema(),
            $this->state(),
            'h0',
            1,
        );
    }

    public function testBeginThrowsForAnUnknownOpeningScene(): void
    {
        $this->expectException(UnknownSceneException::class);
        $this->expectExceptionMessage('nowhere');

        Transition::begin(
            InMemoryStoryGraph::fromArray([['key' => 'start']]),
            self::schema(),
            $this->state(),
            'nowhere',
            1,
        );
    }

    /**
     * A one-scene story is a legal story, and `is_ending` comes from the graph
     * in `begin()` exactly as it does in `run()`.
     */
    public function testBeginReportsAnEndingOpeningScene(): void
    {
        $result = Transition::begin(
            InMemoryStoryGraph::fromArray([['key' => 'the_end']], ['the_end']),
            self::schema(),
            $this->state(),
            'the_end',
            3,
        );

        self::assertTrue($result->isEnding);
        self::assertSame(['scene_entered', 'ending_reached'], self::names($result));
        self::assertSame(['event' => 'ending_reached', 'scene' => 'the_end', 'turn' => 3], $result->toArray()['events'][1]);
    }

    /**
     * **The reason `begin()` is a factoring and not a second implementation.**
     *
     * Entering `x` through a choice and entering `x` through `begin()` must
     * produce the same state and the same entry events — the redirect chain,
     * the diff, the visit, the history entry, the ending flag. The only
     * difference permitted is the `ChoiceMade` the turn emits and the entry
     * does not, so the comparison is the run's event list with its first
     * element dropped.
     *
     * If the two ever diverge — someone reorders the visit against `on_enter`
     * in one path only, or forgets `EndingReached` in the other — this fails.
     */
    public function testBeginAndTheOnEnterHalfOfRunAgreeExceptForTheChoiceMade(): void
    {
        $scenes = [
            ['key' => 'lobby', 'choices' => [['key' => 'go']], 'next' => 'x'],
            ['key' => 'x', 'on_enter' => [['op' => 'sub', 'var' => 'hp', 'value' => 1], ['op' => 'goto', 'scene' => 'the_end']]],
            ['key' => 'the_end', 'on_enter' => [['op' => 'set_flag', 'flag' => 'done']]],
        ];

        $ran = $this->transition($scenes, ['the_end'])->run($this->state(), 'lobby', 'go', 4);
        $begun = Transition::begin(
            InMemoryStoryGraph::fromArray($scenes, ['the_end']),
            self::schema(),
            $this->state(),
            'x',
            4,
        );

        self::assertSame($ran->state->toArray(), $begun->state->toArray());
        self::assertSame($ran->sceneKey, $begun->sceneKey);
        self::assertSame($ran->isEnding, $begun->isEnding);
        self::assertSame('choice_made', $ran->events[0]->name());
        self::assertSame(
            array_slice($ran->toArray()['events'], 1),
            $begun->toArray()['events'],
        );
    }

    /**
     * @param  list<array<array-key, mixed>> $scenes
     * @param  list<string>                  $endings
     */
    private function transition(array $scenes, array $endings = []): Transition
    {
        $evaluator = new ConditionEvaluator(self::schema());

        return new Transition(
            InMemoryStoryGraph::fromArray($scenes, $endings),
            $evaluator,
            new EffectApplier(self::schema()),
            new NextSceneResolver($evaluator),
        );
    }

    private static function schema(): VariableSchema
    {
        return VariableSchema::of([new VariableDefinition('hp', VariableType::Int, 100)]);
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
