<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\Ast\Condition;
use Storyteller\Engine\ConditionEvaluator;
use Storyteller\Engine\EffectApplier;
use Storyteller\Engine\Exception\MalformedNodeException;
use Storyteller\Engine\Exception\UnknownVariableException;
use Storyteller\Engine\NextSceneResolver;
use Storyteller\Engine\State\GameState;
use Storyteller\Engine\State\VariableDefinition;
use Storyteller\Engine\State\VariableSchema;
use Storyteller\Engine\State\VariableType;
use Storyteller\Engine\Transition;
use Storyteller\Tests\Unit\Engine\Double\InMemoryStoryGraph;

/**
 * Playing a session that is pinned to an older published version.
 *
 * The Engine has no notion of a version: a `StoryGraph` and a
 * `VariableSchema` are handed to it per turn, so "pinned to v1" means nothing
 * more than "constructed with v1's graph and v1's schema". These tests pin
 * that this really is all there is — that no path reaches for a newest
 * anything — and that a v1 state survives being read by v2 code in the three
 * ways a published story actually changes: a rule is rewritten, a variable is
 * dropped, and a scene is removed.
 *
 * The one hard boundary is the state envelope: `v: 1` is written literally and
 * an envelope from a future build is refused rather than guessed at.
 */
#[CoversClass(Transition::class)]
#[CoversClass(GameState::class)]
final class ContentVersioningTest extends TestCase
{
    /**
     * v1: the crossroads leads to the cave whenever the player has a lantern,
     * and `luck` is a declared variable.
     *
     * @var list<array<array-key, mixed>>
     */
    private const array STORY_V1 = [
        [
            'key' => 'crossroads',
            'choices' => [['key' => 'north']],
            'next' => [
                ['scene' => 'cave', 'if' => ['op' => 'has_item', 'item' => 'lantern']],
                ['scene' => 'meadow'],
            ],
        ],
        ['key' => 'cave', 'on_enter' => [['op' => 'set_flag', 'flag' => 'in_cave']]],
        ['key' => 'meadow'],
    ];

    /**
     * v2 of the same story: the cave now also needs a rope, a new scene is
     * published, and `luck` has been retired.
     *
     * @var list<array<array-key, mixed>>
     */
    private const array STORY_V2 = [
        [
            'key' => 'crossroads',
            'choices' => [['key' => 'north']],
            'next' => [
                [
                    'scene' => 'cave',
                    'if' => ['op' => 'and', 'of' => [
                        ['op' => 'has_item', 'item' => 'lantern'],
                        ['op' => 'has_item', 'item' => 'rope'],
                    ]],
                ],
                ['scene' => 'ravine'],
            ],
        ],
        ['key' => 'cave', 'on_enter' => [['op' => 'set_flag', 'flag' => 'in_cave']]],
        ['key' => 'ravine'],
        ['key' => 'meadow'],
    ];

    /**
     * **The whole of version pinning: the injected graph decides, every
     * time.** The same state and the same choice reach different scenes under
     * v1 and v2, run in the same process, in both orders — so nothing is
     * cached, and nothing consults a "current" version.
     */
    public function testTheSameTurnFollowsWhicheverVersionItWasHandedAndNoOther(): void
    {
        $state = GameState::create([], [], ['lantern' => 1], [], []);

        $onV1 = $this->transition(self::STORY_V1)->run($state, 'crossroads', 'north', 1);
        $onV2 = $this->transition(self::STORY_V2)->run($state, 'crossroads', 'north', 1);
        $onV1Again = $this->transition(self::STORY_V1)->run($state, 'crossroads', 'north', 1);

        self::assertSame('cave', $onV1->sceneKey);
        self::assertTrue($onV1->state->hasFlag('in_cave'));

        // v2 wants a rope the v1 session never had a reason to pick up.
        self::assertSame('ravine', $onV2->sceneKey);
        self::assertFalse($onV2->state->hasFlag('in_cave'));

        // The v2 run left nothing behind that could change the v1 answer.
        self::assertSame($onV1->toArray(), $onV1Again->toArray());
    }

    /**
     * A session saved under v1 carries `luck`, which v2 retired. The turn must
     * still run: the variable is simply not the new schema's business, it is
     * preserved verbatim in the state, and no phantom diff entry is produced
     * for it (M2, at the level where it actually bites).
     */
    public function testAVariableTheNewerSchemaNoLongerDeclaresIsCarriedThroughATurn(): void
    {
        $v1State = GameState::create(['gold' => 5, 'luck' => 3], [], ['lantern' => 1], [], []);

        $result = $this->transition(self::STORY_V2, self::schemaV2())->run($v1State, 'crossroads', 'north', 1);

        self::assertSame('ravine', $result->sceneKey);
        self::assertSame(3, $result->state->var('luck'));
        self::assertSame(5, $result->state->var('gold'));

        // Nothing in the log mentions a variable the turn never touched.
        self::assertSame(['choice_made', 'scene_entered'], array_map(
            static fn (object $event): string => $event->name(),
            $result->events,
        ));
    }

    /**
     * The other side of the same rule: a *condition* that still names the
     * retired variable is an authoring error in the new version and fails
     * loudly, naming the variable. Silence here would be a gate quietly
     * answering `false`.
     */
    public function testAConditionNamingTheRetiredVariableFailsLoudlyUnderTheNewSchema(): void
    {
        $evaluator = new ConditionEvaluator(self::schemaV2());
        $v1State = GameState::create(['gold' => 5, 'luck' => 3], [], [], [], []);
        $node = Condition::fromArray(['op' => 'gte', 'var' => 'luck', 'value' => 1]);

        // Under v1's schema the same node is an ordinary question.
        self::assertTrue((new ConditionEvaluator(self::schemaV1()))->evaluate($node, $v1State));

        $this->expectException(UnknownVariableException::class);
        $this->expectExceptionMessage('luck');

        $evaluator->evaluate($node, $v1State);
    }

    /**
     * `visited` and `history` are state, not graph: a scene that v2 deleted is
     * still counted, still answers the `visited` operator, and never causes a
     * graph lookup. A session that walked through `meadow` under v1 therefore
     * keeps that fact even against a version where the scene is gone — which
     * is what makes "published versions are immutable, sessions are pinned"
     * work from the Engine's side.
     */
    public function testVisitsToASceneARetiredVersionDeletedAreStillCountedAndReadable(): void
    {
        $graphV3 = InMemoryStoryGraph::fromArray([
            ['key' => 'crossroads', 'choices' => [['key' => 'north']], 'next' => 'cave'],
            ['key' => 'cave'],
        ]);

        $v1State = GameState::create([], [], [], ['meadow' => 2], ['meadow', 'meadow']);
        $evaluator = new ConditionEvaluator(self::schemaV2());

        self::assertFalse($graphV3->hasScene('meadow'));
        self::assertTrue($evaluator->evaluate(
            Condition::fromArray(['op' => 'visited', 'scene' => 'meadow', 'times' => 2]),
            $v1State,
        ));
        self::assertFalse($evaluator->evaluate(
            Condition::fromArray(['op' => 'visited', 'scene' => 'meadow', 'times' => 3]),
            $v1State,
        ));

        $schema = self::schemaV2();
        $result = (new Transition(
            $graphV3,
            $evaluator,
            new EffectApplier($schema),
            new NextSceneResolver($evaluator),
        ))->run($v1State, 'crossroads', 'north', 7);

        self::assertSame('cave', $result->sceneKey);
        self::assertSame(['cave' => 1, 'meadow' => 2], $result->state->toArray()['visited']);
        self::assertSame(['meadow', 'meadow', 'cave'], $result->state->toArray()['history']);
    }

    /**
     * The frozen envelope, written out as a literal rather than produced by
     * the code under test: this is the shape a `play_session` row holds, and a
     * save written by an earlier build must load and play without
     * interpretation.
     *
     * The matching refusal is the other half — an envelope from a *future*
     * build is rejected, because guessing at a field this one has never seen
     * is how a save gets silently rewritten.
     */
    public function testAStateWrittenByAnEarlierBuildLoadsAndPlays(): void
    {
        $saved = [
            'v' => 1,
            'vars' => ['gold' => 5, 'luck' => 3],
            'flags' => ['met_elder'],
            'inventory' => ['lantern' => 1],
            'visited' => ['crossroads' => 1],
            'history' => ['crossroads'],
        ];

        $state = GameState::fromArray($saved);

        self::assertSame($saved, $state->toArray());
        self::assertSame(1, GameState::VERSION);

        $result = $this->transition(self::STORY_V1)->run($state, 'crossroads', 'north', 2);

        self::assertSame('cave', $result->sceneKey);
        self::assertSame(['cave' => 1, 'crossroads' => 1], $result->state->toArray()['visited']);
        self::assertSame(1, $result->state->toArray()['v']);

        $this->expectException(MalformedNodeException::class);

        GameState::fromArray(['v' => 2] + $saved);
    }

    /**
     * @param list<array<array-key, mixed>> $scenes
     */
    private function transition(array $scenes, ?VariableSchema $schema = null): Transition
    {
        $schema ??= self::schemaV1();
        $evaluator = new ConditionEvaluator($schema);

        return new Transition(
            InMemoryStoryGraph::fromArray($scenes),
            $evaluator,
            new EffectApplier($schema),
            new NextSceneResolver($evaluator),
        );
    }

    private static function schemaV1(): VariableSchema
    {
        return VariableSchema::of([
            new VariableDefinition('gold', VariableType::Int, 0),
            new VariableDefinition('luck', VariableType::Int, 1),
        ]);
    }

    /**
     * v2 retired `luck`.
     */
    private static function schemaV2(): VariableSchema
    {
        return VariableSchema::of([new VariableDefinition('gold', VariableType::Int, 0)]);
    }
}
