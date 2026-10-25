<?php

declare(strict_types=1);

namespace Storyteller\Engine\Exception;

/**
 * A scene key the story graph does not know.
 *
 * Raised by the graph itself and by the transition whenever it is about to
 * enter a scene, so a dangling `goto` or `next` target fails loudly rather
 * than leaving the session on a scene that does not exist.
 */
final class UnknownSceneException extends EngineException
{
    public static function named(string $sceneKey): self
    {
        return new self(sprintf('Unknown scene "%s".', $sceneKey));
    }
}
