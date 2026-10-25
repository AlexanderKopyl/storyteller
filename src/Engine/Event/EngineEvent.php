<?php

declare(strict_types=1);

namespace Storyteller\Engine\Event;

/**
 * Something the Engine did during one turn, in a form Play can persist.
 *
 * No event carries a timestamp: `turn` is the caller's counter, and wall-clock
 * time is added by whoever writes the log. An event that read the clock itself
 * would make the Engine non-deterministic by construction.
 */
interface EngineEvent
{
    public function name(): string;

    /**
     * @return array<string, mixed> with an `event` key equal to name()
     */
    public function toArray(): array;
}
