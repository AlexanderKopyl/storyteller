<?php

declare(strict_types=1);

namespace Storyteller\Engine\Graph;

use Storyteller\Engine\Ast\Condition;
use Storyteller\Engine\Support\ArrayReader;

/**
 * One entry of a scene's `next` list: where to go, and the condition under
 * which that entry applies.
 *
 * A rule with no `if` is a fallback and matches unconditionally **at its
 * position**, so a fallback written first makes everything after it
 * unreachable. That is an authoring mistake, not an engine one, and the
 * resolver does not second-guess it.
 *
 * "No `if`" means the key is **absent**. An explicit `"if": null` is rejected
 * at parse time, because reading it as a fallback would make every rule after
 * this one unreachable without saying so.
 */
final readonly class NextRule
{
    public function __construct(public string $scene, public ?Condition $if = null)
    {
    }

    public function isFallback(): bool
    {
        return $this->if === null;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param string                  $path breadcrumb used in error messages
     */
    public static function fromArray(array $node, string $path = 'next'): self
    {
        $reader = new ArrayReader($node, $path);

        $reader->rejectUnknownKeys('scene', 'if');

        // An omitted `if` makes this rule a fallback. An explicit null is
        // malformed: read as a fallback, it would silently make every rule
        // after this one unreachable.
        $reader->rejectNull('if', 'object');

        $scene = $reader->requireString('scene');
        $guard = $reader->has('if')
            ? Condition::fromArray($reader->requireArray('if'), $path . '.if')
            : null;

        return new self($scene, $guard);
    }

    /**
     * @param  list<array<array-key, mixed>> $nodes
     * @return list<NextRule>
     */
    public static function listFromArray(array $nodes, string $path = 'next'): array
    {
        $rules = [];

        foreach ($nodes as $index => $node) {
            $rules[] = self::fromArray($node, $path . '[' . $index . ']');
        }

        return $rules;
    }

    /**
     * @return array{scene: string, if?: array<string, mixed>}
     */
    public function toArray(): array
    {
        $out = ['scene' => $this->scene];

        if ($this->if !== null) {
            $out['if'] = $this->if->toArray();
        }

        return $out;
    }
}
