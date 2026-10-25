<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine\Ast;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\Ast\Comparison;
use Storyteller\Engine\Ast\Condition;
use Storyteller\Engine\Ast\ConditionOp;
use Storyteller\Engine\Ast\FlagCheck;
use Storyteller\Engine\Ast\HasItem;
use Storyteller\Engine\Ast\LogicalAnd;
use Storyteller\Engine\Ast\LogicalNot;
use Storyteller\Engine\Ast\LogicalOr;
use Storyteller\Engine\Ast\Visited;
use Storyteller\Engine\Exception\MalformedNodeException;
use Storyteller\Engine\Exception\UnknownOperatorException;

/**
 * The condition AST is the format `src/Content` compiles authored YAML into
 * and a published story version freezes, so every documented shape is parsed
 * and round-tripped here literally.
 */
#[CoversClass(Condition::class)]
#[CoversClass(Comparison::class)]
#[CoversClass(FlagCheck::class)]
#[CoversClass(HasItem::class)]
#[CoversClass(Visited::class)]
#[CoversClass(LogicalAnd::class)]
#[CoversClass(LogicalOr::class)]
#[CoversClass(LogicalNot::class)]
final class ConditionTest extends TestCase
{
    /**
     * @param array<array-key, mixed> $node
     * @param class-string<Condition> $expectedClass
     */
    #[DataProvider('documentedShapeProvider')]
    public function testEveryOperatorParsesFromItsDocumentedShape(
        array $node,
        string $expectedClass,
        ConditionOp $expectedOp,
    ): void {
        $condition = Condition::fromArray($node);

        self::assertSame($expectedClass, $condition::class);
        self::assertSame($expectedOp, $condition->op());
    }

    /**
     * @param array<array-key, mixed> $node
     * @param class-string<Condition> $expectedClass
     */
    #[DataProvider('documentedShapeProvider')]
    public function testEveryOperatorRoundTripsThroughToArray(
        array $node,
        string $expectedClass,
        ConditionOp $expectedOp,
    ): void {
        $condition = Condition::fromArray($node);
        $reparsed = Condition::fromArray($condition->toArray());

        self::assertEquals($condition, $reparsed);
        self::assertSame($condition->toArray(), $reparsed->toArray());
        self::assertSame($expectedClass, $reparsed::class);
        self::assertSame($expectedOp, $reparsed->op());
    }

    public function testParsedNodesExposeTheirOperands(): void
    {
        $comparison = Condition::fromArray(['op' => 'eq', 'var' => 'hp', 'value' => 100]);
        $flag = Condition::fromArray(['op' => 'flag', 'flag' => 'met_elder']);
        $item = Condition::fromArray(['op' => 'has_item', 'item' => 'rope', 'qty' => 2]);
        $visited = Condition::fromArray(['op' => 'visited', 'scene' => 'cave', 'times' => 3]);

        self::assertInstanceOf(Comparison::class, $comparison);
        self::assertSame('hp', $comparison->var);
        self::assertSame(100, $comparison->value);

        self::assertInstanceOf(FlagCheck::class, $flag);
        self::assertSame('met_elder', $flag->flag);

        self::assertInstanceOf(HasItem::class, $item);
        self::assertSame('rope', $item->item);
        self::assertSame(2, $item->qty);

        self::assertInstanceOf(Visited::class, $visited);
        self::assertSame('cave', $visited->scene);
        self::assertSame(3, $visited->times);
    }

    public function testQtyAndTimesDefaultToOne(): void
    {
        $item = Condition::fromArray(['op' => 'has_item', 'item' => 'rope']);
        $visited = Condition::fromArray(['op' => 'visited', 'scene' => 'cave']);

        self::assertSame(['op' => 'has_item', 'item' => 'rope', 'qty' => 1], $item->toArray());
        self::assertSame(['op' => 'visited', 'scene' => 'cave', 'times' => 1], $visited->toArray());
    }

    /**
     * M3, the `qty` half: an **omitted** `qty` means "one of them", but an
     * **explicit null** is malformed content, and reading it as the default
     * turns "the vault needs 5 ropes" into "the vault needs 1 rope" without a
     * word — the same silent weakening of an authored gate M3 was about, one
     * level down from `condition`.
     */
    public function testAnOmittedQtyDefaultsToOneButAnExplicitNullIsRejected(): void
    {
        $omitted = Condition::fromArray(['op' => 'has_item', 'item' => 'rope']);

        self::assertInstanceOf(HasItem::class, $omitted);
        self::assertSame(1, $omitted->qty);

        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('condition: key "qty" must be of type int, got null.');

        Condition::fromArray(['op' => 'has_item', 'item' => 'rope', 'qty' => null]);
    }

    /**
     * M3, the `times` half: an **omitted** `times` means "at least once", an
     * explicit null is malformed and must not be read as that default.
     */
    public function testAnOmittedTimesDefaultsToOneButAnExplicitNullIsRejected(): void
    {
        $omitted = Condition::fromArray(['op' => 'visited', 'scene' => 'cave']);

        self::assertInstanceOf(Visited::class, $omitted);
        self::assertSame(1, $omitted->times);

        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('condition: key "times" must be of type int, got null.');

        Condition::fromArray(['op' => 'visited', 'scene' => 'cave', 'times' => null]);
    }

    /**
     * @param array<array-key, mixed> $node
     */
    #[DataProvider('badQuantityProvider')]
    public function testQtyAndTimesRejectZeroAndNonInteger(array $node): void
    {
        $this->expectException(MalformedNodeException::class);

        Condition::fromArray($node);
    }

    public function testAndOrNestChildrenAndNotTakesASingleObject(): void
    {
        $condition = Condition::fromArray([
            'op' => 'and',
            'of' => [
                ['op' => 'flag', 'flag' => 'a'],
                [
                    'op' => 'or',
                    'of' => [
                        ['op' => 'not', 'of' => ['op' => 'flag', 'flag' => 'b']],
                        ['op' => 'has_item', 'item' => 'rope'],
                    ],
                ],
            ],
        ]);

        self::assertInstanceOf(LogicalAnd::class, $condition);
        self::assertCount(2, $condition->of);

        $inner = $condition->of[1];

        self::assertInstanceOf(LogicalOr::class, $inner);
        self::assertCount(2, $inner->of);
        self::assertInstanceOf(LogicalNot::class, $inner->of[0]);
        self::assertInstanceOf(FlagCheck::class, $inner->of[0]->of);
    }

    public function testNotRejectsAListAndAndRejectsASingleObject(): void
    {
        $this->expectException(MalformedNodeException::class);

        Condition::fromArray(['op' => 'and', 'of' => ['op' => 'flag', 'flag' => 'a']]);
    }

    public function testNotRejectsAListOfChildren(): void
    {
        $this->expectException(MalformedNodeException::class);

        Condition::fromArray(['op' => 'not', 'of' => [['op' => 'flag', 'flag' => 'a']]]);
    }

    #[DataProvider('nAryOperatorProvider')]
    public function testAndOrRejectAnEmptyOfList(string $op): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('of');

        Condition::fromArray(['op' => $op, 'of' => []]);
    }

    public function testUnknownOperatorThrows(): void
    {
        $this->expectException(UnknownOperatorException::class);
        $this->expectExceptionMessage('xor');

        Condition::fromArray(['op' => 'xor', 'of' => []]);
    }

    public function testAMissingOpKeyThrowsMalformedNode(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('op');

        Condition::fromArray(['flag' => 'a']);
    }

    public function testANonStringOpThrowsMalformedNode(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('string');

        Condition::fromArray(['op' => 7]);
    }

    /**
     * @param array<array-key, mixed> $node
     */
    #[DataProvider('missingRequiredKeyProvider')]
    public function testARequiredKeyMissingThrowsMalformedNode(array $node, string $missingKey): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage($missingKey);

        Condition::fromArray($node);
    }

    /**
     * @param array<array-key, mixed> $node
     */
    #[DataProvider('unknownKeyProvider')]
    public function testAnUnknownKeyIsRejected(array $node): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('extra');

        Condition::fromArray($node);
    }

    public function testErrorPathNamesTheNestedPosition(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('condition.of[1].of');

        Condition::fromArray([
            'op' => 'and',
            'of' => [
                ['op' => 'flag', 'flag' => 'a'],
                ['op' => 'not', 'of' => ['op' => 'flag']],
            ],
        ]);
    }

    public function testThePathCanBeOverriddenByTheCaller(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('choices[2].condition');

        Condition::fromArray(['op' => 'flag'], 'choices[2].condition');
    }

    /**
     * Every shape in the AST specification, in operator order.
     *
     * @return iterable<string, array{0: array<array-key, mixed>, 1: class-string<Condition>, 2: ConditionOp}>
     */
    public static function documentedShapeProvider(): iterable
    {
        yield 'eq' => [['op' => 'eq', 'var' => 'hp', 'value' => 100], Comparison::class, ConditionOp::Eq];
        yield 'neq' => [['op' => 'neq', 'var' => 'name', 'value' => 'ash'], Comparison::class, ConditionOp::Neq];
        yield 'lt' => [['op' => 'lt', 'var' => 'hp', 'value' => 10], Comparison::class, ConditionOp::Lt];
        yield 'lte' => [['op' => 'lte', 'var' => 'hp', 'value' => 10], Comparison::class, ConditionOp::Lte];
        yield 'gt' => [['op' => 'gt', 'var' => 'gold', 'value' => 0], Comparison::class, ConditionOp::Gt];
        yield 'gte' => [['op' => 'gte', 'var' => 'gold', 'value' => 0], Comparison::class, ConditionOp::Gte];
        yield 'flag' => [['op' => 'flag', 'flag' => 'met_elder'], FlagCheck::class, ConditionOp::Flag];
        yield 'not_flag' => [['op' => 'not_flag', 'flag' => 'met_elder'], FlagCheck::class, ConditionOp::NotFlag];
        yield 'has_item' => [['op' => 'has_item', 'item' => 'rope', 'qty' => 1], HasItem::class, ConditionOp::HasItem];
        yield 'visited' => [
            ['op' => 'visited', 'scene' => 'crossroads', 'times' => 1],
            Visited::class,
            ConditionOp::Visited,
        ];
        yield 'and' => [
            ['op' => 'and', 'of' => [['op' => 'flag', 'flag' => 'a'], ['op' => 'flag', 'flag' => 'b']]],
            LogicalAnd::class,
            ConditionOp::AndOp,
        ];
        yield 'or' => [
            ['op' => 'or', 'of' => [['op' => 'flag', 'flag' => 'a'], ['op' => 'flag', 'flag' => 'b']]],
            LogicalOr::class,
            ConditionOp::OrOp,
        ];
        yield 'not' => [
            ['op' => 'not', 'of' => ['op' => 'flag', 'flag' => 'a']],
            LogicalNot::class,
            ConditionOp::NotOp,
        ];
        yield 'float value' => [
            ['op' => 'eq', 'var' => 'ratio', 'value' => 0.5],
            Comparison::class,
            ConditionOp::Eq,
        ];
        yield 'bool value' => [
            ['op' => 'eq', 'var' => 'alive', 'value' => true],
            Comparison::class,
            ConditionOp::Eq,
        ];
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>}>
     */
    public static function badQuantityProvider(): iterable
    {
        yield 'qty zero' => [['op' => 'has_item', 'item' => 'rope', 'qty' => 0]];
        yield 'qty negative' => [['op' => 'has_item', 'item' => 'rope', 'qty' => -1]];
        yield 'qty float' => [['op' => 'has_item', 'item' => 'rope', 'qty' => 1.0]];
        yield 'qty string' => [['op' => 'has_item', 'item' => 'rope', 'qty' => '1']];
        yield 'times zero' => [['op' => 'visited', 'scene' => 'cave', 'times' => 0]];
        yield 'times float' => [['op' => 'visited', 'scene' => 'cave', 'times' => 2.5]];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function nAryOperatorProvider(): iterable
    {
        yield 'and' => ['and'];
        yield 'or' => ['or'];
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>, 1: string}>
     */
    public static function missingRequiredKeyProvider(): iterable
    {
        yield 'eq without var' => [['op' => 'eq', 'value' => 1], 'var'];
        yield 'eq without value' => [['op' => 'eq', 'var' => 'hp'], 'value'];
        yield 'flag without flag' => [['op' => 'flag'], 'flag'];
        yield 'not_flag without flag' => [['op' => 'not_flag'], 'flag'];
        yield 'has_item without item' => [['op' => 'has_item'], 'item'];
        yield 'visited without scene' => [['op' => 'visited'], 'scene'];
        yield 'and without of' => [['op' => 'and'], 'of'];
        yield 'or without of' => [['op' => 'or'], 'of'];
        yield 'not without of' => [['op' => 'not'], 'of'];
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>}>
     */
    public static function unknownKeyProvider(): iterable
    {
        yield 'comparison' => [['op' => 'eq', 'var' => 'hp', 'value' => 1, 'extra' => 1]];
        yield 'flag' => [['op' => 'flag', 'flag' => 'a', 'extra' => 1]];
        yield 'has_item' => [['op' => 'has_item', 'item' => 'rope', 'extra' => 1]];
        yield 'visited' => [['op' => 'visited', 'scene' => 'cave', 'extra' => 1]];
        yield 'and' => [['op' => 'and', 'of' => [['op' => 'flag', 'flag' => 'a']], 'extra' => 1]];
        yield 'not' => [['op' => 'not', 'of' => ['op' => 'flag', 'flag' => 'a'], 'extra' => 1]];
    }
}
