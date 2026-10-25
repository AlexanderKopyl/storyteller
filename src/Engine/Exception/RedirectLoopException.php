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
    /**
     * `$refusedScene` is the scene the hop that broke the budget was headed
     * **to**, and the message says so. The guard runs before the hop is taken,
     * so that scene was never entered — for a six-hop chain it is the only one
     * in the chain that was not. The message used to call it "the last scene
     * reached", which named the one scene the turn had not reached and sent an
     * operator debugging a redirect cycle to the wrong end of it (review m3).
     */
    public static function exceeded(int $limit, string $refusedScene): self
    {
        return new self(sprintf('Redirect budget of %d exceeded; the redirect to "%s" was refused.', $limit, $refusedScene));
    }
}
