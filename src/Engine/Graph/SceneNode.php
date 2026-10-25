<?php

declare(strict_types=1);

namespace Storyteller\Engine\Graph;

use Storyteller\Engine\Ast\Effect;
use Storyteller\Engine\Exception\ChoiceUnavailableException;
use Storyteller\Engine\Exception\MalformedNodeException;
use Storyteller\Engine\Support\ArrayReader;

/**
 * An authored scene: the effects that run on entering it, the choices it
 * offers, and where it goes next.
 *
 * `next` keeps the authored union — a scene name, or an ordered rule list —
 * rather than normalising to a list, because "a plain string resolves to
 * itself" is a guarantee the format makes and a normalisation would hide. An
 * empty list means "no next", which is how an ending or a dead end is
 * written.
 *
 * Duplicate choice keys are rejected at parse time: `choice()` would
 * otherwise have to pick one, and either pick would be arbitrary.
 */
final readonly class SceneNode
{
    /**
     * @param list<Effect>          $onEnter
     * @param list<ChoiceNode>      $choices
     * @param string|list<NextRule> $next an empty list means "no next"
     */
    public function __construct(
        public string $key,
        public array $onEnter = [],
        public array $choices = [],
        public string|array $next = [],
    ) {
    }

    public function hasChoice(string $choiceKey): bool
    {
        foreach ($this->choices as $choice) {
            if ($choice->key === $choiceKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws ChoiceUnavailableException if the scene offers no such choice
     */
    public function choice(string $choiceKey): ChoiceNode
    {
        foreach ($this->choices as $choice) {
            if ($choice->key === $choiceKey) {
                return $choice;
            }
        }

        throw ChoiceUnavailableException::notFound($this->key, $choiceKey);
    }

    /**
     * @param array<array-key, mixed> $node
     * @param string                  $path breadcrumb used in error messages
     */
    public static function fromArray(array $node, string $path = 'scene'): self
    {
        $reader = new ArrayReader($node, $path);

        $reader->rejectUnknownKeys('key', 'on_enter', 'choices', 'next');

        $key = $reader->requireString('key');

        $choices = [];

        foreach ($reader->optionalArrayList('choices') as $index => $child) {
            $choice = ChoiceNode::fromArray($child, $path . '.choices[' . $index . ']');

            foreach ($choices as $seen) {
                if ($seen->key === $choice->key) {
                    throw MalformedNodeException::duplicateKey($path . '.choices', $choice->key);
                }
            }

            $choices[] = $choice;
        }

        return new self(
            $key,
            Effect::listFromArray($reader->optionalArrayList('on_enter'), $path . '.on_enter'),
            $choices,
            self::parseNext($reader, $path),
        );
    }

    /**
     * @return array{
     *     key: string,
     *     on_enter: list<array<string, mixed>>,
     *     choices: list<array<string, mixed>>,
     *     next: string|list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'on_enter' => array_map(static fn (Effect $effect): array => $effect->toArray(), $this->onEnter),
            'choices' => array_map(static fn (ChoiceNode $choice): array => $choice->toArray(), $this->choices),
            'next' => is_string($this->next)
                ? $this->next
                : array_map(static fn (NextRule $rule): array => $rule->toArray(), $this->next),
        ];
    }

    /**
     * @return string|list<NextRule>
     */
    private static function parseNext(ArrayReader $reader, string $path): string|array
    {
        if (!$reader->has('next')) {
            return [];
        }

        if ($reader->isString('next')) {
            return $reader->requireString('next');
        }

        return NextRule::listFromArray($reader->requireArrayList('next'), $path . '.next');
    }
}
