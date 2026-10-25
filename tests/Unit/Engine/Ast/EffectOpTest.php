<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine\Ast;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\Ast\EffectOp;

/**
 * Like the condition operators, the effect `op` strings are a published
 * format: an operator added, removed or renamed without this list changing
 * would silently invalidate compiled stories.
 */
#[CoversClass(EffectOp::class)]
final class EffectOpTest extends TestCase
{
    public function testCaseListIsExactlyTheEightDeclaredOperators(): void
    {
        $values = array_map(static fn (EffectOp $op): string => $op->value, EffectOp::cases());

        self::assertSame(
            ['set', 'add', 'sub', 'set_flag', 'clear_flag', 'give_item', 'remove_item', 'goto'],
            $values,
        );
        self::assertCount(8, EffectOp::cases());
    }

    #[DataProvider('arithmeticProvider')]
    public function testIsArithmeticIsTrueForAddAndSubOnly(EffectOp $op, bool $expected): void
    {
        self::assertSame($expected, $op->isArithmetic());
    }

    /**
     * @return iterable<string, array{0: EffectOp, 1: bool}>
     */
    public static function arithmeticProvider(): iterable
    {
        foreach (EffectOp::cases() as $op) {
            yield $op->value => [$op, in_array($op->value, ['add', 'sub'], true)];
        }
    }
}
