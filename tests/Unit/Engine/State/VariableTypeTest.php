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
     * The grid is exhaustive on purpose, and deliberately asymmetric: `Float`
     * accepts an int (widening is lossless, and `json_decode` hands back an
     * int for any float written with a zero fraction), while `Int` still
     * refuses a float, which is what makes "no silent narrowing" enforceable
     * later in the applier.
     */
    #[DataProvider('acceptanceGridProvider')]
    public function testAcceptsMatchesTheDeclaredType(
        VariableType $type,
        int|float|string|bool $value,
        bool $expected,
    ): void {
        self::assertSame($expected, $type->accepts($value));
    }

    /**
     * The single normalisation point behind B1: an int reaching a
     * `Float`-declared variable becomes a real float, and nothing else is
     * touched. Narrowing is never performed — a float stays a float even for
     * an `Int` declaration, so `accepts()` can still refuse it.
     */
    #[DataProvider('canonicalisationGridProvider')]
    public function testCanonicaliseWidensOnlyAnIntIntoAFloatDeclaration(
        VariableType $type,
        int|float|string|bool $value,
        int|float|string|bool $expected,
    ): void {
        // `assertSame` on a scalar is `===`, so this pins the PHP type as
        // well as the value: `1` would not satisfy an expected `1.0`.
        self::assertSame($expected, $type->canonicalise($value));
    }

    #[DataProvider('numericGridProvider')]
    public function testIsNumericIsTrueForIntAndFloatOnly(VariableType $type, bool $expected): void
    {
        self::assertSame($expected, $type->isNumeric());
    }

    /**
     * **r2 — the class docblock's "widening loses nothing" is false above
     * 2^53, and this is where the limit becomes discoverable.**
     *
     * A `float` holds 53 bits of mantissa, so `9007199254740993` — the first
     * odd integer past the boundary — is not representable and widening
     * silently returns its even neighbour. `accepts()` says yes, so nothing
     * throws; the author's number is simply changed. The limit is a property
     * of IEEE-754 and of JSON, not of this code, which is why the honest fix
     * is to correct the docblock rather than the behaviour — but the limit
     * must be written down somewhere that fails if it ever moves.
     *
     * An `int`-declared variable is unaffected: nothing is ever narrowed, so
     * the same literal stays exact.
     */
    public function testWideningAnIntIntoAFloatDeclarationIsLossyAbove2Pow53(): void
    {
        $float = VariableType::Float;

        self::assertSame(9007199254740992, 2 ** 53);

        // At the boundary, widening is exact in both directions.
        self::assertTrue($float->accepts(9007199254740992));
        self::assertSame(9007199254740992.0, $float->canonicalise(9007199254740992));
        self::assertSame(9007199254740992, (int) $float->canonicalise(9007199254740992));

        // One above it, the value is accepted and then quietly changed.
        self::assertTrue($float->accepts(9007199254740993));
        self::assertSame(9007199254740992.0, $float->canonicalise(9007199254740993));
        self::assertNotSame(9007199254740993, (int) $float->canonicalise(9007199254740993));

        // The asymmetry's other half is exact at any magnitude: an int
        // declaration never narrows, so it never loses a digit.
        self::assertSame(9007199254740993, VariableType::Int->canonicalise(9007199254740993));
        self::assertSame(PHP_INT_MAX, VariableType::Int->canonicalise(PHP_INT_MAX));
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
                // The one deliberate widening: a float declaration also takes
                // an int, because that is what a float with a zero fraction
                // comes back as from JSON.
                $expected = $type->value === $label
                    || ($type === VariableType::Float && $label === 'int');

                yield sprintf('%s accepts %s', $type->value, $label) => [$type, $value, $expected];
            }
        }
    }

    /**
     * @return iterable<string, array{0: VariableType, 1: int|float|string|bool, 2: int|float|string|bool}>
     */
    public static function canonicalisationGridProvider(): iterable
    {
        yield 'float declaration widens an int' => [VariableType::Float, 1, 1.0];
        yield 'float declaration widens a negative int' => [VariableType::Float, -3, -3.0];
        yield 'float declaration widens zero' => [VariableType::Float, 0, 0.0];
        yield 'float declaration leaves a float alone' => [VariableType::Float, 2.5, 2.5];
        yield 'int declaration leaves an int alone' => [VariableType::Int, 1, 1];
        yield 'int declaration never narrows a float' => [VariableType::Int, 1.0, 1.0];
        yield 'string declaration leaves a string alone' => [VariableType::String, '1', '1'];
        yield 'bool declaration leaves a bool alone' => [VariableType::Bool, true, true];

        // r2: widening is exact at 2^53 and lossy one above it.
        yield 'float declaration widens 2^53 exactly' => [
            VariableType::Float,
            9007199254740992,
            9007199254740992.0,
        ];
        yield 'float declaration rounds 2^53 + 1 to its even neighbour' => [
            VariableType::Float,
            9007199254740993,
            9007199254740992.0,
        ];
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
