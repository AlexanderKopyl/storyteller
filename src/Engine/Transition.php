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
use Storyteller\Engine\Exception\EngineException;
use Storyteller\Engine\Exception\NoNextSceneException;
use Storyteller\Engine\Exception\RedirectLoopException;
use Storyteller\Engine\Exception\UnknownSceneException;
use Storyteller\Engine\Graph\StoryGraph;
use Storyteller\Engine\State\GameState;
use Storyteller\Engine\State\VariableSchema;

/**
 * One turn: a player at a scene picks a choice, and this is everything that
 * follows.
 *
 * There are two entry points and they share their second half. {@see self::run()}
 * is a turn — a choice is named, its condition checked, its effects applied,
 * the `next` resolved — and {@see self::begin()} is the entry that starts a
 * story, where no choice has been made yet. Both end in the same private
 * `enter()`: one `on_enter` redirect chain, one budget, one visit rule, one
 * event order.
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
 * consequence for an author is deliberate — a scene's own `on_enter` runs
 * before its visit is counted — but it is only observable on the **next**
 * turn, from a choice condition or a `next` guard: effects carry no
 * conditions, so nothing inside an `on_enter` can read `visited` at all.
 * "First time here" is a `not visited` guard on a choice, never a test inside
 * `on_enter`.
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
     * @throws ChoiceUnavailableException  if the choice does not exist (`reason` is `NotFound`) or its
     *                                     condition does not hold (`reason` is `ConditionFailed`)
     * @throws NoNextSceneException        if no `next` rule matched
     * @throws RedirectLoopException       if the turn spent more than MAX_REDIRECTS redirects
     */
    public function run(GameState $state, string $currentScene, string $choiceKey, int $turn): TransitionResult
    {
        self::requireScene($this->graph, $currentScene);

        $scene = $this->graph->scene($currentScene);
        $choice = $scene->choice($choiceKey);
        $condition = $choice->condition;

        $here = ['scene' => $currentScene, 'choice' => $choiceKey];

        if ($condition !== null && !self::inContext(
            [...$here, 'phase' => 'choice_condition', 'turn' => $turn],
            fn (): bool => $this->evaluator->evaluate($condition, $state),
        )) {
            throw ChoiceUnavailableException::conditionFailed($currentScene, $choiceKey);
        }

        /** @var list<EngineEvent> $events */
        $events = [new ChoiceMade($currentScene, $choiceKey, $turn)];

        $chosen = self::inContext(
            [...$here, 'phase' => EffectPhase::Choice->value, 'turn' => $turn],
            fn (): EffectResult => $this->applier->apply($choice->effects, $state),
        );
        $state = $chosen->state;

        if (!$chosen->diff->isEmpty()) {
            $events[] = new EffectsApplied($currentScene, EffectPhase::Choice, $chosen->diff, $turn);
        }

        if ($chosen->isRedirect()) {
            // A `goto` in the choice's effects replaces the declared `next`
            // entirely, and spends the first redirect of the turn.
            $target = $chosen->redirectTo;
            $redirects = 1;
            self::guardRedirects($redirects, $target);
        } else {
            $target = self::inContext(
                [...$here, 'phase' => 'next', 'turn' => $turn],
                fn (): string => $this->resolver->resolve($scene->next, $state, $currentScene),
            );
            $redirects = 0;
        }

        return self::enter($this->graph, $this->applier, $state, $target, $turn, $redirects, $events);
    }

    /**
     * Enter a scene with no choice having been made: a story's opening scene,
     * and the only way its `on_enter` ever runs.
     *
     * Without this, nothing applies the first scene's effects, nothing marks
     * it visited and nothing emits its `SceneEntered` — so a story that sets
     * `hp` on arrival begins without it, and `{"op":"visited","scene":"start"}`
     * is false while the player is standing in it. A caller could only fix
     * that by re-implementing the entry algorithm, which would move D-VISIT
     * and D-REDIRECT into a context that is not supposed to know them. Here
     * it stays in one place: `begin()` and `run()` call the same private
     * {@see self::enter()}, so the two cannot drift apart.
     *
     * Static because a story that has not begun has no turn machinery yet —
     * there is no choice to evaluate and no `next` to resolve, so neither a
     * `ConditionEvaluator` nor a `NextSceneResolver` takes any part. The graph
     * and the schema are all it needs.
     *
     * The budget is the same five, spent the same way by a `goto` in the
     * opening scene's `on_enter`, and refused with the same
     * `RedirectLoopException`. Events are the turn's, minus the one that would
     * be a lie: **no `ChoiceMade` is emitted, because no choice was made.**
     *
     * @param  int                   $turn the caller's turn counter; the Engine never reads a clock
     * @throws UnknownSceneException if the scene, or any scene reached from it, is unknown
     * @throws RedirectLoopException if the entry spent more than MAX_REDIRECTS redirects
     */
    public static function begin(
        StoryGraph $graph,
        VariableSchema $schema,
        GameState $state,
        string $sceneKey,
        int $turn,
    ): TransitionResult {
        return self::enter($graph, new EffectApplier($schema), $state, $sceneKey, $turn, 0, []);
    }

    /**
     * Coming to rest on a scene: its `on_enter`, the redirect chain that may
     * start, the visit, and the events that follow.
     *
     * `run()` arrives here having already made a choice and possibly spent the
     * first redirect on it; `begin()` arrives with neither. Everything from
     * this point on is identical for both, which is why it is written once.
     *
     * @param  list<EngineEvent>     $events the events emitted so far, continued
     * @throws UnknownSceneException if the target, or any scene redirected to, is unknown
     * @throws RedirectLoopException if the budget is exceeded
     */
    private static function enter(
        StoryGraph $graph,
        EffectApplier $applier,
        GameState $state,
        string $target,
        int $turn,
        int $redirects,
        array $events,
    ): TransitionResult {
        self::requireScene($graph, $target);

        while (true) {
            // No `choice` key: an entry has no choice to name, and a turn's
            // choice is not where an `on_enter` node is broken. The Engine
            // does not invent context it does not have (review M4).
            $entered = self::inContext(
                ['scene' => $target, 'phase' => EffectPhase::OnEnter->value, 'turn' => $turn],
                fn (): EffectResult => $applier->apply($graph->scene($target)->onEnter, $state),
            );
            $state = $entered->state;

            if (!$entered->diff->isEmpty()) {
                $events[] = new EffectsApplied($target, EffectPhase::OnEnter, $entered->diff, $turn);
            }

            if (!$entered->isRedirect()) {
                break;
            }

            ++$redirects;
            self::guardRedirects($redirects, $entered->redirectTo);

            $target = $entered->redirectTo;

            self::requireScene($graph, $target);
        }

        $state = $state->withVisit($target);

        $events[] = new SceneEntered($target, $redirects, $turn);

        $isEnding = $graph->isEnding($target);

        if ($isEnding) {
            $events[] = new EndingReached($target, $turn);
        }

        return new TransitionResult($state, $target, $isEnding, $events);
    }

    /**
     * Runs one phase of a turn and, if it fails, says where it was.
     *
     * The evaluator and the applier know a variable name and nothing else; a
     * turn knows the scene, the choice, the phase and the turn number. This is
     * the one place the second is attached to the first. The exception type is
     * unchanged, so every `catch` keeps catching what it caught — only the
     * message and the structured context grow (review M4).
     *
     * @template T
     * @param  array<string, string|int> $context
     * @param  callable(): T             $phase
     * @return T
     */
    private static function inContext(array $context, callable $phase): mixed
    {
        try {
            return $phase();
        } catch (EngineException $failure) {
            throw $failure->withContext($context);
        }
    }

    /**
     * Called after every increment and after the initial one, so there is
     * exactly one place the off-by-one can live: five redirects complete, the
     * sixth throws.
     */
    private static function guardRedirects(int $redirects, string $scene): void
    {
        if ($redirects > self::MAX_REDIRECTS) {
            throw RedirectLoopException::exceeded(self::MAX_REDIRECTS, $scene);
        }
    }

    private static function requireScene(StoryGraph $graph, string $sceneKey): void
    {
        if (!$graph->hasScene($sceneKey)) {
            throw UnknownSceneException::named($sceneKey);
        }
    }
}
