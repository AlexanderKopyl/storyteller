<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\Event\ChoiceMade;
use Storyteller\Engine\Event\EffectPhase;
use Storyteller\Engine\Event\EffectsApplied;
use Storyteller\Engine\Event\EndingReached;
use Storyteller\Engine\Event\EngineEvent;
use Storyteller\Engine\Event\SceneEntered;
use Storyteller\Engine\State\GameState;
use Storyteller\Engine\State\StateDiff;
use Storyteller\Engine\State\VariableDefinition;
use Storyteller\Engine\State\VariableSchema;
use Storyteller\Engine\State\VariableType;

/**
 * The serialized events are Play's event-log payloads, so each shape is
 * pinned literally — including the absence of a timestamp, which Play adds
 * when it writes the row.
 */
#[CoversClass(EffectPhase::class)]
#[CoversClass(ChoiceMade::class)]
#[CoversClass(EffectsApplied::class)]
#[CoversClass(SceneEntered::class)]
#[CoversClass(EndingReached::class)]
final class EngineEventsTest extends TestCase
{
    public function testEffectPhaseCaseListIsExactlyChoiceAndOnEnter(): void
    {
        self::assertSame(
            ['choice', 'on_enter'],
            array_map(static fn (EffectPhase $phase): string => $phase->value, EffectPhase::cases()),
        );
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('eventProvider')]
    public function testEveryEventExposesItsNameAndSerializesWithAnEventKey(
        EngineEvent $event,
        string $expectedName,
        array $expected,
    ): void {
        self::assertSame($expectedName, $event->name());
        self::assertSame($expectedName, $event->toArray()['event']);
        self::assertSame($expected, $event->toArray());
    }

    /**
     * @return iterable<string, array{0: EngineEvent, 1: string, 2: array<string, mixed>}>
     */
    public static function eventProvider(): iterable
    {
        yield 'choice_made' => [
            new ChoiceMade('crossroads', 'north', 7),
            'choice_made',
            ['event' => 'choice_made', 'scene' => 'crossroads', 'choice' => 'north', 'turn' => 7],
        ];
        yield 'effects_applied' => [
            new EffectsApplied('crossroads', EffectPhase::Choice, StateDiff::empty(), 7),
            'effects_applied',
            ['event' => 'effects_applied', 'scene' => 'crossroads', 'phase' => 'choice', 'diff' => [], 'turn' => 7],
        ];
        yield 'scene_entered' => [
            new SceneEntered('cave', 2, 7),
            'scene_entered',
            ['event' => 'scene_entered', 'scene' => 'cave', 'redirects' => 2, 'turn' => 7],
        ];
        yield 'ending_reached' => [
            new EndingReached('the_end', 7),
            'ending_reached',
            ['event' => 'ending_reached', 'scene' => 'the_end', 'turn' => 7],
        ];
    }

    public function testEffectsAppliedEmbedsTheDiffAndThePhase(): void
    {
        $schema = VariableSchema::of([new VariableDefinition('hp', VariableType::Int, 100)]);
        $before = GameState::create(['hp' => 95], [], [], [], []);
        $diff = StateDiff::between($before, $before->withVar('hp', 90)->withFlag('brave'), $schema);

        $event = new EffectsApplied('cave', EffectPhase::OnEnter, $diff, 7);

        self::assertSame(EffectPhase::OnEnter, $event->phase);
        self::assertSame($diff, $event->diff);
        self::assertSame(
            [
                'event' => 'effects_applied',
                'scene' => 'cave',
                'phase' => 'on_enter',
                'diff' => [
                    'vars' => ['hp' => ['from' => 95, 'to' => 90]],
                    'flags' => ['added' => ['brave']],
                ],
                'turn' => 7,
            ],
            $event->toArray(),
        );
    }

    /**
     * `turn` is the caller's counter; wall-clock time belongs to Play, which
     * adds it when it logs. An event that carried its own would make the
     * Engine non-deterministic by construction.
     */
    public function testNoEventCarriesATimestamp(): void
    {
        $forbidden = ['at', 'time', 'timestamp', 'createdat', 'occurredat', 'now', 'date'];

        foreach (self::eventClasses() as $class) {
            foreach ((new \ReflectionClass($class))->getProperties() as $property) {
                self::assertNotContains(
                    strtolower($property->getName()),
                    $forbidden,
                    sprintf('%s must not carry its own clock.', $class),
                );
            }
        }
    }

    public function testEveryEventIsAnEngineEventAndNamesItselfConsistently(): void
    {
        $events = [
            new ChoiceMade('crossroads', 'north', 7),
            new EffectsApplied('cave', EffectPhase::Choice, StateDiff::empty(), 7),
            new SceneEntered('cave', 0, 7),
            new EndingReached('the_end', 7),
        ];

        foreach ($events as $event) {
            self::assertContains(
                EngineEvent::class,
                class_implements($event),
                sprintf('%s must implement EngineEvent.', $event::class),
            );
            self::assertSame($event->name(), $event->toArray()['event']);
            self::assertSame(7, $event->toArray()['turn']);
        }

        self::assertSame(
            ['choice_made', 'effects_applied', 'scene_entered', 'ending_reached'],
            array_map(static fn (EngineEvent $event): string => $event->name(), $events),
        );
    }

    /**
     * @return list<class-string>
     */
    private static function eventClasses(): array
    {
        return [ChoiceMade::class, EffectsApplied::class, SceneEntered::class, EndingReached::class];
    }
}
