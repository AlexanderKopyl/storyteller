# engine-core — summary

The pure, deterministic, framework-free rules engine: `src/Engine`.

- **Commits:** 38, `065803d..d4ba1b8`
- **Diff:** 84 files, +11,827 / −1
- **Phase documents:** `01-analysis.md` · `02-plan.md` · `03-implementation-notes.md` · `04-review.md` · `05-test-report.md`
- **Published spec:** `docs/engine/ast.md`

## What changed, per bounded context

| Context | Change |
|---------|--------|
| `src/Engine` | **New.** 51 files: the condition and effect AST (13 + 8 operators), `GameState`, `VariableSchema`, `StateDiff`, `ConditionEvaluator`, `EffectApplier`, `NextSceneResolver`, `Transition`, the `StoryGraph` contract, four events, a 10-class exception family, and `Rng`/`SeededRng`. |
| `src/Content` | None. `StoryGraph` is defined in Engine for Content to implement later. |
| `src/Play` | None. |
| `src/Api` | None. |
| `src/Shared` | None. Engine imports nothing from it. |
| tooling | `Dockerfile`, `docker/php/php.ini`, `composer.json`, `Makefile`, `phpunit.xml` — PCOV line coverage scoped to `src/Engine` (step 1, commit `065803d`). Nothing else touched for the rest of the feature. |

## The four checks

Run by the orchestrator at `d4ba1b8`, inside the `php` container, quoting each command's own output:

| Check | Result |
|-------|--------|
| `composer cs-check` | `Found 0 of 87 files that can be fixed` |
| `composer phpstan` | `[OK] No errors` (level 8, no baseline, zero `@phpstan-ignore`) |
| `composer deptrac` | `Violations 0 · Skipped 0 · Uncovered 0 · Allowed 0 · Warnings 0 · Errors 0` |
| `composer test` | `OK (542 tests, 7923 assertions)` |
| `composer coverage` | `Lines: 100.00% (655/655)` · `Classes: 100.00% (44/44)` · `Methods: 100.00% (189/189)` |

`composer qa` exit 0.

### On the acceptance criterion "deptrac confirms Engine has no outgoing dependencies except Shared"

deptrac reports `Allowed 0`, and always will for this feature. `MatchingLayersHandler` (priority 1) calls `stopPropagation()` on same-layer pairs before `AllowDependencyHandler` (priority −100) can record one, and every edge here is `Engine -> Engine`. The JSON report confirms it: `"files": []`.

The meaningful numbers are `Uncovered 0` — every dependency the analyser found was classified — and `Violations 0`. What they prove is **stronger** than the criterion asked for: Engine has *no* outgoing dependencies at all, not merely none beyond `Shared`.

Positive control: during step 13 a Symfony import was temporarily placed in `src/Engine/Transition.php`. deptrac returned `Violations 1` and `BoundedContextLayoutTest` failed. The rule is demonstrably live, not merely reporting zero.

## Tests added

28 test files under `tests/Unit/Engine/`, plus the `InMemoryStoryGraph` double. 542 tests, 7,923 assertions.

What distinguishes this suite from one that merely reaches 100% coverage: every test added in the Test phase was falsified against a temporary `src/` mutation (14 probes, tabulated in `05-test-report.md`), and the implementer ran its own probes during the build — removing `sort($flags)` broke the commutative-effects case, injecting `mt_rand()` into `NextSceneResolver` broke the purity scan, a static property on `EngineException` broke the reflection guard. Tests that cannot fail are not evidence; these were shown to fail.

Highlights:

- **Determinism** — two independently built runs asserted equal at four strengths, with the full six-event sequence and all three `EffectsApplied` diffs pinned literally.
- **`SeededRng` golden values** — literal expected draws for given `(seed, turn)` pairs, with a SHA-256 digest beside them so a future failure says whether `hash()` or `Xoshiro256StarStar` moved. Before this, reproducibility was only proven *within* a process.
- **Locale sorting** — nothing in the suite distinguished `SORT_STRING` from `SORT_LOCALE_STRING`; they agree in the `C` locale, so the slip would have corrupted only collating machines. One `DeterminismTest` case now goes red on that swap.

## Review findings

Gate C ran twice. **0 blockers remain.**

### Fixed

| Id | Finding | Commit |
|----|---------|--------|
| **B1** *(blocker)* | `json_encode(1.0)` is `"1"` and decodes as `int`, which `VariableType::Float` rejected — so a `float` variable could not survive the JSONB round trip the spec requires. An `eq` was `true` before a save and `false` after reloading identical state. Fixed by letting `Float` accept `int` and canonicalising at every schema-aware boundary; `Int` still rejects `float`. | `9671a19` |
| **M2** | One variable absent from the schema made *every* transition throw, including `apply([], $state)` with no effects. | `b028b2b` |
| **M3** | An explicit `"condition": null` silently yielded an **always-available** choice. | `633c1e3` |
| **M5** | "No such choice" and "choice is locked" shared one exception type, separable only by English prose. | `e4ca1b9` |
| **r4** | M3 named five keys; two were closed. `qty: null` → 1, `times: null` → 1 silently — turning *"needs 5 ropes"* into *"needs 1 rope"* with no error. | `d266d70` |
| **M6** | No golden-value RNG test; a PHP upgrade changing the generator would have gone unnoticed while saves silently diverged. | `d203702` |
| **r1** | `toArray() === toArray()` differs for semantically equal states. Pinned in executable form: JSON identical, raw `===` not. | `f68ce66` |
| **r2** | `Float->accepts(9007199254740993)` canonicalises to `...992.0`. Pinned at type level and as `eq`. | `ce2046e` |

### Open — not fixed, carried deliberately

| Id | Finding | Why deferred |
|----|---------|--------------|
| **r3 / B1-b** *(major)* | `GameState::fromArray()` accepts any scalar from JSONB; nothing validates a loaded state against its schema. With `hp => "10"` under an `int` declaration: `eq` false, `gt` true, `add` throws, `set` succeeds — four operators, three policies, one malformed state. | Wants `VariableSchema::assertState()` at the **load** boundary. That is a persistence decision belonging with `Play`; nothing persists yet. **Must close before `Play` stores anything.** |
| **M1** *(major)* | No entry point for the *first* scene: its `on_enter` never runs, so `Play` would reimplement D-VISIT and D-REDIRECT itself. | Adds public API beyond the specified `Transition::run` signature — a user decision. **Becomes a breaking change once `Content` or `Play` exists.** |
| **M4** *(major)* | Evaluation-time errors carry no scene/choice/phase, though `Transition` holds all three. | Debuggability, not correctness. |
| **r2 docblock** *(minor)* | `VariableType.php:13-16` still says widening int→float "loses nothing" — the stated justification for the whole asymmetry, and false above 2⁵³. The behaviour is now pinned by tests; the comment is wrong. | Comment-only; needs the implementer. |
| minors | Impossible-scenario docblocks on `visited`; nine public methods with no production call site; `RedirectLoopException` naming an unreached scene; purity scan missing `getenv`/`spl_object_id`/file I/O; `requireIntMap`/`requireScalarMap` accepting a positional array. | Low consequence. |
| — | No mutation-testing tool. The suite's falsification was done by hand. | A new dependency; standing rule 9 requires a plan entry first. |

### Known non-defect

`EffectApplier`'s `max(0, …)` for `remove_item` is unfalsifiable — deleting it leaves the suite green, because `GameState::withItem()` already drops a key at `qty <= 0`. Defence in depth, recorded so a future reader does not mistake it for dead code.

## Plan defects found during implementation

1. **D-VISIT's planned test case is not expressible.** "A scene whose `on_enter` branches on its own visit count" cannot exist: effects carry no conditions. The decision stands, pinned by two observable consequences instead, and `docs/engine/ast.md` documents `visited` in terms of choice conditions and `next` guards.
2. **`EffectsApplied.diff` had no `from` for a variable's first `set`.** `GameState::initial()` carries no vars; defaults live in the schema. Resolved by passing `VariableSchema` into `StateDiff::between()` so an absent `from` resolves to the declared default — the spec's own rule, "missing var evaluates against the schema default, never null".
3. **`GameState::initial()` takes no parameter** where the spec wrote `initial(VariableSchema)`. Kept deliberately: a state carrying only *overrides* stays independent of the schema version, so changing a declared default later reaches saved states instead of being frozen into them.

## Model routing

| Phase / step | Agent | Model | Reason |
|--------------|-------|-------|--------|
| 1 Analyze | `analyst` | `opus` | Ambiguity and consequence-finding; 15 risks, 14 open questions. |
| 2 Plan | `planner` | `opus` | Architecture; the plan is where mistakes are still cheap. |
| 3 Implement · step 1 | `implementer` | `sonnet` | PCOV wiring — build config, mechanical, verifiable by a command. Completed in 101s. |
| 3 Implement · steps 2–7 | `implementer` | `opus` | Capability floor: `src/Engine`. |
| 3 Implement · steps 8–13 | `implementer` | `opus` | Capability floor: `src/Engine`, cross-context `StoryGraph` interface. |
| 3 Implement · steps 14–15 | `implementer` | `opus` | Capability floor: determinism. |
| 3 Implement · step 16 | `implementer` | `sonnet` | Documenting a frozen, fully tested AST; no design decisions open. |
| 4 Review | `reviewer` | `opus` | Adversarial reading of an all-`src/Engine` diff. |
| Gate C · fix loop 1 | `implementer` | `opus` | Escalation rule — `src/Engine` is both floor and ceiling, so the loop ran at the top tier rather than escalating into one. |
| Gate C · re-review | `reviewer` | `opus` | Same. |
| Gate C · fix loop 2 (r4) | `implementer` | `opus` | Same. |
| 5 Test | `tester` | `opus` | Capability floor: determinism and AST operators. |

**Escalations:** none available. Every Engine step sits on the `opus` capability floor, which is also the ceiling, so both Gate C loops ran at the top tier by the floor rather than by escalation. No step was retried at a lower tier.

**Routing note:** the two `sonnet` steps (1 and 16) were the only ones clear of the floor — a build-config change and documentation of an already-frozen AST. Both passed their gates first time.

## Verification stance

Every check in this document was re-run by the orchestrator rather than taken from an agent's report. Two agent-reported figures did not survive that:

- The Test phase reported `7935` assertions; the committed tree gives **7,923**, stable across runs. The difference is `EnginePurityTest` scanning `src/Engine`, whose assertion count scaled with the temporary mutation-probe copies present during that agent's run.
- The B1 blocker was reproduced in the container before the fix loop was dispatched, rather than accepted from the review.

## Open items for the user

1. **M1 — first-scene entry point.** Decide before `Content` is planned; it is a breaking change afterwards.
2. **r3 — `VariableSchema::assertState()` at the load boundary.** Must close before `Play` persists anything.
3. **CLAUDE.md names `TransitionResolver`** in the `src/Engine` row; the class is `Transition`. Stale reference.
4. **Whether 100% line coverage should be machine-enforced** (a `--coverage-text` threshold in the `qa` chain) rather than checked by hand.
5. **The `opus` capability floor in `.claude/skills/sdlc/SKILL.md`** leaves no room to escalate for `src/Engine` work. Worth stating explicitly that the floor is also the ceiling there.
