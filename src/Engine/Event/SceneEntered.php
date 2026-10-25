<?php

declare(strict_types=1);

namespace Storyteller\Engine\Event;

/**
 * The turn came to rest on a scene. Emitted once, for the scene that was
 * finally entered — never for an intermediate hop a redirect passed through.
 *
 * `redirects` carries how much of the turn's redirect budget was spent, so
 * the budget is observable in the log rather than only in a thrown exception.
 */
final readonly class SceneEntered implements EngineEvent
{
    public function __construct(
        public string $sceneKey,
        public int $redirects,
        public int $turn,
    ) {
    }

    public function name(): string
    {
        return 'scene_entered';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event' => $this->name(),
            'scene' => $this->sceneKey,
            'redirects' => $this->redirects,
            'turn' => $this->turn,
        ];
    }
}
