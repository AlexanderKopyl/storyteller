<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\Ast\Effect;
use Storyteller\Engine\Ast\EffectOp;
use Storyteller\Engine\EffectApplier;
use Storyteller\Engine\Exception\TypeMismatchException;
use Storyteller\Engine\Exception\UnknownOperatorException;
use Storyteller\Engine\Exception\UnknownVariableException;
use Storyteller\Engine\State\GameState;
use Storyteller\Engine\State\VariableDefinition;
use Storyteller\Engine\State\VariableSchema;
use Storyteller\Engine\State\VariableType;
use Storyteller\Engine\Support\ArrayReader;

/**
 * The applier is the only writer of state inside a turn, and the only place
 * the declared variable types are enforced on a write: `set` refuses a value
 * of the wrong type, and `add` / `sub` refuse a non-numeric variable, a
 * fractional step into an int and an overflow that silently became a float.
 *
 * `goto` is the other half: it ends the list where it stands, so the effects
 * after it never run.
 */
#[CoversClass(EffectApplier::class)]
final class EffectApplierTest extends TestCase
{
    /**
     * @param array<array-key, mixed> $node
     * @param array<string, mixed>    $expectedState
     */
    #[DataProvider('documentedOperatorProvider')]
    public function testEveryOperatorChangesStateAsDocumented(
        array $node,
        array $expectedState,
        ?string $expectedRedirect,
    ): void {
        $result = $this->applier()->apply($this->effects([$node]), $this->state());

        self::assertSame($expectedState, $result->state->toArray());
        self::assertSame($expectedRedirect, $result->redirectTo);
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>, 1: array<string, mixed>, 2: string|null}>
     */
    public static function documentedOperatorProvider(): iterable
    {
        yield 'set' => [
            ['op' => 'set', 'var' => 'hp', 'value' => 50],
            self::stateArray(['vars' => ['hp' => 50]]),
            null,
        ];
        yield 'add' => [
            ['op' => 'add', 'var' => 'hp', 'value' => 5],
            self::stateArray(['vars' => ['hp' => 100]]),
            null,
        ];
        yield 'sub' => [
            ['op' => 'sub', 'var' => 'hp', 'value' => 5],
            self::stateArray(['vars' => ['hp' => 90]]),
            null,
        ];
        yield 'set_flag' => [
            ['op' => 'set_flag', 'flag' => 'brave'],
            self::stateArray(['flags' => ['brave', 'met_elder']]),
            null,
        ];
        yield 'clear_flag' => [
            ['op' => 'clear_flag', 'flag' => 'met_elder'],
            self::stateArray(['flags' => []]),
            null,
        ];
        yield 'give_item' => [
            ['op' => 'give_item', 'item' => 'rope', 'qty' => 3],
            self::stateArray(['inventory' => ['rope' => 5]]),
            null,
        ];
        yield 'remove_item' => [
            ['op' => 'remove_item', 'item' => 'rope', 'qty' => 1],
            self::stateArray(['inventory' => ['rope' => 1]]),
            null,
        ];
        yield 'goto' => [
            ['op' => 'goto', 'scene' => 'cave'],
            self::stateArray([]),
            'cave',
        ];
    }

    public function testSetRejectsAValueOfTheWrongDeclaredType(): void
    {
        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage('hp');

        $this->applier()->apply($this->effects([['op' => 'set', 'var' => 'hp', 'value' => 'ten']]), $this->state());
    }

    /**
     * @param array<array-key, mixed> $node
     */
    #[DataProvider('arithmeticOnANonNumericVariableProvider')]
    public function testAddAndSubRejectStringAndBoolVariables(array $node): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->applier()->apply($this->effects([$node]), $this->state());
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>}>
     */
    public static function arithmeticOnANonNumericVariableProvider(): iterable
    {
        yield 'add on a string variable' => [['op' => 'add', 'var' => 'name', 'value' => 1]];
        yield 'sub on a string variable' => [['op' => 'sub', 'var' => 'name', 'value' => 1]];
        yield 'add on a bool variable' => [['op' => 'add', 'var' => 'brave', 'value' => 1]];
        yield 'sub on a bool variable' => [['op' => 'sub', 'var' => 'brave', 'value' => 1]];
    }

    public function testAddAFloatToAnIntVariableThrowsRatherThanPromoting(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->applier()->apply($this->effects([['op' => 'add', 'var' => 'hp', 'value' => 0.5]]), $this->state());
    }

    public function testIntegerOverflowIsCaughtAsATypeMismatch(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->applier()->apply(
            $this->effects([['op' => 'add', 'var' => 'hp', 'value' => 1]]),
            GameState::create(['hp' => PHP_INT_MAX], [], [], [], []),
        );
    }

    /**
     * `GameState` carries no schema and validates nothing, so a state built
     * elsewhere can disagree with the declaration. Arithmetic refuses it
     * rather than letting PHP coerce the value.
     */
    public function testAStateCarryingANonNumericValueForANumericVariableIsRejected(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->applier()->apply(
            $this->effects([['op' => 'add', 'var' => 'hp', 'value' => 1]]),
            GameState::create(['hp' => 'ten'], [], [], [], []),
        );
    }

    public function testRemoveItemNeverGoesBelowZeroAndRemovesTheKeyAtZero(): void
    {
        $result = $this->applier()->apply(
            $this->effects([['op' => 'remove_item', 'item' => 'rope', 'qty' => 5]]),
            $this->state(),
        );

        self::assertSame([], $result->state->toArray()['inventory']);
        self::assertSame(0, $result->state->itemQty('rope'));
        self::assertSame(['rope' => ['from' => 2, 'to' => 0]], $result->diff->inventory);
    }

    /**
     * The three points around the inventory floor, in one place: one short of
     * the stock, exactly the stock, and one more than the stock.
     *
     * The third row is D-DIFF's rule made visible — the diff records
     * *outcomes*, not attempts, so a `remove_item` that had nothing to take
     * produces no entry at all and therefore no `EffectsApplied` event. A
     * quantity is never negative and a zero entry is never persisted.
     *
     * @param array<string, int> $expectedInventory
     * @param array<string, array{from: int, to: int}> $expectedDiff
     */
    #[DataProvider('removeItemBoundaryProvider')]
    public function testRemoveItemIsClampedAtZero(
        int $held,
        int $removed,
        array $expectedInventory,
        array $expectedDiff,
    ): void {
        $result = $this->applier()->apply(
            $this->effects([['op' => 'remove_item', 'item' => 'rope', 'qty' => $removed]]),
            GameState::create([], [], $held > 0 ? ['rope' => $held] : [], [], []),
        );

        self::assertSame($expectedInventory, $result->state->toArray()['inventory']);
        self::assertSame($expectedDiff, $result->diff->inventory);
        self::assertGreaterThanOrEqual(0, $result->state->itemQty('rope'));
    }

    /**
     * @return iterable<string, array{0: int, 1: int, 2: array<string, int>, 3: array<string, array{from: int, to: int}>}>
     */
    public static function removeItemBoundaryProvider(): iterable
    {
        yield 'one short of the stock' => [3, 2, ['rope' => 1], ['rope' => ['from' => 3, 'to' => 1]]];
        yield 'exactly the stock' => [3, 3, [], ['rope' => ['from' => 3, 'to' => 0]]];
        yield 'one more than the stock' => [3, 4, [], ['rope' => ['from' => 3, 'to' => 0]]];
        yield 'far more than the stock' => [1, 1000, [], ['rope' => ['from' => 1, 'to' => 0]]];
        yield 'nothing held at all' => [0, 1, [], []];
        yield 'nothing held, large quantity' => [0, 1000, [], []];
    }

    /**
     * `sub` is unsigned-free on purpose: the Engine has no notion of a floor
     * for a variable, only of its declared type, so a story that subtracts 200
     * from 95 gets -105 and may branch on it. Clamping would be hidden policy
     * — the author who wants a floor writes the `next` guard.
     *
     * Pinned in both directions and for both numeric types, and asserted
     * through `toArray()` so a silently clamped 0 could not pass.
     */
    public function testSubMayDriveAVariableNegativeAndNothingClampsIt(): void
    {
        $applier = $this->applier();

        $int = $applier->apply(
            $this->effects([['op' => 'sub', 'var' => 'hp', 'value' => 200]]),
            $this->state(),
        );
        $float = $applier->apply(
            $this->effects([['op' => 'sub', 'var' => 'ratio', 'value' => 2.5]]),
            $this->state(),
        );
        $back = $applier->apply(
            $this->effects([['op' => 'add', 'var' => 'hp', 'value' => 300]]),
            $int->state,
        );

        self::assertSame(['hp' => -105], $int->state->toArray()['vars']);
        self::assertSame(['vars' => ['hp' => ['from' => 95, 'to' => -105]]], $int->diff->toArray());

        // The float default is 1.0, so this one starts from the declaration.
        self::assertSame(-1.5, $float->state->var('ratio'));

        self::assertSame(195, $back->state->var('hp'));
    }

    /**
     * A no-op arithmetic step is a no-op in the log too: the diff is computed
     * from the before/after states, so `add 0` and `set` to the value already
     * held record nothing and emit no event.
     */
    public function testAnArithmeticStepOfZeroAndASetToTheCurrentValueProduceNoDiff(): void
    {
        $applier = $this->applier();

        foreach ([
            ['op' => 'add', 'var' => 'hp', 'value' => 0],
            ['op' => 'sub', 'var' => 'hp', 'value' => 0],
            ['op' => 'set', 'var' => 'hp', 'value' => 95],
        ] as $node) {
            $result = $applier->apply($this->effects([$node]), $this->state());

            self::assertTrue($result->diff->isEmpty(), json_encode($node, JSON_THROW_ON_ERROR));
            self::assertSame($this->state()->toArray(), $result->state->toArray());
        }
    }

    /**
     * Variable, flag and item keys are author-supplied strings and the Engine
     * puts no character class on them. Two are worth pinning because they are
     * where a naive `ksort` or a `!$key` test would break: a multi-byte key
     * and the empty string.
     *
     * `SORT_STRING` is a byte comparison, so the canonical order is the UTF-8
     * byte order — '' first, ASCII before any multi-byte sequence — and it is
     * the same order in every locale, which is the property the ordering
     * exists for.
     */
    public function testVariableFlagAndItemKeysMayBeUnicodeOrEmpty(): void
    {
        $applier = new EffectApplier(VariableSchema::of([
            new VariableDefinition('', VariableType::Int, 0),
            new VariableDefinition('здоровье', VariableType::Int, 10),
            new VariableDefinition('体力', VariableType::Int, 20),
        ]));

        $result = $applier->apply(
            $this->effects([
                ['op' => 'add', 'var' => 'здоровье', 'value' => 5],
                ['op' => 'set', 'var' => '', 'value' => 7],
                ['op' => 'sub', 'var' => '体力', 'value' => 1],
                ['op' => 'set_flag', 'flag' => ''],
                ['op' => 'set_flag', 'flag' => 'прошёл_зал'],
                ['op' => 'give_item', 'item' => '', 'qty' => 2],
                ['op' => 'give_item', 'item' => 'верёвка', 'qty' => 3],
            ]),
            GameState::initial(),
        );

        $array = $result->state->toArray();

        self::assertSame(['' => 7, 'здоровье' => 15, '体力' => 19], $array['vars']);
        self::assertSame(['', 'прошёл_зал'], $array['flags']);
        self::assertSame(['' => 2, 'верёвка' => 3], $array['inventory']);

        // The diff keys the same strings back, and the whole state survives
        // the JSONB round trip byte for byte.
        self::assertSame(
            ['' => ['from' => 0, 'to' => 7], 'здоровье' => ['from' => 10, 'to' => 15], '体力' => ['from' => 20, 'to' => 19]],
            $result->diff->vars,
        );

        $json = json_encode($array, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame($array, GameState::fromArray($decoded)->toArray());
    }

    public function testGiveItemAndRemoveItemDefaultToAQuantityOfOne(): void
    {
        $applier = $this->applier();

        $given = $applier->apply($this->effects([['op' => 'give_item', 'item' => 'rope']]), $this->state());
        $removed = $applier->apply($this->effects([['op' => 'remove_item', 'item' => 'rope']]), $this->state());

        self::assertSame(3, $given->state->itemQty('rope'));
        self::assertSame(1, $removed->state->itemQty('rope'));
    }

    public function testClearingAnAbsentFlagProducesNoDiffEntry(): void
    {
        $result = $this->applier()->apply(
            $this->effects([['op' => 'clear_flag', 'flag' => 'never_set']]),
            $this->state(),
        );

        self::assertTrue($result->diff->isEmpty());
        self::assertSame([], $result->diff->toArray());
    }

    public function testGotoSetsRedirectToAndDoesNotMutateState(): void
    {
        $before = $this->state();

        $result = $this->applier()->apply($this->effects([['op' => 'goto', 'scene' => 'cave']]), $before);

        self::assertTrue($result->isRedirect());
        self::assertSame('cave', $result->redirectTo);
        self::assertSame($before->toArray(), $result->state->toArray());
        self::assertTrue($result->diff->isEmpty());
    }

    public function testGotoShortCircuitsTheRemainingEffects(): void
    {
        $result = $this->applier()->apply(
            $this->effects([
                ['op' => 'set_flag', 'flag' => 'brave'],
                ['op' => 'goto', 'scene' => 'cave'],
                ['op' => 'set_flag', 'flag' => 'wise'],
                ['op' => 'sub', 'var' => 'hp', 'value' => 95],
            ]),
            $this->state(),
        );

        self::assertSame('cave', $result->redirectTo);
        self::assertSame(['brave', 'met_elder'], $result->state->toArray()['flags']);
        self::assertSame(95, $result->state->var('hp'));
        self::assertSame(['added' => ['brave'], 'removed' => []], $result->diff->flags);
    }

    public function testAListWithTwoGotoNodesHonoursTheFirst(): void
    {
        $result = $this->applier()->apply(
            $this->effects([['op' => 'goto', 'scene' => 'cave'], ['op' => 'goto', 'scene' => 'ridge']]),
            $this->state(),
        );

        self::assertSame('cave', $result->redirectTo);
    }

    public function testAnEmptyEffectListProducesAnEmptyDiffAndNoRedirect(): void
    {
        $before = $this->state();

        $result = $this->applier()->apply([], $before);

        self::assertFalse($result->isRedirect());
        self::assertTrue($result->diff->isEmpty());
        self::assertSame($before->toArray(), $result->state->toArray());
    }

    /**
     * `visited` is the category no effect operator can reach: only
     * `Transition` writes it, and it does so outside both effect phases.
     */
    public function testTheDiffCoversAllFourCategoriesAcrossOnePhase(): void
    {
        $result = $this->applier()->apply(
            $this->effects([
                ['op' => 'sub', 'var' => 'hp', 'value' => 5],
                ['op' => 'set_flag', 'flag' => 'brave'],
                ['op' => 'clear_flag', 'flag' => 'met_elder'],
                ['op' => 'give_item', 'item' => 'torch', 'qty' => 2],
            ]),
            $this->state(),
        );

        self::assertSame(
            [
                'vars' => ['hp' => ['from' => 95, 'to' => 90]],
                'flags' => ['added' => ['brave'], 'removed' => ['met_elder']],
                'inventory' => ['torch' => ['from' => 0, 'to' => 2]],
            ],
            $result->diff->toArray(),
        );
        self::assertSame([], $result->diff->visited);
    }

    public function testAnEffectTypeTheApplierDoesNotKnowThrows(): void
    {
        $unknown = new readonly class () extends Effect {
            public function op(): EffectOp
            {
                return EffectOp::Set;
            }

            /**
             * @return array<string, mixed>
             */
            public function toArray(): array
            {
                return ['op' => 'set'];
            }

            protected static function parse(ArrayReader $reader, EffectOp $op): self
            {
                throw new \LogicException('not parseable');
            }
        };

        $this->expectException(UnknownOperatorException::class);

        $this->applier()->apply([$unknown], $this->state());
    }

    /**
     * M2: a saved session whose story version no longer declares one of the
     * variables it carries must still be playable. Before the fix the diff —
     * which runs on every phase of every turn — resolved every name in either
     * state, so an undeclared one threw `UnknownVariableException` even for a
     * turn with no effects at all, naming a variable the turn never mentioned.
     */
    public function testAStateCarryingAnUndeclaredVariableStillTakesAnEmptyTurn(): void
    {
        $state = GameState::create(['hp' => 10, 'legacy_mana' => 3], [], [], [], []);

        $result = $this->applier()->apply([], $state);

        self::assertSame($state, $result->state);
        self::assertTrue($result->diff->isEmpty());
        self::assertSame(3, $result->state->var('legacy_mana'));
    }

    public function testAnEffectThatNamesAnUndeclaredVariableStillThrows(): void
    {
        $this->expectException(UnknownVariableException::class);
        $this->expectExceptionMessage('legacy_mana');

        $this->applier()->apply(
            $this->effects([['op' => 'set', 'var' => 'legacy_mana', 'value' => 4]]),
            GameState::create(['legacy_mana' => 3], [], [], [], []),
        );
    }

    /**
     * B1: a compiled `{"op":"set","var":"ratio","value":1.0}` node comes back
     * from the `story_version` snapshot as `value: 1`. It must still apply,
     * and it must store a real `float`, not the int it arrived as.
     */
    public function testASetNodeWithAnIntegralFloatValueSurvivesAJsonRoundTrip(): void
    {
        $node = ['op' => 'set', 'var' => 'ratio', 'value' => 2.0];

        $json = json_encode($node, JSON_THROW_ON_ERROR);

        self::assertSame('{"op":"set","var":"ratio","value":2}', $json);

        // No `assertIsArray()` here: the encoded literal is precise enough
        // that static analysis already proves the decoded shape.
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $result = $this->applier()->apply($this->effects([$decoded]), $this->state());

        // The declared default is 1.0, so both sides of the diff are floats.
        self::assertSame(2.0, $result->state->var('ratio'));
        self::assertSame(
            ['vars' => ['ratio' => ['from' => 1.0, 'to' => 2.0]]],
            $result->diff->toArray(),
        );
    }

    /**
     * @param  list<array<array-key, mixed>> $nodes
     * @return list<Effect>
     */
    private function effects(array $nodes): array
    {
        return Effect::listFromArray($nodes, 'effects');
    }

    private function applier(): EffectApplier
    {
        return new EffectApplier(VariableSchema::of([
            new VariableDefinition('hp', VariableType::Int, 100),
            new VariableDefinition('ratio', VariableType::Float, 1.0),
            new VariableDefinition('name', VariableType::String, 'ash'),
            new VariableDefinition('brave', VariableType::Bool, false),
        ]));
    }

    private function state(): GameState
    {
        return GameState::create(['hp' => 95], ['met_elder'], ['rope' => 2], ['cave' => 1], ['cave']);
    }

    /**
     * @param  array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function stateArray(array $changes): array
    {
        return array_replace([
            'v' => 1,
            'vars' => ['hp' => 95],
            'flags' => ['met_elder'],
            'inventory' => ['rope' => 2],
            'visited' => ['cave' => 1],
            'history' => ['cave'],
        ], $changes);
    }
}
