<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionProperty;
use SplFileInfo;

/**
 * The Engine's purity rules, as executable assertions over its own source.
 *
 * CLAUDE.md states them in prose — deterministic, no `rand()`, no `time()`, no
 * globals, no static mutable state — and prose cannot fail a build. Four of
 * the five cases below scan or reflect over every file under `src/Engine`, so
 * the rules hold for files that do not exist yet as much as for the ones that
 * do.
 *
 * `testThePatternsMatchTheCallFormsTheyClaimTo` is the reason the scan is
 * worth running: a regex that quietly stopped matching would turn the guard
 * green and empty at the same time, which is strictly worse than having no
 * guard at all. It follows the precedent set by `BoundedContextLayoutTest`.
 */
#[CoversNothing]
final class EnginePurityTest extends TestCase
{
    /**
     * Global functions whose result depends on something other than their
     * arguments.
     *
     * @var list<string>
     */
    private const array NON_DETERMINISTIC_FUNCTIONS = [
        'rand',
        'srand',
        'mt_rand',
        'mt_srand',
        'random_int',
        'random_bytes',
        'array_rand',
        'shuffle',
        'str_shuffle',
        'time',
        'microtime',
        'hrtime',
        'date',
        'getdate',
        'uniqid',
    ];

    /**
     * Functions whose result depends on the process, the machine or the
     * environment the code happens to be running in.
     *
     * `spl_object_id()` and `spl_object_hash()` are the subtle ones: they are
     * stable within a process and meaningless across two, so sorting or keying
     * by them produces a different answer for the same inputs on the next
     * request — the classic way a "deterministic" engine stops being one.
     *
     * @var list<string>
     */
    private const array AMBIENT_FUNCTIONS = [
        'getenv',
        'putenv',
        'php_uname',
        'gethostname',
        'getmypid',
        'sys_get_temp_dir',
        'getcwd',
        'spl_object_id',
        'spl_object_hash',
        'memory_get_usage',
        'memory_get_peak_usage',
        'connection_status',
    ];

    /**
     * I/O. CLAUDE.md's "framework-free, deterministic, no I/O" is the one
     * Engine invariant that was held by prose alone: the Engine reads no file,
     * opens no socket and writes nothing. Content arrives as a decoded array
     * and state arrives as a `GameState`; whoever read them is not the Engine.
     *
     * @var list<string>
     */
    private const array IO_FUNCTIONS = [
        'file_get_contents',
        'file_put_contents',
        'fopen',
        'fread',
        'fwrite',
        'fgets',
        'fputs',
        'readfile',
        'file',
        'file_exists',
        'is_file',
        'is_dir',
        'glob',
        'scandir',
        'opendir',
        'readdir',
        'mkdir',
        'rmdir',
        'unlink',
        'rename',
        'copy',
        'touch',
        'tempnam',
        'tmpfile',
        'fsockopen',
        'stream_socket_client',
        'curl_init',
        'error_log',
        'syslog',
    ];

    /**
     * Types that may not be named anywhere under `src/Engine`, not even in a
     * docblock, so that a grep over the directory stays a sufficient check
     * (step 15's rule). `Random\Engine\Secure` draws from the OS CSPRNG and
     * cannot be seeded, so a single use would make a saved game unreplayable.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_TYPES = [
        'Random\\Engine\\Secure',
    ];

    /**
     * @var list<string>
     */
    private const array SUPERGLOBALS = [
        '_GET',
        '_POST',
        '_SERVER',
        '_ENV',
        '_REQUEST',
        '_COOKIE',
        '_FILES',
        '_SESSION',
        'GLOBALS',
    ];

    /**
     * Engine classes whose covering test is shared with their siblings, each
     * mapped to the test file that owns them. Everything not listed here must
     * have a test file at the mirrored path.
     *
     * @var array<string, string>
     */
    private const array SHARED_COVERAGE = [
        'Ast/Comparison.php' => 'Ast/ConditionTest.php',
        'Ast/FlagCheck.php' => 'Ast/ConditionTest.php',
        'Ast/HasItem.php' => 'Ast/ConditionTest.php',
        'Ast/Visited.php' => 'Ast/ConditionTest.php',
        'Ast/LogicalAnd.php' => 'Ast/ConditionTest.php',
        'Ast/LogicalOr.php' => 'Ast/ConditionTest.php',
        'Ast/LogicalNot.php' => 'Ast/ConditionTest.php',
        'Ast/SetVar.php' => 'Ast/EffectTest.php',
        'Ast/Arithmetic.php' => 'Ast/EffectTest.php',
        'Ast/FlagEffect.php' => 'Ast/EffectTest.php',
        'Ast/ItemEffect.php' => 'Ast/EffectTest.php',
        'Ast/GoToScene.php' => 'Ast/EffectTest.php',
        'Event/EngineEvent.php' => 'Event/EngineEventsTest.php',
        'Event/EffectPhase.php' => 'Event/EngineEventsTest.php',
        'Event/ChoiceMade.php' => 'Event/EngineEventsTest.php',
        'Event/EffectsApplied.php' => 'Event/EngineEventsTest.php',
        'Event/SceneEntered.php' => 'Event/EngineEventsTest.php',
        'Event/EndingReached.php' => 'Event/EngineEventsTest.php',
        'Exception/EngineException.php' => 'Exception/EngineExceptionTest.php',
        'Exception/ChoiceUnavailableException.php' => 'Exception/EngineExceptionTest.php',
        'Exception/ChoiceUnavailableReason.php' => 'Exception/EngineExceptionTest.php',
        'Exception/MalformedNodeException.php' => 'Exception/EngineExceptionTest.php',
        'Exception/NoNextSceneException.php' => 'Exception/EngineExceptionTest.php',
        'Exception/RedirectLoopException.php' => 'Exception/EngineExceptionTest.php',
        'Exception/TypeMismatchException.php' => 'Exception/EngineExceptionTest.php',
        'Exception/UnknownOperatorException.php' => 'Exception/EngineExceptionTest.php',
        'Exception/UnknownSceneException.php' => 'Exception/EngineExceptionTest.php',
        'Exception/UnknownVariableException.php' => 'Exception/EngineExceptionTest.php',
        'Graph/StoryGraph.php' => 'Graph/SceneNodeTest.php',
        'Random/Rng.php' => 'Random/SeededRngTest.php',
    ];

    public function testNoEngineSourceUsesANonDeterministicFunction(): void
    {
        $files = self::engineFiles();

        self::assertNotEmpty($files, 'The purity scan found no Engine source to scan.');

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            self::assertIsString($contents, sprintf('Could not read "%s".', $file));

            foreach (self::impurityPatterns() as $label => $pattern) {
                self::assertDoesNotMatchRegularExpression(
                    $pattern,
                    $contents,
                    sprintf(
                        'src/Engine must stay deterministic and do no I/O, but "%s" uses %s. '
                        . 'Inject a seed or a clock, or have the caller read it and pass it in.',
                        $file,
                        $label,
                    ),
                );
            }
        }
    }

    /**
     * Positive and negative samples for every pattern, so the scan above
     * cannot go vacuous without this test going red.
     */
    public function testThePatternsMatchTheCallFormsTheyClaimTo(): void
    {
        $impure = [
            'bare call' => "<?php\n\n\$x = time();\n",
            'call with arguments' => "<?php\n\n  \$x = mt_rand(1, 2);\n",
            'fully qualified call' => "<?php\n\n\$x = \\random_int(1, 6);\n",
            'call with inner spacing' => "<?php\n\n\$x = uniqid ();\n",
            'shuffle in place' => "<?php\n\nshuffle(\$items);\n",
            'date call' => "<?php\n\n\$x = date('Y');\n",
            'callable string' => "<?php\n\n\$f = 'microtime';\n\$x = \$f();\n",
            'superglobal read' => "<?php\n\n\$x = \$_SERVER['HTTP_HOST'];\n",
            'globals read' => "<?php\n\n\$x = \$GLOBALS['config'];\n",
            'environment read' => "<?php\n\n\$x = getenv('APP_ENV');\n",
            'process identity' => "<?php\n\n\$x = getmypid();\n",
            'object identity' => "<?php\n\n\$key = spl_object_id(\$node);\n",
            'object hash' => "<?php\n\n\$key = spl_object_hash(\$node);\n",
            'machine identity' => "<?php\n\n\$x = php_uname('n');\n",
            'reading a file' => "<?php\n\n\$yaml = file_get_contents(\$path);\n",
            'opening a file' => "<?php\n\n\$h = fopen(\$path, 'rb');\n",
            'listing files' => "<?php\n\n\$files = glob(\$dir . '/*.yaml');\n",
            'writing a log' => "<?php\n\nerror_log('here');\n",
            'opening a socket' => "<?php\n\n\$s = fsockopen(\$host, 80);\n",
            'the unseedable engine, imported' => "<?php\n\nuse Random\\Engine\\Secure;\n",
            'the unseedable engine, merely mentioned' => "<?php\n\n// never Random\\Engine\\Secure, which cannot be seeded\n",
        ];

        foreach ($impure as $label => $source) {
            self::assertTrue(
                self::matchesAnyImpurityPattern($source),
                sprintf('No impurity pattern matches the %s sample.', $label),
            );
        }

        $pure = [
            'a longer function that merely ends in a forbidden name' => "<?php\n\n\$x = strtotime(\$s);\n",
            'an instance method of the same name' => "<?php\n\n\$x = \$this->date(\$s);\n",
            'a static method of the same name' => "<?php\n\n\$x = Formatter::date(\$s);\n",
            'a word that contains a forbidden name' => "<?php\n\n\$this->update(\$row);\n",
            'a property that contains a forbidden name' => "<?php\n\n\$x = \$this->updated;\n",
            'a nullsafe method of the same name' => "<?php\n\n\$x = \$clock?->time();\n",
            'an injected randomizer' => "<?php\n\n\$x = \$this->randomizer->getInt(1, 6);\n",
            'a variable whose name contains a forbidden name' => "<?php\n\n\$runtime = 1;\n\$_seed = 2;\n",
            'a seeded engine import' => "<?php\n\nuse Random\\Engine\\Xoshiro256StarStar;\n",
            'a method whose name is an I/O function' => "<?php\n\n\$x = \$this->glob(\$pattern);\n",
            'a method whose name is an ambient function' => "<?php\n\n\$x = \$config->getenv('APP_ENV');\n",
            'a longer function ending in an I/O name' => "<?php\n\n\$x = unserialize_file(\$path);\n",
            'a property whose name contains an I/O name' => "<?php\n\n\$x = \$this->fileExists;\n",
            'a word containing a forbidden type name' => "<?php\n\n\$x = SecureHash::of(\$s);\n",
        ];

        foreach ($pure as $label => $source) {
            self::assertFalse(
                self::matchesAnyImpurityPattern($source),
                sprintf('An impurity pattern produces a false positive on the %s sample.', $label),
            );
        }
    }

    /**
     * A static property is the one place a `readonly` class can still hide
     * mutable state, so it is checked by reflection rather than by regex.
     */
    public function testNoEngineClassDeclaresAStaticProperty(): void
    {
        $classes = self::engineClasses();

        self::assertNotEmpty($classes);

        foreach ($classes as $class) {
            self::assertSame(
                [],
                (new ReflectionClass($class))->getProperties(ReflectionProperty::IS_STATIC),
                sprintf('%s declares a static property; the Engine holds no mutable state.', $class),
            );
        }
    }

    public function testEveryConcreteEngineClassIsFinal(): void
    {
        foreach (self::engineClasses() as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isInterface() || $reflection->isEnum() || $reflection->isAbstract()) {
                continue;
            }

            self::assertTrue(
                $reflection->isFinal(),
                sprintf('%s is concrete and must be final.', $class),
            );
        }
    }

    /**
     * A structural backstop for the 100 %-coverage bar: the coverage report is
     * read by a human, this is not.
     */
    public function testEveryEngineClassHasACoveringTestFile(): void
    {
        $testRoot = self::projectDir() . '/tests/Unit/Engine';

        foreach (self::engineFiles() as $file) {
            $relative = substr($file, strlen(self::projectDir() . '/src/Engine/'));

            $expected = array_key_exists($relative, self::SHARED_COVERAGE)
                ? self::SHARED_COVERAGE[$relative]
                : substr($relative, 0, -4) . 'Test.php';

            self::assertFileExists(
                $testRoot . '/' . $expected,
                sprintf('src/Engine/%s has no covering test file.', $relative),
            );
        }

        // No stale allow-list entry: every shared-coverage key is a real file.
        foreach (array_keys(self::SHARED_COVERAGE) as $relative) {
            self::assertFileExists(
                self::projectDir() . '/src/Engine/' . $relative,
                sprintf('SHARED_COVERAGE lists src/Engine/%s, which does not exist.', $relative),
            );
        }
    }

    private static function matchesAnyImpurityPattern(string $source): bool
    {
        foreach (self::impurityPatterns() as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The lookbehind is what separates a call from a coincidence: `->`, `::`,
     * `$` and any word character before the name mean it is a method, a
     * property, a variable or a longer function, none of which this guard
     * claims to judge. A leading `\` is deliberately *not* excluded — a
     * fully-qualified `\time()` is exactly the call being forbidden.
     *
     * @return array<string, string>
     */
    private static function impurityPatterns(): array
    {
        $patterns = [];

        $functions = [
            ...self::NON_DETERMINISTIC_FUNCTIONS,
            ...self::AMBIENT_FUNCTIONS,
            ...self::IO_FUNCTIONS,
        ];

        foreach ($functions as $function) {
            $patterns[$function . '()'] = sprintf('/(?<![\w$>:])%s\s*\(/', preg_quote($function, '/'));
            $patterns[sprintf("'%s' as a callable string", $function)] = sprintf(
                '/([\'"])%s\1/',
                preg_quote($function, '/'),
            );
        }

        foreach (self::SUPERGLOBALS as $superglobal) {
            $patterns['$' . $superglobal] = sprintf('/\$%s\b/', preg_quote($superglobal, '/'));
        }

        foreach (self::FORBIDDEN_TYPES as $type) {
            // Named anywhere at all, import or docblock: the rule is that a
            // grep for the name over src/Engine comes back empty.
            $patterns['the type ' . $type] = sprintf('/%s/', preg_quote($type, '/'));
        }

        return $patterns;
    }

    /**
     * @return list<class-string>
     */
    private static function engineClasses(): array
    {
        $classes = [];

        foreach (self::engineFiles() as $file) {
            $relative = substr($file, strlen(self::projectDir() . '/src/Engine/'), -4);

            /** @var class-string $class */
            $class = 'Storyteller\\Engine\\' . str_replace('/', '\\', $relative);

            self::assertTrue(
                class_exists($class) || interface_exists($class) || enum_exists($class),
                sprintf('"%s" does not declare the autoloadable type %s.', $file, $class),
            );

            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    private static function engineFiles(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            self::projectDir() . '/src/Engine',
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

    private static function projectDir(): string
    {
        return dirname(__DIR__, 3);
    }
}
