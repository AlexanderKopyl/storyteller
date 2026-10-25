<?php

declare(strict_types=1);

namespace Storyteller\Engine\Event;

use Storyteller\Engine\State\StateDiff;

/**
 * One phase of effects changed the state, and this is what changed.
 *
 * Emitted once per phase and only when the diff is non-empty, so a turn can
 * legitimately produce none, one or two of these — and a scene that is merely
 * redirected through still emits its own, because its effects really ran.
 */
final readonly class EffectsApplied implements EngineEvent
{
    public function __construct(
        public string $sceneKey,
        public EffectPhase $phase,
        public StateDiff $diff,
        public int $turn,
    ) {
    }

    public function name(): string
    {
        return 'effects_applied';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event' => $this->name(),
            'scene' => $this->sceneKey,
            'phase' => $this->phase->value,
            'diff' => $this->diff->toArray(),
            'turn' => $this->turn,
        ];
    }
}
