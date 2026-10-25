<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\Ast\Condition;
use Storyteller\Engine\ConditionEvaluator;
use Storyteller\Engine\EffectApplier;
use Storyteller\Engine\NextSceneResolver;
use Storyteller\Engine\State\GameState;
use Storyteller\Engine\State\VariableDefinition;
use Storyteller\Engine\State\VariableSchema;
use Storyteller\Engine\State\VariableType;
use Storyteller\Engine\Transition;
use Storyteller\Engine\TransitionResult;
use Storyteller\Tests\Unit\Engine\Double\InMemoryStoryGraph;

/**
 * The determinism contract, asserted rather than asserted *about*.
 *
 * "Identical" is pinned at four strengths on purpose, because each one lets a
 * different bug through on its own:
 *
 *  - `assertNotSame` first, so the comparison cannot be satisfied by identity;
 *  - `assertEquals` — deep object-graph equality, the event list included;
 *  - `assertSame` on `toArray()` — key-order-sensitive, which `assertEquals`
 *    is not, so this is what pins the canonical ordering;
 *  - `json_encode` byte equality — the form that actually reaches JSONB.
 *
 * Every case also asserts something non-vacuous about the *content* (the event
 * name sequence, the diff payloads, the sorted flag list), so a run that
 * produced nothing at all, or two runs that are wrong in the same way, cannot
 * pass.
 */
#[CoversClass(Transition::class)]
#[CoversClass(TransitionResult::class)]
#[CoversClass(ConditionEvaluator::class)]
#[CoversClass(GameState::class)]
final class DeterminismTest extends TestCase
{
    /**
     * A turn that exercises every event: a choice with effects, an `on_enter`
     * that both mutates and redirects, a second `on_enter` after the redirect,
     * and an ending.
     *
     * @var list<array<array-key, mixed>>
     */
    private const array BRANCHING_STORY = [
        [
            'key' => 'start',
            'choices' => [[
                'key' => 'go',
                'effects' => [
                    ['op' => 'set_flag', 'flag' => 'lit'],
                    ['op' => 'add', 'var' => 'gold', 'value' => 3],
                    ['op' => 'give_item', 'item' => 'rope', 'qty' => 2],
                ],
            ]],
            'next' => [
                ['scene' => 'hall', 'if' => ['op' => 'flag', 'flag' => 'lit']],
                ['scene' => 'elsewhere'],
            ],
        ],
        [
            'key' => 'hall',
            'on_enter' => [
                ['op' => 'set_flag', 'flag' => 'passed_hall'],
                ['op' => 'goto', 'scene' => 'vault'],
            ],
        ],
        [
            'key' => 'vault',
            'on_enter' => [
                ['op' => 'give_item', 'item' => 'gem'],
                ['op' => 'sub', 'var' => 'gold', 'value' => 1],
            ],
        ],
        ['key' => 'elsewhere'],
    ];

    /**
     * Two choices carrying the same multiset of commutative effects in a
     * different order, converging on the same scene.
     *
     * @var list<array<array-key, mixed>>
     */
    private const array COMMUTATIVE_STORY = [
        [
            'key' => 'start',
            'choices' => [
                [
                    'key' => 'a',
                    'effects' => [
                        ['op' => 'set_flag', 'flag' => 'wise'],
                        ['op' => 'give_item', 'item' => 'rope', 'qty' => 1],
                        ['op' => 'add', 'var' => 'gold', 'value' => 5],
                        ['op' => 'set_flag', 'flag' => 'brave'],
                        ['op' => 'give_item', 'item' => 'torch', 'qty' => 2],
                    ],
                ],
                [
                    'key' => 'b',
                    'effects' => [
                        ['op' => 'give_item', 'item' => 'torch', 'qty' => 2],
                        ['op' => 'set_flag', 'flag' => 'brave'],
                        ['op' => 'add', 'var' => 'gold', 'value' => 5],
                        ['op' => 'give_item', 'item' => 'rope', 'qty' => 1],
                        ['op' => 'set_flag', 'flag' => 'wise'],
                    ],
                ],
            ],
            'next' => 'hub',
        ],
        ['key' => 'hub'],
    ];

    /**
     * Same inputs, two runs, nothing shared by identity: two story graphs,
     * two schemas, two evaluators, two appliers, two resolvers and two
     * `GameState` instances, all built independently from the same literals.
     */
    public function testIdenticalInputsProduceIdenticalTransitionResults(): void
    {
        $first = $this->transition(self::BRANCHING_STORY, ['vault']);
        $second = $this->transition(self::BRANCHING_STORY, ['vault']);

        $stateA = $this->state();
        $stateB = $this->state();

        self::assertNotSame($first, $second);
        self::assertNotSame($stateA, $stateB);

        $a = $first->run($stateA, 'start', 'go', 7);
        $b = $second->run($stateB, 'start', 'go', 7);

        self::assertNotSame($a, $b);
        self::assertEquals($a, $b);
        self::assertSame($a->toArray(), $b->toArray());
        self::assertSame(
            json_encode($a->toArray(), JSON_THROW_ON_ERROR),
            json_encode($b->toArray(), JSON_THROW_ON_ERROR),
        );

        // Non-vacuous: the turn really did produce the full event sequence, in
        // this order, and the two EffectsApplied diffs really do carry payloads.
        self::assertSame(
            [
                'choice_made',
                'effects_applied',
                'effects_applied',
                'effects_applied',
                'scene_entered',
                'ending_reached',
            ],
            self::eventNames($a),
        );
        self::assertSame(self::eventNames($a), self::eventNames($b));

        $diffs = self::effectDiffs($a);

        self::assertSame(
            [
                [
                    'vars' => ['gold' => ['from' => 10, 'to' => 13]],
                    'flags' => ['added' => ['lit']],
                    'inventory' => ['rope' => ['from' => 0, 'to' => 2]],
                ],
                ['flags' => ['added' => ['passed_hall']]],
                ['vars' => ['gold' => ['from' => 13, 'to' => 12]], 'inventory' => ['gem' => ['from' => 0, 'to' => 1]]],
            ],
            $diffs,
        );
        self::assertSame($diffs, self::effectDiffs($b));
    }

    /**
     * The same multiset of commutative effects applied in two different orders
     * reaches one canonical state. `assertSame` on `toArray()` is the assertion
     * the key-sorting and flag-sorting exist for: it fails the moment either is
     * dropped.
     */
    public function testDifferentEffectOrderingsReachAnIdenticalState(): void
    {
        $ra = $this->transition(self::COMMUTATIVE_STORY)->run($this->state(), 'start', 'a', 3);
        $rb = $this->transition(self::COMMUTATIVE_STORY)->run($this->state(), 'start', 'b', 3);

        self::assertSame('hub', $ra->sceneKey);
        self::assertSame('hub', $rb->sceneKey);

        self::assertNotSame($ra->state, $rb->state);
        self::assertEquals($ra->state, $rb->state);
        self::assertSame($ra->state->toArray(), $rb->state->toArray());
        self::assertSame(
            json_encode($ra->state->toArray(), JSON_THROW_ON_ERROR),
            json_encode($rb->state->toArray(), JSON_THROW_ON_ERROR),
        );

        // Pins the rule, not merely the agreement: sorted and de-duplicated.
        self::assertSame(['brave', 'wise'], $ra->state->toArray()['flags']);
        self::assertSame(
            ['rope' => 1, 'torch' => 2],
            $ra->state->toArray()['inventory'],
        );
        self::assertSame(['gold' => 15], $ra->state->toArray()['vars']);
    }

    /**
     * The caller keeps its own object, byte for byte. A run reads the state it
     * is given and returns a new one; running twice from the very same
     * instance therefore yields the very same result.
     */
    public function testTheInputStateIsNotMutatedByARun(): void
    {
        $state = $this->state();
        $before = $state->toArray();
        $beforeJson = json_encode($before, JSON_THROW_ON_ERROR);

        $first = $this->transition(self::BRANCHING_STORY, ['vault'])->run($state, 'start', 'go', 7);

        self::assertSame($before, $state->toArray());
        self::assertSame($beforeJson, json_encode($state->toArray(), JSON_THROW_ON_ERROR));

        // The run produced a *different* object with a *different* value, so
        // the assertion above is not satisfied by the run having done nothing.
        self::assertNotSame($state, $first->state);
        self::assertNotSame($state->toArray(), $first->state->toArray());

        // The same instance, handed to a second, independently built engine.
        $second = $this->transition(self::BRANCHING_STORY, ['vault'])->run($state, 'start', 'go', 7);

        self::assertSame($before, $state->toArray());
        self::assertSame($first->toArray(), $second->toArray());
    }

    /**
     * Evaluation leaves nothing behind: a condition's answer depends on the
     * state it is handed, never on what was evaluated before it. Asserted by
     * walking the same conditions forwards, backwards and forwards again
     * through one evaluator, then through a freshly built one.
     */
    public function testEvaluatingAConditionDoesNotDependOnEarlierEvaluations(): void
    {
        $schema = self::schema();
        $evaluator = new ConditionEvaluator($schema);
        $state = GameState::create(['gold' => 10], ['lit'], ['rope' => 2], ['hall' => 1], ['hall']);

        $conditions = array_map(
            static fn (array $node): Condition => Condition::fromArray($node),
            [
                ['op' => 'flag', 'flag' => 'lit'],
                ['op' => 'not_flag', 'flag' => 'lit'],
                ['op' => 'gt', 'var' => 'gold', 'value' => 5],
                ['op' => 'has_item', 'item' => 'rope', 'qty' => 3],
                ['op' => 'visited', 'scene' => 'hall'],
                ['op' => 'and', 'of' => [
                    ['op' => 'flag', 'flag' => 'lit'],
                    ['op' => 'not', 'of' => ['op' => 'eq', 'var' => 'gold', 'value' => 10]],
                ]],
            ],
        );

        $forwards = array_map(
            static fn (Condition $c): bool => $evaluator->evaluate($c, $state),
            $conditions,
        );

        $backwards = array_map(
            static fn (Condition $c): bool => $evaluator->evaluate($c, $state),
            array_reverse($conditions),
        );

        $forwardsAgain = array_map(
            static fn (Condition $c): bool => $evaluator->evaluate($c, $state),
            $conditions,
        );

        $freshEvaluator = new ConditionEvaluator(self::schema());
        $fromScratch = array_map(
            static fn (Condition $c): bool => $freshEvaluator->evaluate($c, $state),
            $conditions,
        );

        self::assertSame([true, false, true, false, true, false], $forwards);
        self::assertSame($forwards, $forwardsAgain);
        self::assertSame($forwards, array_reverse($backwards));
        self::assertSame($forwards, $fromScratch);

        // The state the conditions were read from is itself untouched.
        self::assertSame(
            GameState::create(['gold' => 10], ['lit'], ['rope' => 2], ['hall' => 1], ['hall'])->toArray(),
            $state->toArray(),
        );
    }

    /**
     * @param  list<array<array-key, mixed>> $scenes
     * @param  list<string>                  $endings
     */
    private function transition(array $scenes, array $endings = []): Transition
    {
        $schema = self::schema();
        $evaluator = new ConditionEvaluator($schema);

        return new Transition(
            InMemoryStoryGraph::fromArray($scenes, $endings),
            $evaluator,
            new EffectApplier($schema),
            new NextSceneResolver($evaluator),
        );
    }

    private static function schema(): VariableSchema
    {
        return VariableSchema::of([new VariableDefinition('gold', VariableType::Int, 0)]);
    }

    private function state(): GameState
    {
        return GameState::create(['gold' => 10], [], [], [], []);
    }

    /**
     * @return list<string>
     */
    private static function eventNames(TransitionResult $result): array
    {
        return array_map(static fn (object $event): string => $event->name(), $result->events);
    }

    /**
     * Every `EffectsApplied` payload, in emission order.
     *
     * @return list<mixed>
     */
    private static function effectDiffs(TransitionResult $result): array
    {
        $diffs = [];

        foreach ($result->toArray()['events'] as $event) {
            if ($event['event'] === 'effects_applied') {
                $diffs[] = $event['diff'];
            }
        }

        return $diffs;
    }
}
