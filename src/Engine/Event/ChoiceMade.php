<?php

declare(strict_types=1);

namespace Storyteller\Engine\Event;

/**
 * The player's choice was accepted: it exists on the scene and its condition
 * held. Emitted once per turn, before any effect runs.
 */
final readonly class ChoiceMade implements EngineEvent
{
    public function __construct(
        public string $sceneKey,
        public string $choiceKey,
        public int $turn,
    ) {
    }

    public function name(): string
    {
        return 'choice_made';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event' => $this->name(),
            'scene' => $this->sceneKey,
            'choice' => $this->choiceKey,
            'turn' => $this->turn,
        ];
    }
}
