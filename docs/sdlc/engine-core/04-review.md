# 04 — Review: engine-core

- **Feature slug:** `engine-core`
- **Reviewed against:** `docs/sdlc/engine-core/02-plan.md`, `docs/sdlc/engine-core/03-implementation-notes.md` and `CLAUDE.md`
- **Diff reviewed:** `065803d..81fe63c` (21 commits; 75 files, +8755/−0 — `src/Engine/**`, `tests/Unit/Engine/**` and the notes document only)
- **Date:** 2026-10-25

## Verdict

**blockers: 1 · majors: 6 · minors: 5**

This is high-quality work. The dependency rule, the purity rules, the redirect budget, the
D-VISIT ordering and the event sequence are all implemented exactly as planned and pinned by
tests that genuinely discriminate the alternatives — `testASceneRedirectedThroughIsNotMarkedVisitedButItsEffectsStillApply`
would fail under the rejected design, which is what a determinism test is supposed to do.
The green checks the orchestrator verified are real, and so is the test quality behind them.

It is **not mergeable as it stands**, for one reason: the serialized forms the plan freezes as
cross-context contracts do not survive JSON. A `float` variable holding an integral value
(`1.0`) is written by `json_encode` as `1`, read back as `int`, and the Engine's strict
`Float` type then either rejects it or silently changes the answer of an `eq`. I reproduced
all three failure modes against the committed code (probe output quoted in B1). "Same
`GameState` + same input ⇒ same result" currently holds in-process and breaks across the
`play_session` JSONB column this very state object is designed for. Fix B1, add the four
round-trip tests it names, and the rest is normal pre-merge cleanup. None of the six majors
requires a format change *if* taken now; three of them become breaking once `Content` or
`Play` is built on top.

## Findings

### Blockers

#### B1 — `float` variables do not survive a JSON round trip; `eq` silently flips after a save

- **File:** `src/Engine/State/VariableType.php:25` (`self::Float => is_float($value)`), with
  consequences at `src/Engine/State/GameState.php:96`, `src/Engine/State/VariableDefinition.php:44`
  and `src/Engine/ConditionEvaluator.php:76`
- **Category:** engine purity / determinism · cross-context contract · tests
- **Problem:** `VariableType::Float->accepts()` requires `is_float()` and deliberately rejects
  `int` (plan: "`Float` does **not** accept an int"). But `json_encode()` without
  `JSON_PRESERVE_ZERO_FRACTION` writes a float with a zero fraction as an integer, and
  `json_decode()` returns it as `int`. Since `GameState::toArray()` is defined by the plan as
  the `play_session` JSONB column and the AST / schema `toArray()` as the `story_version`
  snapshot, every float with an integral value is corrupted by the only persistence path these
  objects have. Reproduced against the committed code on PHP 8.5.3 with the project's default
  `serialize_precision = -1`:

  ```
  PROBE2 json: {"ratio":{"type":"float","default":1}}
  PROBE2: TypeMismatchException -> Variable "ratio" is declared as float, but the value is of type int.
  PROBE3 before save: true
  PROBE3 json: {"v":1,"vars":{"ratio":1},...}
  PROBE3 after load: false            // the identical eq node, the identical state
  PROBE4 json: {"op":"set","var":"ratio","value":1}
  PROBE4: TypeMismatchException -> Variable "ratio" is declared as float, but the value is of type int.
  ```

  Three distinct reachable failures: (1) a schema with `default: 1.0` cannot be re-read after
  being serialized — `VariableSchema::fromArray(json_decode(json_encode($schema->toArray())))`
  throws; (2) a compiled `{"op":"set","var":"ratio","value":1.0}` node throws
  `TypeMismatchException` at play time although it parsed and ran before persistence; (3)
  worst, an `eq` that is `true` before a save is `false` after the state is reloaded — no
  exception, no log entry, a different story.
- **Why it matters:** this is the determinism guarantee, which is the Engine's entire reason
  for existing, failing across the one boundary that matters. It is also an unexploded
  format bomb: the moment `src/Content` compiles a story with a float variable and `src/Play`
  persists a session, the shape is frozen and the fix becomes a JSONB data migration (plan P6's
  own argument). Every float test in the suite uses a non-integral value (`0.5`, `0.25`,
  `ratio => 0.1 + 0.2`), and the one `json_encode`/`json_decode` round-trip test
  (`GameStateTest::testRoundTripsAnItemKeyThatLooksLikeAnInteger`) covers an integer-like
  *key*, never an integral float *value* — which is exactly why 100 % line coverage did not
  see this.
- **Suggested fix:** make `float` round-trip-safe inside the Engine rather than relying on
  every future encoder in every context remembering `JSON_PRESERVE_ZERO_FRACTION` (which would
  be an unenforceable cross-context requirement, and would not help a state already written).
  Concretely: add a canonicalisation step to `VariableSchema` — e.g.
  `canonicalise(string $name, int|float|string|bool $value): int|float|string|bool` that widens
  an `int` to `float` for a `Float`-declared variable — and call it from
  `VariableDefinition::__construct()`, `VariableSchema::resolve()`, `VariableSchema::assertAccepts()`
  and `ConditionEvaluator::compare()` (for the literal). Note that widening `accepts()` alone
  is **not** sufficient: PROBE3 still fails, because `resolve()` would return `int 1` and
  `1 === 1.0` is false — the value has to be normalised on both sides of the `===`. `Int` must
  stay strict (no float → int), so OQ-5b is unaffected.
  While in `ConditionEvaluator::compare()`, also validate the *resolved* value against the
  declared type, as `EffectApplier::arithmetic()` already does at
  `src/Engine/EffectApplier.php:98` ("a state built elsewhere can disagree with the
  declaration"). Today a disagreeing state makes the applier throw and the evaluator silently
  return `false` — the same input, two different policies, and B1 proves such states are
  reachable through ordinary persistence.
  Required new tests: a `GameState` `json_encode`→`json_decode`→`fromArray` round trip with
  `['ratio' => 1.0]`; the same for `VariableSchema` with `default: 1.0`; the same for a
  `Comparison` and a `SetVar` node with `value: 1.0`; and an `eq`-before/`eq`-after assertion
  on the reloaded state.

### Majors

#### M1 — the Engine ships no way to enter the first scene of a story

- **File:** `src/Engine/Transition.php:57` (`run()` is the only public entry point)
- **Problem / Why / Suggested fix:** `Transition::run()` requires a `$currentScene` and a
  `$choiceKey`, and `GameState::initial()` deliberately marks nothing visited (P5 / OQ-9c). So
  nothing in the Engine runs the **start scene's `on_enter`**, marks it visited, or emits its
  `SceneEntered` — a story whose opening scene sets `hp` or a flag in `on_enter` begins with
  those effects never applied, and `{"op":"visited","scene":"start"}` is false while the player
  is standing in it. `src/Play` can only fix this by reimplementing the entry algorithm —
  `on_enter` → redirect loop → `withVisit` → events — i.e. by re-owning D-VISIT and D-REDIRECT
  in a context that is not supposed to know them, which is precisely the drift P4 and P9 exist
  to prevent. Fix: add `Transition::enter(GameState $state, string $sceneKey, int $turn): TransitionResult`,
  which is steps 9–13 of the planned algorithm factored out of `run()` and shared with it, with
  its own tests for the start-scene `on_enter`, a start-scene `goto`, and the visit/history
  entry. Additive, no format change — but it must land before `src/Play` is planned.

#### M2 — a state carrying an undeclared variable makes *every* turn throw, including one with no effects

- **File:** `src/Engine/State/StateDiff.php:44` (`$schema->resolve($name, $before)` over the
  union of both states' var keys)
- **Problem / Why / Suggested fix:** `StateDiff::between()` resolves **every** variable present
  in either state, so one name the schema does not declare aborts the diff — and the diff runs
  on every phase of every turn, even an empty one. Verified:
  `(new EffectApplier($schema))->apply([], GameState::create(['hp'=>10,'legacy_mana'=>3],…))`
  throws `UnknownVariableException: Unknown variable "legacy_mana"`. P3's rule ("a name absent
  from the schema is an error even when the state carries a value") was written about *resolving
  a variable an AST node names*; applying it to a passive enumeration converts "the author asked
  about an unknown variable" into "this session can never take another turn", with a message
  that names a variable the turn never mentioned. A saved session whose story version's schema
  no longer lists a variable is permanently bricked, and nothing in the suite covers it. Fix:
  skip undeclared names in `between()` (they are not the diff's business — `isEmpty()` and the
  event payload are unaffected), or, if the strict reading is intended, make it an explicit
  validation with its own exception and message at the turn boundary. Either way add the test:
  `apply([], $stateWithUndeclaredVar)`.

#### M3 — a `null` condition is silently dropped, turning a guarded choice into an unguarded one

- **File:** `src/Engine/Graph/ChoiceNode.php:48` and `src/Engine/Graph/NextRule.php:41`, via
  `src/Engine/Support/ArrayReader.php:248` (`has()` is `isset()`)
- **Problem / Why / Suggested fix:** `has()` means present-**and-not-null**, so
  `{"key":"north","condition":null}` parses as a choice with **no condition — always
  available**, and `{"scene":"cave","if":null}` parses as an unconditional fallback. In YAML a
  `condition:` line with nothing after it is exactly `null`, and a compiler that emits
  `"condition": null` for "no condition" is the obvious thing for `src/Content` to do. The
  result is an authored gate silently removed rather than an authoring error reported — the
  opposite of what P7 ("a typo fails at compile time instead of silently doing nothing at play
  time") was introduced to guarantee, and the one category of silent failure that changes who
  can reach what. The same `isset()` reading also turns `"qty": null` into `qty = 1` and
  `"next": null` into a dead end. Fix: distinguish absent from null — e.g. a
  `presentButNull()` check (`array_key_exists($key, $data) && $data[$key] === null`) that
  raises `MalformedNodeException::wrongType(..., 'object', 'null')` — at least for `condition`,
  `if`, `next`, `qty` and `times`. Tests: one per key.

#### M4 — evaluation-time exceptions carry no scene, choice or phase, although `Transition` holds all three

- **File:** `src/Engine/Exception/UnknownVariableException.php:15` (and
  `TypeMismatchException`, `UnknownOperatorException`)
- **Problem / Why / Suggested fix:** parse-time errors carry a breadcrumb (`condition.of[1].if`)
  and are excellent. Evaluation-time errors carry only a variable name: `Unknown variable
  "hp".` is the entire message an operator will see for a failed turn, with no indication of
  which scene, which choice, which `next` rule, or which of the two effect phases produced it.
  `Transition::run()` knows `$currentScene`, `$choiceKey`, `$target`, the phase and `$turn`
  and discards all of it. For a story with 400 scenes, debugging that is a grep. Fix: catch
  `EngineException` at the phase boundaries in `Transition::run()` and rethrow with context
  (or add an optional structured context array / `withContext()` on `EngineException`, which
  also gives Play something to log and Api something to correlate). Keep the original as
  `previous`. Test: assert the scene and choice keys appear in the message of a failure raised
  from inside a choice-effect phase.

#### M5 — the two `ChoiceUnavailableException` cases are distinguishable only by English prose

- **File:** `src/Engine/Exception/ChoiceUnavailableException.php:16` and `:21`
- **Problem / Why / Suggested fix:** "this scene has no choice `west`" is a client/content bug
  (an Api would answer 404/422 and should not retry); "the choice exists but its condition is
  false" is a state conflict (409, and legitimately racy if two turns are submitted
  concurrently). Both are the same class with no code, no enum and no property — the only
  discriminator is `getMessage()`, so `src/Api` will have to string-match or collapse two
  different HTTP answers into one. The plan's rationale ("the caller's recovery is the same in
  both cases") does not survive contact with an HTTP boundary, and no test distinguishes them
  today (`TransitionTest::testAChoiceWhoseConditionIsFalseThrows` and
  `testAChoiceKeyAbsentFromTheSceneThrows` both assert only the choice key). Fix: add a
  `public readonly ChoiceUnavailableReason $reason` (enum `NotFound` / `ConditionFailed`) set by
  the two named constructors, and assert it in both tests. Cheap now, a breaking change for
  Api once it ships.

#### M6 — `SeededRng` promises cross-version reproducibility that no test can falsify

- **File:** `src/Engine/Random/SeededRng.php:23` ("the same `(seed, turn, index)` yields the
  same value … on a different machine, a year later"), tests at
  `tests/Unit/Engine/Random/SeededRngTest.php`
- **Problem / Why / Suggested fix:** the implementer flagged this honestly (notes, step 15) and
  it should be closed rather than deferred. The suite proves `SeededRng` is a pure function of
  its triple **within one process**; nothing pins the actual bytes. The docblock above makes
  the stronger claim, and the system's purpose — replaying a saved game later, against the same
  immutable story version — depends on the stronger claim. A future PHP changing
  `Xoshiro256StarStar`'s output, or `Randomizer::getInt()`'s bounded-range algorithm, or
  `getFloat()`'s γ-section, would silently re-roll every historical draw and no gate would go
  red. It is not a blocker only because the class is wired into nothing today
  (`testNothingInSrcEngineReferencesRng`), which is also why fixing it now costs nothing. Fix:
  pin three golden values — `intBetween(1, 0, 1, 6)`, `intBetween(7, 3, 0, 100)` and
  `fraction(1, 0)` — as literals, with a comment saying that a change here is a format break,
  not a test to update. Four lines; it turns a PHP upgrade from a silent corruption into a
  failing build.

### Minors

#### m1 — two docblocks instruct authors to do something the AST cannot express

- **File:** `src/Engine/Ast/Visited.php:12-16` and `src/Engine/Transition.php:31-35`
- **Problem / Suggested fix:** both describe `visited` "evaluated inside scene X's own
  `on_enter`" and a "first time here" test "written there". Effects carry no conditions, so
  nothing inside `on_enter` can read `visited` at all — the implementer established this in
  deviation 10 and the planned test case was dropped for exactly this reason. The rule itself
  is right and is correctly pinned by its observable consequences; only the illustration is
  impossible. Reword both in terms of **choice conditions and `next` guards** on the following
  turn, and make sure `docs/engine/ast.md` (step 16) does not copy the current phrasing.

#### m2 — nine public methods have no production call site

- **File:** `src/Engine/EffectResult.php:30` (`isRedirect()`), plus `ConditionOp::isComparison()`,
  `EffectOp::isArithmetic()`, `NextRule::isFallback()`, `ChoiceNode::isAlwaysAvailable()`,
  `SceneNode::hasChoice()`, `VariableSchema::names()`, `VariableSchema::defaultOf()`,
  `ArrayReader::child()`
- **Problem / Suggested fix:** each is reached only by the test written for it. Notably
  `Transition` uses `$chosen->redirectTo !== null` rather than the `$r->isRedirect()` the plan's
  own step 7 specifies, and `ArrayReader::child()` was orphaned when `parse()` started taking
  the path explicitly (deviation 5). Harmless, but it means the 100 % figure includes surface
  nothing exercises. Either use them (`isRedirect()` in `Transition` would be a one-line
  improvement in readability) or note in the review of the next feature that they are
  consumer-facing API awaiting `Content`/`Play`. Do not delete anything `StoryGraph` implementers
  will need.

#### m3 — `RedirectLoopException` names a scene that was never reached

- **File:** `src/Engine/Exception/RedirectLoopException.php:17`, called from
  `src/Engine/Transition.php:104`
- **Problem / Suggested fix:** the guard is called with `$entered->redirectTo` — the scene the
  turn was *about to* enter — but the message reads "the last scene reached was …". In
  `TransitionTest::testSixRedirectsThrowRedirectLoopException` the asserted name is `h6`, which
  is the one scene in the chain that was never entered. Reword to "the redirect to … was
  refused", or pass the scene actually last entered. One-line message change, but this is the
  message an operator debugging a redirect cycle will read first.

#### m4 — the purity scan does not cover the remaining determinism holes

- **File:** `tests/Unit/Engine/EnginePurityTest.php:38` (`NON_DETERMINISTIC_FUNCTIONS`)
- **Problem / Suggested fix:** the list and its self-test are excellent, and the three
  falsification probes recorded in the notes are the right way to earn trust in a guard. It
  does not yet forbid `getenv`, `spl_object_id` / `spl_object_hash` (the classic cross-process
  ordering leak), `php_uname`, `Random\Engine\Secure`, or file I/O (`file_get_contents`,
  `fopen`, `glob`) — the last being CLAUDE.md's "no I/O" rule, which is the one Engine
  invariant currently held by prose alone. Each costs one array entry plus one sample pair in
  `testThePatternsMatchTheCallFormsTheyClaimTo`.

#### m5 — the state envelope accepts a positional array where a map is required

- **File:** `src/Engine/Support/ArrayReader.php:188` (`requireIntMap`) and `:211`
  (`requireScalarMap`)
- **Problem / Suggested fix:** unlike `requireArrayList`/`requireStringList`, neither checks
  shape, so `{"vars": [1, 2]}` is accepted as `{"0": 1, "1": 2}` — two variables named `0` and
  `1`, which then fail far away (see M2). The empty case must keep passing, since an empty map
  serializes as `[]`. Fix: reject a **non-empty** list (`$raw !== [] && array_is_list($raw)`)
  with `wrongType(..., 'map', 'list')`, and add a test case per method.

## Checklist

| Area | Result | Note |
|------|--------|------|
| Dependency rule (deptrac) | pass | `Engine → nothing` holds: no Symfony/Doctrine/PSR import, the exception family stays in-Engine, `src/Shared` untouched. Deviation 6 (`Allowed 0`) is correctly diagnosed — deptrac never records an intra-layer edge — and the analysed-file count is the right falsifiable substitute. The step-13 probe (a `Symfony\…\Uuid` import producing `Violations 1` *and* a `BoundedContextLayoutTest` failure) is the strongest evidence in the feature that the gate bites. |
| Engine purity & determinism | **fail** | No clock, no RNG, no statics, no globals, no I/O; `SORT_STRING` (not `SORT_LOCALE_STRING`) everywhere, so ordering is locale-independent; canonicalisation is structural rather than asserted. But B1 breaks "same state + same input ⇒ same result" across the persistence boundary, and M6 leaves the one seeded component's cross-version claim untested. |
| Plan conformance | pass | `Transition::run()` follows the 13-step algorithm literally and in order; D-REDIRECT is exact (five pass at `redirects === 5`, the sixth throws, the choice `goto` counted); D-VISIT is implemented and — despite its planned test case being inexpressible — is pinned by two consequences that genuinely discriminate it from the rejected design. All 13 deviations in `03-implementation-notes.md` are real, justified and additive. m1 and m2 are the only drift. |
| Engine changes have unit tests | pass | 24 test files, one per class or an explicit shared-coverage mapping enforced by `EnginePurityTest`. Boundary testing is real (`lt 96/95`, `lte 95/94`, `gt 94/95`, `gte 95/96`; `has_item`/`visited` below, at and above threshold); short-circuiting is proved by a child that would throw; the event list is asserted as a literal array, not a count. Gaps are named in B1, M2 and M3. |
| Input validation & auth | n/a (pass for this layer) | No API surface in this diff. The parse-time layer is strict in the right places — unknown keys rejected, `array_is_list` enforced for lists, numeric strings and bools refused where a number is required, duplicate choice keys and empty `and`/`or` rejected. M3 and m5 are the two holes. |
| Error handling | **fail** | One root (`EngineException extends \RuntimeException`), eight leaf types, no swallowed exception, no `catch` that hides anything, and no state *value* ever interpolated into a message (`get_debug_type()` is used throughout) — so nothing leaks PII from a save. But M4 (no scene/choice/phase context at evaluation time) and M5 (two failure modes, one type, no code) will both be felt at the Api boundary. Note for the Api phase: raw Engine messages name internal scene keys and variable names of possibly-unpublished content and must not be forwarded to clients verbatim. |
| Performance (N+1, indexes, loads) | pass | No I/O, no queries. The redirect loop is bounded by `MAX_REDIRECTS`; `and`/`or` short-circuit; `StateDiff` is O(vars + inventory + visited) per phase and `history` is never diffed. The only unbounded growth is `history`, which the plan explicitly assigns to Play to cap. |
| Naming & PSR-12 | pass | Ubiquitous language is consistent (`GameState`, `StoryGraph`, `SceneNode`, `on_enter`, `is_ending`); every namespace matches its path; `snake_case` is applied uniformly to the wire format and `camelCase` to PHP; `LogicalAnd`/`GoToScene` are correctly worked around. |

## Good things worth keeping

- **The falsification probes.** Deleting a `sort()`, injecting `mt_rand`, adding a static
  property, and importing a Symfony class — each run, each confirmed to fail the intended
  guard, each reverted by file copy rather than `git checkout`. That is how a guard earns the
  right to be trusted, and it is why I did not have to re-derive the purity claims.
- **`EnginePurityTest::testThePatternsMatchTheCallFormsTheyClaimTo`.** A regex guard with a
  self-test of nine positive and nine negative samples, including `strtotime(`, `$clock?->time()`
  and `Formatter::date(`. A scan that cannot go vacuous is worth more than a scan with a longer
  list.
- **The open-hierarchy `default` arms** in the evaluator and the applier. Refusing to suppress
  `match.unhandled` and instead making the unhandled case throw `UnknownOperatorException` —
  with a test that constructs an anonymous `Condition` subclass to reach it — is what makes
  CLAUDE.md's "adding an operator means adding it to the evaluator" fail loudly instead of
  quietly returning `false`.
- **Structural prevention over tested prevention.** `withVisit()` as the only writer of both
  `visited` and `history` (P4) and `isEnding` living only on `StoryGraph` (P9) remove two
  whole classes of drift rather than asserting their absence.
- **Reporting the `StateDiff` `from` gap as a plan defect instead of inventing a shape**, and
  the honest flagging of the missing RNG golden value and of the inexpressible D-VISIT case.
  Three times the implementer stopped at a decision that was not theirs to make. That is the
  behaviour that made this review cheap.

---

## Re-review — Gate C fix loop 1

- **Diff re-reviewed:** `db1d609..24a32d5` (5 commits; 28 files, +866/−49)
- **Scope:** targeted. B1, M2, M3, M5 and the two items carried forward (B1-b, M3-a) only.
  M1, M4, M6 and m1–m5 were not re-audited; they stand as written above.
- **Date:** 2026-10-25

### Verdict

**blockers: 0 · new majors: 1 (M3-a, the unfixed remainder of M3) · new minors: 2**

B1, M2 and M5 are closed. M3 is closed for `condition` and `if` and open for `next`, `qty`
and `times`. All four fixes are one commit each, test-first, and the new tests discriminate —
every one of them fails if its fix is reverted, and the three B1 tests assert with
`assertSame` on a scalar, which is `===` and therefore pins the PHP type and not just the
value. Nothing is mergeable-blocking. The remaining items are the two the implementer
declared rather than smuggled, plus one edge the fix itself opened.

### B1 — fixed

All three originally reproduced failures are gone. Re-probed against `24a32d5` on the same
PHP 8.5.3 / `serialize_precision=-1`:

```
schema json round trip        => {"ratio":{"type":"float","default":1}}   (was: TypeMismatchException)
set node 1.0 round trip       => applies, stores float(2)                 (was: TypeMismatchException)
eq before save / after reload => true / true                              (was: true / false)
```

The asymmetry survived exactly as required: `VariableType.php:39`
`Float->accepts(1) === true`, `Int->accepts(1.0) === false`, and `VariableType.php:54`
`Int->canonicalise(1.0)` returns `float(1.0)` rather than narrowing — so `accepts()` can
still refuse it. `VariableSchema::resolve()` (`:121`) is verifiably the **only** raw read of
a variable anywhere in `src/Engine` (`rg -e '->var\(' src/Engine` returns that one line), so
the "widens on read" half of the claim is structural, not a convention.

### B1-a — the declared residual gap: **genuinely narrowed, not a blocker**

I tested the claim rather than accepting it. Constructing a float-declared variable holding
a PHP `int` (via `create()`, `withVar()` and a real `json_encode`/`fromArray` round trip),
and comparing it against the semantically equal in-memory state:

```
raw var():            int   vs  float        <- the gap
resolve():            identical (float)
StateDiff between:    empty, []              <- no phantom event
json_encode(toArray): byte-identical
object ==  :          true
toArray() === :       FALSE                  <- the one observable consequence
after one applier turn touching ratio:   re-converge, toArray() === true
after one applier turn NOT touching ratio: toArray() === false, json identical
```

So: nothing misbehaves. `StateDiff` does not emit a phantom change, `resolve()` is stable,
the evaluator and applier cannot see the difference, the applier re-canonicalises on write,
and — the point that actually settles it for `Play` and `Content` — **the serialized bytes
are identical either way**, because `json_encode` collapses `1.0` to `1` regardless. The
persisted shape is one shape. `DeterminismTest`'s guarantee is untouched: it compares two
states built the same way, and its `assertSame($a->toArray(), $b->toArray())` still holds.

The gap is therefore reduced to a single PHP-value-identity fact: two semantically equal
`GameState` objects can differ under `===` on `toArray()` and on `var()`. That is a real
footgun for the consumers that do not exist yet, but it is a **minor**, not a blocker in
disguise — see r1 for the one thing I want done about it.

#### r1 (minor) — the residual gap is documented but not pinned

- **File:** `src/Engine/State/GameState.php:140` (`var()`), with
  `tests/Unit/Engine/State/GameStateTest.php:219`
- **Problem:** `testAFullStateWithAnIntegralFloatSurvivesAJsonEncodeDecodeCycle` asserts
  `assertEquals($state, $reloaded)` — loose, and correctly so, because `assertSame` on
  `toArray()` would fail. But nothing states *why* it is loose, and nothing pins the
  asymmetry itself. `GameState` has no `equals()` today; the day someone adds one as
  `$this->toArray() === $other->toArray()`, two identical saved sessions compare unequal and
  no test goes red.
- **Suggested fix:** two assertions in that same test —
  `self::assertIsInt($reloaded->var('ratio'))` and
  `self::assertIsFloat($state->var('ratio'))`, with a comment naming `resolve()` as the only
  honest read — plus one line in `03-implementation-notes.md` for the `Play` phase: compare
  states by their encoded JSON or through `resolve()`, never by `===` on `toArray()`.

#### r2 (minor) — widening is not lossless above 2^53, and the docblock says it is

- **File:** `src/Engine/State/VariableType.php:39` and `:54`
- **Problem:** `Float->accepts(9007199254740993)` is now `true` and `canonicalise()` returns
  `9007199254740992.0` — a different number, silently. Verified. Before the fix that value
  threw `TypeMismatchException`. The class docblock (`:13-16`) and the commit message both
  assert "widening loses nothing", which is the justification for the whole asymmetry, and
  it is false for `|int| > 2^53`.
- **Why it matters:** it is a sliver of the same silent-numeric-corruption class B1 was
  about. It is only a minor because no story variable plausibly exceeds 2^53, and because
  the pre-existing behaviour (a hard throw) was not better for the author.
- **Suggested fix:** cheapest honest option is to correct the docblock —
  "widening is lossless for every value a story will hold; above 2^53 an int is rounded, as
  it would be by JSON anyway". If you prefer the guard, refuse the widening in
  `canonicalise()` above `2**53` and let `assertAccepts()` throw. Either way add the
  `9007199254740993` case to `canonicalisationGridProvider`.

### B1-b — carried forward: **partly part of the original defect; now a standalone major**

It was written inside B1's suggested fix, but the reachability proof B1 gave it is gone: the
int-for-float path that made a disagreeing state reachable through ordinary persistence is
exactly what `canonicalise()` closed. So removing it from the blocker was correct.

It is not, however, resolved. The inconsistency is still there and still reachable, because
`GameState::fromArray()` accepts any scalar from a JSONB column and nothing validates a
loaded state against its story version's schema. Probed on a state carrying `hp => "10"`
under an `int` declaration:

```
evaluator  eq hp == 10  => false        (silent wrong answer)
evaluator  gt hp >  5   => true         (silent wrong answer, opposite direction)
applier    add hp + 1   => TypeMismatchException
applier    set hp = 1   => succeeds, overwrites
```

Four code paths, three different policies, for one malformed state.

#### r3 (major) — one disagreeing variable, three policies

- **File:** `src/Engine/ConditionEvaluator.php:76` (`compare()` does not validate `$current`),
  against `src/Engine/EffectApplier.php:100` which does
- **Why it matters:** a gate silently answering `true` is the one failure mode M3 and P7
  exist to prevent, and `Play` is about to become the thing that loads these states from
  JSONB. It is not a blocker for *this* diff — no Engine path produces such a state any
  more — but it must close before `Play` persists anything.
- **Suggested fix:** prefer validation at the boundary over symmetry in the evaluator —
  add `VariableSchema::assertState(GameState $state): void` (throwing
  `TypeMismatchException` per disagreeing declared variable, ignoring undeclared ones as
  `StateDiff` now does) for `Play` to call once on load. That is one check per session
  instead of one per comparison, and it gives a caller a real error instead of a wrong
  answer. If you would rather keep it inside the evaluator, the three-line version in the
  original B1 still applies. Tests: the four probe rows above, pinned.

### M2 — fixed

`src/Engine/State/StateDiff.php:58` skips an undeclared name. `apply([], $stateWithLegacyVar)`
now returns an empty diff instead of throwing, and `testAnEffectThatNamesAnUndeclaredVariableStillThrows`
pins that P3's strict rule still bites where it belongs. The skip loses nothing: no effect can
write an undeclared variable, so no change to one can ever be missing from the event payload.
No new problem.

### M3 — half fixed; **the other half is the defect I reported, not new scope**

`condition` and `if` are closed, with the exact messages asserted and both readings — absent
vs explicitly null — pinned per key. `ArrayReader::rejectNull()` (`:264`) is the right
primitive and its own test asserts it stays silent for both absent and non-null.

#### r4 (major, = M3-a) — `qty`, `times` and `next` still read an explicit null as a default

- **File:** `src/Engine/Ast/HasItem.php:35`, `src/Engine/Ast/Visited.php:40`,
  `src/Engine/Ast/ItemEffect.php:39` (all `optionalPositiveInt(..., 1)`, which is
  `has()`-gated), and `src/Engine/Graph/SceneNode.php:124`
- **Problem:** my original M3 named five keys — "at least for `condition`, `if`, `next`,
  `qty` and `times`. Tests: one per key." Two were done. The remaining three behave exactly
  as reported; verified against `24a32d5`:

  ```
  {"op":"has_item","item":"rope","qty":null}   => qty 1      (silent)
  {"op":"visited","scene":"cave","times":null} => times 1    (silent)
  {"op":"give_item","item":"rope","qty":null}  => gives 1    (silent)
  {"key":"s","next":null}                      => next []    (dead end, loud at play time)
  ```

  So a compiler emitting `"qty": null` turns "the vault needs 5 ropes" into "the vault needs
  1 rope" without a word — the same silent-gate-removal M3 was about, in the same place, one
  level down. `next: null` is the mild one: it produces `NoNextSceneException` when a player
  reaches the scene, which is loud but is still a runtime failure where a compile-time one
  was available.
- **Suggested fix:** one `rejectNull()` call per site — `'qty', 'int'`, `'times', 'int'`,
  `'next', 'string or list'` — plus the three or four tests. The primitive already exists; this
  is the five-minute remainder of a finding that was accepted, not a new request.

  The implementer's note (`03-implementation-notes.md`, deviation M3-a) records the scoping
  honestly and I have no complaint about the decision — only about leaving the record
  implying it was extra scope. It was the finding.

### M5 — fixed

`ChoiceUnavailableReason` (`not_found` / `condition_failed`) with a `public readonly $reason`
set by both named constructors. Better than I asked for in two places: the enum's docblock
states that the backing values are the wire form and a rename is a breaking change, and
`EngineExceptionTest::FAMILY_SUPPORT_TYPES` names the enum explicitly rather than loosening
the directory guard to let any non-exception through. `testTheChoiceUnavailableReasonHasExactlyTheTwoDeclaredCases`
pins the order and the values. Both call sites assert the reason, in `Transition` and in
`SceneNode`. No new problem.

### Re-review summary

| Finding | Status |
|---------|--------|
| B1 — float JSON round trip | **fixed** |
| B1-a — residual raw-view gap | narrowed to a PHP-identity difference with no observable misbehaviour; see r1, r2 |
| B1-b — resolved value unvalidated in `compare()` | still open, correctly de-scoped from the blocker; now **r3 (major)** |
| M2 — undeclared variable aborts every turn | **fixed** |
| M3 — explicit null condition | **fixed for `condition` / `if`**; `qty` / `times` / `next` still open as **r4 (major)** |
| M5 — typed `ChoiceUnavailableException` reason | **fixed** |

**No remaining blockers.** M1, M4, M6 and m1–m5 are unchanged and remain open as recorded above.
