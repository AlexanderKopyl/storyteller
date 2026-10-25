<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Shared;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Encodes the bounded-context layout itself as an executable assertion.
 *
 * The import scan closes - from the test side - two gaps deptrac leaves open:
 *
 *  1. Vendor *functions* are invisible to deptrac. A `use function
 *     Symfony\Component\String\u;` inside src/Engine produces 0 violations,
 *     0 uncovered entries and 0 warnings, because the Framework layer's
 *     `must: classLike` collector rejects a FunctionReference and
 *     UncoveredDependentHandler only records ClassLikeTokens. Confirmed to be
 *     a deptrac limitation rather than a configuration mistake (review N2):
 *     neither a `functionName` collector nor `analyser.types: [class,
 *     function, function_call]` catches it.
 *  2. src/Shared is subject to the same no-framework rule as src/Engine -
 *     plan decision P3 withholds the Framework layer from both, because
 *     Engine depends on Shared and a framework-contaminated Shared would
 *     reintroduce exactly the contamination the rule exists to prevent.
 *
 * Class, interface, trait, enum, attribute, docblock-type and inline
 * fully-qualified-name imports are deptrac's job and are deliberately not
 * duplicated here; `use function` / `use const` are this test's unique value.
 */
#[CoversNothing]
final class BoundedContextLayoutTest extends TestCase
{
    /**
     * Every source and migration leaf the bounded-context layout promises.
     *
     * @var list<string>
     */
    private const array CONTEXT_DIRECTORIES = [
        'src/Shared',
        'src/Engine',
        'src/Content/Domain',
        'src/Content/Application',
        'src/Content/Infrastructure',
        'src/Play/Domain',
        'src/Play/Application',
        'src/Play/Infrastructure',
        'src/Api/Controller',
        'src/Api/Dto',
        'src/Api/Security',
        'migrations',
    ];

    /**
     * Contexts that must stay framework-free (CLAUDE.md; plan decision P3).
     *
     * @var list<string>
     */
    private const array FRAMEWORK_FREE_CONTEXTS = [
        'src/Engine',
        'src/Shared',
    ];

    /**
     * Vendor roots a framework-free context may never import from.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_VENDOR_ROOTS = [
        'Symfony',
        'Doctrine',
        'Psr',
        'Monolog',
        'PHPUnit',
    ];

    public function testEveryBoundedContextDirectoryExists(): void
    {
        foreach (self::CONTEXT_DIRECTORIES as $directory) {
            self::assertDirectoryExists(
                self::projectDir() . '/' . $directory,
                sprintf('Bounded-context directory "%s" is missing.', $directory),
            );
        }
    }

    #[DataProvider('frameworkFreeContextProvider')]
    public function testFrameworkFreeContextsImportNoFrameworkNamespace(string $context): void
    {
        $contextDir = self::projectDir() . '/' . $context;

        self::assertDirectoryExists($contextDir);

        foreach (self::phpFilesIn($contextDir) as $file) {
            $contents = file_get_contents($file);

            self::assertIsString($contents, sprintf('Could not read "%s".', $file));

            foreach (self::forbiddenImportPatterns() as $pattern) {
                self::assertDoesNotMatchRegularExpression(
                    $pattern,
                    $contents,
                    sprintf(
                        '%s must stay framework-free, but "%s" imports a forbidden namespace.',
                        $context,
                        $file,
                    ),
                );
            }
        }
    }

    /**
     * The regexes themselves are asserted, so the scan above cannot silently
     * stop matching: a pattern that no longer recognises `use function` would
     * make this test red instead of making the scan vacuous.
     */
    public function testForbiddenImportPatternsMatchTheImportFormsTheyClaimTo(): void
    {
        $samples = [
            'plain class import' => "<?php\n\nuse Symfony\\Component\\HttpFoundation\\Request;\n",
            'grouped class import' => "<?php\n\nuse Symfony\\Component\\Uid\\{Uuid, Ulid};\n",
            'doctrine class import' => "<?php\n\nuse Doctrine\\ORM\\EntityManagerInterface;\n",
            'psr class import' => "<?php\n\nuse Psr\\Log\\LoggerInterface;\n",
            'function import' => "<?php\n\nuse function Symfony\\Component\\String\\u;\n",
            'function import, extra spacing' => "<?php\n\nuse  function   Doctrine\\Common\\Util\\thing;\n",
            'grouped function import' => "<?php\n\nuse function Symfony\\Component\\String\\{u, b};\n",
            'const import' => "<?php\n\nuse const Monolog\\Level\\DEBUG;\n",
            'phpunit import' => "<?php\n\nuse PHPUnit\\Framework\\Assert;\n",
        ];

        foreach ($samples as $label => $source) {
            $matched = false;

            foreach (self::forbiddenImportPatterns() as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $matched = true;

                    break;
                }
            }

            self::assertTrue($matched, sprintf('No forbidden-import pattern matches the %s sample.', $label));
        }

        $allowed = [
            'first-party import' => "<?php\n\nuse Storyteller\\Shared\\StoryId;\n",
            'php built-in import' => "<?php\n\nuse DateTimeImmutable;\n",
            'seeded randomizer' => "<?php\n\nuse Random\\Randomizer;\nuse Random\\Engine\\Mt19937;\n",
            'word boundary, not a vendor root' => "<?php\n\nuse SymfonyLike\\Thing;\n",
            'mention inside a comment body' => "<?php\n\n// see Symfony\\Component\\Uid for prior art\n",
        ];

        foreach ($allowed as $label => $source) {
            foreach (self::forbiddenImportPatterns() as $pattern) {
                self::assertDoesNotMatchRegularExpression(
                    $pattern,
                    $source,
                    sprintf('Pattern "%s" produces a false positive on the %s sample.', $pattern, $label),
                );
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function frameworkFreeContextProvider(): iterable
    {
        foreach (self::FRAMEWORK_FREE_CONTEXTS as $context) {
            yield $context => [$context];
        }
    }

    /**
     * `use X`, `use function X` and `use const X` for every forbidden vendor
     * root. The `\b` after the root keeps `SymfonyLike\…` out of scope.
     *
     * @return list<string>
     */
    private static function forbiddenImportPatterns(): array
    {
        $roots = implode('|', self::FORBIDDEN_VENDOR_ROOTS);

        return [
            sprintf('/^use\s+(?:%s)\b\\\\/m', $roots),
            sprintf('/^use\s+function\s+(?:%s)\b\\\\/m', $roots),
            sprintf('/^use\s+const\s+(?:%s)\b\\\\/m', $roots),
        ];
    }

    /**
     * @return list<string>
     */
    private static function phpFilesIn(string $directory): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private static function projectDir(): string
    {
        return dirname(__DIR__, 3);
    }
}
