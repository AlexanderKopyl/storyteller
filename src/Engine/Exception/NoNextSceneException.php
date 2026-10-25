<?php

declare(strict_types=1);

namespace Storyteller\Engine\Exception;

/**
 * A `next` rule list that ran out: no rule matched and no fallback was
 * declared, or the list was empty to begin with.
 */
final class NoNextSceneException extends EngineException
{
    public static function noRuleMatched(string $sceneKey): self
    {
        return new self(sprintf('No next-scene rule matched in scene "%s".', $sceneKey));
    }
}
