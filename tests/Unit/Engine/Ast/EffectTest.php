<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine\Ast;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\Ast\Arithmetic;
use Storyteller\Engine\Ast\Effect;
use Storyteller\Engine\Ast\EffectOp;
use Storyteller\Engine\Ast\FlagEffect;
use Storyteller\Engine\Ast\GoToScene;
use Storyteller\Engine\Ast\ItemEffect;
use Storyteller\Engine\Ast\SetVar;
use Storyteller\Engine\Exception\MalformedNodeException;
use Storyteller\Engine\Exception\UnknownOperatorException;
use Storyteller\Engine\Support\ArrayReader;

/**
 * The effect AST is the second half of the format `src/Content` compiles into,
 * so every documented shape is parsed and round-tripped literally.
 */
#[CoversClass(Effect::class)]
#[CoversClass(SetVar::class)]
#[CoversClass(Arithmetic::class)]
#[CoversClass(FlagEffect::class)]
#[CoversClass(ItemEffect::class)]
#[CoversClass(GoToScene::class)]
final class EffectTest extends TestCase
{
    /**
     * @param array<array-key, mixed> $node
     * @param class-string<Effect>    $expectedClass
     */
    #[DataProvider('documentedShapeProvider')]
    public function testEveryOperatorParsesFromItsDocumentedShape(
        array $node,
        string $expectedClass,
        EffectOp $expectedOp,
    ): void {
        $effect = Effect::fromArray($node);

        self::assertSame($expectedClass, $effect::class);
        self::assertSame($expectedOp, $effect->op());
    }

    /**
     * @param array<array-key, mixed> $node
     * @param class-string<Effect>    $expectedClass
     */
    #[DataProvider('documentedShapeProvider')]
    public function testEveryOperatorRoundTripsThroughToArray(
        array $node,
        string $expectedClass,
        EffectOp $expectedOp,
    ): void {
        $effect = Effect::fromArray($node);
        $reparsed = Effect::fromArray($effect->toArray());

        self::assertEquals($effect, $reparsed);
        self::assertSame($effect->toArray(), $reparsed->toArray());
        self::assertSame($expectedClass, $reparsed::class);
        self::assertSame($expectedOp, $reparsed->op());
    }

    public function testParsedNodesExposeTheirOperands(): void
    {
        $set = Effect::fromArray(['op' => 'set', 'var' => 'hp', 'value' => 100]);
        $add = Effect::fromArray(['op' => 'add', 'var' => 'hp', 'value' => 5]);
        $flag = Effect::fromArray(['op' => 'set_flag', 'flag' => 'met_elder']);
        $item = Effect::fromArray(['op' => 'give_item', 'item' => 'rope', 'qty' => 3]);
        $goto = Effect::fromArray(['op' => 'goto', 'scene' => 'cave']);

        self::assertInstanceOf(SetVar::class, $set);
        self::assertSame('hp', $set->var);
        self::assertSame(100, $set->value);

        self::assertInstanceOf(Arithmetic::class, $add);
        self::assertSame('hp', $add->var);
        self::assertSame(5, $add->value);

        self::assertInstanceOf(FlagEffect::class, $flag);
        self::assertSame('met_elder', $flag->flag);

        self::assertInstanceOf(ItemEffect::class, $item);
        self::assertSame('rope', $item->item);
        self::assertSame(3, $item->qty);

        self::assertInstanceOf(GoToScene::class, $goto);
        self::assertSame('cave', $goto->scene);
    }

    /**
     * @param array<array-key, mixed> $node
     */
    #[DataProvider('nonNumericArithmeticProvider')]
    public function testAddAndSubRejectANonNumericValueAtParseTime(array $node): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('number');

        Effect::fromArray($node);
    }

    public function testAddAndSubAcceptAFloat(): void
    {
        $effect = Effect::fromArray(['op' => 'sub', 'var' => 'ratio', 'value' => 0.25]);

        self::assertInstanceOf(Arithmetic::class, $effect);
        self::assertSame(0.25, $effect->value);
    }

    /**
     * @param int|float|string|bool $value
     */
    #[DataProvider('scalarValueProvider')]
    public function testSetAcceptsAnyScalarAtParseTime(int|float|string|bool $value): void
    {
        $effect = Effect::fromArray(['op' => 'set', 'var' => 'x', 'value' => $value]);

        self::assertInstanceOf(SetVar::class, $effect);
        self::assertSame($value, $effect->value);
    }

    public function testQtyDefaultsToOne(): void
    {
        self::assertSame(
            ['op' => 'give_item', 'item' => 'rope', 'qty' => 1],
            Effect::fromArray(['op' => 'give_item', 'item' => 'rope'])->toArray(),
        );
        self::assertSame(
            ['op' => 'remove_item', 'item' => 'rope', 'qty' => 1],
            Effect::fromArray(['op' => 'remove_item', 'item' => 'rope'])->toArray(),
        );
    }

    /**
     * M3, the item-effect half: an **omitted** `qty` grants or takes one, an
     * **explicit null** is malformed content. Reading the second as the first
     * would hand the player one rope where the author wrote five, silently.
     */
    public function testAnOmittedQtyDefaultsToOneButAnExplicitNullIsRejected(): void
    {
        $omitted = Effect::fromArray(['op' => 'give_item', 'item' => 'rope']);

        self::assertInstanceOf(ItemEffect::class, $omitted);
        self::assertSame(1, $omitted->qty);

        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('effect: key "qty" must be of type int, got null.');

        Effect::fromArray(['op' => 'give_item', 'item' => 'rope', 'qty' => null]);
    }

    /**
     * @param array<array-key, mixed> $node
     */
    #[DataProvider('badQuantityProvider')]
    public function testQtyRejectsZeroAndNonIntegers(array $node): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('qty');

        Effect::fromArray($node);
    }

    public function testUnknownOperatorThrows(): void
    {
        $this->expectException(UnknownOperatorException::class);
        $this->expectExceptionMessage('multiply');

        Effect::fromArray(['op' => 'multiply', 'var' => 'hp', 'value' => 2]);
    }

    public function testAMissingOpKeyThrowsMalformedNode(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('op');

        Effect::fromArray(['var' => 'hp', 'value' => 1]);
    }

    /**
     * @param array<array-key, mixed> $node
     */
    #[DataProvider('missingRequiredKeyProvider')]
    public function testMissingKeyThrows(array $node, string $missingKey): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage($missingKey);

        Effect::fromArray($node);
    }

    /**
     * @param array<array-key, mixed> $node
     */
    #[DataProvider('unknownKeyProvider')]
    public function testUnknownKeyIsRejected(array $node): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('extra');

        Effect::fromArray($node);
    }

    public function testThePathCanBeOverriddenByTheCaller(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('scene.on_enter[0]');

        Effect::fromArray(['op' => 'set_flag'], 'scene.on_enter[0]');
    }

    public function testListFromArrayPreservesOrder(): void
    {
        $effects = Effect::listFromArray(
            [
                ['op' => 'set_flag', 'flag' => 'a'],
                ['op' => 'give_item', 'item' => 'rope'],
                ['op' => 'goto', 'scene' => 'cave'],
            ],
            'scene.on_enter',
        );

        self::assertCount(3, $effects);
        self::assertSame(
            [EffectOp::SetFlag, EffectOp::GiveItem, EffectOp::GoTo],
            array_map(static fn (Effect $effect): EffectOp => $effect->op(), $effects),
        );
        self::assertSame([], Effect::listFromArray([], 'scene.on_enter'));
    }

    public function testListFromArrayReportsThePositionOfAMalformedElement(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('scene.on_enter[1]');

        Effect::listFromArray(
            [
                ['op' => 'set_flag', 'flag' => 'a'],
                ['op' => 'set_flag'],
            ],
            'scene.on_enter',
        );
    }

    /**
     * A non-object element is rejected one layer down, by the reader that
     * produces the list in the first place — which is the only way a caller
     * can legitimately build the argument.
     */
    public function testTheReaderRejectsANonObjectElementBeforeTheListIsParsed(): void
    {
        $reader = new ArrayReader(['on_enter' => [['op' => 'set_flag', 'flag' => 'a'], 7]], 'scene');

        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('scene.on_enter');

        Effect::listFromArray($reader->requireArrayList('on_enter'), 'scene.on_enter');
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>, 1: class-string<Effect>, 2: EffectOp}>
     */
    public static function documentedShapeProvider(): iterable
    {
        yield 'set' => [['op' => 'set', 'var' => 'hp', 'value' => 100], SetVar::class, EffectOp::Set];
        yield 'add' => [['op' => 'add', 'var' => 'hp', 'value' => 5], Arithmetic::class, EffectOp::Add];
        yield 'sub' => [['op' => 'sub', 'var' => 'hp', 'value' => 5], Arithmetic::class, EffectOp::Sub];
        yield 'set_flag' => [['op' => 'set_flag', 'flag' => 'met_elder'], FlagEffect::class, EffectOp::SetFlag];
        yield 'clear_flag' => [['op' => 'clear_flag', 'flag' => 'met_elder'], FlagEffect::class, EffectOp::ClearFlag];
        yield 'give_item' => [
            ['op' => 'give_item', 'item' => 'rope', 'qty' => 1],
            ItemEffect::class,
            EffectOp::GiveItem,
        ];
        yield 'remove_item' => [
            ['op' => 'remove_item', 'item' => 'rope', 'qty' => 1],
            ItemEffect::class,
            EffectOp::RemoveItem,
        ];
        yield 'goto' => [['op' => 'goto', 'scene' => 'cave'], GoToScene::class, EffectOp::GoTo];
        yield 'set a string' => [['op' => 'set', 'var' => 'name', 'value' => 'ash'], SetVar::class, EffectOp::Set];
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>}>
     */
    public static function nonNumericArithmeticProvider(): iterable
    {
        yield 'add a numeric string' => [['op' => 'add', 'var' => 'hp', 'value' => '5']];
        yield 'add a bool' => [['op' => 'add', 'var' => 'hp', 'value' => true]];
        yield 'sub a numeric string' => [['op' => 'sub', 'var' => 'hp', 'value' => '5']];
        yield 'sub a bool' => [['op' => 'sub', 'var' => 'hp', 'value' => false]];
    }

    /**
     * @return iterable<string, array{0: int|float|string|bool}>
     */
    public static function scalarValueProvider(): iterable
    {
        yield 'int' => [1];
        yield 'float' => [1.5];
        yield 'string' => ['ash'];
        yield 'bool' => [true];
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>}>
     */
    public static function badQuantityProvider(): iterable
    {
        yield 'give_item zero' => [['op' => 'give_item', 'item' => 'rope', 'qty' => 0]];
        yield 'give_item negative' => [['op' => 'give_item', 'item' => 'rope', 'qty' => -2]];
        yield 'remove_item float' => [['op' => 'remove_item', 'item' => 'rope', 'qty' => 1.0]];
        yield 'remove_item string' => [['op' => 'remove_item', 'item' => 'rope', 'qty' => '1']];
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>, 1: string}>
     */
    public static function missingRequiredKeyProvider(): iterable
    {
        yield 'set without var' => [['op' => 'set', 'value' => 1], 'var'];
        yield 'set without value' => [['op' => 'set', 'var' => 'hp'], 'value'];
        yield 'add without var' => [['op' => 'add', 'value' => 1], 'var'];
        yield 'add without value' => [['op' => 'add', 'var' => 'hp'], 'value'];
        yield 'set_flag without flag' => [['op' => 'set_flag'], 'flag'];
        yield 'clear_flag without flag' => [['op' => 'clear_flag'], 'flag'];
        yield 'give_item without item' => [['op' => 'give_item'], 'item'];
        yield 'remove_item without item' => [['op' => 'remove_item'], 'item'];
        yield 'goto without scene' => [['op' => 'goto'], 'scene'];
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>}>
     */
    public static function unknownKeyProvider(): iterable
    {
        yield 'set' => [['op' => 'set', 'var' => 'hp', 'value' => 1, 'extra' => 1]];
        yield 'add' => [['op' => 'add', 'var' => 'hp', 'value' => 1, 'extra' => 1]];
        yield 'set_flag' => [['op' => 'set_flag', 'flag' => 'a', 'extra' => 1]];
        yield 'give_item' => [['op' => 'give_item', 'item' => 'rope', 'extra' => 1]];
        yield 'goto' => [['op' => 'goto', 'scene' => 'cave', 'extra' => 1]];
    }
}
