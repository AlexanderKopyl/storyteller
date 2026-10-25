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
