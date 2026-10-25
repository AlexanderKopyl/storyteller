<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\Ast\Comparison;
use Storyteller\Engine\Ast\Condition;
use Storyteller\Engine\Ast\ConditionOp;
use Storyteller\Engine\ConditionEvaluator;
use Storyteller\Engine\Exception\TypeMismatchException;
use Storyteller\Engine\Exception\UnknownOperatorException;
use Storyteller\Engine\Exception\UnknownVariableException;
use Storyteller\Engine\State\GameState;
use Storyteller\Engine\State\VariableDefinition;
use Storyteller\Engine\State\VariableSchema;
use Storyteller\Engine\State\VariableType;
use Storyteller\Engine\Support\ArrayReader;

/**
 * The evaluator is where the schema's type rules stop being declarations and
 * start being behaviour: a missing variable resolves to its declared default,
 * an ordering comparison refuses a non-numeric variable, and a literal of the
 * wrong declared type is an error rather than a silent `false`.
 */
#[CoversClass(ConditionEvaluator::class)]
final class ConditionEvaluatorTest extends TestCase
{
    /**
     * @param array<array-key, mixed> $node
     */
    #[DataProvider('satisfiedAndUnsatisfiedProvider')]
    public function testEveryOperatorHasASatisfiedAndAnUnsatisfiedCase(array $node, bool $expected): void
    {
        self::assertSame($expected, $this->evaluator()->evaluate(Condition::fromArray($node), $this->state()));
    }

    /**
     * Thirteen operators, each once true and once false against the same state.
     *
     * @return iterable<string, array{0: array<array-key, mixed>, 1: bool}>
     */
    public static function satisfiedAndUnsatisfiedProvider(): iterable
    {
        yield 'eq satisfied' => [['op' => 'eq', 'var' => 'hp', 'value' => 95], true];
        yield 'eq unsatisfied' => [['op' => 'eq', 'var' => 'hp', 'value' => 100], false];
        yield 'neq satisfied' => [['op' => 'neq', 'var' => 'name', 'value' => 'bo'], true];
        yield 'neq unsatisfied' => [['op' => 'neq', 'var' => 'name', 'value' => 'ash'], false];
        yield 'lt satisfied' => [['op' => 'lt', 'var' => 'hp', 'value' => 96], true];
        yield 'lt unsatisfied' => [['op' => 'lt', 'var' => 'hp', 'value' => 95], false];
        yield 'lte satisfied' => [['op' => 'lte', 'var' => 'hp', 'value' => 95], true];
        yield 'lte unsatisfied' => [['op' => 'lte', 'var' => 'hp', 'value' => 94], false];
        yield 'gt satisfied' => [['op' => 'gt', 'var' => 'hp', 'value' => 94], true];
        yield 'gt unsatisfied' => [['op' => 'gt', 'var' => 'hp', 'value' => 95], false];
        yield 'gte satisfied' => [['op' => 'gte', 'var' => 'hp', 'value' => 95], true];
        yield 'gte unsatisfied' => [['op' => 'gte', 'var' => 'hp', 'value' => 96], false];
        yield 'flag satisfied' => [['op' => 'flag', 'flag' => 'met_elder'], true];
        yield 'flag unsatisfied' => [['op' => 'flag', 'flag' => 'never_set'], false];
        yield 'not_flag satisfied' => [['op' => 'not_flag', 'flag' => 'never_set'], true];
        yield 'not_flag unsatisfied' => [['op' => 'not_flag', 'flag' => 'met_elder'], false];
        yield 'has_item satisfied' => [['op' => 'has_item', 'item' => 'rope', 'qty' => 2], true];
        yield 'has_item unsatisfied' => [['op' => 'has_item', 'item' => 'rope', 'qty' => 3], false];
        yield 'visited satisfied' => [['op' => 'visited', 'scene' => 'cave', 'times' => 2], true];
        yield 'visited unsatisfied' => [['op' => 'visited', 'scene' => 'cave', 'times' => 3], false];

        yield 'and satisfied' => [[
            'op' => 'and',
            'of' => [['op' => 'flag', 'flag' => 'met_elder'], ['op' => 'eq', 'var' => 'hp', 'value' => 95]],
        ], true];
        yield 'and unsatisfied' => [[
            'op' => 'and',
            'of' => [['op' => 'flag', 'flag' => 'met_elder'], ['op' => 'eq', 'var' => 'hp', 'value' => 1]],
        ], false];
        yield 'or satisfied' => [[
            'op' => 'or',
            'of' => [['op' => 'flag', 'flag' => 'never_set'], ['op' => 'eq', 'var' => 'hp', 'value' => 95]],
        ], true];
        yield 'or unsatisfied' => [[
            'op' => 'or',
            'of' => [['op' => 'flag', 'flag' => 'never_set'], ['op' => 'eq', 'var' => 'hp', 'value' => 1]],
        ], false];
        yield 'not satisfied' => [['op' => 'not', 'of' => ['op' => 'flag', 'flag' => 'never_set']], true];
        yield 'not unsatisfied' => [['op' => 'not', 'of' => ['op' => 'flag', 'flag' => 'met_elder']], false];
    }

    /**
     * **"A missing variable resolves to its declared default, never null" is a
     * core rule, so every operator is asked it, not just `eq` and `gt`.**
     *
     * The state is `GameState::initial()` — five empty collections — so every
     * answer below comes from the schema or from an empty collection. Each
     * comparison row is paired, true and false around the default, which is
     * what makes the row a test of the default rather than of a constant.
     *
     * @param array<array-key, mixed> $node
     */
    #[DataProvider('schemaDefaultProvider')]
    public function testEveryOperatorAnswersFromTheSchemaDefaultOnAnEmptyState(array $node, bool $expected): void
    {
        self::assertSame($expected, $this->evaluator()->evaluate(Condition::fromArray($node), GameState::initial()));
    }

    /**
     * Defaults: hp = 100 (int), gold = 0 (int), ratio = 1.0 (float),
     * name = 'ash' (string), brave = false (bool).
     *
     * @return iterable<string, array{0: array<array-key, mixed>, 1: bool}>
     */
    public static function schemaDefaultProvider(): iterable
    {
        yield 'eq against the int default' => [['op' => 'eq', 'var' => 'hp', 'value' => 100], true];
        yield 'eq against another int' => [['op' => 'eq', 'var' => 'hp', 'value' => 99], false];
        yield 'eq against the float default' => [['op' => 'eq', 'var' => 'ratio', 'value' => 1.0], true];
        yield 'eq against the string default' => [['op' => 'eq', 'var' => 'name', 'value' => 'ash'], true];
        yield 'eq against the bool default' => [['op' => 'eq', 'var' => 'brave', 'value' => false], true];
        yield 'eq against the other bool' => [['op' => 'eq', 'var' => 'brave', 'value' => true], false];
        yield 'neq against the default' => [['op' => 'neq', 'var' => 'name', 'value' => 'ash'], false];
        yield 'neq against another string' => [['op' => 'neq', 'var' => 'name', 'value' => 'bo'], true];
        yield 'lt above the default' => [['op' => 'lt', 'var' => 'hp', 'value' => 101], true];
        yield 'lt at the default' => [['op' => 'lt', 'var' => 'hp', 'value' => 100], false];
        yield 'lte at the default' => [['op' => 'lte', 'var' => 'hp', 'value' => 100], true];
        yield 'lte below the default' => [['op' => 'lte', 'var' => 'hp', 'value' => 99], false];
        yield 'gt below the default' => [['op' => 'gt', 'var' => 'hp', 'value' => 99], true];
        yield 'gt at the default' => [['op' => 'gt', 'var' => 'hp', 'value' => 100], false];
        yield 'gte at the default' => [['op' => 'gte', 'var' => 'hp', 'value' => 100], true];
        yield 'gte above the default' => [['op' => 'gte', 'var' => 'hp', 'value' => 101], false];
        yield 'gt on the zero default' => [['op' => 'gt', 'var' => 'gold', 'value' => 0], false];
        yield 'gte on the zero default' => [['op' => 'gte', 'var' => 'gold', 'value' => 0], true];
        yield 'lt on the float default' => [['op' => 'lt', 'var' => 'ratio', 'value' => 1.5], true];

        // The open collections: no schema, nothing recorded, so the answer is
        // the empty one — and it is an answer, not an error.
        yield 'flag on an empty flag set' => [['op' => 'flag', 'flag' => 'met_elder'], false];
        yield 'not_flag on an empty flag set' => [['op' => 'not_flag', 'flag' => 'met_elder'], true];
        yield 'has_item on an empty inventory' => [['op' => 'has_item', 'item' => 'rope'], false];
        yield 'has_item qty 5 on an empty inventory' => [['op' => 'has_item', 'item' => 'rope', 'qty' => 5], false];
        yield 'visited on an empty visit map' => [['op' => 'visited', 'scene' => 'cave'], false];
        yield 'visited times 2 on an empty visit map' => [['op' => 'visited', 'scene' => 'cave', 'times' => 2], false];

        yield 'and over two defaults' => [[
            'op' => 'and',
            'of' => [['op' => 'eq', 'var' => 'hp', 'value' => 100], ['op' => 'eq', 'var' => 'gold', 'value' => 0]],
        ], true];
        yield 'or over two defaults' => [[
            'op' => 'or',
            'of' => [['op' => 'eq', 'var' => 'hp', 'value' => 1], ['op' => 'eq', 'var' => 'gold', 'value' => 0]],
        ], true];
        yield 'not over a default' => [['op' => 'not', 'of' => ['op' => 'eq', 'var' => 'hp', 'value' => 1]], true];
    }

    /**
     * The resolved default keeps its declared PHP type, which is the half of
     * the rule `assertSame` on a bool result cannot see: a `float` default
     * read back as `int 1` would make `eq … 1.0` false, and a `bool` default
     * read as `null` would make every comparison on it wrong in a way no
     * single assertion above would localise.
     */
    public function testADefaultIsResolvedWithItsDeclaredPhpTypeAndIsNeverNull(): void
    {
        $schema = VariableSchema::of([
            new VariableDefinition('hp', VariableType::Int, 100),
            new VariableDefinition('ratio', VariableType::Float, 1.0),
            new VariableDefinition('name', VariableType::String, 'ash'),
            new VariableDefinition('brave', VariableType::Bool, false),
        ]);
        $empty = GameState::initial();

        self::assertSame(100, $schema->resolve('hp', $empty));
        self::assertSame(1.0, $schema->resolve('ratio', $empty));
        self::assertSame('ash', $schema->resolve('name', $empty));
        self::assertSame(false, $schema->resolve('brave', $empty));

        // And the state really does not carry any of them.
        foreach (['hp', 'ratio', 'name', 'brave'] as $name) {
            self::assertFalse($empty->hasVar($name));
        }
    }

    public function testComparisonUsesTheSchemaDefaultWhenTheStateHasNoValue(): void
    {
        $empty = GameState::initial();
        $evaluator = $this->evaluator();

        self::assertTrue($evaluator->evaluate(Condition::fromArray(['op' => 'eq', 'var' => 'hp', 'value' => 100]), $empty));
        self::assertTrue($evaluator->evaluate(Condition::fromArray(['op' => 'eq', 'var' => 'gold', 'value' => 0]), $empty));
        self::assertFalse($evaluator->evaluate(Condition::fromArray(['op' => 'gt', 'var' => 'gold', 'value' => 0]), $empty));
        self::assertTrue($evaluator->evaluate(Condition::fromArray(['op' => 'eq', 'var' => 'name', 'value' => 'ash']), $empty));
    }

    /**
     * @param array<array-key, mixed> $node
     */
    #[DataProvider('orderingOnANonNumericVariableProvider')]
    public function testOrderingOperatorsRejectStringAndBoolVariables(array $node): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->evaluator()->evaluate(Condition::fromArray($node), $this->state());
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>}>
     */
    public static function orderingOnANonNumericVariableProvider(): iterable
    {
        yield 'lt on a string variable' => [['op' => 'lt', 'var' => 'name', 'value' => 'b']];
        yield 'lte on a string variable' => [['op' => 'lte', 'var' => 'name', 'value' => 'b']];
        yield 'gt on a bool variable' => [['op' => 'gt', 'var' => 'brave', 'value' => false]];
        yield 'gte on a bool variable' => [['op' => 'gte', 'var' => 'brave', 'value' => false]];
    }

    public function testComparisonRejectsALiteralOfTheWrongDeclaredType(): void
    {
        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage('hp');

        $this->evaluator()->evaluate(
            Condition::fromArray(['op' => 'eq', 'var' => 'hp', 'value' => 1.0]),
            $this->state(),
        );
    }

    /**
     * Float equality is exact and no epsilon is introduced: an epsilon would be
     * hidden policy, and the Engine has none.
     */
    public function testEqOnAFloatIsAnExactComparison(): void
    {
        $state = GameState::create(['ratio' => 0.1 + 0.2], [], [], [], []);
        $evaluator = $this->evaluator();

        self::assertFalse($evaluator->evaluate(Condition::fromArray(['op' => 'eq', 'var' => 'ratio', 'value' => 0.3]), $state));
        self::assertTrue($evaluator->evaluate(Condition::fromArray(['op' => 'eq', 'var' => 'ratio', 'value' => 0.1 + 0.2]), $state));
    }

    /**
     * **r2, as an author would meet it.** `ratio` is declared `float`, so both
     * the stored value and the literal are widened before the `===`. Above
     * 2^53 the widening is not injective, and the evaluator therefore reports
     * that a variable holding 9007199254740993 equals 9007199254740992 — two
     * literals an author wrote as different numbers.
     *
     * `hp` is declared `int`, is never widened, and stays exact at the same
     * magnitude. That contrast is the whole content of the limit: it belongs
     * to the `float` declaration, not to the Engine's comparison.
     */
    public function testAFloatDeclaredVariableCannotTellTwoIntegersAbove2Pow53Apart(): void
    {
        $evaluator = $this->evaluator();
        $state = GameState::create(['ratio' => 9007199254740993, 'hp' => 9007199254740993], [], [], [], []);

        self::assertTrue($evaluator->evaluate(
            Condition::fromArray(['op' => 'eq', 'var' => 'ratio', 'value' => 9007199254740993]),
            $state,
        ));
        self::assertTrue($evaluator->evaluate(
            Condition::fromArray(['op' => 'eq', 'var' => 'ratio', 'value' => 9007199254740992]),
            $state,
        ));

        // The int declaration keeps them apart.
        self::assertTrue($evaluator->evaluate(
            Condition::fromArray(['op' => 'eq', 'var' => 'hp', 'value' => 9007199254740993]),
            $state,
        ));
        self::assertFalse($evaluator->evaluate(
            Condition::fromArray(['op' => 'eq', 'var' => 'hp', 'value' => 9007199254740992]),
            $state,
        ));
    }

    public function testUnknownVariableThrows(): void
    {
        $this->expectException(UnknownVariableException::class);
        $this->expectExceptionMessage('mana');

        $this->evaluator()->evaluate(
            Condition::fromArray(['op' => 'eq', 'var' => 'mana', 'value' => 1]),
            $this->state(),
        );
    }

    /**
     * The child that would throw is the proof: if it were evaluated the test
     * would error instead of returning a value.
     */
    public function testAndShortCircuitsOnTheFirstFalseAndOrOnTheFirstTrue(): void
    {
        $exploding = ['op' => 'eq', 'var' => 'mana', 'value' => 1];
        $evaluator = $this->evaluator();

        self::assertFalse($evaluator->evaluate(
            Condition::fromArray(['op' => 'and', 'of' => [['op' => 'flag', 'flag' => 'never_set'], $exploding]]),
            $this->state(),
        ));

        self::assertTrue($evaluator->evaluate(
            Condition::fromArray(['op' => 'or', 'of' => [['op' => 'flag', 'flag' => 'met_elder'], $exploding]]),
            $this->state(),
        ));
    }

    public function testNestedAndOrNotEvaluateCorrectly(): void
    {
        $node = [
            'op' => 'and',
            'of' => [
                ['op' => 'flag', 'flag' => 'met_elder'],
                [
                    'op' => 'or',
                    'of' => [
                        ['op' => 'has_item', 'item' => 'lantern'],
                        ['op' => 'not', 'of' => ['op' => 'gt', 'var' => 'hp', 'value' => 95]],
                    ],
                ],
            ],
        ];

        self::assertTrue($this->evaluator()->evaluate(Condition::fromArray($node), $this->state()));

        $node['of'][0] = ['op' => 'flag', 'flag' => 'never_set'];

        self::assertFalse($this->evaluator()->evaluate(Condition::fromArray($node), $this->state()));
    }

    /**
     * Depth, not breadth. A `not` chain 64 levels deep must still answer by
     * parity, and a 32-level alternation of `and` / `or` must still reach the
     * leaf at the bottom — including the leaf that decides the answer, which
     * is flipped between the two halves of the test so neither result can be
     * a constant.
     *
     * Nesting is built by code rather than written out, because a hand-written
     * tree stops at the depth a human will type, which is not where recursion
     * breaks.
     */
    public function testDeeplyNestedLogicalOperatorsEvaluateToTheBottom(): void
    {
        $evaluator = $this->evaluator();
        $state = $this->state();

        $true = ['op' => 'flag', 'flag' => 'met_elder'];
        $false = ['op' => 'flag', 'flag' => 'never_set'];

        // 64 nested `not`s: an even number of negations is the identity.
        $node = $true;

        for ($depth = 0; $depth < 64; ++$depth) {
            $node = ['op' => 'not', 'of' => $node];
        }

        self::assertTrue($evaluator->evaluate(Condition::fromArray($node), $state));

        // 65 of them is one negation.
        self::assertFalse($evaluator->evaluate(
            Condition::fromArray(['op' => 'not', 'of' => $node]),
            $state,
        ));

        // 32 alternating and/or levels around a deciding leaf.
        $decides = static function (array $leaf) use ($true): array {
            $node = $leaf;

            for ($depth = 0; $depth < 32; ++$depth) {
                $node = $depth % 2 === 0
                    ? ['op' => 'and', 'of' => [$true, $node]]
                    : ['op' => 'or', 'of' => [$node]];
            }

            return $node;
        };

        self::assertTrue($evaluator->evaluate(Condition::fromArray($decides($true)), $state));
        self::assertFalse($evaluator->evaluate(Condition::fromArray($decides($false)), $state));
    }

    /**
     * The degenerate arity. An empty `of` is a parse error (there is no
     * identity element to invent), so the smallest legal `and` / `or` has one
     * child and must be exactly that child.
     */
    public function testASingleChildAndOrIsItsChild(): void
    {
        $evaluator = $this->evaluator();
        $state = $this->state();

        foreach (['and', 'or'] as $op) {
            self::assertTrue($evaluator->evaluate(
                Condition::fromArray(['op' => $op, 'of' => [['op' => 'flag', 'flag' => 'met_elder']]]),
                $state,
            ));
            self::assertFalse($evaluator->evaluate(
                Condition::fromArray(['op' => $op, 'of' => [['op' => 'flag', 'flag' => 'never_set']]]),
                $state,
            ));
        }
    }

    public function testHasItemAndVisitedAreTestedBelowAtAndAboveTheirThreshold(): void
    {
        $evaluator = $this->evaluator();
        $state = $this->state();

        self::assertTrue($evaluator->evaluate(Condition::fromArray(['op' => 'has_item', 'item' => 'rope']), $state));
        self::assertTrue($evaluator->evaluate(Condition::fromArray(['op' => 'has_item', 'item' => 'rope', 'qty' => 2]), $state));
        self::assertFalse($evaluator->evaluate(Condition::fromArray(['op' => 'has_item', 'item' => 'rope', 'qty' => 3]), $state));

        self::assertTrue($evaluator->evaluate(Condition::fromArray(['op' => 'visited', 'scene' => 'cave']), $state));
        self::assertTrue($evaluator->evaluate(Condition::fromArray(['op' => 'visited', 'scene' => 'cave', 'times' => 2]), $state));
        self::assertFalse($evaluator->evaluate(Condition::fromArray(['op' => 'visited', 'scene' => 'cave', 'times' => 3]), $state));
    }

    /**
     * A `Comparison` carrying an operator that is not a comparison cannot come
     * out of `fromArray()`, but the constructor is public and the class
     * hierarchy is open, so the evaluator refuses it loudly rather than
     * guessing. This is the branch that makes "add the operator to the
     * evaluator too" fail instead of silently doing nothing.
     */
    public function testAComparisonCarryingANonComparisonOperatorThrows(): void
    {
        $this->expectException(UnknownOperatorException::class);
        $this->expectExceptionMessage('flag');

        $this->evaluator()->evaluate(new Comparison(ConditionOp::Flag, 'hp', 1), $this->state());
    }

    public function testAConditionNodeTypeTheEvaluatorDoesNotKnowThrows(): void
    {
        $unknown = new readonly class () extends Condition {
            public function op(): ConditionOp
            {
                return ConditionOp::Eq;
            }

            /**
             * @return array<string, mixed>
             */
            public function toArray(): array
            {
                return ['op' => 'eq'];
            }

            protected static function parse(ArrayReader $reader, ConditionOp $op, string $path): self
            {
                throw new \LogicException('not parseable');
            }
        };

        $this->expectException(UnknownOperatorException::class);

        $this->evaluator()->evaluate($unknown, $this->state());
    }

    /**
     * B1, the silent variant: the same `eq` node and the same state, once
     * in-process and once after a trip through JSON. Before the fix the first
     * answer was `true` and the second `false` — no exception, no log entry, a
     * different story.
     */
    public function testAnEqOnAnIntegralFloatHoldsIdenticallyBeforeASaveAndAfterAReload(): void
    {
        $node = ['op' => 'eq', 'var' => 'ratio', 'value' => 1.0];
        $state = GameState::create(['ratio' => 1.0], [], [], [], []);

        $before = $this->evaluator()->evaluate(Condition::fromArray($node), $state);

        $stateJson = json_encode($state->toArray(), JSON_THROW_ON_ERROR);
        $nodeJson = json_encode(Condition::fromArray($node)->toArray(), JSON_THROW_ON_ERROR);

        $decodedState = json_decode($stateJson, true, 512, JSON_THROW_ON_ERROR);
        $decodedNode = json_decode($nodeJson, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decodedState);
        self::assertIsArray($decodedNode);

        $after = $this->evaluator()->evaluate(
            Condition::fromArray($decodedNode),
            GameState::fromArray($decodedState),
        );

        self::assertTrue($before);
        self::assertSame($before, $after);
    }

    private function evaluator(): ConditionEvaluator
    {
        return new ConditionEvaluator(VariableSchema::of([
            new VariableDefinition('hp', VariableType::Int, 100),
            new VariableDefinition('gold', VariableType::Int, 0),
            new VariableDefinition('ratio', VariableType::Float, 1.0),
            new VariableDefinition('name', VariableType::String, 'ash'),
            new VariableDefinition('brave', VariableType::Bool, false),
        ]));
    }

    private function state(): GameState
    {
        return GameState::create(
            ['hp' => 95, 'name' => 'ash', 'ratio' => 0.5, 'brave' => true],
            ['met_elder'],
            ['rope' => 2],
            ['cave' => 2],
            ['cave', 'crossroads', 'cave'],
        );
    }
}
