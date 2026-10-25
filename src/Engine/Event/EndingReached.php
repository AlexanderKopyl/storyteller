<?php

declare(strict_types=1);

namespace Storyteller\Engine\Event;

/**
 * The scene the turn came to rest on is an ending, according to the graph —
 * the only authority on that question.
 */
final readonly class EndingReached implements EngineEvent
{
    public function __construct(
        public string $sceneKey,
        public int $turn,
    ) {
    }

    public function name(): string
    {
        return 'ending_reached';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event' => $this->name(),
            'scene' => $this->sceneKey,
            'turn' => $this->turn,
        ];
    }
}
