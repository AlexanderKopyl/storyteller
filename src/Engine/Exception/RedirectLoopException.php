<?php

declare(strict_types=1);

namespace Storyteller\Engine\Exception;

/**
 * The redirect budget for a single transition was exhausted.
 *
 * There is no cycle detection: a legitimate A -> B -> A re-entry inside the
 * budget is allowed, and only the count is enforced.
 */
final class RedirectLoopException extends EngineException
{
    public static function exceeded(int $limit, string $lastScene): self
    {
        return new self(sprintf('Redirect budget of %d exceeded; the last scene reached was "%s".', $limit, $lastScene));
    }
}
