<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Shared;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins deptrac's `Unlayered` catch-all (review finding M3).
 *
 * composer.json maps `"Storyteller\\": "src/"`, so a file at a new top-level
 * directory under src/ is valid, autoloadable production code. deptrac only
 * evaluates a dependency whose *depender* is layered, so before the catch-all
 * such a class was reported nowhere at all - not a violation, not Uncovered.
 *
 * The catch-all is three regexes with one deliberate exception
 * (src/Kernel.php, decision R13), and a wrong regex fails open and silently.
 * This test applies them the way deptrac's DirectoryCollector does - `#<value>#i`
 * against a normalised path - so the exception stays exactly one file wide.
 */
#[CoversNothing]
final class UnlayeredSourceTest extends TestCase
{
    public function testUnlayeredIsGrantedNothingAndGrantedToNobody(): void
    {
        $ruleset = self::ruleset();

        self::assertArrayHasKey('Unlayered', $ruleset, 'deptrac.yaml declares no Unlayered catch-all.');
        self::assertSame([], $ruleset['Unlayered'], 'Unlayered must be allowed to depend on nothing.');

        foreach ($ruleset as $layer => $allowed) {
            self::assertNotContains(
                'Unlayered',
                (array) $allowed,
                sprintf('Layer "%s" is granted Unlayered; code outside the five contexts must stay unreachable.', $layer),
            );
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function pathProvider(): iterable
    {
        yield 'a new top-level directory is caught' => ['/app/src/Support/M3Probe.php', true];
        yield 'a nested file under one is caught' => ['/app/src/Support/Deep/Nested/Thing.php', true];
        yield 'a loose file at the src root is caught' => ['/app/src/Loose.php', true];

        yield 'src/Kernel.php is the one carve-out' => ['/app/src/Kernel.php', false];
        yield 'Shared is a real layer' => ['/app/src/Shared/StoryId.php', false];
        yield 'Engine is a real layer' => ['/app/src/Engine/GameState.php', false];
        yield 'Content is a real layer' => ['/app/src/Content/Domain/Story.php', false];
        yield 'Play is a real layer' => ['/app/src/Play/Domain/Session.php', false];
        yield 'Api is a real layer' => ['/app/src/Api/Controller/PlayController.php', false];
        yield 'tests/ is not src/' => ['/app/tests/Unit/Shared/UnlayeredSourceTest.php', false];
        yield 'vendor is not src/' => ['/app/vendor/symfony/routing/Route.php', false];
    }

    #[DataProvider('pathProvider')]
    public function testCatchAllClassifiesPathsAsIntended(string $path, bool $expected): void
    {
        self::assertSame(
            $expected,
            self::isUnlayered($path),
            sprintf('deptrac.yaml\'s Unlayered collector classifies "%s" wrongly.', $path),
        );
    }

    /**
     * Mirrors BoolCollector over DirectoryCollector: all `must` match and no
     * `must_not` matches.
     */
    private static function isUnlayered(string $path): bool
    {
        $collector = self::unlayeredBoolCollector();

        foreach ((array) ($collector['must'] ?? []) as $must) {
            if (!self::pathMatches($must, $path)) {
                return false;
            }
        }

        foreach ((array) ($collector['must_not'] ?? []) as $mustNot) {
            if (self::pathMatches($mustNot, $path)) {
                return false;
            }
        }

        return true;
    }

    private static function pathMatches(mixed $collector, string $path): bool
    {
        self::assertIsArray($collector);
        self::assertSame('directory', $collector['type'] ?? null, 'The Unlayered catch-all is expected to use directory collectors only.');

        $value = $collector['value'] ?? null;
        self::assertIsString($value);

        return preg_match('#' . $value . '#i', $path) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private static function unlayeredBoolCollector(): array
    {
        /** @var list<array{name?: string, collectors?: list<array<string, mixed>>}> $layers */
        $layers = self::deptrac()['layers'] ?? [];

        foreach ($layers as $layer) {
            if (($layer['name'] ?? null) !== 'Unlayered') {
                continue;
            }

            foreach ($layer['collectors'] ?? [] as $collector) {
                if (($collector['type'] ?? null) === 'bool') {
                    return $collector;
                }
            }
        }

        self::fail('deptrac.yaml has no Unlayered layer with a bool collector.');
    }

    /**
     * @return array<string, mixed>
     */
    private static function ruleset(): array
    {
        /** @var array<string, mixed> $ruleset */
        $ruleset = self::deptrac()['ruleset'] ?? [];

        self::assertNotEmpty($ruleset);

        return $ruleset;
    }

    /**
     * @return array<string, mixed>
     */
    private static function deptrac(): array
    {
        /** @var array{deptrac?: mixed} $parsed */
        $parsed = Yaml::parseFile(dirname(__DIR__, 3) . '/deptrac.yaml');

        self::assertIsArray($parsed['deptrac'] ?? null);

        /** @var array<string, mixed> $config */
        $config = $parsed['deptrac'];

        return $config;
    }
}
