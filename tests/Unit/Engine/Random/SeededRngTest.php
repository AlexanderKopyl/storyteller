<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine\Random;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use SplFileInfo;
use Storyteller\Engine\Random\Rng;
use Storyteller\Engine\Random\SeededRng;

/**
 * The RNG is index-addressed rather than sequential, so every assertion below
 * is a property of a pure function of `(seed, turn, index)` — there is no
 * internal cursor to advance, nothing to warm up and nothing to reset. That is
 * also why none of these tests can be flaky: a "random" value here is a fixed
 * value whose number nobody has written down.
 *
 * Within-process purity is the weaker half. The stronger half — the one a
 * saved game actually depends on — is pinned by
 * {@see self::testTheGoldenValuesOfTheDocumentedSeedTriplesNeverChange()}:
 * without literal expected values, every assertion here would still pass if
 * `Random\Engine\Xoshiro256StarStar` started producing entirely different
 * numbers, because the suite would merely be comparing the new numbers with
 * themselves.
 */
#[CoversClass(SeededRng::class)]
final class SeededRngTest extends TestCase
{
    private const string SEED = 'storyteller/engine-core';

    public function testTheSameSeedTurnAndIndexAlwaysProduceTheSameValue(): void
    {
        $rng = new SeededRng(self::SEED);

        $first = $rng->intBetween(4, 2, 0, 1_000_000);

        // Interleave other draws: an index-addressed generator has no cursor
        // for them to advance, so the original draw must still agree.
        $rng->intBetween(4, 3, 0, 1_000_000);
        $rng->intBetween(9, 2, 0, 1_000_000);
        $rng->fraction(4, 2);

        self::assertSame($first, $rng->intBetween(4, 2, 0, 1_000_000));
        self::assertSame($first, (new SeededRng(self::SEED))->intBetween(4, 2, 0, 1_000_000));

        $fraction = $rng->fraction(4, 2);

        self::assertSame($fraction, $rng->fraction(4, 2));
        self::assertSame($fraction, (new SeededRng(self::SEED))->fraction(4, 2));
    }

    public function testTwoInstancesWithTheSameSeedAgreeOnAWholeSequence(): void
    {
        $a = new SeededRng(self::SEED);
        $b = new SeededRng(self::SEED);

        self::assertNotSame($a, $b);

        for ($index = 0; $index < 16; ++$index) {
            self::assertSame(
                $a->intBetween(1, $index, 1, 6),
                $b->intBetween(1, $index, 1, 6),
                sprintf('The two instances disagree at index %d.', $index),
            );
            self::assertSame($a->fraction(1, $index), $b->fraction(1, $index));
        }
    }

    public function testADifferentTurnProducesADifferentSequence(): void
    {
        $rng = new SeededRng(self::SEED);

        self::assertNotSame(self::sequence($rng, 1), self::sequence($rng, 2));
    }

    public function testADifferentIndexProducesADifferentValueWithinATurn(): void
    {
        $values = self::sequence(new SeededRng(self::SEED), 1);

        self::assertCount(16, $values);
        self::assertCount(16, array_unique($values, SORT_REGULAR));
    }

    public function testADifferentSeedProducesADifferentSequence(): void
    {
        $one = self::sequence(new SeededRng(self::SEED), 1);
        $other = self::sequence(new SeededRng(self::SEED . '!'), 1);

        self::assertNotSame($one, $other);
    }

    public function testIntBetweenAlwaysReturnsAValueInRangeIncludingTheDegenerateRange(): void
    {
        $rng = new SeededRng(self::SEED);
        $seen = [];

        for ($index = 0; $index < 200; ++$index) {
            $value = $rng->intBetween(1, $index, 3, 9);

            self::assertGreaterThanOrEqual(3, $value);
            self::assertLessThanOrEqual(9, $value);

            $seen[$value] = true;
            self::assertSame(4, $rng->intBetween(1, $index, 4, 4));
        }

        // Non-vacuous: the range is actually explored, not pinned to one end.
        self::assertGreaterThan(1, count($seen));
    }

    public function testFractionIsAlwaysInTheHalfOpenUnitInterval(): void
    {
        $rng = new SeededRng(self::SEED);

        for ($index = 0; $index < 200; ++$index) {
            $value = $rng->fraction(2, $index);

            self::assertGreaterThanOrEqual(0.0, $value);
            self::assertLessThan(1.0, $value);
        }
    }

    /**
     * **M6 — the golden values. A change here is a format break, not a test to
     * update.**
     *
     * A player's save stores a seed and replays draws against it, so
     * `(seed, turn, index) -> value` is a persisted format in everything but
     * name. Every other test in this file compares the implementation with
     * itself and would stay green if a future PHP re-rolled every historical
     * draw; these literals are the only thing that would go red.
     *
     * Recorded on PHP 8.5.3 by running the committed `SeededRng` and printing
     * the results — see `docs/sdlc/engine-core/05-test-report.md`. If this
     * test fails after a PHP upgrade, the digest assertion says which half
     * moved: a changed digest means `hash('sha256', …)`, an unchanged digest
     * with changed draws means `Xoshiro256StarStar` or `Randomizer`. Either
     * way, existing saves no longer replay and the fix is a seeding migration,
     * not a new expected value.
     */
    public function testTheGoldenValuesOfTheDocumentedSeedTriplesNeverChange(): void
    {
        $rng = new SeededRng(self::SEED);

        // The derivation's own input, so a failure below can be diagnosed
        // without a debugger: this is the 32-byte engine seed for (turn 1, index 0).
        self::assertSame(
            'c0433713bd5a0f31d0990ce24b8793365e4e153e0a8bf2c1f03b23087976e51b',
            hash('sha256', self::SEED . '|1|0'),
        );

        self::assertSame(3, $rng->intBetween(1, 0, 1, 6));
        self::assertSame(29, $rng->intBetween(7, 3, 0, 100));
        self::assertSame(8603048871373097032, $rng->intBetween(1, 0, 0, PHP_INT_MAX));
        self::assertSame(-1, $rng->intBetween(42, 9, -10, 10));

        self::assertSame(0.869706102612091, $rng->fraction(1, 0));
        self::assertSame(0.06122881568830785, $rng->fraction(7, 3));

        // The numeric convenience seed is part of the same format.
        self::assertSame(3, SeededRng::fromInt(42)->intBetween(1, 0, 1, 6));
        self::assertSame(0.627412892597092, SeededRng::fromInt(42)->fraction(1, 0));
    }

    public function testFromIntProducesAUsableSeed(): void
    {
        $a = SeededRng::fromInt(42);
        $b = SeededRng::fromInt(42);
        $other = SeededRng::fromInt(43);

        self::assertInstanceOf(Rng::class, $a);
        self::assertSame(self::sequence($a, 1), self::sequence($b, 1));
        self::assertNotSame(self::sequence($a, 1), self::sequence($other, 1));
    }

    /**
     * The forward-contract claim, asserted rather than written in a comment:
     * nothing in the Engine consumes randomness yet. `Transition` already
     * threads `int $turn` so that a future `chance` condition or `roll` effect
     * can be wired in without changing a signature.
     */
    public function testNothingInSrcEngineReferencesRng(): void
    {
        $engineRoot = dirname(__DIR__, 4) . '/src/Engine';
        $randomDir = $engineRoot . '/Random/';
        $scanned = 0;

        foreach (self::phpFilesIn($engineRoot) as $file) {
            if (str_starts_with($file, $randomDir)) {
                continue;
            }

            $contents = file_get_contents($file);

            self::assertIsString($contents);
            self::assertDoesNotMatchRegularExpression(
                '/\bRng\b|Engine\\\\Random\b/',
                $contents,
                sprintf('"%s" references the RNG, which is an unused forward contract.', $file),
            );

            ++$scanned;
        }

        self::assertGreaterThan(40, $scanned, 'The reference scan covered almost nothing.');

        // And no Engine signature anywhere accepts or returns an Rng.
        foreach (self::phpFilesIn($engineRoot) as $file) {
            $relative = substr($file, strlen($engineRoot . '/'), -4);

            /** @var class-string $class */
            $class = 'Storyteller\\Engine\\' . str_replace('/', '\\', $relative);

            if ($class === SeededRng::class || $class === Rng::class) {
                continue;
            }

            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                self::assertNotContains(Rng::class, self::typeNamesOf($method), sprintf(
                    '%s::%s() mentions Rng in its signature.',
                    $class,
                    $method->getName(),
                ));
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function typeNamesOf(ReflectionMethod $method): array
    {
        $types = [$method->getReturnType()];

        foreach ($method->getParameters() as $parameter) {
            $types[] = $parameter->getType();
        }

        $names = [];

        foreach ($types as $type) {
            foreach (self::flatten($type) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function flatten(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType) {
            $names = [];

            foreach ($type->getTypes() as $member) {
                foreach (self::flatten($member) as $name) {
                    $names[] = $name;
                }
            }

            return $names;
        }

        return [];
    }

    /**
     * Sixteen draws across indices 0-15 of one turn, wide enough that a
     * collision between two indices would be a real defect rather than luck.
     *
     * @return list<int>
     */
    private static function sequence(Rng $rng, int $turn): array
    {
        $values = [];

        for ($index = 0; $index < 16; ++$index) {
            $values[] = $rng->intBetween($turn, $index, 0, PHP_INT_MAX);
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private static function phpFilesIn(string $directory): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $directory,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }
}
