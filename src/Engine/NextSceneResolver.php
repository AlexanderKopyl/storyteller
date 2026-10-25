<?php

declare(strict_types=1);

namespace Storyteller\Engine;

use Storyteller\Engine\Exception\NoNextSceneException;
use Storyteller\Engine\Graph\NextRule;
use Storyteller\Engine\State\GameState;

/**
 * Turns a scene's declared `next` into the key of the scene that follows it.
 *
 * A plain string resolves to itself. A list is walked in order and the
 * **first** rule whose guard holds wins, even if a later one would also
 * match; a rule with no guard is a fallback and matches at its position.
 * Running out of rules — including starting with none, which is how an ending
 * or a dead end is encoded — is an error rather than a silent stop.
 *
 * The resolver holds no graph and validates nothing beyond "a rule matched":
 * whether the scene it names exists is the caller's question.
 */
final readonly class NextSceneResolver
{
    public function __construct(private ConditionEvaluator $evaluator)
    {
    }

    /**
     * @param  string|list<NextRule> $next
     * @throws NoNextSceneException  if no rule matched
     */
    public function resolve(string|array $next, GameState $state, string $sceneKey): string
    {
        if (is_string($next)) {
            return $next;
        }

        foreach ($next as $rule) {
            if ($rule->if === null || $this->evaluator->evaluate($rule->if, $state)) {
                return $rule->scene;
            }
        }

        throw NoNextSceneException::noRuleMatched($sceneKey);
    }
}
