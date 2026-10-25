<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\ConditionEvaluator;
use Storyteller\Engine\EffectApplier;
use Storyteller\Engine\Event\EngineEvent;
use Storyteller\Engine\Exception\ChoiceUnavailableException;
use Storyteller\Engine\Exception\ChoiceUnavailableReason;
use Storyteller\Engine\NextSceneResolver;
use Storyteller\Engine\State\GameState;
use Storyteller\Engine\State\VariableDefinition;
use Storyteller\Engine\State\VariableSchema;
use Storyteller\Engine\State\VariableType;
use Storyteller\Engine\Transition;
use Storyteller\Engine\TransitionResult;
use Storyteller\Tests\Unit\Engine\Double\InMemoryStoryGraph;

/**
 * What the Engine does and does not promise when two turns race on one
 * session.
 *
 * The Engine has no session, no lock and no turn cursor: `run()` is a pure
 * function of `(state, scene, choice, turn)`. That is a strong property — a
 * retry is free, and two submissions cannot corrupt each other — and a sharp
 * edge: **nothing here de-duplicates a replayed choice**, because nothing here
 * can tell a retry from a second, identical move the player really made.
 *
 * Each test below says which half it is pinning, so `src/Play` can read this
 * file as the list of concurrency obligations it inherits rather than
 * discovering them against a database.
 */
#[CoversClass(Transition::class)]
#[CoversClass(TransitionResult::class)]
final class SessionConcurrencyTest extends TestCase
{
    /**
     * A hub with two mutually exclusive moves and a gate that one of them
     * closes, which is what makes a stale replay observable.
     *
     * @var list<array<array-key, mixed>>
     */
    private const array STORY = [
        [
            'key' => 'hub',
            'choices' => [
                [
                    'key' => 'spend',
                    'condition' => ['op' => 'gte', 'var' => 'gold', 'value' => 10],
                    'effects' => [
                        ['op' => 'sub', 'var' => 'gold', 'value' => 10],
                        ['op' => 'give_item', 'item' => 'lantern'],
                    ],
                ],
                [
                    'key' => 'rest',
                    'effects' => [['op' => 'add', 'var' => 'gold', 'value' => 1]],
                ],
                [
                    'key' => 'forage',
                    'effects' => [
                        ['op' => 'give_item', 'item' => 'berry', 'qty' => 2],
                        ['op' => 'sub', 'var' => 'gold', 'value' => 1],
                    ],
                ],
            ],
            'next' => 'hub',
        ],
    ];

    /**
     * **The Engine's half: two submissions against one state are independent.**
     *
     * Both are computed from the same input and neither can see the other,
     * because neither mutates anything shared. Which of the two is kept — and
     * whether the loser is replayed, rejected or merged — is entirely
     * `src/Play`'s decision, and the Engine gives it two complete candidate
     * states to choose between rather than a half-applied one.
     */
    public function testTwoChoicesSubmittedAgainstTheSameStateAreComputedIndependently(): void
    {
        $base = $this->state();
        $snapshot = $base->toArray();
        $transition = $this->transition();

        $spend = $transition->run($base, 'hub', 'spend', 4);
        $rest = $transition->run($base, 'hub', 'rest', 4);

        // Neither run touched the state they share.
        self::assertSame($snapshot, $base->toArray());

        // Each is the base plus its own effects, and only its own.
        self::assertSame(2, $spend->state->var('gold'));
        self::assertSame(1, $spend->state->itemQty('lantern'));
        self::assertSame(13, $rest->state->var('gold'));
        self::assertSame(0, $rest->state->itemQty('lantern'));

        // Both are complete turns, not partial ones: each recorded its visit.
        self::assertSame(1, $spend->state->visitCount('hub'));
        self::assertSame(1, $rest->state->visitCount('hub'));
        self::assertNotSame($spend->state->toArray(), $rest->state->toArray());
    }

    /**
     * **The property a retry rests on: the same turn computed twice is the
     * same bytes**, event payloads included. No clock, no id, no counter is
     * mixed in, so `Play` may safely re-run a turn whose write it could not
     * confirm.
     */
    public function testRerunningTheSameTurnAgainstTheSameStateIsByteIdentical(): void
    {
        $base = $this->state();

        $first = $this->transition()->run($base, 'hub', 'spend', 4);
        $second = $this->transition()->run($base, 'hub', 'spend', 4);

        self::assertNotSame($first, $second);
        self::assertSame(
            json_encode($first->toArray(), JSON_THROW_ON_ERROR),
            json_encode($second->toArray(), JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            ['choice_made', 'effects_applied', 'scene_entered'],
            self::names($first),
        );
    }

    /**
     * **The obligation `Play` inherits: replay is not idempotent.**
     *
     * Re-submitting an ungated choice against the state it already produced
     * applies it a *second* time — two more berries, another gold spent, a
     * second visit, a second full set of events, under the very same turn
     * number. That is correct for a pure function and wrong for a session, so
     * de-duplication has to live where the turn number and the event log do:
     * in `src/Play`.
     *
     * The turn number does not help: it is carried into the events verbatim
     * and never compared with anything.
     */
    public function testReplayingAnAcceptedChoiceAgainstItsOwnResultAppliesItTwice(): void
    {
        $transition = $this->transition();

        $first = $transition->run($this->state(), 'hub', 'forage', 4);
        $replay = $transition->run($first->state, 'hub', 'forage', 4);

        self::assertSame(11, $first->state->var('gold'));
        self::assertSame(2, $first->state->itemQty('berry'));
        self::assertSame(1, $first->state->visitCount('hub'));

        self::assertSame(10, $replay->state->var('gold'));
        self::assertSame(4, $replay->state->itemQty('berry'));
        self::assertSame(2, $replay->state->visitCount('hub'));
        self::assertSame(['hub', 'hub'], $replay->state->toArray()['history']);

        // The same turn number, accepted without a murmur.
        self::assertSame(
            ['event' => 'choice_made', 'scene' => 'hub', 'choice' => 'forage', 'turn' => 4],
            $replay->toArray()['events'][0],
        );
    }

    /**
     * The one case the Engine *does* refuse: a stale choice whose guard the
     * first turn closed. `gold` is 2 after one `spend`, so the `gte 10` gate
     * fails and the replay is a `ConditionFailed` — the state conflict a
     * caller should answer 409 to, told apart from "no such choice" by type.
     */
    public function testAStaleChoiceWhoseGuardTheFirstTurnClosedIsRefused(): void
    {
        $transition = $this->transition();
        $first = $transition->run($this->state(), 'hub', 'spend', 4);

        try {
            $transition->run($first->state, 'hub', 'spend', 5);
            self::fail('The stale choice was accepted.');
        } catch (ChoiceUnavailableException $exception) {
            self::assertSame(ChoiceUnavailableReason::ConditionFailed, $exception->reason);
        }

        // And the refusal changed nothing: the state the caller still holds is
        // exactly what the first turn produced.
        self::assertSame(2, $first->state->var('gold'));
        self::assertSame(1, $first->state->itemQty('lantern'));
    }

    /**
     * **Event-log ordering is positional and nothing more.** The list order is
     * the only ordering the Engine provides: no event carries a sequence
     * number, an id or a timestamp, and two different turns given the same
     * turn number produce indistinguishable payloads.
     *
     * So an append-only log that preserves insertion order is sufficient, and
     * a log that re-sorts by any field in the payload is not. `Play` owns the
     * sequence.
     */
    public function testTheEventLogCarriesNoOrderingKeyOfItsOwn(): void
    {
        $transition = $this->transition();

        $one = $transition->run($this->state(), 'hub', 'rest', 9);
        $two = $transition->run($one->state, 'hub', 'rest', 9);

        $firstPayloads = $one->toArray()['events'];
        $secondPayloads = $two->toArray()['events'];

        // Two different turns of one session, identical on the wire apart from
        // the diff that distinguishes their arithmetic.
        self::assertSame(self::names($one), self::names($two));
        self::assertSame($firstPayloads[0], $secondPayloads[0]);
        self::assertSame($firstPayloads[2], $secondPayloads[2]);
        self::assertNotSame($firstPayloads[1], $secondPayloads[1]);

        foreach ([...$firstPayloads, ...$secondPayloads] as $payload) {
            self::assertArrayNotHasKey('id', $payload);
            self::assertArrayNotHasKey('seq', $payload);
            self::assertArrayNotHasKey('at', $payload);
            self::assertArrayNotHasKey('timestamp', $payload);
        }

        // The order within a turn is the documented one, and it is the list's
        // own order — `scene_entered` last, after both effect phases.
        self::assertSame(['choice_made', 'effects_applied', 'scene_entered'], self::names($two));
    }

    private function transition(): Transition
    {
        $schema = VariableSchema::of([new VariableDefinition('gold', VariableType::Int, 0)]);
        $evaluator = new ConditionEvaluator($schema);

        return new Transition(
            InMemoryStoryGraph::fromArray(self::STORY),
            $evaluator,
            new EffectApplier($schema),
            new NextSceneResolver($evaluator),
        );
    }

    private function state(): GameState
    {
        return GameState::create(['gold' => 12], [], [], [], []);
    }

    /**
     * @return list<string>
     */
    private static function names(TransitionResult $result): array
    {
        return array_map(static fn (EngineEvent $event): string => $event->name(), $result->events);
    }
}
