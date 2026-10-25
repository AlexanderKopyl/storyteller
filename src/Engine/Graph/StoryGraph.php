<?php

declare(strict_types=1);

namespace Storyteller\Engine\Graph;

use Storyteller\Engine\Exception\UnknownSceneException;

/**
 * The Engine's view of a story: scenes by key, and which of them end it.
 *
 * This is the contract `src/Content` implements over a compiled story
 * version. The Engine deliberately ships no implementation — the only one in
 * the repository lives under `tests/` — so nothing here can quietly start
 * depending on how content is stored.
 *
 * Whether a scene is an ending is asked of the graph rather than read off the
 * scene, so there is exactly one answer and no second place for it to drift.
 */
interface StoryGraph
{
    public function hasScene(string $sceneKey): bool;

    /**
     * @throws UnknownSceneException if the graph has no such scene
     */
    public function scene(string $sceneKey): SceneNode;

    /**
     * @throws UnknownSceneException if the graph has no such scene
     */
    public function isEnding(string $sceneKey): bool;
}
