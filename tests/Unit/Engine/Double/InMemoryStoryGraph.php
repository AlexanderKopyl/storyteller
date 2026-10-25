<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine\Double;

use Storyteller\Engine\Exception\UnknownSceneException;
use Storyteller\Engine\Graph\SceneNode;
use Storyteller\Engine\Graph\StoryGraph;

/**
 * The only `StoryGraph` implementation in the repository, and it lives under
 * `tests/` on purpose: the Engine defines the contract, `src/Content` will
 * implement it, and nothing in `src/` is allowed to assume a storage shape in
 * the meantime.
 */
final readonly class InMemoryStoryGraph implements StoryGraph
{
    /** @var array<string, SceneNode> */
    private array $scenes;

    /** @var list<string> */
    private array $endings;

    /**
     * @param list<SceneNode> $scenes
     * @param list<string>    $endings
     */
    public function __construct(array $scenes, array $endings = [])
    {
        $byKey = [];

        foreach ($scenes as $scene) {
            $byKey[$scene->key] = $scene;
        }

        $this->scenes = $byKey;
        $this->endings = $endings;
    }

    /**
     * @param list<array<array-key, mixed>> $nodes
     * @param list<string>                  $endings
     */
    public static function fromArray(array $nodes, array $endings = []): self
    {
        return new self(
            array_map(static fn (array $node): SceneNode => SceneNode::fromArray($node), $nodes),
            $endings,
        );
    }

    public function hasScene(string $sceneKey): bool
    {
        return array_key_exists($sceneKey, $this->scenes);
    }

    public function scene(string $sceneKey): SceneNode
    {
        return $this->scenes[$sceneKey] ?? throw UnknownSceneException::named($sceneKey);
    }

    public function isEnding(string $sceneKey): bool
    {
        if (!$this->hasScene($sceneKey)) {
            throw UnknownSceneException::named($sceneKey);
        }

        return in_array($sceneKey, $this->endings, true);
    }
}
