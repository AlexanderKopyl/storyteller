<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine\Ast;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\Ast\ConditionOp;

/**
 * The operator list is a published format: a story compiled against it lives
 * in an immutable story version, so an operator silently added, removed or
 * renamed has to fail here rather than at play time.
 */
#[CoversClass(ConditionOp::class)]
final class ConditionOpTest extends TestCase
{
    public function testCaseListIsExactlyTheThirteenDeclaredOperators(): void
    {
        $values = array_map(static fn (ConditionOp $op): string => $op->value, ConditionOp::cases());

        self::assertSame(
            ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'flag', 'not_flag', 'has_item', 'visited', 'and', 'or', 'not'],
            $values,
        );
        self::assertCount(13, ConditionOp::cases());
    }

    #[DataProvider('partitionProvider')]
    public function testIsComparisonAndIsOrderingPartitionTheCases(
        ConditionOp $op,
        bool $isComparison,
        bool $isOrdering,
    ): void {
        self::assertSame($isComparison, $op->isComparison());
        self::assertSame($isOrdering, $op->isOrdering());
    }

    public function testEveryOrderingOperatorIsAlsoAComparison(): void
    {
        foreach (ConditionOp::cases() as $op) {
            if ($op->isOrdering()) {
                self::assertTrue($op->isComparison(), sprintf('%s orders but does not compare.', $op->value));
            }
        }
    }

    /**
     * @return iterable<string, array{0: ConditionOp, 1: bool, 2: bool}>
     */
    public static function partitionProvider(): iterable
    {
        $comparisons = ['eq', 'neq', 'lt', 'lte', 'gt', 'gte'];
        $orderings = ['lt', 'lte', 'gt', 'gte'];

        foreach (ConditionOp::cases() as $op) {
            yield $op->value => [
                $op,
                in_array($op->value, $comparisons, true),
                in_array($op->value, $orderings, true),
            ];
        }
    }
}
