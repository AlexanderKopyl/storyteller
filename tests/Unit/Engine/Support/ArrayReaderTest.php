<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine\Support;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\Exception\MalformedNodeException;
use Storyteller\Engine\Support\ArrayReader;

/**
 * The reader is the one place in `src/Engine` that touches `mixed`, so every
 * narrowing it performs is pinned here: PHP's type juggling must not leak
 * through it, and every rejection must name the path and the key.
 */
#[CoversClass(ArrayReader::class)]
final class ArrayReaderTest extends TestCase
{
    public function testRequireStringReturnsTheValue(): void
    {
        self::assertSame('cave', self::reader(['scene' => 'cave'])->requireString('scene'));
        self::assertSame('', self::reader(['scene' => ''])->requireString('scene'));
    }

    public function testRequireStringRejectsAMissingKey(): void
    {
        self::assertMalformed(
            static fn (): string => self::reader([], 'condition')->requireString('scene'),
            'condition',
            'scene',
            'missing',
        );
    }

    public function testRequireStringRejectsAWrongType(): void
    {
        self::assertMalformed(
            static fn (): string => self::reader(['scene' => 7], 'condition')->requireString('scene'),
            'condition',
            'scene',
            'string',
            'int',
        );
    }

    public function testRequireIntReturnsTheValue(): void
    {
        self::assertSame(-3, self::reader(['qty' => -3])->requireInt('qty'));
        self::assertSame(0, self::reader(['qty' => 0])->requireInt('qty'));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    #[DataProvider('nonIntegerProvider')]
    public function testRequireIntRejectsAFloatAndABooleanAndANumericString(array $data): void
    {
        self::assertMalformed(
            static fn (): int => self::reader($data, 'effect')->requireInt('qty'),
            'effect',
            'qty',
            'int',
        );
    }

    public function testRequirePositiveIntReturnsTheValue(): void
    {
        self::assertSame(1, self::reader(['times' => 1])->requirePositiveInt('times'));
        self::assertSame(99, self::reader(['times' => 99])->requirePositiveInt('times'));
    }

    #[DataProvider('nonPositiveIntProvider')]
    public function testRequirePositiveIntRejectsZeroAndNegatives(int $value): void
    {
        self::assertMalformed(
            static fn (): int => self::reader(['times' => $value], 'condition')->requirePositiveInt('times'),
            'condition',
            'times',
            'greater than or equal to 1',
        );
    }

    public function testOptionalPositiveIntReturnsTheDefaultWhenAbsent(): void
    {
        self::assertSame(1, self::reader([])->optionalPositiveInt('qty', 1));
        self::assertSame(4, self::reader([])->optionalPositiveInt('qty', 4));
        self::assertSame(2, self::reader(['qty' => 2])->optionalPositiveInt('qty', 1));
    }

    public function testOptionalPositiveIntStillValidatesAPresentValue(): void
    {
        self::assertMalformed(
            static fn (): int => self::reader(['qty' => 0], 'effect')->optionalPositiveInt('qty', 1),
            'effect',
            'qty',
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    #[DataProvider('scalarProvider')]
    public function testRequireScalarAcceptsIntFloatStringAndBool(array $data, int|float|string|bool $expected): void
    {
        self::assertSame($expected, self::reader($data)->requireScalar('value'));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    #[DataProvider('nonScalarProvider')]
    public function testRequireScalarRejectsNullAndArray(array $data): void
    {
        self::assertMalformed(
            static fn (): int|float|string|bool => self::reader($data, 'condition')->requireScalar('value'),
            'condition',
            'value',
        );
    }

    public function testRequireNumberAcceptsIntAndFloat(): void
    {
        self::assertSame(5, self::reader(['value' => 5])->requireNumber('value'));
        self::assertSame(-0.5, self::reader(['value' => -0.5])->requireNumber('value'));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    #[DataProvider('nonNumberProvider')]
    public function testRequireNumberRejectsStringAndBool(array $data): void
    {
        self::assertMalformed(
            static fn (): int|float => self::reader($data, 'effect')->requireNumber('value'),
            'effect',
            'value',
            'number',
        );
    }

    public function testRequireArrayReturnsTheValueAndRejectsAMissingKeyAndAScalar(): void
    {
        self::assertSame(['a' => 1], self::reader(['of' => ['a' => 1]])->requireArray('of'));

        self::assertMalformed(
            static fn (): array => self::reader([], 'condition')->requireArray('of'),
            'condition',
            'of',
        );
        self::assertMalformed(
            static fn (): array => self::reader(['of' => 'x'], 'condition')->requireArray('of'),
            'condition',
            'of',
            'array',
        );
    }

    public function testOptionalArrayReturnsNullWhenAbsent(): void
    {
        self::assertNull(self::reader([])->optionalArray('condition'));
        self::assertSame(['op' => 'flag'], self::reader(['condition' => ['op' => 'flag']])->optionalArray('condition'));
    }

    public function testRequireArrayListReturnsTheElements(): void
    {
        $reader = self::reader(['of' => [['op' => 'a'], ['op' => 'b']]]);

        self::assertSame([['op' => 'a'], ['op' => 'b']], $reader->requireArrayList('of'));
        self::assertSame([], self::reader(['of' => []])->requireArrayList('of'));
    }

    public function testRequireArrayListRejectsANonListAndANonArrayElement(): void
    {
        self::assertMalformed(
            static fn (): array => self::reader(['of' => ['a' => ['op' => 'x']]], 'condition')->requireArrayList('of'),
            'condition',
            'of',
            'list',
        );
        self::assertMalformed(
            static fn (): array => self::reader(['of' => [['op' => 'x'], 7]], 'condition')->requireArrayList('of'),
            'condition.of',
            '1',
            'array',
        );
    }

    public function testOptionalArrayListReturnsTheDefaultWhenAbsent(): void
    {
        self::assertSame([], self::reader([])->optionalArrayList('effects'));
        self::assertSame([['op' => 'x']], self::reader([])->optionalArrayList('effects', [['op' => 'x']]));
        self::assertSame([['op' => 'y']], self::reader(['effects' => [['op' => 'y']]])->optionalArrayList('effects'));
    }

    public function testRequireStringListReturnsTheElementsAndRejectsNonStrings(): void
    {
        self::assertSame(['a', 'b'], self::reader(['flags' => ['a', 'b']])->requireStringList('flags'));

        self::assertMalformed(
            static fn (): array => self::reader(['flags' => ['x' => 'a']], 'state')->requireStringList('flags'),
            'state',
            'flags',
            'list',
        );
        self::assertMalformed(
            static fn (): array => self::reader(['flags' => ['a', 2]], 'state')->requireStringList('flags'),
            'state.flags',
            '1',
            'string',
        );
    }

    public function testRequireIntMapCastsKeysToStringAndRejectsNonIntValues(): void
    {
        $map = self::reader(['inventory' => ['10' => 2, 'rope' => 1]])->requireIntMap('inventory');

        self::assertSame(['10' => 2, 'rope' => 1], $map);

        self::assertMalformed(
            static fn (): array => self::reader(['inventory' => ['rope' => 1.5]], 'state')->requireIntMap('inventory'),
            'state.inventory',
            'rope',
            'int',
        );
        self::assertMalformed(
            static fn (): array => self::reader(['inventory' => 'x'], 'state')->requireIntMap('inventory'),
            'state',
            'inventory',
        );
    }

    public function testRequireScalarMapAcceptsEveryScalarAndRejectsNestedArrays(): void
    {
        $map = self::reader(['vars' => ['gold' => 10, 'ratio' => 0.5, 'name' => 'ash', 'alive' => true]])
            ->requireScalarMap('vars');

        self::assertSame(['gold' => 10, 'ratio' => 0.5, 'name' => 'ash', 'alive' => true], $map);

        self::assertMalformed(
            static fn (): array => self::reader(['vars' => ['gold' => []]], 'state')->requireScalarMap('vars'),
            'state.vars',
            'gold',
        );
    }

    public function testHasReportsAPresentNonNullKey(): void
    {
        $reader = self::reader(['a' => 0, 'b' => null]);

        self::assertTrue($reader->has('a'));
        self::assertFalse($reader->has('b'), 'A null value counts as absent, matching the ?? narrowing.');
        self::assertFalse($reader->has('c'));
    }

    /**
     * The one authored key with a union shape is a scene's `next`, which is
     * either a scene name or a rule list. Asking first keeps that branch here
     * rather than making the caller touch the raw array.
     */
    public function testIsStringDistinguishesAStringValueFromEveryOtherShape(): void
    {
        $reader = self::reader(['scene' => 'cave', 'rules' => [['scene' => 'cave']], 'nothing' => null]);

        self::assertTrue($reader->isString('scene'));
        self::assertFalse($reader->isString('rules'));
        self::assertFalse($reader->isString('nothing'));
        self::assertFalse($reader->isString('absent'));
    }

    public function testRejectUnknownKeysAcceptsTheAllowedSet(): void
    {
        $reader = self::reader(['op' => 'has_item', 'item' => 'rope', 'qty' => 2]);

        $reader->rejectUnknownKeys('op', 'item', 'qty');

        self::assertTrue($reader->has('op'));
    }

    public function testRejectUnknownKeysThrowsOnTheFirstExtraKey(): void
    {
        self::assertMalformed(
            static function (): void {
                self::reader(['op' => 'flag', 'flag' => 'a', 'extra' => 1], 'condition')
                    ->rejectUnknownKeys('op', 'flag');
            },
            'condition',
            'extra',
        );
    }

    public function testChildExtendsThePathInErrorMessages(): void
    {
        $reader = self::reader(['b' => ['c' => 7]], 'a');

        self::assertSame(7, $reader->child('b')->requireInt('c'));

        self::assertMalformed(
            static fn (): string => self::reader(['b' => ['c' => 7]], 'a')->child('b')->requireString('c'),
            'a.b',
            'c',
        );
        self::assertMalformed(
            static fn (): ArrayReader => self::reader(['b' => 'nope'], 'a')->child('b'),
            'a',
            'b',
            'array',
        );
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>}>
     */
    public static function nonIntegerProvider(): iterable
    {
        yield 'float' => [['qty' => 1.0]];
        yield 'bool' => [['qty' => true]];
        yield 'numeric string' => [['qty' => '1']];
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function nonPositiveIntProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>, 1: int|float|string|bool}>
     */
    public static function scalarProvider(): iterable
    {
        yield 'int' => [['value' => 7], 7];
        yield 'float' => [['value' => 7.5], 7.5];
        yield 'string' => [['value' => 'ash'], 'ash'];
        yield 'bool' => [['value' => false], false];
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>}>
     */
    public static function nonScalarProvider(): iterable
    {
        yield 'null' => [['value' => null]];
        yield 'array' => [['value' => []]];
        yield 'absent' => [[]];
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>}>
     */
    public static function nonNumberProvider(): iterable
    {
        yield 'numeric string' => [['value' => '5']];
        yield 'bool' => [['value' => true]];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function reader(array $data, string $path = 'node'): ArrayReader
    {
        return new ArrayReader($data, $path);
    }

    private static function assertMalformed(Closure $call, string ...$expectedFragments): void
    {
        try {
            $call();
        } catch (MalformedNodeException $exception) {
            foreach ($expectedFragments as $fragment) {
                self::assertStringContainsString($fragment, $exception->getMessage());
            }

            return;
        }

        self::fail('Expected a MalformedNodeException, none was thrown.');
    }
}
