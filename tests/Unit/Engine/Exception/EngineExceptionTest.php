<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine\Exception;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Storyteller\Engine\Exception\ChoiceUnavailableException;
use Storyteller\Engine\Exception\ChoiceUnavailableReason;
use Storyteller\Engine\Exception\EngineException;
use Storyteller\Engine\Exception\MalformedNodeException;
use Storyteller\Engine\Exception\NoNextSceneException;
use Storyteller\Engine\Exception\RedirectLoopException;
use Storyteller\Engine\Exception\TypeMismatchException;
use Storyteller\Engine\Exception\UnknownOperatorException;
use Storyteller\Engine\Exception\UnknownSceneException;
use Storyteller\Engine\Exception\UnknownVariableException;
use Storyteller\Engine\State\VariableType;

/**
 * The Engine's error family is deliberately self-contained (open question OQ-4):
 * every exception it throws descends from one abstract root that lives inside
 * `src/Engine`, so the layer keeps zero outgoing dependencies and a caller can
 * catch `EngineException` to catch everything the Engine can raise.
 */
#[CoversClass(EngineException::class)]
#[CoversClass(ChoiceUnavailableException::class)]
#[CoversClass(ChoiceUnavailableReason::class)]
#[CoversClass(MalformedNodeException::class)]
#[CoversClass(NoNextSceneException::class)]
#[CoversClass(RedirectLoopException::class)]
#[CoversClass(TypeMismatchException::class)]
#[CoversClass(UnknownOperatorException::class)]
#[CoversClass(UnknownSceneException::class)]
#[CoversClass(UnknownVariableException::class)]
final class EngineExceptionTest extends TestCase
{
    /**
     * Every concrete exception the Engine may throw.
     *
     * Deliberately typed as plain `class-string`: declaring the narrower
     * `class-string<EngineException>` would let static analysis prove the
     * inheritance assertions below instead of running them, which is the one
     * thing this test exists to do at runtime.
     *
     * @var list<class-string>
     */
    private const array CONCRETE_EXCEPTIONS = [
        ChoiceUnavailableException::class,
        MalformedNodeException::class,
        NoNextSceneException::class,
        RedirectLoopException::class,
        TypeMismatchException::class,
        UnknownOperatorException::class,
        UnknownSceneException::class,
        UnknownVariableException::class,
    ];

    /**
     * Files in `src/Engine/Exception` that are part of the family but are not
     * themselves exceptions — today, the one enum that discriminates
     * `ChoiceUnavailableException`'s two cases. Listing them keeps the
     * directory guard below honest instead of merely loosening it.
     *
     * @var list<string>
     */
    private const array FAMILY_SUPPORT_TYPES = ['ChoiceUnavailableReason'];

    /**
     * The only non-Engine ancestors an Engine exception may have.
     *
     * @var list<class-string<\Throwable>>
     */
    private const array ALLOWED_FOREIGN_ANCESTORS = [
        \RuntimeException::class,
        \Exception::class,
    ];

    /**
     * @param class-string $class
     */
    #[DataProvider('concreteExceptionProvider')]
    public function testEveryEngineExceptionExtendsTheAbstractRoot(string $class): void
    {
        // The ancestry is inspected as data rather than asserted with
        // `assertTrue(is_subclass_of(...))`: PHPUnit's assertions carry PHPStan
        // narrowing, so the first such call would prove the second one true at
        // analysis time instead of letting it run.
        $ancestors = class_parents($class);

        self::assertIsArray($ancestors);
        self::assertContains(
            EngineException::class,
            $ancestors,
            sprintf('%s must extend %s.', $class, EngineException::class),
        );
        self::assertContains(
            \RuntimeException::class,
            $ancestors,
            sprintf('%s must be a RuntimeException.', $class),
        );

        $reflection = new ReflectionClass($class);

        self::assertFalse($reflection->isAbstract(), sprintf('%s must be concrete.', $class));
        self::assertTrue($reflection->isFinal(), sprintf('%s must be final.', $class));
    }

    /**
     * M5: the two cases must be distinguishable by something a caller can
     * branch on. "No such choice" is a client or content bug (an Api answers
     * 404/422 and should not retry); "the choice exists but its condition is
     * false" is a state conflict (409, and legitimately racy if two turns are
     * submitted concurrently). Matching `getMessage()` is not an interface.
     */
    public function testChoiceUnavailableCarriesATypedReasonPerCase(): void
    {
        self::assertSame(
            ChoiceUnavailableReason::NotFound,
            ChoiceUnavailableException::notFound('crossroads', 'north')->reason,
        );
        self::assertSame(
            ChoiceUnavailableReason::ConditionFailed,
            ChoiceUnavailableException::conditionFailed('crossroads', 'north')->reason,
        );
    }

    public function testTheChoiceUnavailableReasonHasExactlyTheTwoDeclaredCases(): void
    {
        $values = array_map(
            static fn (ChoiceUnavailableReason $reason): string => $reason->value,
            ChoiceUnavailableReason::cases(),
        );

        // The wire form is pinned as well as the case list: Api will map these
        // onto status codes, so a rename is a breaking change, not a tidy-up.
        self::assertSame(['not_found', 'condition_failed'], $values);
    }

    public function testTheRootIsAbstractAndIsTheOnlyAbstractExceptionInTheFamily(): void
    {
        $root = new ReflectionClass(EngineException::class);
        $parent = $root->getParentClass();

        self::assertTrue($root->isAbstract(), 'EngineException must be abstract.');
        self::assertSame(
            \RuntimeException::class,
            $parent === false ? '(no parent)' : $parent->getName(),
        );
    }

    /**
     * Guards the two providers above against going stale: a tenth file dropped
     * into `src/Engine/Exception` that no list mentions fails here.
     */
    public function testTheExceptionDirectoryHoldsExactlyTheDeclaredFamily(): void
    {
        $directory = self::projectDir() . '/src/Engine/Exception';

        self::assertDirectoryExists($directory);

        $found = glob($directory . '/*.php');

        self::assertIsArray($found);

        $names = [];

        foreach ($found as $file) {
            $names[] = basename($file, '.php');
        }

        sort($names);

        $expected = ['EngineException', ...self::FAMILY_SUPPORT_TYPES];

        foreach (self::CONCRETE_EXCEPTIONS as $class) {
            $expected[] = (new ReflectionClass($class))->getShortName();
        }

        sort($expected);

        self::assertSame($expected, $names);
    }

    /**
     * @param Closure(): EngineException   $factory
     * @param class-string<EngineException> $expectedClass
     * @param list<string>                  $expectedFragments
     */
    #[DataProvider('namedConstructorProvider')]
    public function testNamedConstructorsProduceTheDocumentedMessage(
        Closure $factory,
        string $expectedClass,
        array $expectedFragments,
    ): void {
        $exception = $factory();

        self::assertSame($expectedClass, $exception::class);
        self::assertNotSame('', $exception->getMessage());

        foreach ($expectedFragments as $fragment) {
            self::assertStringContainsString($fragment, $exception->getMessage());
        }
    }

    /**
     * Pins OQ-4: nothing in the family reaches outside `Storyteller\Engine`
     * except the two SPL roots, so no framework type can creep in through a
     * base class.
     *
     * @param class-string $class
     */
    #[DataProvider('everyExceptionProvider')]
    public function testNoEngineExceptionExtendsAFrameworkType(string $class): void
    {
        $parent = (new ReflectionClass($class))->getParentClass();

        while ($parent !== false) {
            $name = $parent->getName();

            self::assertTrue(
                str_starts_with($name, 'Storyteller\\Engine\\')
                || in_array($name, self::ALLOWED_FOREIGN_ANCESTORS, true),
                sprintf('%s inherits from the foreign type %s.', $class, $name),
            );

            $parent = $parent->getParentClass();
        }
    }

    /**
     * @return iterable<string, array{0: class-string}>
     */
    public static function concreteExceptionProvider(): iterable
    {
        foreach (self::CONCRETE_EXCEPTIONS as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @return iterable<string, array{0: class-string}>
     */
    public static function everyExceptionProvider(): iterable
    {
        yield EngineException::class => [EngineException::class];

        yield from self::concreteExceptionProvider();
    }

    /**
     * @return iterable<string, array{0: Closure(): EngineException, 1: class-string<EngineException>, 2: list<string>}>
     */
    public static function namedConstructorProvider(): iterable
    {
        yield 'MalformedNodeException::missingKey' => [
            static fn (): EngineException => MalformedNodeException::missingKey('condition.of[1]', 'var'),
            MalformedNodeException::class,
            ['condition.of[1]', 'var'],
        ];

        yield 'MalformedNodeException::wrongType' => [
            static fn (): EngineException => MalformedNodeException::wrongType('effect', 'qty', 'int', 'string'),
            MalformedNodeException::class,
            ['effect', 'qty', 'int', 'string'],
        ];

        yield 'MalformedNodeException::unknownKey' => [
            static fn (): EngineException => MalformedNodeException::unknownKey('condition', 'extra'),
            MalformedNodeException::class,
            ['condition', 'extra'],
        ];

        yield 'MalformedNodeException::duplicateKey' => [
            static fn (): EngineException => MalformedNodeException::duplicateKey('schema', 'hp'),
            MalformedNodeException::class,
            ['schema', 'hp'],
        ];

        yield 'MalformedNodeException::notAPositiveInt' => [
            static fn (): EngineException => MalformedNodeException::notAPositiveInt('condition', 'times'),
            MalformedNodeException::class,
            ['condition', 'times'],
        ];

        yield 'MalformedNodeException::unsupportedStateVersion' => [
            static fn (): EngineException => MalformedNodeException::unsupportedStateVersion(2, 1),
            MalformedNodeException::class,
            ['2', '1'],
        ];

        yield 'UnknownOperatorException::condition' => [
            static fn (): EngineException => UnknownOperatorException::condition('xor'),
            UnknownOperatorException::class,
            ['xor', 'condition'],
        ];

        yield 'UnknownOperatorException::effect' => [
            static fn (): EngineException => UnknownOperatorException::effect('multiply'),
            UnknownOperatorException::class,
            ['multiply', 'effect'],
        ];

        yield 'TypeMismatchException::value' => [
            static fn (): EngineException => TypeMismatchException::value('hp', VariableType::Int, 'float'),
            TypeMismatchException::class,
            ['hp', 'int', 'float'],
        ];

        yield 'TypeMismatchException::notNumeric' => [
            static fn (): EngineException => TypeMismatchException::notNumeric('name', VariableType::String, 'lt'),
            TypeMismatchException::class,
            ['name', 'string', 'lt'],
        ];

        yield 'UnknownVariableException::named' => [
            static fn (): EngineException => UnknownVariableException::named('hp'),
            UnknownVariableException::class,
            ['hp'],
        ];

        yield 'UnknownSceneException::named' => [
            static fn (): EngineException => UnknownSceneException::named('cave'),
            UnknownSceneException::class,
            ['cave'],
        ];

        yield 'ChoiceUnavailableException::notFound' => [
            static fn (): EngineException => ChoiceUnavailableException::notFound('crossroads', 'north'),
            ChoiceUnavailableException::class,
            ['crossroads', 'north'],
        ];

        yield 'ChoiceUnavailableException::conditionFailed' => [
            static fn (): EngineException => ChoiceUnavailableException::conditionFailed('crossroads', 'north'),
            ChoiceUnavailableException::class,
            ['crossroads', 'north', 'condition'],
        ];

        yield 'NoNextSceneException::noRuleMatched' => [
            static fn (): EngineException => NoNextSceneException::noRuleMatched('crossroads'),
            NoNextSceneException::class,
            ['crossroads'],
        ];

        yield 'RedirectLoopException::exceeded' => [
            static fn (): EngineException => RedirectLoopException::exceeded(5, 'cave'),
            RedirectLoopException::class,
            ['5', 'cave'],
        ];
    }

    private static function projectDir(): string
    {
        return dirname(__DIR__, 4);
    }
}
