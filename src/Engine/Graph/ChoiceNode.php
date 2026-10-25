<?php

declare(strict_types=1);

namespace Storyteller\Engine\Graph;

use Storyteller\Engine\Ast\Condition;
use Storyteller\Engine\Ast\Effect;
use Storyteller\Engine\Support\ArrayReader;

/**
 * An authored choice: what the player may pick, when they may pick it, and
 * what happens when they do.
 *
 * A choice with no condition is always available; a condition that evaluates
 * false makes picking it an error rather than a no-op, because the player was
 * never offered it.
 *
 * "No condition" means the `condition` key is **absent**. An explicit
 * `"condition": null` is rejected at parse time: it is what a YAML
 * `condition:` line with nothing after it compiles to, and treating it as "no
 * guard" would silently turn an authored gate into an open door.
 */
final readonly class ChoiceNode
{
    /**
     * @param list<Effect> $effects
     */
    public function __construct(
        public string $key,
        public ?Condition $condition = null,
        public array $effects = [],
    ) {
    }

    /**
     * m2: `Transition` reads `condition` directly, because it has to evaluate
     * it. Kept for `src/Api`, which has the opposite job — listing a scene's
     * choices for a client — and for which this is the one question that needs
     * no state at all: an unguarded choice is available to everyone, and only
     * the rest have to be evaluated against the session.
     */
    public function isAlwaysAvailable(): bool
    {
        return $this->condition === null;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param string                  $path breadcrumb used in error messages
     */
    public static function fromArray(array $node, string $path = 'choice'): self
    {
        $reader = new ArrayReader($node, $path);

        $reader->rejectUnknownKeys('key', 'condition', 'effects');

        // An omitted `condition` means "no guard". An explicit null does not:
        // it is malformed content, and reading it as "no guard" would turn an
        // authored gate into an always-available choice without a word.
        $reader->rejectNull('condition', 'object');

        return new self(
            $reader->requireString('key'),
            $reader->has('condition')
                ? Condition::fromArray($reader->requireArray('condition'), $path . '.condition')
                : null,
            Effect::listFromArray($reader->optionalArrayList('effects'), $path . '.effects'),
        );
    }

    /**
     * @return array{key: string, condition?: array<string, mixed>, effects: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        $out = ['key' => $this->key];

        if ($this->condition !== null) {
            $out['condition'] = $this->condition->toArray();
        }

        $out['effects'] = array_map(
            static fn (Effect $effect): array => $effect->toArray(),
            $this->effects,
        );

        return $out;
    }
}
