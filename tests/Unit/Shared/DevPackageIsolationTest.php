<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Shared;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps deptrac's `Dev` layer honest (review finding N1).
 *
 * The `Dev` layer lists namespace prefixes rather than package names, because
 * deptrac's `composer` collector resolves a package to its psr-0/psr-4
 * autoload keys only, and phpunit/phpunit, every sebastian/*, phpstan/phpstan,
 * theseer/tokenizer, phar-io/* and jetbrains/phpstorm-stubs declare none of
 * those - they autoload by classmap or files. A prefix list buys coverage of
 * those packages at the price of being able to go stale, which is the one
 * failure mode deptrac.yaml's Framework comment refuses to accept silently.
 *
 * This test is that price. It recomputes the dev-only namespace set from
 * composer.lock plus composer's generated autoload maps and asserts, in both
 * directions, that the layer matches it:
 *
 *  - every symbol reachable from a dev-only package is captured by `Dev`
 *    (a new or upgraded dev dependency with a new namespace turns this red);
 *  - no symbol reachable from a production package is captured by `Dev`
 *    (a prefix that is too broad - `Symfony\` rather than
 *    `Symfony\Polyfill\Php8` - turns this red);
 *  - no prefix in `Dev` is dead (a removed dev dependency turns this red).
 *
 * composer.lock's `packages-dev` is the exact transitive dev-only closure:
 * composer places a package there only when nothing in `require` reaches it.
 */
#[CoversNothing]
final class DevPackageIsolationTest extends TestCase
{
    /**
     * Guards against a vacuous pass if the derivation below ever stops
     * producing symbols.
     */
    private const int MINIMUM_SYMBOLS_PER_SIDE = 50;

    /**
     * Production roots that must demonstrably survive the Dev layer. Without
     * this, a derivation that silently stopped seeing Symfony would still pass
     * the "no production symbol is captured" assertion below.
     *
     * @var list<string>
     */
    private const array PRODUCTION_ROOTS = ['Symfony\\', 'Doctrine\\', 'Psr\\', 'Monolog\\'];

    public function testDevLayerIsGrantedToNoLayerAndGrantsNothing(): void
    {
        $ruleset = self::deptracConfig()['ruleset'];

        self::assertArrayHasKey('Dev', $ruleset, 'deptrac.yaml has no Dev layer in its ruleset.');
        self::assertSame([], $ruleset['Dev'], 'The Dev layer must be allowed to depend on nothing.');

        foreach ($ruleset as $layer => $allowed) {
            self::assertNotContains(
                'Dev',
                (array) $allowed,
                sprintf('Layer "%s" is granted Dev; no src/ layer may import a require-dev package.', $layer),
            );
        }
    }

    /**
     * Framework is "not ours, not a built-in", which would otherwise swallow
     * the dev packages too and put every dev token in two layers at once.
     */
    public function testFrameworkLayerExcludesTheDevLayer(): void
    {
        $framework = self::layerDefinition('Framework');

        $excludesDev = false;

        foreach ($framework as $collector) {
            if (!is_array($collector) || ($collector['type'] ?? null) !== 'bool') {
                continue;
            }

            foreach ((array) ($collector['must_not'] ?? []) as $mustNot) {
                if (is_array($mustNot) && ($mustNot['type'] ?? null) === 'layer' && ($mustNot['value'] ?? null) === 'Dev') {
                    $excludesDev = true;
                }
            }
        }

        self::assertTrue($excludesDev, 'The Framework layer must exclude the Dev layer, or the two overlap.');
    }

    public function testEveryDevOnlyNamespaceIsCapturedByTheDevLayer(): void
    {
        $patterns = self::devLayerPatterns();
        $symbols = self::symbolsByPackage()['dev'];

        self::assertGreaterThan(self::MINIMUM_SYMBOLS_PER_SIDE, count($symbols), 'Derived no dev-only symbols; the derivation is broken.');

        foreach ($symbols as $symbol => $package) {
            self::assertTrue(
                self::matchesAny($patterns, $symbol),
                sprintf('"%s" (from dev-only package %s) is not captured by deptrac.yaml\'s Dev layer.', $symbol, $package),
            );
        }
    }

    public function testNoProductionNamespaceIsCapturedByTheDevLayer(): void
    {
        $patterns = self::devLayerPatterns();
        $symbols = self::symbolsByPackage()['prod'];

        self::assertGreaterThan(self::MINIMUM_SYMBOLS_PER_SIDE, count($symbols), 'Derived no production symbols; the derivation is broken.');

        foreach (self::PRODUCTION_ROOTS as $root) {
            $seen = false;

            foreach (array_keys($symbols) as $symbol) {
                if (str_starts_with($symbol, $root)) {
                    $seen = true;

                    break;
                }
            }

            self::assertTrue($seen, sprintf('Derived no production symbol under "%s"; the derivation is broken.', $root));
        }

        foreach ($symbols as $symbol => $package) {
            self::assertFalse(
                self::matchesAny($patterns, $symbol),
                sprintf('"%s" (from production package %s) is captured by the Dev layer, which would ban a legitimate import.', $symbol, $package),
            );
        }
    }

    public function testNoDevLayerPatternIsDead(): void
    {
        $symbols = array_keys(self::symbolsByPackage()['dev']);

        foreach (self::devLayerPatterns() as $pattern) {
            $matched = false;

            foreach ($symbols as $symbol) {
                if (preg_match($pattern, $symbol) === 1) {
                    $matched = true;

                    break;
                }
            }

            self::assertTrue($matched, sprintf('Dev layer pattern "%s" matches no installed dev-only symbol; it is stale.', $pattern));
        }
    }

    /**
     * tests/ legitimately uses PHPUnit. It stays outside deptrac's paths.
     */
    public function testOnlySrcIsAnalysed(): void
    {
        self::assertSame(['./src'], self::deptracConfig()['paths']);
    }

    /**
     * @param list<string> $patterns
     */
    private static function matchesAny(array $patterns, string $symbol): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $symbol) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `Dev` layer's collector values, compiled exactly the way deptrac's
     * AbstractTypeCollector compiles a `classLike` value: `/<value>/i`.
     *
     * @return list<string>
     */
    private static function devLayerPatterns(): array
    {
        $patterns = [];

        foreach (self::layerDefinition('Dev') as $collector) {
            self::assertIsArray($collector);
            self::assertSame('classLike', $collector['type'] ?? null, 'The Dev layer is expected to use classLike collectors only.');

            $value = $collector['value'] ?? null;
            self::assertIsString($value);

            $patterns[] = '/' . $value . '/i';
        }

        self::assertNotEmpty($patterns, 'The Dev layer declares no collectors.');

        return $patterns;
    }

    /**
     * Every class-like symbol composer can autoload out of a vendor package,
     * split by whether that package is dev-only or production.
     *
     * Two sources, because one is not enough: the psr-4 map covers packages
     * that declare a namespace prefix, and the classmap covers the ones that
     * do not (phpunit and friends). psr-4 prefixes are turned into a probe
     * symbol, since the prefix itself is what the layer has to match.
     *
     * Symbols in the global namespace are skipped: they are the polyfilled
     * PHP built-ins (`Attribute`, `ValueError`, ...) that deptrac's
     * `php_internal` collector already removes from both layers.
     *
     * @return array{dev: array<string, string>, prod: array<string, string>}
     */
    private static function symbolsByPackage(): array
    {
        /** @var array{dev: array<string, string>, prod: array<string, string>}|null $cache */
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $lock = self::readJson(self::projectDir() . '/composer.lock');

        $side = [];

        foreach (['packages' => 'prod', 'packages-dev' => 'dev'] as $section => $which) {
            self::assertArrayHasKey($section, $lock);
            self::assertIsArray($lock[$section]);

            foreach ($lock[$section] as $package) {
                self::assertIsArray($package);
                self::assertIsString($package['name'] ?? null);

                $side[$package['name']] = $which;
            }
        }

        $symbols = ['dev' => [], 'prod' => []];

        foreach (self::readMap(self::projectDir() . '/vendor/composer/autoload_classmap.php') as $symbol => $file) {
            $package = self::packageOf(is_string($file) ? $file : '');

            if ($package === null || !isset($side[$package]) || !str_contains($symbol, '\\')) {
                continue;
            }

            $symbols[$side[$package]][$symbol] = $package;
        }

        foreach (self::readMap(self::projectDir() . '/vendor/composer/autoload_psr4.php') as $prefix => $paths) {
            foreach ((array) $paths as $path) {
                $package = self::packageOf(is_string($path) ? $path : '');

                if ($package === null || !isset($side[$package]) || $prefix === '') {
                    continue;
                }

                $symbols[$side[$package]][$prefix . 'DeptracProbe'] = $package;
            }
        }

        return $cache = $symbols;
    }

    /**
     * `/app/vendor/sebastian/diff/src` -> `sebastian/diff`.
     */
    private static function packageOf(string $path): ?string
    {
        $position = strrpos($path, '/vendor/');

        if ($position === false) {
            return null;
        }

        $segments = explode('/', substr($path, $position + strlen('/vendor/')));

        if (count($segments) < 2) {
            return null;
        }

        return $segments[0] . '/' . $segments[1];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function layerDefinition(string $name): array
    {
        /** @var list<array{name?: string, collectors?: mixed}> $layers */
        $layers = self::deptracConfig()['layers'];

        foreach ($layers as $layer) {
            if (($layer['name'] ?? null) === $name) {
                self::assertIsArray($layer['collectors'] ?? null);

                /** @var array<array-key, mixed> $collectors */
                $collectors = $layer['collectors'];

                return $collectors;
            }
        }

        self::fail(sprintf('deptrac.yaml declares no "%s" layer.', $name));
    }

    /**
     * @return array{paths: mixed, layers: mixed, ruleset: array<string, mixed>}
     */
    private static function deptracConfig(): array
    {
        /** @var array{deptrac?: array{paths?: mixed, layers?: mixed, ruleset?: mixed}} $parsed */
        $parsed = Yaml::parseFile(self::projectDir() . '/deptrac.yaml');

        self::assertIsArray($parsed['deptrac'] ?? null);
        self::assertIsArray($parsed['deptrac']['ruleset'] ?? null);

        /** @var array<string, mixed> $ruleset */
        $ruleset = $parsed['deptrac']['ruleset'];

        return [
            'paths' => $parsed['deptrac']['paths'] ?? null,
            'layers' => $parsed['deptrac']['layers'] ?? null,
            'ruleset' => $ruleset,
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function readMap(string $file): array
    {
        self::assertFileExists($file, 'Run `composer install` first.');

        /** @var mixed $map */
        $map = require $file;

        self::assertIsArray($map);

        return $map;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function readJson(string $file): array
    {
        $contents = file_get_contents($file);

        self::assertIsString($contents, sprintf('Could not read "%s".', $file));

        /** @var mixed $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }

    private static function projectDir(): string
    {
        return dirname(__DIR__, 3);
    }
}
