# 03 — Implementation notes: engine-core

- **Feature slug:** `engine-core`
- **Plan:** `docs/sdlc/engine-core/02-plan.md`
- **Date:** 2026-10-25

## Progress

| Step | Status | Commit |
|------|--------|--------|
| 1. Wire PCOV line coverage scoped to src/Engine | done | `build: wire PCOV line coverage scoped to src/Engine` (065803d) |
| 2. The exception family | done | `feat(engine): add the engine exception family` (198fddb) |
| 3. The `mixed`-narrowing array reader | done | `feat(engine): add the validated array reader for AST parsing` (2b5705d) |
| 4. VariableType, VariableDefinition, VariableSchema | done | `feat(engine): add VariableType, VariableDefinition and VariableSchema` (0fb54e5) |
| 5. GameState and StateDiff | done — `GameState` in cd9c837, `StateDiff` unblocked and landed in 095b02e | `feat(engine): add the immutable GameState and schema-backed resolution` (cd9c837) · `feat(engine): add the structural state diff` (095b02e) |
| 6. The condition AST | done | `feat(engine): add the condition AST and its 13 operators` (a4fbcd1) |
| 7. The effect AST | done | `feat(engine): add the effect AST and its 8 operators` (82e7904) |
| 8. `ConditionEvaluator` | done | `feat(engine): add the condition evaluator` (94f5faf) |
| 9. `EffectApplier` + `EffectResult` | done | `feat(engine): add the effect applier with goto short-circuit semantics` (7ba4c62) |
| 10. `NextRule` + `NextSceneResolver` | done | `feat(engine): add next-rule resolution with ordered first-match semantics` (13d2cd9) |
| 11. `StoryGraph` / `SceneNode` / `ChoiceNode` + the double | done | `feat(engine): add the StoryGraph contract that Content will implement` (98e07d2) |
| 12. The four events | done | `feat(engine): add the four engine events and their serialized shapes` (b4dda04) |
| 13. `Transition` + `TransitionResult` | done | `feat(engine): add Transition with redirect-budget and visit semantics` (8333176) |
| 14. Determinism and purity guards | done | `test(engine): pin determinism and the purity invariants` (8f1a8db) |
| 15. `Rng` + `SeededRng` | done | `feat(engine): add the seeded RNG forward contract (unused by design)` (6eb4724) · `Engine: drop the literal Secure engine name from the RNG docblock` (fb22e74) |
| 16. `docs/engine/ast.md` | done | `docs(engine): add the AST specification` (5cfa5ca) |

## Step log

### Step 1 — Wire coverage so the 100 % criterion is measurable from the first Engine commit

- **Changed:**
  - `docker/php/php.ini` — added the pinned `pcov.enabled = 1` / `pcov.directory = /app/src`
    block with the comment quoted in the plan.
  - `composer.json` — added the `coverage` script and its description; `qa` left untouched.
  - `phpunit.xml` — added `<coverage ignoreDeprecatedCodeUnits="true"
    disableCodeCoverageIgnore="true"/>` directly after `</testsuites>`; `<source>` unchanged.
  - `Makefile` — added `coverage` to `.PHONY` and the `coverage: guard-running` target.
- **Tests added:** none (plan specifies no production code changes for this step).
- **Checks:** `composer cs-fix` ok (0 files fixed) · `composer phpstan` ok (no errors) ·
  `composer test` 24 passed (unit suite) · `composer deptrac` ok (Violations 0, Allowed 0 —
  unchanged, `src/Engine` still empty) · `composer qa` exit 0.
- **Commit:** `build: wire PCOV line coverage scoped to src/Engine` (`065803d`)
- **Notes / verification evidence:**
  1. `make build && make up` — image rebuilt (php.ini is `COPY`ed, not bind-mounted),
     containers started successfully.
  2. `docker compose exec -T php php --ri pcov`:
     ```
     PCOV support => Enabled
     PCOV version => 1.0.12
     pcov.directory => /app/src
     pcov.exclude => none
     pcov.initial.memory => 65336 bytes
     pcov.initial.files => 64
     ```
     Confirmed as a **configured** value (not auto-detected) via `php --ini`, which shows
     `/usr/local/etc/php/conf.d/99-storyteller.ini` (the file `docker/php/php.ini` is copied
     to) in the list of parsed additional `.ini` files, and `cat` of that file inside the
     container shows the literal `pcov.directory = /app/src` line.
  3. `docker compose exec -T php composer coverage` — exit code 0:
     ```
     PHPUnit 12.5.40 by Sebastian Bergmann and contributors.
     Runtime:       PHP 8.5.12 with PCOV 1.0.12
     ......................                                            22 / 22 (100%)
     OK (22 tests, 2014 assertions)
     Generating code coverage report in Clover XML format ... done [00:00]
     Generating code coverage report in HTML format ... done [00:00.006]
     Code Coverage Report:
       Summary:
       Classes:        (0/0)
       Methods:        (0/0)
       Lines:          (0/0)
     ```
     `--coverage-filter src/Engine` was **not** rejected even though `src/Engine` currently
     holds only `.gitkeep`; PHPUnit reported an empty (0/0) summary rather than erroring, so
     the documented fallback (dropping the filter and re-verifying filtered form at the end
     of step 2) was not needed.
  4. `docker compose exec -T php composer qa` — exit code 0, ~1s wall time inside the
     container (unit suite 24 tests / 2020 assertions, cs-check/phpstan/deptrac all green) —
     unchanged from pre-step-1 behaviour, confirming the `qa` script itself was not touched
     and its runtime did not double.
  - `git check-ignore -v var/coverage/html` → `.gitignore:2:/var/	var/coverage/html` —
    confirms `/var/` on line 2 already ignores `var/coverage`; **no `.gitignore` change
    made**, as specified.
  - Neither `<coverage>` attribute was rejected by PHPUnit 12.5's XSD, so no attribute was
    dropped.

### Step 2 — The exception family

- **Written:**
  - `tests/Unit/Engine/Exception/EngineExceptionTest.php` **first** (ran `composer test`: 23
    errors / 9 failures, all "class not found" or "directory does not exist" — the intended
    reason), then the 9 files under `src/Engine/Exception/`: `EngineException` (abstract,
    extends `\RuntimeException`), `MalformedNodeException`, `UnknownOperatorException`,
    `UnknownVariableException`, `TypeMismatchException`, `UnknownSceneException`,
    `ChoiceUnavailableException`, `NoNextSceneException`, `RedirectLoopException`.
- **Tests added:** 5 test methods, 36 cases after provider expansion — ancestry of all 8
  concrete classes, the abstract root and its parent, the directory-contents guard, the 13
  available named constructors, and the no-framework-ancestor scan.
- **Commit:** `feat(engine): add the engine exception family` (`198fddb`)
- **Checks:** `cs-fix` 0 fixed · `phpstan` 0 errors · `deptrac` 0/0/0/0 · `test` 56 passed
  (2179 assertions) · `coverage` **100.00 % lines (13/13)**, classes 100.00 % (7/7).
- **Deviations:** two, both recorded in the commit message.
  1. **`TypeMismatchException::value()` / `::notNumeric()` were deferred to step 4.** Both are
     typed on `State\VariableType`, which does not exist until step 4, so step 2 cannot
     declare them. Resolved with the same mechanism the plan itself uses for
     `VariableSchema::resolve()` (deferred from step 4 to step 5). The plan's own step-2 test
     catalogue says "all **13** named constructors" while the interface list declares 15,
     which is exactly this split (15 − 2), so the deferral matches the plan's intent rather
     than working around it. End state after step 4 is identical to the plan.
  2. **Test mechanics, not design:** the ancestry assertions read `class_parents()` instead of
     `assertTrue(is_subclass_of(...))`. PHPUnit's assertions carry PHPStan `@phpstan-assert`
     annotations, so the first such call narrows `$class` and makes the second a statically
     proven tautology (`staticMethod.alreadyNarrowedType`), which is a level-8 error. The
     const list is typed `list<class-string>` rather than `list<class-string<EngineException>>`
     for the same reason: the narrower type would let the analyser prove what the test exists
     to check at runtime.
- **Evidence recorded for AC-4 (`deptrac` `Allowed` count) — see *Deviations* below.**

### Step 3 — The `mixed`-narrowing array reader

- **Written:** `tests/Unit/Engine/Support/ArrayReaderTest.php` **first** (34 errors, class not
  found), then `src/Engine/Support/ArrayReader.php` with all 17 methods from the plan's
  interface, unchanged in name, signature and return type.
- **Tests added:** 24 test methods, 34 cases — happy path, missing key and wrong type for
  every reader; float / bool / numeric-string rejection for `requireInt`; zero and negative
  for `requirePositiveInt`; the `optionalPositiveInt` default *and* validation of a present
  value; all four scalars plus `null` and `[]`; `requireNumber`'s string/bool rejection; a map
  passed where a list is required and a scalar element inside a list; the `(string) $k` key
  cast; `rejectUnknownKeys`; and the `a.b.c` path produced by `child()`.
- **Commit:** `feat(engine): add the validated array reader for AST parsing` (`2b5705d`)
- **Checks:** `cs-fix` 0 fixed · `phpstan` 0 errors, **no `phpstan-baseline.neon`, no
  `@phpstan-ignore` anywhere in `src` or `tests`** · `deptrac` 0/0/0/0 · `test` 90 passed
  (2281 assertions) · `coverage` **100.00 % lines (104/104)**, `ArrayReader` 91/91.
- **Deviations:** none. The level-8 narrowing strategy held exactly as planned — `is_*()`
  checks plus element-by-element copies into a fresh array, with no suppression required.
  Two behaviours the plan left implicit are documented in the class: `has()` means
  present-**and-not-null**, matching the `??` narrowing the plan's own sample code uses, and
  `requireArrayList()` / `requireStringList()` enforce `array_is_list()` so an authored map
  cannot pass as a list.

### Step 4 — VariableType, VariableDefinition, VariableSchema

- **Written:** `tests/Unit/Engine/State/VariableTypeTest.php`,
  `VariableDefinitionTest.php`, `VariableSchemaTest.php` **first** (22 errors / 9 failures),
  then `src/Engine/State/VariableType.php`, `VariableDefinition.php`, `VariableSchema.php`.
  `TypeMismatchException`'s two named constructors (deferred from step 2) landed here with
  their provider cases in `EngineExceptionTest`.
- **Tests added:** 19 test methods, 44 cases — the exhaustive 4×4 acceptance grid (`Float`
  does **not** accept an int), `isNumeric()`, the rejected default, the definition round trip,
  unknown type name / unknown key / missing default, key-sorting, duplicate names, unknown
  variables through `typeOf` / `defaultOf` / `assertAccepts`, `empty()` and the schema round
  trip.
- **Commit:** `feat(engine): add VariableType, VariableDefinition and VariableSchema`
  (`0fb54e5`)
- **Checks:** `cs-fix` 0 fixed · `phpstan` 0 errors · `deptrac` 0/0/0/0 · `test` 134 passed
  (2369 assertions) · `coverage` **100.00 % lines (160/160)**.
- **Deviations:** one, additive. **`MalformedNodeException::duplicateKey(string $path, string
  $key)` was added.** The plan fixes the *exception class* for the duplicate-variable-name
  case ("duplicate name => MalformedNodeException") but names no constructor for it, and none
  of the five declared ones fits: `unknownKey()` would produce "key X is not allowed here" for
  a key that is both allowed and present twice. The same factory will serve the duplicate
  choice keys the plan requires in step 11. No serialized format is affected.
  `VariableSchema::resolve()` is absent by the plan's own instruction and lands in step 5.
  Reserved words worked as enum case names (`Int`, `Float`, `String`, `Bool`), so the
  documented `IntType` fallback was not needed.

### Step 5 — GameState (and `VariableSchema::resolve()`); **StateDiff blocked**

- **Written:** `tests/Unit/Engine/State/GameStateTest.php` and three `resolve()` cases
  appended to `VariableSchemaTest` **first** (22 errors / 9 failures), then
  `src/Engine/State/GameState.php` and `VariableSchema::resolve()`.
  **`src/Engine/State/StateDiff.php` was not written — see the blocker below.**
- **Tests added:** 18 test methods, 31 cases — `initial()` empty and marking nothing;
  key-sorting of vars / inventory / visited; flags sorted and de-duplicated; a 50-entry
  unbounded history with duplicates intact; `visited` and `history` agreeing after a mixed
  sequence; immutability of all `with*` methods; zero-removal in `withItem`; `var()` throwing;
  the `v: 1` envelope and key order; an unknown version; each of the six keys dropped in turn;
  an unknown key; three round trips (full, empty, float+bool); the integer-like item key
  through `json_encode`/`json_decode`; and order-independence of the `with*` sequence.
- **Commit:** `feat(engine): add the immutable GameState and schema-backed resolution`
  (`cd9c837`) — the subject deliberately does not say "and its structural diff", because the
  diff is not in it.
- **Checks:** `cs-fix` 0 fixed · `phpstan` 0 errors · `deptrac` 0/0/0/0 · `test` 165 passed
  (2453 assertions) · `coverage` **100.00 % lines (224/224)**.
- **Blocker (plan defect, reported not improvised):** `StateDiff`'s `vars` category is frozen
  as `array<string, array{from: int|float|string|bool, to: int|float|string|bool}>` — `from`
  is a required non-null scalar. A variable that is **absent** from the before-state and
  present in the after-state has no `from`. This is not a corner case: `GameState::initial()`
  starts with no vars at all (plan decision P5 — the default lives in the schema, consulted at
  read time), so the **first `set` of every variable in every story** hits it. `inventory` and
  `visited` have a natural zero via `itemQty()` / `visitCount()`; `vars` has none, and `var()`
  throws for an absent name. Choosing what `from` is there means inventing a shape in a format
  that crosses into Play's event log as the `EffectsApplied` payload — which the plan itself
  calls a plan defect rather than an implementer's call. Three candidate resolutions, for the
  planner or the user to pick:
  1. widen the shape to `from: int|float|string|bool|null`, `null` meaning "the state carried
     no value yet" — information-preserving, but changes the frozen payload;
  2. keep the shape and omit variables absent before — type-preserving, but the first `set` of
     every variable disappears from the event log, which contradicts D-DIFF's "records
     outcomes";
  3. give `StateDiff::between()` the `VariableSchema` so `from` is the schema default —
     consistent with P3 / AC-15 ("never null") and keeps the declared scalar type, but changes
     `between()`'s signature and couples the diff to the schema.
  Nothing in steps 2–7 depends on `StateDiff`: its only consumers are `EffectApplier`
  (step 9) and the `EffectsApplied` event (step 12), both outside this run. `GameState` does
  not reference it. So the blocker costs nothing until step 9.

### Step 6 — The condition AST

- **Written:** `tests/Unit/Engine/Ast/ConditionOpTest.php` and `ConditionTest.php` **first**
  (8 errors / 30 failures), then `src/Engine/Ast/ConditionOp.php`, `Condition.php`,
  `Comparison.php`, `FlagCheck.php`, `HasItem.php`, `Visited.php`, `LogicalAnd.php`,
  `LogicalOr.php`, `LogicalNot.php`.
- **Tests added:** 16 test methods, 72 cases — the 13-value case list and the
  comparison/ordering partition; every documented shape parsed and round-tripped; operand
  accessors; `qty`/`times` defaulting to 1 and rejecting zero, negatives, floats and numeric
  strings; three-level nesting; `not` with a list and `and` with an object; the empty `of`;
  an unknown operator; a missing and a non-string `op`; each required key dropped in turn;
  unknown keys per node type; the nested error path `condition.of[1].of`; and a
  caller-supplied path.
- **Commit:** `feat(engine): add the condition AST and its 13 operators` (`a4fbcd1`)
- **Checks:** `cs-fix` 0 fixed · `phpstan` 0 errors · `deptrac` 0/0/0/0 · `test` 243 passed
  (2682 assertions) · `coverage` **100.00 % lines (297/297)**.
- **Deviations:** two mechanical, neither touching a serialized format.
  1. **`parse()` is `protected static`, not `private static`.** PHP resolves `private` against
     the *declaring* class, so `Condition::fromArray()` — running in the parent's scope —
     cannot call `Comparison::parse()` if it is private. `protected` is the minimum that
     works, and it is declared `abstract protected static` on `Condition` so every node class
     must provide it.
  2. **`parse()` takes the breadcrumb `string $path` as a third argument.** A node with
     children has to extend the path for them (`condition.of[1]`), and `ArrayReader::$path` is
     private. The alternative was adding a public `path()` accessor to `ArrayReader`, whose
     surface is already frozen, committed and at 100 % coverage; passing the path the
     dispatcher already holds is the smaller change. A `final protected static
     parseChildren()` helper on `Condition` carries the `of`-list parsing shared by `and` and
     `or`, including the empty-list rejection (`MalformedNodeException::wrongType(…,
     'non-empty list', 'empty list')` — the plan fixes the exception class but not the
     factory).

### Step 7 — The effect AST

- **Written:** `tests/Unit/Engine/Ast/EffectOpTest.php` and `EffectTest.php` **first**
  (12 errors / 27 failures), then `src/Engine/Ast/EffectOp.php`, `Effect.php`, `SetVar.php`,
  `Arithmetic.php`, `FlagEffect.php`, `ItemEffect.php`, `GoToScene.php`.
- **Tests added:** 16 test methods, 62 cases — the 8-value case list and `isArithmetic()`;
  every documented shape parsed and round-tripped; operand accessors; `add`/`sub` rejecting a
  numeric string and a bool at parse time and accepting a float; `set` accepting all four
  scalars; `qty` defaulting to 1 and rejecting zero, negatives, floats and strings; an unknown
  operator; a missing `op`; each required key dropped in turn; unknown keys per node type; a
  caller-supplied path; `listFromArray` order, the empty list and the reported position
  `on_enter[1]`.
- **Commit:** `feat(engine): add the effect AST and its 8 operators` (`82e7904`)
- **Checks:** `cs-fix` 0 fixed · `phpstan` 0 errors · `deptrac` 0 violations / 0 skipped /
  0 uncovered / 0 warnings / 0 errors · `test` 305 passed (2856 assertions) · `coverage`
  **100.00 % lines (338/338)**.
- **Deviations:** `parse()` is `protected static` for the same visibility reason as step 6,
  and takes no path argument because no effect node has children.
  `testListFromArrayPreservesOrderAndRejectsANonObjectElement` was split: the order half is
  asserted directly, and the non-object-element half is asserted **through
  `ArrayReader::requireArrayList()`**, because `listFromArray()`'s declared parameter is
  `list<array<array-key, mixed>>` and passing a scalar element would be a type violation the
  test suite is itself analysed for at level 8. The rejection the case name asks for is real
  and covered; it simply lives one layer down, in the only call path a caller can use.

### Step 8 — The condition evaluator

- **Written:** `tests/Unit/Engine/ConditionEvaluatorTest.php` **first** (31 errors / 8
  failures, all `Class "Storyteller\Engine\ConditionEvaluator" not found`), then
  `src/Engine/ConditionEvaluator.php`.
- **Tests added:** 11 test methods, 40 cases — all 13 operators once satisfied and once
  unsatisfied against one state; the schema default read end to end; the four ordering
  operators refused on a `string` and a `bool` variable; `eq` on an `int` variable against
  `1.0`; exact float equality (`0.1 + 0.2` is not `eq 0.3`, and *is* `eq 0.1 + 0.2`); an
  unknown variable; `and` / `or` short-circuiting proven by a skipped child that would throw;
  three-level nesting in both polarities; `has_item` / `visited` below, at and above their
  threshold with the default of 1; and the two defensive branches.
- **Commit:** `feat(engine): add the condition evaluator` (`94f5faf`)
- **Checks:** `cs-fix` 1 fixed (the test) · `phpstan` 0 errors · `deptrac` 0/0/0/0/0/0 ·
  `test` 344 passed (2913 assertions) · `coverage` **100.00 % lines (371/371)**.
- **Deviations:** one, structural. **The evaluator has two `default` arms that throw
  `UnknownOperatorException`.** `match (true)` over `instanceof` arms is a PHPStan level-8
  error without one (`match.unhandled`, verified by running it), and the condition hierarchy
  is genuinely open — `Condition` is abstract, not sealed, and `Comparison`'s constructor is
  public and takes any `ConditionOp`. Rather than suppress the error, both branches are real
  behaviour and both are covered by tests that construct such a node directly
  (`new Comparison(ConditionOp::Flag, …)` and a `readonly` anonymous subclass of `Condition`).
  This is what makes CLAUDE.md's "adding an operator means adding it to the evaluator" fail
  loudly instead of evaluating to `false`.

### Step 9 — `StateDiff` (step-5 carry-over), then `EffectApplier` and `EffectResult`

Two commits, because they are two logical changes.

- **Written:** `tests/Unit/Engine/State/StateDiffTest.php` **first** (8 errors), then
  `src/Engine/State/StateDiff.php`; then `tests/Unit/Engine/EffectApplierTest.php` and
  `tests/Unit/Engine/EffectResultTest.php` **first** (17 errors / 9 failures), then
  `src/Engine/EffectApplier.php` and `src/Engine/EffectResult.php`.
- **The step-5 blocker is resolved exactly as the orchestrator decided.**
  `StateDiff::between(GameState $before, GameState $after, VariableSchema $schema)`: a
  variable absent from `$before->vars` resolves its `from` through
  `$schema->resolve($name, $before)`, i.e. the declared default. `from` and `to` stay
  non-nullable `int|float|string|bool` and the frozen payload shape
  `array{from: scalar, to: scalar}` is unchanged. Rationale recorded as given: the
  specification's governing rule for a missing variable — *"Missing var evaluates against the
  schema default, never null"* — already exists, and the diff is a view of variable state, so
  it obeys the rule the evaluator obeys. A nullable `from` would introduce the null the spec
  bans; omitting absent-before variables would hide the first `set` of every variable in every
  story. Both callers (`EffectApplier::apply()` and `Transition::run()`) already hold a
  schema, so nothing was re-threaded and no committed code changed.
- **Tests added:** `StateDiffTest` 8 methods — identical states, `empty()`, vars `from`/`to`,
  the absent-before variable diffing from its default (and a variable *set to* its default
  producing no entry at all), flags split with each half omitted when empty, a removed
  inventory key at `to: 0`, the `visited` category covered directly, and the fixed category
  order. `EffectApplierTest` 15 methods, 27 cases — all 8 operators against a full state;
  wrong-typed `set`; `add`/`sub` on a `string` and a `bool` variable; `int + 0.5`;
  `PHP_INT_MAX + 1`; `remove_item` clamped at 0 with the key dropped; the `qty` default;
  clearing an absent flag; `goto` leaving state untouched, short-circuiting the rest, and the
  two-`goto` list; the empty list; and the diff across one phase. `EffectResultTest` 1 method.
- **Commits:** `feat(engine): add the structural state diff` (`095b02e`) ·
  `feat(engine): add the effect applier with goto short-circuit semantics` (`7ba4c62`)
- **Checks:** `cs-fix` 0 then 1 fixed (the test) · `phpstan` 0 errors · `deptrac` 0/0/0/0/0/0 ·
  `test` 352 then 378 passed (2934 / 2995 assertions) · `coverage` **100.00 % lines (423/423
  then 459/459)**.
- **Deviations:** two, both small.
  1. **`StateDiff::unionOfKeys()` casts keys back to `string`.** PHP stores a
     decimal-integer-like array key as an `int`, so an item named `"10"` would otherwise reach
     `itemQty(string $item)` as an `int` and fail under `strict_types`. The same coercion is
     already documented for `GameState` and pinned by `GameStateTest`.
  2. **`EffectApplier::arithmetic()` also rejects a non-numeric *value* for a numerically
     declared variable.** `GameState` carries no schema and validates nothing, so a state
     built elsewhere can disagree with the declaration; refusing it is both the correct
     behaviour and what narrows the type for level 8 without a suppression. Covered by its own
     test. The applier carries the same open-hierarchy `default` arm as the evaluator, for the
     same reason and with the same kind of test.

### Step 10 — `NextRule` and `NextSceneResolver`

- **Written:** `tests/Unit/Engine/Graph/NextRuleTest.php` and
  `tests/Unit/Engine/NextSceneResolverTest.php` **first** (7 errors / 6 failures), then
  `src/Engine/Graph/NextRule.php` and `src/Engine/NextSceneResolver.php`.
- **Tests added:** 13 test methods — `isFallback()`, the round trip with and without `if`
  (`if` omitted when null), list order, the empty list, a missing `scene`, an unknown key, the
  nested error path `next[1].if`, a caller-supplied path, direct construction; and for the
  resolver: a plain string resolving to itself, first-match-wins with a later rule that would
  also match, a fallback used only when nothing earlier matched, exhaustion, and the empty
  list.
- **Commit:** `feat(engine): add next-rule resolution with ordered first-match semantics`
  (`13d2cd9`)
- **Checks:** `cs-fix` 0 fixed · `phpstan` 0 errors · `deptrac` 0/0/0/0/0/0 · `test` 391
  passed (3033 assertions) · `coverage` **100.00 % lines (483/483)**.
- **Deviations:** none. The resolver tests `$rule->if === null` rather than calling
  `isFallback()`, because the explicit null check is what narrows `?Condition` for level 8;
  `isFallback()` is part of the public contract and is covered by `NextRuleTest`.

### Step 11 — The `StoryGraph` contract, its nodes, and the in-memory double

- **Written:** `tests/Unit/Engine/Graph/ChoiceNodeTest.php`,
  `tests/Unit/Engine/Graph/SceneNodeTest.php` and a new case in `ArrayReaderTest` **first**
  (11 errors / 4 failures), then `src/Engine/Graph/ChoiceNode.php`, `SceneNode.php`,
  `StoryGraph.php`, `ArrayReader::isString()`, and
  `tests/Unit/Engine/Double/InMemoryStoryGraph.php`.
- **Tests added:** 13 test methods — the documented full scene parsed and round-tripped;
  optional keys defaulting to empty; `next` as both a string and a rule list; `hasChoice()` /
  `choice()` and the absent key; duplicate choice keys; missing and unknown keys; the nested
  error paths `scene.on_enter[0]`, `scene.choices[1]`, `scene.next[0]`,
  `choice.effects[1]`; and `isString()` against a string, a list, a null and an absent key.
- **Commit:** `feat(engine): add the StoryGraph contract that Content will implement`
  (`98e07d2`)
- **Checks:** `cs-fix` 0 fixed · `phpstan` 0 errors · `deptrac` 0/0/0/0/0/0 · `test` 406
  passed (3097 assertions) · `coverage` **100.00 % lines (541/541)**. AC-10:
  `grep -rl 'implements StoryGraph' src` returns nothing; the only implementation is the
  double under `tests/`.
- **Deviations:** one, additive. **`ArrayReader::isString(string $key): bool` was added.**
  `next` is the format's one authored union (a scene name or a rule list), and its reader has
  to know which it is holding before reading it. The alternatives were to let `SceneNode`
  touch the raw decoded array — which breaks the plan's own rule that `ArrayReader` is the
  single narrowing point — or to catch a `MalformedNodeException` and retry, which uses an
  exception for control flow. One four-line method with its own test case is the smaller
  change. No existing signature changed.

### Step 12 — The four engine events

- **Written:** `tests/Unit/Engine/Event/EngineEventsTest.php` **first** (5 errors), then
  `src/Engine/Event/EngineEvent.php`, `EffectPhase.php`, `ChoiceMade.php`,
  `EffectsApplied.php`, `SceneEntered.php`, `EndingReached.php`.
- **Tests added:** 5 test methods, 8 cases — the `EffectPhase` case list; every event's
  `name()` and full serialized shape, with `toArray()['event'] === name()`; `EffectsApplied`
  embedding a real diff and its phase; a reflection scan proving no event carries a clock; and
  a case that constructs all four events *inside* the test.
- **Commit:** `feat(engine): add the four engine events and their serialized shapes`
  (`b4dda04`)
- **Checks:** `cs-fix` 0 fixed · `phpstan` 0 errors · `deptrac` 0/0/0/0/0/0 · `test` 414
  passed (3162 assertions) · `coverage` **100.00 % lines (573/573)**.
- **Notes:** the constructor-coverage point is worth recording — **PHPUnit data providers run
  outside the covered test's measurement window**, so events built only in a provider showed
  their constructors as uncovered (99.48 %). The fix is a test that constructs each event in
  its own body, not a coverage annotation. `#[CoversClass]` is declared for the five concrete
  classes; the `EngineEvent` interface has no executable lines and is deliberately not listed.

### Step 13 — `Transition` and `TransitionResult`

- **Written:** `tests/Unit/Engine/TransitionTest.php` and
  `tests/Unit/Engine/TransitionResultTest.php` **first** (13 errors / 6 failures), then
  `src/Engine/Transition.php` and `src/Engine/TransitionResult.php`. `run()` follows the
  plan's 13-step algorithm literally.
- **Tests added:** 16 test methods — the happy path's state and resting scene; the exact
  five-event list for an ending turn; `EffectsApplied` emitted twice / once / not at all; a
  phase whose effects change nothing emitting nothing; a false choice condition; an absent
  choice key; an unknown current scene; a dangling `next`, a dangling choice `goto` and a
  dangling `on_enter` `goto`; a choice `goto` overriding the declared `next`; a two-hop
  `on_enter` redirect; exactly five redirects succeeding with `SceneEntered.redirects === 5`;
  six throwing; the choice `goto` counting toward the budget (1 + 5 throws); the
  redirected-through scene unvisited but its effects logged; the visit recorded after
  `on_enter`; `isEnding` in both directions; and `NoNextSceneException` propagating.
  `TransitionResultTest` pins the nested wire shape and the empty event list.
- **Commit:** `feat(engine): add Transition with redirect-budget and visit semantics`
  (`8333176`)
- **Checks:** `cs-fix` 0 fixed · `phpstan` 0 errors · `deptrac` 0/0/0/0/0/0 · `test` 433
  passed (3227 assertions) · `coverage` **100.00 % lines (622/622)**.
- **Deptrac enforcement probe (run, reverted, not committed).** `use
  Symfony\Component\Uid\Uuid;` plus a `Uuid::v4()` call was added to `src/Engine/Transition.php`.
  `composer deptrac` then reported `DependsOnDisallowedLayer  Storyteller\Engine\Transition
  must not depend on Symfony\Component\Uid\Uuid` with `Violations 1`, **and** `composer test`
  failed in
  `BoundedContextLayoutTest::testFrameworkFreeContextsImportNoFrameworkNamespace@src/Engine`.
  The file was restored from a copy taken before the edit (no `git checkout`, no reset), and
  both gates were re-verified green. The gate bites.
- **Deviations:** one, and it is a plan defect rather than a choice. **The planned case
  `testVisitedIsFalseInsideTheSceneOwnOnEnterOnFirstEntryAndTrueOnReEntry` is not
  expressible.** Effects carry no conditions — there is no conditional effect operator — so
  nothing executed *inside* `on_enter` can read `visited` at all, and the difference between
  incrementing before and after `on_enter` is invisible from within that phase. It is pinned
  instead by its two observable consequences, which together are equivalent:
  (i) `testASceneRedirectedThroughIsNotMarkedVisitedButItsEffectsStillApply` — under
  "increment before `on_enter`" the redirected-through scene *would* appear in `visited` and
  `history`, so this case discriminates the two designs; and (ii)
  `testASceneIsNotVisitedUntilAfterItsOwnOnEnter`, which asserts the on_enter
  `EffectsApplied` precedes `SceneEntered` and then, across two consecutive runs, that a
  choice guarded on the scene's own visit count becomes available exactly as far as entries
  have actually happened. **Note for the AST spec (step 16):** D-VISIT's documentation line on
  the `visited` operator should describe the re-entry rule in terms of *choice conditions and
  `next` guards*, since `on_enter` cannot branch.

### Step 14 — Determinism and purity guards

- **Written:** `tests/Unit/Engine/DeterminismTest.php` and
  `tests/Unit/Engine/EnginePurityTest.php`. **No production file** — this step adds no
  behaviour, it makes two prose rules executable.
- **Tests added (9 methods).** `DeterminismTest` (4): identical inputs through two
  **independently built** object graphs (two `InMemoryStoryGraph`s, two schemas, two
  evaluators, two appliers, two resolvers) and two independently built `GameState`s, asserted
  at four strengths — `assertNotSame`, `assertEquals`, `assertSame` on `toArray()` and
  `json_encode` byte equality — with the six-event name sequence and **all three
  `EffectsApplied` diff payloads pinned literally**, so the case cannot pass vacuously; the
  commutative-effects case, where the same multiset applied in two orders converges on one
  canonical state and the sorted, de-duplicated flag list is asserted as a *rule*
  (`['brave', 'wise']`) rather than as agreement; input-state immutability; and condition-order
  independence. `EnginePurityTest` (5): the source scan, the pattern self-test, the static-property
  reflection, the final-class reflection, and the source → test-file map.
- **Commit:** `test(engine): pin determinism and the purity invariants` (`8f1a8db`)
- **Checks:** `cs-fix` 0 of 81 fixed · `phpstan` `[OK] No errors` · `deptrac` 0/0/0/0/0/0 ·
  `test` 442 passed (5411 assertions) · `coverage` **100.00 % lines (622/622)**, 43/43
  classes, 180/180 methods.
- **Falsification probes — all three run and reverted, none committed.** A guard that cannot
  fail is worth nothing, so each was made to fail on purpose:
  1. deleting `sort($flags, SORT_STRING)` from `GameState::canonicalFlags()` made
     `testDifferentEffectOrderingsReachAnIdenticalState` fail (expected `['wise','brave']`,
     got `['brave','wise']`);
  2. injecting `mt_rand(1, 2) + $_SERVER['x']` into `NextSceneResolver` made
     `testNoEngineSourceUsesANonDeterministicFunction` fail on the
     `/(?<![\w$>:])mt_rand\s*\(/` pattern;
  3. adding `public static int $probe` made `testNoEngineClassDeclaresAStaticProperty` fail.
     The probe had to be placed on `EngineException` (the abstract root): the same edit on a
     `final readonly` class is a **PHP compile-time fatal**, which is its own, stronger
     enforcement but is not a test failure. Each file was restored from a copy taken before the
     edit — no `git checkout`, no reset — and `git status --porcelain src/` re-verified clean.
- **Scope added beyond the plan's three cases**, at the orchestrator's explicit instruction and
  additive only: `testTheInputStateIsNotMutatedByARun` (the caller's object is byte-identical
  after a run, the run is shown to have produced a different object with a different value, and
  the same instance handed to a second engine yields the same result) and
  `testEvaluatingAConditionDoesNotDependOnEarlierEvaluations` (the same six conditions walked
  forwards, backwards, forwards again, and through a freshly built evaluator, all agreeing, with
  the expected `[true, false, true, false, true, false]` written out so the case cannot pass on
  an empty list). The plan's third determinism case lives in `GameStateTest` and was landed in
  step 5, as planned.
- **Notes.** `DeterminismTest` carries `#[CoversClass]` for `Transition`, `TransitionResult`,
  `ConditionEvaluator` and `GameState` — the four classes whose determinism it pins (P11).
  `EnginePurityTest` carries `#[CoversNothing]`, and is the second of the plan's two scanning
  guards after `BoundedContextLayoutTest`. The impurity regex uses the lookbehind
  `(?<![\w$>:])` so that `strtotime(`, `$this->date(`, `Formatter::date(`, `$this->update(`
  and `$clock?->time()` are **not** matched while a fully-qualified `\time()` **is** — a
  leading backslash is deliberately not excluded, because that is the call being forbidden.
  The forbidden-function list is a superset of the plan's eight: `srand`, `mt_srand`,
  `random_bytes`, `array_rand`, `str_shuffle`, `hrtime` and `getdate` were added because they
  are the same class of defect and cost one array entry each.

### Step 15 — The `Rng` forward contract and `SeededRng`

- **Written:** `tests/Unit/Engine/Random/SeededRngTest.php` **first** (9 cases, 8 errors —
  `Class "Storyteller\Engine\Random\SeededRng" not found`), then `src/Engine/Random/Rng.php`
  and `src/Engine/Random/SeededRng.php`.
- **Tests added:** the plan's nine cases. Same `(seed, turn, index)` always agrees — including
  when other draws are interleaved between the two, which is what "index-addressed, not
  sequential" actually means; two instances agree on a whole 16-draw sequence of both
  `intBetween` and `fraction`; a different turn, a different index and a different seed each
  produce a different sequence; 200 draws stay inside `[3, 9]` **and** explore more than one
  value, with `intBetween(t, i, 4, 4) === 4` asserted at every one of them; 200 fractions stay
  in `[0.0, 1.0)`; `fromInt()` is deterministic and seed-sensitive; and nothing in `src/Engine`
  references the RNG.
- **Commits:** `feat(engine): add the seeded RNG forward contract (unused by design)`
  (`6eb4724`), then `Engine: drop the literal Secure engine name from the RNG docblock`
  (`fb22e74`) — see *Deviations* 13.
- **Checks:** `cs-check` `Found 0 of 84 files that can be fixed`, exit 0 · `phpstan`
  `[OK] No errors` · `deptrac` Violations 0 / Skipped 0 / Uncovered 0 / Allowed 0 / Warnings 0
  / Errors 0 (`Random\*` produces no entry at all, as the plan predicted) · `test` 451 passed
  (7566 assertions) · `coverage` **100.00 % lines (630/630)**, 100.00 % classes (44/44),
  100.00 % methods (185/185). **This is where AC-21 is claimed.**
- **Notes.** `SeededRng` is `final readonly` with a single `string $seed` property. Each draw
  derives its own 32-byte engine seed from `hash('sha256', $seed . '|' . $turn . '|' . $index,
  true)` and runs a **fresh** `Random\Engine\Xoshiro256StarStar` over it, exactly as the plan
  binds. There is therefore no shared engine to advance and no call ordering that can change an
  answer. `intBetween()` does not guard `$min <= $max` (the Engine trusts its inputs);
  `fraction()` uses `Randomizer::getFloat(0.0, 1.0, IntervalBoundary::ClosedOpen)` for the
  half-open interval. **It is wired into nothing**: no AST node, evaluator, applier, resolver
  or `Transition` signature changed, and `testNothingInSrcEngineReferencesRng` asserts that
  twice — a source scan over all 48 Engine files outside `Random/` plus a reflection pass over
  every Engine method's parameter and return types.
- **No golden numbers**, per the plan's "property, not a golden number". The consequence is
  worth stating: the suite proves `SeededRng` is a pure function of `(seed, turn, index)`
  *within a process*, and cross-process / cross-version reproducibility rests on the
  construction plus `EnginePurityTest`, which forbids every source of process-local entropy
  under `src/Engine`. A pinned golden value would be the only thing that would also catch a
  future PHP changing `Xoshiro256StarStar`'s output. Flagged for the reviewer rather than added.

### Step 16 — `docs/engine/ast.md`

- **Written:** `docs/engine/ast.md` only. No file under `src/` or `tests/` touched; no
  dependency added. Verified every JSON example against the committed parser by running a
  small throwaway script (not committed) inside the `php` container that round-trips all 13
  condition shapes, all 8 effect shapes, the scene/choice/`next` example, the `GameState`
  example, the `VariableSchema` example and a `StateDiff::between()` call, asserting each
  `toArray()` equals the literal JSON the document shows — including the `StateDiff` example,
  which is not a mechanical round trip but a real `between()` computation. All passed
  (`ALL OK`).
- **Content:** conventions (snake_case, unknown-key rejection, parse-time vs evaluation-time
  validation, the schema-default rule, "the Engine trusts its inputs"); all 13 condition
  operators and all 8 effect operators with shape, required/optional keys, defaults and a
  verified example each; a cross-cutting rules section restating the missing-variable,
  `remove_item`-floor and `goto`-is-state-inert rules; a dedicated `visited` section that
  documents D-VISIT's after-`on_enter` increment **in terms of choice conditions and `next`
  guards, not `on_enter`**, explicitly stating that branching inside `on_enter` on its own
  scene's visit count is not expressible (per the step-13 deviation) and walking the verified
  three-turn example from `TransitionTest::testASceneIsNotVisitedUntilAfterItsOwnOnEnter`; the
  redirect budget section stating the choice-effect `goto` spends the first of five, the guard
  fires after every increment, and there is no cycle detection; the `GameState` envelope, the
  `VariableSchema` shape, the `StateDiff` shape and its D-DIFF omission rules; the event order
  and `TransitionResult` shape; an error-behaviour table naming every exception class under
  `src/Engine/Exception/` and when each fires, built directly from those nine source files
  rather than from the plan's prose; and a closing note that `Rng`/`SeededRng` exist as an
  unused forward contract, per the plan's instruction to say so.
- **Checks:** `composer qa` inside the `php` container — exit 0 (cs-fix 0/84 fixable,
  phpstan `[OK] No errors`, deptrac 0/0/0/0/0/0, test 451 passed / 7566 assertions). No test
  count changed, since this step adds no test.
- **Commit:** `docs(engine): add the AST specification`
- **Deviations:** none. Every shape transcribed was already frozen in the plan's *Public
  interfaces* and *JSON AST node shapes* sections and matches the committed code read directly
  from `src/Engine/Ast/`, `src/Engine/State/`, `src/Engine/Exception/`, `src/Engine/Event/`,
  `src/Engine/Transition.php`, `src/Engine/ConditionEvaluator.php` and
  `src/Engine/EffectApplier.php`. The one place the plan's own prose and the code disagree —
  the planned `visited`-inside-`on_enter` test case being inexpressible (step-13 deviation 10)
  — is documented as the code's actual behaviour, per this step's explicit instruction, rather
  than by repeating the plan's original framing.

## Gate C fix loop 1 (review `04-review.md`)

Scope as given: **B1, M2, M3 and M5 only.** M1 (no first-scene entry point), M4
(evaluation-time error context) and M6 (golden-value RNG test) were explicitly out of scope —
M6 is the tester's, M1 and M4 are open items for the user — and are untouched. No minor was
fixed. `Transition`'s public signature is unchanged. One commit per finding, TDD throughout:
the failing test and its exact failure message are recorded below before each fix.

| Finding | Status | Commit |
|---------|--------|--------|
| B1 — `float` does not survive JSON | done | `fix(engine): B1 make float variables survive a JSON round trip` (9671a19) |
| M2 — an undeclared variable aborts every turn | done | `fix(engine): M2 stop an undeclared variable from aborting every turn` (b028b2b) |
| M3 — `"condition": null` becomes an unguarded choice | done | `fix(engine): M3 reject an explicit null condition instead of dropping it` (633c1e3) |
| M5 — two failures share one exception type | done | `fix(engine): M5 give ChoiceUnavailableException a typed reason` (e4ca1b9) |

### B1 — `float` variables now survive a JSON round trip

- **Failing tests first.** Five new cases, all red before the change. The messages, verbatim:
  - `VariableSchemaTest::testASchemaWhoseDefaultIsAnIntegralFloatSurvivesAJsonRoundTrip` →
    `TypeMismatchException: Variable "ratio" is declared as float, but the value is of type int.`
    (from `VariableDefinition.php:26`, via `VariableSchema::fromArray()`)
  - `EffectApplierTest::testASetNodeWithAnIntegralFloatValueSurvivesAJsonRoundTrip` → the same
    message, from `VariableSchema.php:127` via `EffectApplier::set()`.
  - `ConditionEvaluatorTest::testAnEqOnAnIntegralFloatHoldsIdenticallyBeforeASaveAndAfterAReload`
    → the same message, from `ConditionEvaluator.php:68`.
  - `GameStateTest::testAFullStateWithAnIntegralFloatSurvivesAJsonEncodeDecodeCycle` →
    `Failed asserting that 1 is identical to 1.0.`
  - `VariableTypeTest::testAcceptsMatchesTheDeclaredType@float accepts int` →
    `Failed asserting that false is identical to true.`
- **Changed.** `VariableType::accepts()` — `Float` now accepts an `int`; `Int` still refuses a
  `float`, which is the whole point of the asymmetry (widening is lossless, narrowing is not,
  and PHP draws the same line under `strict_types=1`). New `VariableType::canonicalise()` is
  the single normalisation function: an `int` under a `Float` declaration becomes a real
  `float`, nothing else is ever touched, nothing is ever narrowed or coerced.
  It is reached from every schema-aware boundary, so no operator can see the two forms as
  different: `VariableDefinition::__construct()` (schema defaults — `$default` is no longer a
  promoted property, because a readonly promoted property cannot be reassigned in the
  constructor body), `VariableSchema::resolve()` (the one place a variable is read),
  the new `VariableSchema::canonicalise()`, `ConditionEvaluator::compare()` (both sides of the
  `===`, never just one) and `EffectApplier::set()` / `arithmetic()`.
- **Files:** `src/Engine/State/VariableType.php`, `VariableDefinition.php`, `VariableSchema.php`,
  `GameState.php` (docblock only), `src/Engine/ConditionEvaluator.php`,
  `src/Engine/EffectApplier.php`, `docs/engine/ast.md`; tests in
  `tests/Unit/Engine/State/VariableTypeTest.php`, `VariableSchemaTest.php`, `GameStateTest.php`,
  `tests/Unit/Engine/ConditionEvaluatorTest.php`, `tests/Unit/Engine/EffectApplierTest.php`.
- **Tests added:** the four the orchestrator named (schema default `1.0`; a
  `{"op":"set","value":1.0}` node; an `eq` identical before a save and after a reload; a full
  `GameState` → `json_encode` → `json_decode` → `GameState` cycle), plus an eight-case
  `canonicalise()` grid that pins the asymmetry in both directions and two
  `VariableSchema::resolve()` / `canonicalise()` cases. The existing acceptance grid was
  updated, not deleted: it still asserts every one of the sixteen type/value pairs.
- **Checks:** cs-fix 0/84 · phpstan `[OK] No errors` · deptrac 0 violations · test
  466 passed / 7600 assertions · coverage `Lines 100.00% (638/638)`.
- **Deviation (B1-a), deliberate and reported rather than worked around.** The instruction was
  to normalise "at a single ingestion point" and to route `GameState::withVar()` and the
  array/`create()` constructors through it. **`GameState` cannot canonicalise**, because it
  carries no schema — that is a documented structural invariant of the class ("the state
  carries no schema and therefore validates no types"), and `GameState::fromArray()`, the
  method that actually reads the JSONB column, has no way to learn a variable's declared type.
  Giving it one would change a public signature and re-open a design decision that is not
  mine to take. What I did instead holds the property the instruction was protecting: there is
  exactly **one** normalisation function (`VariableType::canonicalise()`), the applier is the
  Engine's only writer of `vars` and always writes canonical values, and
  `VariableSchema::resolve()` canonicalises on the way out — so the raw `int` a reloaded state
  may carry is invisible to every operator, and `StateDiff` (which reads through `resolve()`)
  and `DeterminismTest` stay consistent. `GameState::var()` remains the raw view and is
  documented as such. The residual gap: `$state->var('ratio')` on a state loaded straight from
  JSONB returns `int 1` rather than `1.0`. Nothing in the Engine reads it that way.
- **Deviation (B1-b).** The review's B1 also suggested validating the *resolved* value against
  the declared type inside `ConditionEvaluator::compare()`, as `EffectApplier::arithmetic()`
  already does. **Not done** — the orchestrator's scope for B1 is the float round trip, and
  that change alters what a type-disagreeing state does (silently `false` today, throw
  tomorrow) for reasons unrelated to JSON. It is a real inconsistency and should be carried
  forward as its own finding rather than smuggled in here.
- **Two `assertIsArray()` calls omitted** at the two `json_decode` sites where the encoded
  input is a literal: PHPStan level 8 proves the decoded shape there and reports
  `staticMethod.alreadyNarrowedType`, i.e. an assertion that could never run. A comment at
  each site says so. The other two round-trip tests keep their `assertIsArray()`, because the
  analyser cannot narrow those. No suppression, no config change.

### M2 — an undeclared variable no longer aborts every turn

- **Failing tests first.** `EffectApplierTest::testAStateCarryingAnUndeclaredVariableStillTakesAnEmptyTurn`
  and `StateDiffTest::testAnUndeclaredVariableAloneLeavesTheDiffEmpty`, both red with
  `UnknownVariableException: Unknown variable "legacy_mana".` raised from `StateDiff.php:45`
  — in the applier's case through `EffectApplier.php:61`, i.e. on `apply([], $state)` with no
  effects at all, exactly as the review reproduced it.
- **Changed.** `StateDiff::between()` skips a variable name the schema does not declare.
  A diff is a read-only observation of state, not a resolution of a name an author wrote;
  applying P3's strict rule to a passive enumeration turned "the author asked about an unknown
  variable" into "this session can never take another turn". `isEmpty()` and the event payload
  are unaffected, since an undeclared name has no declared default to diff against.
- **Files:** `src/Engine/State/StateDiff.php`, `docs/engine/ast.md`; tests in
  `tests/Unit/Engine/State/StateDiffTest.php` and `tests/Unit/Engine/EffectApplierTest.php`.
- **Tests added:** three — the empty turn over a state carrying `legacy_mana`; a diff that
  reports `hp` and silently skips `legacy_mana` in the same call; and
  `testAnEffectThatNamesAnUndeclaredVariableStillThrows`, which pins that the strict rule is
  *narrowed*, not removed.
- **Checks:** cs-fix 0/84 · phpstan `[OK] No errors` · deptrac 0 violations · test
  470 passed / 7608 assertions · coverage `Lines 100.00% (640/640)`.
- **Deviations:** none.

### M3 — an explicit `null` condition is rejected instead of silently dropped

- **Failing tests first.** `ChoiceNodeTest::testAnOmittedConditionIsAlwaysAvailableButAnExplicitNullIsRejected`
  and `NextRuleTest::testAnOmittedIfIsAFallbackButAnExplicitNullIsRejected`, both
  `Failed asserting that exception of type "Storyteller\Engine\Exception\MalformedNodeException" is thrown.`
  — i.e. the node parsed happily, which is the bug. The third,
  `ArrayReaderTest::testRejectNullFiresOnlyForAKeyThatIsPresentAndNull`, was
  `Error: Call to undefined method Storyteller\Engine\Support\ArrayReader::rejectNull()`.
- **Changed.** New `ArrayReader::rejectNull(string $key, string $expected)` —
  `array_key_exists() && === null`, raising
  `MalformedNodeException::wrongType($path, $key, 'object', 'null')`. `has()` is left alone:
  it is correct for a `??` narrowing and is used that way everywhere else. `ChoiceNode` calls
  it for `condition` and `NextRule` for `if`, each right after `rejectUnknownKeys()`.
- **Files:** `src/Engine/Support/ArrayReader.php`, `src/Engine/Graph/ChoiceNode.php`,
  `src/Engine/Graph/NextRule.php`, `docs/engine/ast.md`; tests in
  `tests/Unit/Engine/Support/ArrayReaderTest.php`, `tests/Unit/Engine/Graph/ChoiceNodeTest.php`,
  `tests/Unit/Engine/Graph/NextRuleTest.php`.
- **Tests added:** three, each asserting **both** readings in one test — the omitted key still
  means "no guard" / "fallback", the explicit null is rejected with the exact message
  (`choice: key "condition" must be of type object, got null.` and
  `next[0]: key "if" must be of type object, got null.`). The reader test also asserts that an
  absent key and a non-null value both stay silent.
- **Checks:** cs-fix 0/84 · phpstan `[OK] No errors` · deptrac 0 violations · test
  473 passed / 7617 assertions · coverage `Lines 100.00% (644/644)`.
- **Deviation (M3-a) — recorded wrongly here; corrected in the r4 entry below.** This entry
  originally described `next`, `qty` and `times` as an *additional* suggestion beyond the
  finding. That was wrong. M3 as reported named five keys — "`condition`, `if`, `next`, `qty`
  and `times`. Tests: one per key." — so all five were in scope and three were missed. The
  deviation was **partial delivery of M3**, not a declined extra. Closed by r4 below.

### M5 — the two `ChoiceUnavailableException` cases are distinguishable by type

- **Failing tests first.** Five cases red with
  `Error: Class "Storyteller\Engine\Exception\ChoiceUnavailableReason" not found`, plus
  `TransitionTest::testTheTwoUnavailableReasonsAreNotTheSame` →
  `Failed asserting that null is not identical to null.` with
  `Warning: Undefined property: ChoiceUnavailableException::$reason` — the clearest possible
  statement that the two failures were indistinguishable.
- **Changed.** New `ChoiceUnavailableReason` enum (`not_found` / `condition_failed`) and a
  `public readonly ChoiceUnavailableReason $reason` on the exception, set by the two named
  constructors through a now-private constructor. Backing values are documented as the wire
  form, because Api will map them onto 404/422 vs 409.
- **Files:** `src/Engine/Exception/ChoiceUnavailableReason.php` (new),
  `src/Engine/Exception/ChoiceUnavailableException.php`, `src/Engine/Transition.php`
  (`@throws` line only), `docs/engine/ast.md`; tests in
  `tests/Unit/Engine/Exception/EngineExceptionTest.php`, `tests/Unit/Engine/TransitionTest.php`,
  `tests/Unit/Engine/Graph/SceneNodeTest.php`, `tests/Unit/Engine/EnginePurityTest.php`.
- **Tests added / changed:** the two `TransitionTest` cases and the `SceneNodeTest` case were
  converted from `expectException` to catch-and-assert, so each now asserts its `reason` as
  well as its message — the review's point was precisely that no test discriminated them.
  Added: a third `Transition` case asserting the two reasons differ for two keys of the same
  scene, a per-constructor reason assertion, and a case-list test pinning both backing values.
- **Two test-guard updates, both tightened rather than loosened.**
  `EngineExceptionTest::testTheExceptionDirectoryHoldsExactlyTheDeclaredFamily` would have
  failed on the new file, so a `FAMILY_SUPPORT_TYPES` constant now names the enum explicitly —
  the guard still fails on any *unlisted* file. `EnginePurityTest::SHARED_COVERAGE` gained the
  matching entry; its own staleness self-test keeps that honest.
- **Checks:** cs-fix 0/85 · phpstan `[OK] No errors` · deptrac 0 violations · test
  476 passed / 7675 assertions · coverage `Lines 100.00% (651/651)`.
- **Deviations:** none.

## Gate C fix loop 2 (re-review `04-review.md` → `## Re-review — Gate C fix loop 1`)

Scope as given: **r4 only** — the unfixed remainder of M3. r3 / B1-b
(`VariableSchema::assertState()` at the load boundary) goes to the user as an open item for
`Play`; r1, r2, M1, M4 and M6 are untouched. `Transition`'s public signature is unchanged.

| Finding | Status | Commit |
|---------|--------|--------|
| r4 (= M3-a) — `qty`, `times`, `next` read an explicit null as a default | done | `fix(engine): r4 reject an explicit null qty, times and next` (this commit) |

### r4 — the remaining three keys of M3 reject an explicit `null`

- **Framing.** This is **completing M3, not new scope.** M3 as reported named five keys;
  `633c1e3` closed two. The loop-1 note recording the other three as an additional
  suggestion was wrong and has been corrected in place under *M3 → Deviation (M3-a)*.
- **Failing tests first.** Four new cases, all red with the identical message — which is
  itself the finding, since a silent default throws nothing at all:

  ```
  ConditionTest::testAnOmittedQtyDefaultsToOneButAnExplicitNullIsRejected
  ConditionTest::testAnOmittedTimesDefaultsToOneButAnExplicitNullIsRejected
  EffectTest::testAnOmittedQtyDefaultsToOneButAnExplicitNullIsRejected
  SceneNodeTest::testAnOmittedNextIsADeadEndButAnExplicitNullIsRejected
    => Failed asserting that exception of type
       "Storyteller\Engine\Exception\MalformedNodeException" is thrown.
  ```

- **Changed.** One `ArrayReader::rejectNull()` call per site, no new primitive:
  `HasItem::parse()` `('qty', 'int')` and `Visited::parse()` `('times', 'int')` and
  `ItemEffect::parse()` `('qty', 'int')`, each immediately after `rejectUnknownKeys()`;
  `SceneNode::parseNext()` `('next', 'string or list')` **before** the `has('next')` early
  return, since `has()` is `isset()` and cannot tell absent from null — that ordering is the
  whole fix at that site.
- **Files:** `src/Engine/Ast/HasItem.php`, `src/Engine/Ast/Visited.php`,
  `src/Engine/Ast/ItemEffect.php`, `src/Engine/Graph/SceneNode.php`, `docs/engine/ast.md`;
  tests in `tests/Unit/Engine/Ast/ConditionTest.php`, `tests/Unit/Engine/Ast/EffectTest.php`,
  `tests/Unit/Engine/Graph/SceneNodeTest.php`.
- **Tests added:** four, each asserting **both** readings in one test, as the `condition` and
  `if` tests do — the omitted key still yields the default (`qty` 1, `times` 1, `next` `[]` for
  a terminal scene), the explicit null is rejected with the exact message
  (`condition: key "qty" must be of type int, got null.`,
  `condition: key "times" must be of type int, got null.`,
  `effect: key "qty" must be of type int, got null.`,
  `scene: key "next" must be of type string or list, got null.`). The pre-existing
  `testQtyAndTimesDefaultToOne` and `testOptionalKeysDefaultToEmpty` remain green, which is the
  independent evidence that the omitted-key path was not disturbed.
- **Docs.** `docs/engine/ast.md`'s explicit-null bullet covered only `condition` and `if`; it
  now carries a six-row table of every optional key, what *omitting* it means and the type it
  reports on null, plus why `qty`/`times` are the damaging ones and `next` the mild one.
- **Checks:** cs-fix 0/85 · phpstan `[OK] No errors` · deptrac 0 violations · test
  480 passed / 7690 assertions · coverage `Lines 100.00% (655/655)`.
- **Deviations:** none. Nothing outside r4 was touched; `rejectNull()`, `has()` and
  `optionalPositiveInt()` are unchanged.

## Deviations from the plan

1. **Step 1 — none.** (Unchanged from the original entry: both edge cases the plan anticipated
   did not occur.)
2. **`TypeMismatchException`'s two named constructors moved from step 2 to step 4** — they are
   typed on `VariableType`, which step 4 creates. Same mechanism the plan applies to
   `VariableSchema::resolve()`; matches the plan's own "13 named constructors" count for the
   step-2 provider.
3. **`MalformedNodeException::duplicateKey()` added** (step 4) — the plan fixes the exception
   class for the duplicate-name case but no factory, and none of the five declared ones means
   "declared twice".
4. **`StateDiff` not implemented in step 5 — now resolved in step 9** (commit `095b02e`).
   `between()` takes the `VariableSchema` as a third parameter and an absent-before variable
   diffs from its declared default (candidate 3 of the three offered). Decided by the
   orchestrator, not by the implementer; full rationale under *Step 9* above. The frozen
   payload shape is unchanged and no committed code was touched.
5. **`parse()` is `protected static` and (for conditions) takes the path** — PHP visibility
   rules make `private` impossible, and `ArrayReader`'s path is private. Internal shape only;
   no serialized format, no public API changed.
6. **`composer deptrac` still reports `Allowed 0`, and that is correct** — the plan's AC-4
   expectation ("non-zero `Allowed`… expect it in the low hundreds… if it is still 0 the
   analyser is not seeing `src/Engine`. Report it.") rests on a wrong premise, so it is
   reported rather than engineered around. deptrac never records an intra-layer edge as
   `Allowed`: `DefaultBehavior/Analyser/MatchingLayersHandler` subscribes at priority **1** and
   calls `stopPropagation()` when depender and dependee are in the same layer, while
   `AllowDependencyHandler` — the only thing that creates an `Allowed` rule — subscribes at
   priority **−100** and therefore never runs for such a pair. Every dependency this feature
   creates is `Engine -> Engine`, by design (OQ-3 keeps `src/Shared` empty, OQ-4 keeps the
   exception family inside Engine), so `Allowed` stays 0 for the whole feature. **The
   falsifiable evidence that the analyser does see `src/Engine` is the analysed-file count: 1
   file before step 2, 10 after step 2, 49 after step 7.** No `Engine -> Shared` entry exists,
   as the plan requires. Nothing was added to make the number move.
7. **Open-hierarchy `default` arms in the evaluator and the applier** (steps 8, 9) — PHPStan
   rejects `match (true)` over `instanceof` arms without one, and neither AST root is sealed.
   Both are real behaviour (`UnknownOperatorException`), both are covered by tests that build
   such a node, and no suppression was added. See *Step 8*.
8. **`ArrayReader::isString()` added** (step 11) — the `next` union needs its shape inspected
   before it is read, and all narrowing stays in `ArrayReader`. Additive; no signature changed.
9. **`EffectApplier` rejects a non-numeric value for a numerically declared variable**
   (step 9) — `GameState` validates nothing, so a disagreeing state must fail rather than be
   coerced; it is also what narrows the type for level 8.
10. **D-VISIT's planned test case is not expressible** (step 13) — effects carry no
    conditions, so nothing inside `on_enter` can read `visited`. Pinned by its two observable
    consequences instead. See *Step 13*; this also affects how the `visited` operator should
    be documented in step 16.
11. **Test-mechanics adjustments forced by PHPStan level 8 over `tests/`** — `class_parents()`
   instead of `assertTrue(is_subclass_of(...))`, and `list<class-string>` instead of
   `list<class-string<EngineException>>`, both because PHPUnit's `@phpstan-assert` annotations
   would otherwise let the analyser prove the assertions instead of running them. No
   suppression, no config change.
12. **Two determinism cases added beyond the plan's three** (step 14) — input-state
    immutability and condition-evaluation order independence, both at the orchestrator's
    explicit instruction. Purely additive: no planned case was dropped or weakened, and the
    plan's third case (`GameStateTest::testStateIsIndependentOfTheOrderWithMethodsAreApplied`)
    was already landed in step 5. The forbidden-function list in `EnginePurityTest` is likewise
    a superset of the plan's eight names.
13. **One extra commit in step 15, fixing forward rather than amending** (`fb22e74`). The
    plan's step-15 routing note requires that `Random\Engine\Secure` "never appear" under
    `src/Engine`, so that a grep over the directory is a sufficient check. `6eb4724` named it
    once, in a docblock, only to say it is never used — which still produced a hit and made the
    grep ambiguous. The follow-up rewords the sentence; comment-only, no behaviour change.
    `grep -rn Secure src/` is now empty. History was not rewritten.
14. **Gate C fix loop 1 — `GameState` is not a canonicalisation point (B1-a).** The fix
    instruction named `GameState::withVar()` and `create()` among the paths that must
    normalise a float. They cannot: `GameState` carries no schema, by design, and
    `fromArray()` — the method that actually reads the JSONB column — has no way to learn a
    variable's declared type. Normalisation is therefore applied at every schema-aware
    boundary instead, which holds the property the instruction protects. Full reasoning and
    the residual gap are under *Gate C fix loop 1 → B1*.
15. **Gate C fix loop 1 — one suggestion deliberately not taken, one finding under-delivered.**
    The review's B1 also proposed validating the *resolved* value inside
    `ConditionEvaluator::compare()` (B1-b) — out of that loop's stated scope, deliberately
    declined, still open as r3. M3-a was **not** the same kind of thing: rejecting an explicit
    null for `next`, `qty` and `times` was part of M3 as reported, so loop 1 delivered three
    fifths of an accepted finding and this entry originally mis-recorded that as declining an
    extra. Closed in loop 2 as r4.

## Final check state (after Gate C fix loop 2)

```
composer cs-check -> exit 0 (Found 0 of 85 files that can be fixed)
composer phpstan  -> exit 0 ([OK] No errors; level 8 over src, tests, config, public)
composer deptrac  -> exit 0 (Violations 0 / Skipped violations 0 / Uncovered 0 / Allowed 0 /
                     Warnings 0 / Errors 0)
composer test     -> exit 0 (OK, 480 tests, 7690 assertions)
composer coverage -> exit 0 — src/Engine: Classes 100.00% (44/44), Methods 100.00% (189/189),
                     Lines 100.00% (655/655)
```

Previous state, after step 15 / before the fix loops, for comparison: 84 files, 451 tests,
7566 assertions, 185 methods, 630 lines — all four checks already green. Loop 1 added 25
tests, 109 assertions, 4 methods and 21 lines; loop 2 added 4 tests, 15 assertions, 0 methods
and 4 lines (one `rejectNull()` call per site). Every figure is still 100.00 %.

Integrity checks, all clean: `phpstan-baseline.neon` absent · `grep -rn phpstan-ignore src
tests` → 0 · no `Symfony|Doctrine|Psr|Monolog|PHPUnit` import in `src/Engine` → 0 · no
`rand|mt_rand|random_int|shuffle|time|microtime|uniqid` call site in `src/Engine` → 0 ·
no superglobal → 0 · no static property → 0 · `grep -rn Secure src` → 0 · the first three are
now machine-held by `EnginePurityTest` rather than re-run by hand. `git diff --stat
4bd1269..HEAD` (steps 14–15: 5 files, 1072 insertions, 0 deletions) touches none of
`deptrac.yaml`, `phpunit.xml`, `phpstan.neon`, `composer.json`, `.php-cs-fixer.dist.php`,
`CLAUDE.md`, `Dockerfile`, `.claude/**`, `docs/engine/ast.md`.

## Left for the tester / reviewer

- **Still open from `04-review.md`, by instruction, not by oversight:** M1 (no entry point for
  a story's first scene) and M4 (no scene/choice/phase context on evaluation-time errors) are
  open items for the user; M6 (the golden-value RNG test) is assigned to the tester. All five
  minors are untouched. `Transition`'s public signature is unchanged.
- **Carried forward from the fix loops:** only r3 / B1-b — a type-disagreeing loaded state
  silently evaluates `false` (or `true`) in `ConditionEvaluator::compare()` while the applier
  throws. The re-review's preferred shape is `VariableSchema::assertState(GameState $state)`
  called once at the load boundary, which is `Play`'s work, not the Engine's; it is an open
  item for the user and was explicitly out of loop 2's scope. M3-a is **closed** as r4.
- **`Allowed 0` is expected, not a failure** (deviation 6). If AC-4 is to stay machine-checked,
  the checkable property is the analysed-file count (1 before step 2, 78 after step 13) or the
  absence of an `Engine -> Shared` entry, not the `Allowed` figure. The step-13 probe is the
  positive evidence that the gate bites: a Symfony import in `src/Engine` produced
  `Violations 1` **and** a `BoundedContextLayoutTest` failure.
- **Step 16 is done**: `docs/engine/ast.md` documents the `visited` operator in terms of
  choice conditions and `next` guards, not `on_enter` — see deviation 10 — and states that
  `Rng` exists but no operator uses it. All sixteen plan steps are now complete.
- **The RNG has no golden-value test** (step 15, by plan instruction). Within-process purity is
  proven; cross-version reproducibility of `Xoshiro256StarStar` is not. If that matters, one
  pinned value is the cheapest way to hold it.
- **Two extra determinism cases** were added at the orchestrator's instruction (deviation 12),
  and step 15 took **two** commits rather than one (deviation 13).
- The 100 % figure is read from `--coverage-text`, not enforced by a flag; `#[CoversClass]` is
  present on every Engine test class, so it means "reached by a test that declares it covers
  that class". The `EngineEvent` and `StoryGraph` interfaces have no executable lines and are
  not listed in any `#[CoversClass]`.
- `tests/Unit/Engine/Double/InMemoryStoryGraph.php` is exercised by `TransitionTest` only; it
  is deliberately under `tests/` (AC-10) and therefore outside the coverage filter.

---

## Follow-up loop — the user's open items (2026-10-25)

Closes everything `00-summary.md` listed as open: **r3 / B1-b**, **M1**, **M4**, the **r2
docblock** and the five minors. Started at `dffd765`. One commit per item, test first, the
red message recorded for each, `cs-fix` + `phpstan` after each.

### F1 — `VariableSchema::assertState()`, the load boundary (r3 / B1-b, major)

- **Red first.** `tests/Unit/Engine/State/VariableSchemaTest.php`, four new cases:

  ```
  Error: Call to undefined method Storyteller\Engine\State\VariableSchema::assertState()
  ```

- **Changed.** One method on `VariableSchema`, three lines of body: iterate `$state->vars`
  and call the existing `assertAccepts()` on each. That single call already does both halves
  the finding asks for — `typeOf()` throws `UnknownVariableException` for an undeclared name,
  `accepts()` throws `TypeMismatchException` for a disagreeing type — and it is the
  **canonicalising** acceptance, so `ratio => 1` under a `float` declaration passes and the
  B1 rule does not regress. The weight of the change is in the docblock and the tests, not
  the body.
- **`GameState::fromArray()` is untouched**, as instructed: `GameState` carries no schema by
  design, and this is a method the caller invokes after loading.
- **Policy note, deliberate.** `assertState()` **rejects** an undeclared name while
  `StateDiff::between()` **skips** one (M2's fix). That is not an inconsistency: the diff
  runs on every phase of every turn and must not brick a session over a variable a newer
  story version retired, while `assertState()` runs once, at load, where the caller can still
  refuse or migrate the session. Both policies are now stated in their own docblocks and in
  `docs/engine/ast.md`. Consequence worth knowing for `Play`:
  `ContentVersioningTest::testAVariableTheNewerSchemaNoLongerDeclaresIsCarriedThroughATurn`
  describes a state `assertState()` would reject — playing it is still legal, loading it
  blind is what is now refusable.
- **Files:** `src/Engine/State/VariableSchema.php`, `docs/engine/ast.md`;
  `tests/Unit/Engine/State/VariableSchemaTest.php`.
- **Tests added:** four. The agreeing state (including `ratio => 1`, an absent declared
  variable and `GameState::initial()`); the re-review's own probe — `eq` false, `gt` true,
  `set` succeeds, `add` throws, all four pinned as they are, followed by the one loud answer
  `assertState()` gives; the undeclared name; and the canonical-order case, which builds the
  same three-way-broken state forwards and backwards and asserts both produce the identical
  message.
- **Falsified both ways.** Body replaced by a no-op → 3 of the 4 fail
  (`Failed asserting that exception of type "…TypeMismatchException" is thrown`,
  the same for `UnknownVariableException`, `The forwards state was accepted`). Body replaced
  by a strict `get_debug_type($value) !== $type->value` check (the tightening that would
  regress B1) → the agreeing case fails with
  `Variable "ratio" is declared as float, but the value is of type int.` A check that only
  refused, or only accepted, would be caught.
- **Docs.** `docs/engine/ast.md` gains a `VariableSchema::assertState()` subsection stating
  the obligation on a loading caller, both policies and why they differ, and the four-way
  probe as the motivation.
- **Checks:** cs-check `Found 0 of 87 files that can be fixed` · phpstan `[OK] No errors` ·
  deptrac `Violations 0 / Uncovered 0` · test `OK (546 tests, 7947 assertions)`.
- **Commit:** `feat(engine): validate a loaded state against its schema`
- **Deviations:** none.

### F2 — `Transition::begin()`, the entry point for the first scene (M1, major)

- **Red first.** Seven new cases in `tests/Unit/Engine/TransitionTest.php`:

  ```
  Error: Call to undefined method Storyteller\Engine\Transition::begin()
  ```

- **Changed.** `run()`'s signature and behaviour are untouched; its second half moved into a
  private static `enter(StoryGraph, EffectApplier, GameState, string $target, int $turn,
  int $redirects, list<EngineEvent> $events)` holding the `on_enter` redirect loop, the
  budget guard, `withVisit()`, `SceneEntered`, `isEnding()` and `EndingReached`. `run()` ends
  with `return self::enter(...)` carrying the choice events and the redirect already spent on
  a choice `goto`; `begin()` calls the same method with an empty event list and a counter of
  zero. `guardRedirects()` and `requireScene()` became static (`requireScene()` takes the
  graph), which is what lets both paths share them. The `requireScene($target)` that stood
  just before the loop now lives at the top of `enter()` — same call, same position in the
  order.
- **Files:** `src/Engine/Transition.php`, `docs/engine/ast.md`;
  `tests/Unit/Engine/TransitionTest.php`.
- **Tests added:** seven. The opening scene's `on_enter` applied, visited, in `history`, with
  the full event list asserted literally and **no `choice_made`**; an opening scene with no
  `on_enter` still entered; a `goto` out of the opening scene's `on_enter` (D-VISIT: the
  scene left is transited, not entered); the budget at five and at six, with the same
  message; an unknown opening scene; an ending reached immediately (a one-scene story); and
  the agreement case — the same graph entered through a choice and through `begin()` produces
  the identical state, scene, `isEnding`, and an identical event list once the run's
  `choice_made` is dropped.
- **Falsified both ways.** `begin()` starting the counter at 1 instead of 0 → 4 of the 7 fail,
  the agreement case among them. Mutating the **shared tail** (marking a transited scene
  visited) → 4 failures spread across *both* entry points
  (`testAGotoInOnEnterRedirectsAgain`, `testVisitedCountsAcrossSeveralTurns…`,
  `testASceneRedirectedThroughIsNotMarkedVisited…`, `testBeginFollowsAGotoOutOf…`), which is
  the evidence that the tail really is one implementation and not two.
- **Docs.** `docs/engine/ast.md` gains an *Entering the first scene* section (signature,
  budget, event list, the static rationale) and the event-order paragraph now states what
  `begin()` emits.
- **Checks:** cs-check `Found 0 of 87 files that can be fixed` · phpstan `[OK] No errors` ·
  deptrac `Violations 0 / Uncovered 0` · test `OK (553 tests, 7976 assertions)`.
- **Commit:** `feat(engine): add an entry point for a story's first scene`
- **Deviation — the signature is the user's, the staticness is its consequence.** The review
  (M1) proposed an instance method `Transition::enter(GameState, string, int)`; the
  instruction specifies `begin(StoryGraph $graph, VariableSchema $schema, GameState $state,
  string $sceneKey, int $turn)`. The instruction's shape is what was built. Two notes for the
  reviewer, neither of which changes it: taking a graph on a method of an object that already
  holds one would leave two graphs in scope with no rule about which wins, so `begin()` is
  **static** instead — which is also honest about the fact that an entry needs neither the
  evaluator nor the resolver the constructor demands; and `begin()` therefore builds its own
  `EffectApplier` from the schema, the one piece of wiring `Transition` now knows about
  itself. The shared tail is a private static for the same reason.

### F3 — evaluation-time errors say where they happened (M4, major)

- **Red first.** Four new cases in `tests/Unit/Engine/Exception/EngineExceptionTest.php` and
  three in `tests/Unit/Engine/TransitionTest.php`:

  ```
  Error: Call to undefined method Storyteller\Engine\Exception\UnknownVariableException::withContext()
  Error: Call to undefined method Storyteller\Engine\Exception\TypeMismatchException::context()
  TransitionTest::testAnEvaluationFailureNamesTheSceneChoiceAndPhaseItCameFrom@a choice condition
    Failed asserting that two strings are identical.
    -'Unknown variable "mana". (scene "lobby", choice "north", phase "choice_condition", turn 9)'
    +'Unknown variable "mana".'
  ```

- **Changed.** `EngineException` gains `withContext(array<string,string|int>): static` and
  `context(): array`. The **same object** is annotated and returned rather than a new
  exception wrapping it: the type a caller catches is unchanged (the finding's explicit
  constraint) and the stack trace still starts at the line that detected the problem instead
  of at the `catch` that annotated it — which is why `previous` is not used here, there being
  no second exception to chain. The first context wins, so a message carries at most one
  suffix; an empty context is a no-op rather than an empty parenthesis.
  `Transition` gains one private `inContext(array $context, callable $phase)` wrapping the
  four places a turn can fail: the choice condition, the choice effects, the `next`
  resolution and each `on_enter`. Phases that apply effects use `EffectPhase`'s own wire
  values, so a message and an `EffectsApplied` event say `choice` and `on_enter` the same way.
- **What is deliberately *not* annotated.** `UnknownSceneException`,
  `ChoiceUnavailableException` and `RedirectLoopException` already name their own position,
  and annotating them would have changed messages three existing tests assert literally. The
  `on_enter` context carries **no `choice` key**: an entry through `begin()` has no choice,
  and a turn's choice is not where a broken `on_enter` node is. "Do not make the context
  mandatory where the Engine genuinely does not have it."
- **Files:** `src/Engine/Exception/EngineException.php`, `src/Engine/Transition.php`,
  `docs/engine/ast.md`; tests in `tests/Unit/Engine/Exception/EngineExceptionTest.php`,
  `tests/Unit/Engine/TransitionTest.php`.
- **Tests added:** seven. On the exception: the message and the structured context, with the
  type asserted unchanged; no context by default and an empty context changing nothing; the
  first context winning over a second. On the transition: a four-row provider, one per phase,
  each asserting the **whole** message; the same `on_enter` failure reached through `run()`
  and through `begin()` reading identically; and a `TypeMismatchException` keeping its type,
  its original message as a prefix, and its context as an array.
- **Falsified.** `inContext()` reduced to `return $phase();` → all 6 transition cases fail.
  The first-context-wins guard removed → the double-decoration case fails. Each probe
  reverted by file copy.
- **Docs.** `docs/engine/ast.md` *Error behaviour* gains a *Where an evaluation-time error
  happened* subsection: the suffix format, the four phase names, which exceptions are not
  annotated, that `scene` is where the broken node is rather than where the turn started, and
  the standing note that these messages name unpublished content and must not reach a client
  verbatim.
- **Checks:** cs-check `Found 0 of 87 files that can be fixed` · phpstan `[OK] No errors` ·
  deptrac `Violations 0 / Uncovered 0` · test `OK (562 tests, 8012 assertions)`.
- **Commit:** `fix(engine): say where an evaluation-time error happened`
- **Deviations:** none.

### F4 — the docblock that justified the asymmetry was false above 2^53 (r2, minor)

- **Red first — by probe, not by a new test**, because the behaviour was already pinned
  red-first in `ce2046e` and only the comment was wrong. The three pinning cases were
  re-falsified in this tree by taking the review's *alternative* fix (refusing the widening
  above 2^53 inside `canonicalise()`):

  ```
  ConditionEvaluatorTest::testAFloatDeclaredVariableCannotTellTwoIntegersAbove2Pow53Apart
  VariableTypeTest::testCanonicaliseWidensOnlyAnIntIntoAFloatDeclaration@float declaration rounds 2^53 + 1 to its even neighbour
  VariableTypeTest::testWideningAnIntIntoAFloatDeclarationIsLossyAbove2Pow53
  Tests: 104, Failures: 3
  ```

  So the documented claim is the one the suite holds, and changing the behaviour instead
  would have been a visible, not a silent, decision.
- **Changed.** `src/Engine/State/VariableType.php:13` now reads "widening is lossless for
  every value a story will hold — above 2^53 an int is rounded to the nearest representable
  double, as it would be by JSON anyway", the review's own wording. `canonicalise()`'s
  docblock states the exact limit (`9007199254740992`), the observable consequence
  (`9007199254740993` comes back as `…992.0`, silently), the rejected alternative and why,
  and names the two tests that hold it.
- **Files:** `src/Engine/State/VariableType.php`, `docs/engine/ast.md`;
  `tests/Unit/Engine/State/VariableTypeTest.php` (two docblocks that quoted the false claim —
  rule 1's matching test change; no assertion was altered).
- **Docs.** `docs/engine/ast.md`'s *Conventions* bullet said "Widening is lossless" flatly;
  it now carries the same limit and the `eq` consequence.
- **Checks:** cs-check `Found 0 of 87 files that can be fixed` · phpstan `[OK] No errors` ·
  test `OK (562 tests, 8012 assertions)` (unchanged — a comment).
- **Commit:** `docs(engine): state the real limit of the int-to-float widening`
- **Deviations:** none.

### F5 — the `visited` docblocks described something the AST cannot express (m1, minor)

- **Changed.** `src/Engine/Ast/Visited.php` and `Transition`'s class docblock both claimed a
  "first time here" test "written inside `on_enter`". Effects carry no conditions, so nothing
  there can read `visited` at all — the implementer established this during the build
  (deviation 10) and the planned test case was dropped for it. Both now state the rule in
  terms of **choice conditions and `next` guards on the following turn**, and point at
  `docs/engine/ast.md` and the test that pins it. `docs/engine/ast.md` was already correct
  (step 16) and is unchanged.
- **Tests added:** one, `EffectTest::testNoEffectOperatorTakesACondition` — the structural
  fact underneath the reworded wording: the eight effect operators are pinned as a literal
  list (a conditional one would make the rejected illustration expressible) and an effect
  node carrying a `condition` key is rejected at parse time.
- **Falsified.** Removing `rejectUnknownKeys()` from `FlagEffect::parse()` → the new case
  fails (alongside the pre-existing unknown-key case), so it is not a tautology over a shape
  nobody enforces.
- **Checks:** cs-check `Found 0 of 87 files that can be fixed` · phpstan `[OK] No errors` ·
  test `OK (563 tests, 8015 assertions)`.
- **Commit:** `docs(engine): describe the visit rule where it is observable`

### F6 — the nine public methods with no production call site (m2, minor)

One used, one removed, seven justified in a line each at their declaration.

| Method | Disposition |
|---|---|
| `EffectResult::isRedirect()` | **Used.** `Transition` now branches on it at both redirect sites, as the plan's step 7 specified. |
| `ArrayReader::child()` | **Removed.** Orphaned when `parse()` started taking its path explicitly (deviation 5); `new ArrayReader($reader->requireArray($k), "$path.$k")` is the same two lines where it is wanted, and no parser wanted it. |
| `ConditionOp::isComparison()` | Kept — `src/Content`: a compiler holds an `op` string before any node exists and decides from it whether `var`/`value` are required. Its sibling `isOrdering()` *is* used by the evaluator. |
| `EffectOp::isArithmetic()` | Kept — same reason: whether the named variable must be numeric, asked of an `op` string. |
| `NextRule::isFallback()` | Kept — `src/Content` lints what the Engine deliberately refuses to diagnose: a fallback written first makes later rules unreachable, and a list without one can dead-end at play time. |
| `ChoiceNode::isAlwaysAvailable()` | Kept — `src/Api` lists a scene's choices; this is the one question that needs no state, so only the guarded ones have to be evaluated. |
| `SceneNode::hasChoice()` | Kept — `src/Api` tells a 404 from a 409 *before* attempting a turn. `Transition` uses `choice()`, which throws, because for a turn a missing choice is an error; asking by exception is not validation. |
| `VariableSchema::names()` | Kept — `src/Content` diffs a schema against its predecessor; `toArray()` returns the whole declaration when only names were wanted. |
| `VariableSchema::defaultOf()` | Kept — `src/Content` / `src/Api` show declared defaults; reaching that through `resolve()` against a fabricated empty state would be worse. |

- **A finding of my own, while using `isRedirect()`.** It needs
  `@phpstan-assert-if-true !null $this->redirectTo` for the call sites to narrow. I wrote in
  the docblock that PHPStan verifies the assertion against the body, then checked: replacing
  the body with `return true` still gives `[OK] No errors`. The annotation is taken on
  **trust**. The docblock now says so and names
  `EffectResultTest::testIsRedirectReflectsRedirectTo` as its guard — that test asserts both
  directions at runtime and is what keeps the narrowing honest. An annotation nothing checks,
  described as checked, is the same defect r2 was about.
- **Files:** `src/Engine/EffectResult.php`, `src/Engine/Transition.php`,
  `src/Engine/Ast/ConditionOp.php`, `src/Engine/Ast/EffectOp.php`,
  `src/Engine/Graph/NextRule.php`, `src/Engine/Graph/ChoiceNode.php`,
  `src/Engine/Graph/SceneNode.php`, `src/Engine/State/VariableSchema.php`,
  `src/Engine/Support/ArrayReader.php`; tests in `tests/Unit/Engine/EffectResultTest.php`,
  `tests/Unit/Engine/Support/ArrayReaderTest.php`.
- **Tests:** `ArrayReaderTest::testChildExtendsThePathInErrorMessages` replaced by
  `testANestedReaderNamesTheExtendedPathInErrorMessages`, which pins the property `child()`
  existed for — a nested error names the path that reaches it — on the readers the AST
  actually builds. `EffectResultTest`'s case gains the docblock explaining what it now
  guards.
- **Checks:** cs-check 0 of 87 · phpstan `[OK] No errors` · deptrac `Violations 0` · test
  `OK (563 tests, 8014 assertions)` (one assertion fewer: the removed `child()` case).
- **Commit:** `refactor(engine): use isRedirect and justify the unused public surface`

### F7 — `RedirectLoopException` named the one scene that was never reached (m3, minor)

- **Red first.** The three literal message assertions in `TransitionTest` were changed to the
  new wording before the source was:

  ```
  TransitionTest::testAGotoBackIntoTheSceneBeingEnteredIsStoppedByTheBudgetNotByCycleDetection
    -'Redirect budget of 5 exceeded; the redirect to "b" was refused.'
    +'Redirect budget of 5 exceeded; the last scene reached was "b".'
  ```

- **Changed.** The message now reads `Redirect budget of %d exceeded; the redirect to "%s"
  was refused.` and the parameter is `$refusedScene`. The guard runs *before* the hop is
  taken, so the scene it names is the one the turn never entered — for the six-hop chain,
  `h6` — and "the last scene reached" sent an operator to the wrong end of the chain. The
  named constructor's docblock now states which end it is and why.
- **Files:** `src/Engine/Exception/RedirectLoopException.php`, `docs/engine/ast.md`; tests in
  `tests/Unit/Engine/TransitionTest.php`, `tests/Unit/Engine/Exception/EngineExceptionTest.php`.
- **Tests:** the three literals, plus `EngineExceptionTest`'s fragment for this constructor
  tightened from `'cave'` to `'the redirect to "cave" was refused'` — the fragment list was
  satisfied by both wordings and would not have noticed.
- **Docs.** `docs/engine/ast.md`'s redirect-budget bullet and the error table now say which
  scene is named, and the table row covers `begin()` as well as `run()`.
- **Checks:** cs-check 0 of 87 · phpstan `[OK] No errors` · test `OK (563 tests, 8014 assertions)`.
- **Commit:** `fix(engine): name the refused redirect, not the scene never reached`

### F8 — the purity scan now covers the environment, object identity and I/O (m4, minor)

- **Changed.** `tests/Unit/Engine/EnginePurityTest.php` only (no `src/` change). Two new
  constants beside `NON_DETERMINISTIC_FUNCTIONS`, kept separate because they are separate
  rules: `AMBIENT_FUNCTIONS` (12 — `getenv`, `putenv`, `php_uname`, `gethostname`,
  `getmypid`, `sys_get_temp_dir`, `getcwd`, `spl_object_id`, `spl_object_hash`,
  `memory_get_usage`, `memory_get_peak_usage`, `connection_status`) and `IO_FUNCTIONS` (29 —
  `file_get_contents` through `curl_init`, plus `error_log` and `syslog`), which is
  CLAUDE.md's "no I/O" rule, until now held by prose alone. A third, `FORBIDDEN_TYPES`,
  carries `Random\Engine\Secure` and is matched **anywhere in the file, including a
  docblock**, which is what keeps step 15's "a grep over the directory is a sufficient check"
  true.
- **`spl_object_id`/`spl_object_hash` are the subtle ones** and the reason the review named
  them: stable within a process, meaningless across two, so sorting or keying by one gives a
  different answer for the same inputs on the next request.
- **Samples.** 13 new positive samples (one per new call form, including both an import of
  and a bare mention of the forbidden type) and 5 new negatives — `$this->glob(...)`,
  `$config->getenv(...)`, `unserialize_file(...)`, `$this->fileExists`, `SecureHash::of(...)`
  — so the new patterns cannot go vacuous and cannot fire on a method, a property or a longer
  name.
- **The failure message was wrong for the new rules** ("Inject a seed or a clock instead" for
  a `file_get_contents`); it now names both halves of the rule and the real remedy.
- **Falsified, six probes, each applied to `src/Engine/Transition.php` by file copy and
  reverted the same way:** `getenv()`, `spl_object_id()`, `file_get_contents()`, `glob()`,
  `fopen()` and a `use Random\Engine\Secure;` import. Each produced exactly one failure
  naming the file and the rule. `git status src/` clean afterwards.
- **Checks:** cs-check 0 of 87 · phpstan `[OK] No errors` · test `OK (563 tests, 12181
  assertions)` — the assertion count jumps because the scan asserts once per pattern per
  Engine file.
- **Commit:** `test(engine): forbid the environment, object identity and I/O`

### F9 — a positional list was accepted where the envelope requires a map (m5, minor)

- **Red first.**

  ```
  GameStateTest::testFromArrayRejectsAPositionalListWhereAMapIsRequired
    A positional list was accepted for "vars".
  ArrayReaderTest::testTheMapReadersRejectANonEmptyListButStillAcceptAnEmptyOne
    Expected a MalformedNodeException, none was thrown.
  ```

- **Changed.** One new private `ArrayReader::requireMap()` — the mirror of
  `requireArrayList`'s `array_is_list()` check — used by both `requireIntMap()` and
  `requireScalarMap()`. A **non-empty** list is now `wrongType(..., 'map', 'list')`; the
  empty array stays a valid map, because PHP has one array type and `json_encode` writes a
  state with no variables as `"vars": []`. Refusing that would refuse every fresh session.
- **Why it is not cosmetic.** `{"vars": [1, 2]}` loaded as two variables named `0` and `1`,
  which no schema declares, so the failure surfaced later and elsewhere — and after F1, at a
  different boundary again. A malformed envelope should fail at the envelope.
- **Files:** `src/Engine/Support/ArrayReader.php`, `docs/engine/ast.md`; tests in
  `tests/Unit/Engine/Support/ArrayReaderTest.php`, `tests/Unit/Engine/State/GameStateTest.php`.
- **Tests added:** two. At the reader: both methods refusing a non-empty list with the path,
  the key and both type names; both still accepting `[]`; and a map whose only key is `"10"`
  staying a map (PHP calls a list only what starts at zero and runs consecutively). At the
  envelope: `vars`, `inventory` and `visited` each refused as a list, and the empty envelope
  still round-tripping.
- **Checks:** cs-check 0 of 87 · phpstan `[OK] No errors` · deptrac `Violations 0 /
  Uncovered 0` · test `OK (565 tests, 12200 assertions)`.
- **Commit:** `fix(engine): reject a positional list where the format requires a map`
