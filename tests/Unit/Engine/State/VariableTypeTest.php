<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\State\VariableType;

/**
 * The four declared variable types and the two questions the rest of the
 * Engine asks about them: "does this literal match the declaration?" and "may
 * an ordering or arithmetic operator touch it?".
 */
#[CoversClass(VariableType::class)]
final class VariableTypeTest extends TestCase
{
    public function testCaseListIsExactlyTheFourDeclaredTypes(): void
    {
        $values = array_map(static fn (VariableType $type): string => $type->value, VariableType::cases());

        self::assertSame(['int', 'float', 'string', 'bool'], $values);
    }

    /**
     * The grid is exhaustive on purpose: `Float` does **not** accept an int,
     * which is what makes "no silent promotion" enforceable later in the
     * applier.
     */
    #[DataProvider('acceptanceGridProvider')]
    public function testAcceptsMatchesTheDeclaredType(
        VariableType $type,
        int|float|string|bool $value,
        bool $expected,
    ): void {
        self::assertSame($expected, $type->accepts($value));
    }

    #[DataProvider('numericGridProvider')]
    public function testIsNumericIsTrueForIntAndFloatOnly(VariableType $type, bool $expected): void
    {
        self::assertSame($expected, $type->isNumeric());
    }

    /**
     * @return iterable<string, array{0: VariableType, 1: int|float|string|bool, 2: bool}>
     */
    public static function acceptanceGridProvider(): iterable
    {
        $values = [
            'int' => 1,
            'float' => 1.0,
            'string' => '1',
            'bool' => true,
        ];

        foreach (VariableType::cases() as $type) {
            foreach ($values as $label => $value) {
                yield sprintf('%s accepts %s', $type->value, $label) => [$type, $value, $type->value === $label];
            }
        }
    }

    /**
     * @return iterable<string, array{0: VariableType, 1: bool}>
     */
    public static function numericGridProvider(): iterable
    {
        yield 'int' => [VariableType::Int, true];
        yield 'float' => [VariableType::Float, true];
        yield 'string' => [VariableType::String, false];
        yield 'bool' => [VariableType::Bool, false];
    }
}
