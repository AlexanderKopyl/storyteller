<?php

declare(strict_types=1);

namespace Storyteller\Engine\Exception;

/**
 * The requested choice cannot be taken.
 *
 * Two named constructors rather than two classes: the caller's recovery is the
 * same in both cases, and only the message needs to distinguish "no such
 * choice in this scene" from "the choice exists but its condition is false".
 */
final class ChoiceUnavailableException extends EngineException
{
    public static function notFound(string $sceneKey, string $choiceKey): self
    {
        return new self(sprintf('Scene "%s" has no choice "%s".', $sceneKey, $choiceKey));
    }

    public static function conditionFailed(string $sceneKey, string $choiceKey): self
    {
        return new self(sprintf('Choice "%s" of scene "%s" is unavailable: its condition evaluated to false.', $choiceKey, $sceneKey));
    }
}
