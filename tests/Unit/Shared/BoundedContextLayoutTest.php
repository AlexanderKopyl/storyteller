<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Shared;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Encodes the bounded-context layout itself as an executable assertion.
 *
 * The second test closes - from the test side - the gap deptrac leaves while
 * src/Engine holds no PHP file at all: "Symfony, Doctrine and HTTP types must
 * never appear inside src/Engine" (CLAUDE.md) starts biting the moment the
 * first Engine class lands.
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
     * Namespaces the Engine is forbidden to import, as `use` statements.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_ENGINE_IMPORTS = [
        '/^use\s+Symfony\\\\/m',
        '/^use\s+Doctrine\\\\/m',
        '/^use\s+Psr\\\\/m',
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

    public function testEngineSourcesImportNoFrameworkNamespace(): void
    {
        $engineDir = self::projectDir() . '/src/Engine';

        self::assertDirectoryExists($engineDir);

        foreach (self::phpFilesIn($engineDir) as $file) {
            $contents = file_get_contents($file);

            self::assertIsString($contents, sprintf('Could not read "%s".', $file));

            foreach (self::FORBIDDEN_ENGINE_IMPORTS as $pattern) {
                self::assertDoesNotMatchRegularExpression(
                    $pattern,
                    $contents,
                    sprintf(
                        'The Engine must stay framework-free, but "%s" imports a forbidden namespace.',
                        $file,
                    ),
                );
            }
        }
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
