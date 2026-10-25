<?php

declare(strict_types=1);

namespace Storyteller\Engine;

use Storyteller\Engine\Event\ChoiceMade;
use Storyteller\Engine\Event\EffectPhase;
use Storyteller\Engine\Event\EffectsApplied;
use Storyteller\Engine\Event\EndingReached;
use Storyteller\Engine\Event\EngineEvent;
use Storyteller\Engine\Event\SceneEntered;
use Storyteller\Engine\Exception\ChoiceUnavailableException;
use Storyteller\Engine\Exception\NoNextSceneException;
use Storyteller\Engine\Exception\RedirectLoopException;
use Storyteller\Engine\Exception\UnknownSceneException;
use Storyteller\Engine\Graph\StoryGraph;
use Storyteller\Engine\State\GameState;

/**
 * One turn: a player at a scene picks a choice, and this is everything that
 * follows.
 *
 * **The redirect budget is one counter per turn.** A `goto` in the choice's
 * effects spends the first of five; every `goto` in a target scene's
 * `on_enter` spends another; the sixth throws. There is no cycle detection —
 * a legitimate A → B → A re-entry inside the budget is allowed — because the
 * budget is about termination, not about shape.
 *
 * **The visit is recorded after `on_enter`, and only for the scene the turn
 * came to rest on.** A scene that redirects out of its own `on_enter` was
 * transited, not entered: its effects really happened and are logged, but it
 * appears in neither `visited` nor `history` and gets no `SceneEntered`. The
 * consequence for an author is deliberate — during a scene's own `on_enter`
 * that scene has not been visited yet, so a "first time here" test written
 * there reads as not-yet-visited on the first entry and visited afterwards.
 */
final readonly class Transition
{
    public const int MAX_REDIRECTS = 5;

    public function __construct(
        private StoryGraph $graph,
        private ConditionEvaluator $evaluator,
        private EffectApplier $applier,
        private NextSceneResolver $resolver,
    ) {
    }

    /**
     * @param  int                         $turn the caller's turn counter; the Engine never reads a clock
     * @throws UnknownSceneException       if the current scene, or any scene reached from it, is unknown
     * @throws ChoiceUnavailableException  if the choice does not exist or its condition does not hold
     * @throws NoNextSceneException        if no `next` rule matched
     * @throws RedirectLoopException       if the turn spent more than MAX_REDIRECTS redirects
     */
    public function run(GameState $state, string $currentScene, string $choiceKey, int $turn): TransitionResult
    {
        $this->requireScene($currentScene);

        $scene = $this->graph->scene($currentScene);
        $choice = $scene->choice($choiceKey);

        if ($choice->condition !== null && !$this->evaluator->evaluate($choice->condition, $state)) {
            throw ChoiceUnavailableException::conditionFailed($currentScene, $choiceKey);
        }

        /** @var list<EngineEvent> $events */
        $events = [new ChoiceMade($currentScene, $choiceKey, $turn)];

        $chosen = $this->applier->apply($choice->effects, $state);
        $state = $chosen->state;

        if (!$chosen->diff->isEmpty()) {
            $events[] = new EffectsApplied($currentScene, EffectPhase::Choice, $chosen->diff, $turn);
        }

        if ($chosen->redirectTo !== null) {
            // A `goto` in the choice's effects replaces the declared `next`
            // entirely, and spends the first redirect of the turn.
            $target = $chosen->redirectTo;
            $redirects = 1;
            $this->guardRedirects($redirects, $target);
        } else {
            $target = $this->resolver->resolve($scene->next, $state, $currentScene);
            $redirects = 0;
        }

        $this->requireScene($target);

        while (true) {
            $entered = $this->applier->apply($this->graph->scene($target)->onEnter, $state);
            $state = $entered->state;

            if (!$entered->diff->isEmpty()) {
                $events[] = new EffectsApplied($target, EffectPhase::OnEnter, $entered->diff, $turn);
            }

            if ($entered->redirectTo === null) {
                break;
            }

            ++$redirects;
            $this->guardRedirects($redirects, $entered->redirectTo);

            $target = $entered->redirectTo;

            $this->requireScene($target);
        }

        $state = $state->withVisit($target);

        $events[] = new SceneEntered($target, $redirects, $turn);

        $isEnding = $this->graph->isEnding($target);

        if ($isEnding) {
            $events[] = new EndingReached($target, $turn);
        }

        return new TransitionResult($state, $target, $isEnding, $events);
    }

    /**
     * Called after every increment and after the initial one, so there is
     * exactly one place the off-by-one can live: five redirects complete, the
     * sixth throws.
     */
    private function guardRedirects(int $redirects, string $scene): void
    {
        if ($redirects > self::MAX_REDIRECTS) {
            throw RedirectLoopException::exceeded(self::MAX_REDIRECTS, $scene);
        }
    }

    private function requireScene(string $sceneKey): void
    {
        if (!$this->graph->hasScene($sceneKey)) {
            throw UnknownSceneException::named($sceneKey);
        }
    }
}
