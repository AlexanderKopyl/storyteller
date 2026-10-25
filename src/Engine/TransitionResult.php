<?php

declare(strict_types=1);

namespace Storyteller\Engine;

use Storyteller\Engine\Event\EngineEvent;
use Storyteller\Engine\State\GameState;

/**
 * Everything one turn produced: the state it ended in, the scene it came to
 * rest on, whether that scene ends the story, and the ordered events.
 *
 * `toArray()` is what Play persists — the state becomes the session's JSONB
 * column and the events become its log rows.
 */
final readonly class TransitionResult
{
    /**
     * @param list<EngineEvent> $events in emission order
     */
    public function __construct(
        public GameState $state,
        public string $sceneKey,
        public bool $isEnding,
        public array $events,
    ) {
    }

    /**
     * @return array{
     *     state: array<string, mixed>,
     *     scene: string,
     *     is_ending: bool,
     *     events: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->toArray(),
            'scene' => $this->sceneKey,
            'is_ending' => $this->isEnding,
            'events' => array_map(
                static fn (EngineEvent $event): array => $event->toArray(),
                $this->events,
            ),
        ];
    }
}
