<?php

declare(strict_types=1);

namespace Storyteller\Engine\Exception;

/**
 * The requested choice cannot be taken.
 *
 * Two named constructors rather than two classes, but **not** two
 * indistinguishable errors: each carries a {@see ChoiceUnavailableReason}, so
 * "no such choice in this scene" and "the choice exists but its condition is
 * false" can be told apart by a caller rather than by matching English prose.
 * They are not the same answer at an HTTP boundary — the first is a bug the
 * client cannot retry its way out of, the second is a state conflict — and a
 * caller in Play or Api must be able to branch on that.
 */
final class ChoiceUnavailableException extends EngineException
{
    private function __construct(string $message, public readonly ChoiceUnavailableReason $reason)
    {
        parent::__construct($message);
    }

    public static function notFound(string $sceneKey, string $choiceKey): self
    {
        return new self(
            sprintf('Scene "%s" has no choice "%s".', $sceneKey, $choiceKey),
            ChoiceUnavailableReason::NotFound,
        );
    }

    public static function conditionFailed(string $sceneKey, string $choiceKey): self
    {
        return new self(
            sprintf('Choice "%s" of scene "%s" is unavailable: its condition evaluated to false.', $choiceKey, $sceneKey),
            ChoiceUnavailableReason::ConditionFailed,
        );
    }
}
