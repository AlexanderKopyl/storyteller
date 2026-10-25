# Engine AST specification

This is the reference for the condition/effect JSON AST interpreted by
`src/Engine`, the `GameState` wire format, and the `StateDiff` payload shape.
It documents what the committed code under `src/Engine/Ast/`,
`src/Engine/State/`, `src/Engine/ConditionEvaluator.php` and
`src/Engine/EffectApplier.php` actually does, verified against their tests
under `tests/Unit/Engine/`. Where this document and `docs/sdlc/engine-core/02-plan.md`
disagree, this document follows the code, and the disagreement is called out
explicitly.

All of this is **data, not code**: a story is authored as YAML, compiled into
a JSONB snapshot, and interpreted at play time. Conditions and effects are
small JSON objects (nodes); adding an operator means adding an enum case, a
parser, an evaluator/applier arm, and its unit tests — all four, or it is not
done.

## Conventions

- **All keys are `snake_case`.** `on_enter`, not `onEnter`; `set_flag`, not
  `setFlag`. This matches the operator names and is a frozen format decision:
  changing it after a `story_version` is published is a JSONB data migration,
  not a refactor.
- **Unknown keys are rejected.** Every node's parser calls
  `ArrayReader::rejectUnknownKeys()` with its exact allowed set. A typo in
  authored content (e.g. `"flagg"` instead of `"flag"`) throws
  `MalformedNodeException` at compile time rather than silently doing nothing
  at play time.
- **An explicit `null` is not the same as an omitted key.** For a choice's
  `condition` and a `next` rule's `if`, *omitting* the key means "no guard"
  (an always-available choice, an unconditional fallback), while
  `"condition": null` or `"if": null` is **rejected** with
  `MalformedNodeException` — `key "condition" must be of type object, got
  null`. In YAML a `condition:` line with nothing after it is exactly `null`,
  and silently reading it as "no guard" would delete an authored gate rather
  than report an authoring error. Use the absent key to mean absent.
- **Structural validation happens at parse time; type validation happens at
  evaluation time.** `fromArray()` only checks shape: which keys are present,
  that `qty` / `times` is a positive int, that `value` is a scalar (or a JSON
  number for `add` / `sub`). Whether a literal matches the *variable's
  declared type* is checked by the evaluator or the applier against the
  `VariableSchema`, because the schema is not necessarily available when a
  node is parsed.
- **A missing variable resolves to its schema default, and is never `null`.**
  This is `VariableSchema::resolve(string $name, GameState $state)`, the one
  place variable resolution happens in the Engine. A name the schema does not
  declare is `UnknownVariableException`, **even if the state happens to carry
  a value for it** — the schema is authoritative, not the state. A name the
  schema declares but the state has no entry for resolves to the declared
  default. Both the evaluator and the applier go through this one method.
  The one exception is `StateDiff`, which *observes* state rather than
  resolving a name an author wrote and therefore skips undeclared names — see
  the `StateDiff` section.
- **A `float` variable accepts an `int`; an `int` variable never accepts a
  `float`.** The asymmetry is deliberate and is forced by JSON:
  `json_encode(1.0)` emits `1` and `json_decode("1")` returns an `int`, so an
  integral float in a schema default, in a `set` / `add` / `sub` value, in a
  comparison literal or in a stored variable comes back from the snapshot or
  the session column as an `int`. Widening is lossless, so it is allowed and
  the value is **canonicalised** back to a real `float`
  (`VariableType::canonicalise()`, reached through
  `VariableSchema::canonicalise()` and `VariableSchema::resolve()`). Narrowing
  is not lossless, so a `float` literal under an `int` declaration is still
  `TypeMismatchException`. The practical guarantee: an `eq` that is true
  before a save is true after the reload. Note that `GameState` itself holds
  no schema and so stores whatever it was handed — the widening happens on
  every schema-aware read and write, which is every read and write the Engine
  performs.
- **The Engine trusts its inputs.** All of the above is format validation
  against trusted, already-compiled content — it is not a security boundary.
  External input is validated in `src/Api` before it ever reaches the Engine.

## Conditions

`Storyteller\Engine\Ast\Condition` is the abstract root; `Condition::fromArray()`
reads the `op` key, maps it through `ConditionOp::tryFrom()`, and dispatches to
one of seven concrete classes covering thirteen operators. Every example below
is a shape the parser and tests actually accept.

### `eq` / `neq` — equality

```json
{"op": "eq",  "var": "hp", "value": 100}
{"op": "neq", "var": "name", "value": "ash"}
```

- Class: `Comparison`. Required keys: `op`, `var` (string), `value` (any JSON
  scalar). No optional keys.
- Evaluation: `$schema->assertAccepts($var, $value)` first — comparing a
  variable declared `int` against the literal `1.0` is `TypeMismatchException`,
  not a silent `false`. The reverse is allowed: a variable declared `float`
  accepts the literal `1`, which is widened to `1.0` (see *Conventions*). Then
  the variable is resolved (schema default if absent from state, canonicalised
  the same way) and compared with `===` (`eq`) or `!==` (`neq`). Both sides are
  canonicalised, never just one.
- **Float equality is exact.** `eq` on a `float` variable compares with
  `===`; no epsilon is applied. `0.1 + 0.2` does **not** equal `0.3` under
  `eq`, but it *does* equal the literal `0.1 + 0.2` evaluates to in PHP. An
  epsilon is a policy nobody authored, so none is introduced; a story that
  needs tolerant float comparison must express it some other way (e.g. a
  range with `gte`/`lte`).

### `lt` / `lte` / `gt` / `gte` — ordering

```json
{"op": "lt",  "var": "hp",   "value": 10}
{"op": "lte", "var": "hp",   "value": 10}
{"op": "gt",  "var": "gold", "value": 0}
{"op": "gte", "var": "gold", "value": 0}
```

- Class: `Comparison`, same shape as `eq`/`neq`.
- Evaluation: `assertAccepts` first, then the variable's declared type must be
  numeric (`VariableType::isNumeric()` is true only for `Int` and `Float`) or
  `TypeMismatchException::notNumeric()` is thrown — a `string` or `bool`
  variable cannot be ordered. Then `<`, `<=`, `>`, `>=` on the resolved value.

### `flag` / `not_flag` — flag membership

```json
{"op": "flag",     "flag": "met_elder"}
{"op": "not_flag", "flag": "met_elder"}
```

- Class: `FlagCheck`. Required key: `flag` (string). No optional keys.
- Evaluation: `$state->hasFlag($flag)` for `flag`, its negation for
  `not_flag`. Flags are an **open set** — they are not declared in the
  `VariableSchema` — so no schema lookup happens and an unrecognised flag
  name is never an error; it is simply not set.

### `has_item` — inventory check

```json
{"op": "has_item", "item": "rope", "qty": 1}
```

- Class: `HasItem`. Required key: `item` (string). Optional key: `qty`
  (positive int, **default `1`**).
- Evaluation: `$state->itemQty($item) >= $qty`. `itemQty()` returns `0` for an
  item the inventory has no entry for — there is no "unknown item" error.

### `visited` — scene visit count

```json
{"op": "visited", "scene": "crossroads", "times": 1}
```

- Class: `Visited`. Required key: `scene` (string). Optional key: `times`
  (positive int, **default `1`**).
- Evaluation: `$state->visitCount($scene) >= $times`. `visitCount()` returns
  `0` for a scene never entered.
- **Precise semantics of when a scene counts as visited — see the dedicated
  section below.** This is the one operator with a non-obvious rule and it
  was a deliberate build decision (D-VISIT), not an accident.

### `and` / `or` — n-ary logic

```json
{"op": "and", "of": [{"op": "flag", "flag": "a"}, {"op": "flag", "flag": "b"}]}
{"op": "or",  "of": [{"op": "flag", "flag": "a"}, {"op": "flag", "flag": "b"}]}
```

- Classes: `LogicalAnd`, `LogicalOr`. Required key: `of`, a **non-empty JSON
  list** of condition nodes. No optional keys.
- An **empty `of` list is rejected at parse time** with
  `MalformedNodeException` (`wrongType`, expected `"non-empty list"`, actual
  `"empty list"`). There is no sensible identity element to pick for an empty
  `and` or `or` — silently defaulting to `true` or `false` would be a hidden
  policy — so authoring one is a compile-time error, not a runtime default.
- Evaluation: `and` is true iff every child is true, short-circuiting on the
  first `false`; `or` is true iff at least one child is true,
  short-circuiting on the first `true`. Nesting to any depth is supported;
  each child's error path is extended as `condition.of[<index>]`.

### `not` — negation

```json
{"op": "not", "of": {"op": "flag", "flag": "a"}}
```

- Class: `LogicalNot`. Required key: `of`, a **single condition object** (not
  a list — the shape mirrors the arity: `and`/`or` are n-ary and take a list,
  `not` is unary and takes one object). No optional keys.
- Evaluation: the boolean negation of the evaluated child.

## Effects

`Storyteller\Engine\Ast\Effect` is the abstract root; `Effect::fromArray()`
reads `op`, maps it through `EffectOp::tryFrom()`, and dispatches to one of
five concrete classes covering eight operators. `Effect::listFromArray()`
parses an ordered list (used for both `on_enter` and `choices[].effects`);
order matters because a `goto` ends the list where it stands.

### `set` — assign a variable

```json
{"op": "set", "var": "hp", "value": 100}
```

- Class: `SetVar`. Required keys: `var` (string), `value` (any JSON scalar
  at parse time). No optional keys.
- Application: `$schema->assertAccepts($var, $value)`, then `withVar()`. A
  value the variable's declared type does not accept is
  `TypeMismatchException` — unlike `set`'s parse step, which accepts any
  scalar, the applier enforces the declared type.

### `add` / `sub` — arithmetic

```json
{"op": "add", "var": "hp", "value": 5}
{"op": "sub", "var": "hp", "value": 5}
```

- Class: `Arithmetic`. Required keys: `var` (string), `value` — **must be a
  JSON number at parse time** (`MalformedNodeException` for a numeric string
  or a bool; `requireNumber()` accepts only `is_int`/`is_float`). No optional
  keys.
- Application: the variable's declared type must be numeric
  (`TypeMismatchException::notNumeric()` otherwise — no string concatenation,
  no boolean arithmetic). The current value is resolved (schema default if
  absent), `+` or `-` is applied, and **the result is re-validated against the
  declared type** (`assertAccepts`). Consequences that are tested, not
  incidental: an `int` variable plus a `float` value (e.g. `0.5`) throws
  rather than silently promoting the variable to a float, and an `int`
  overflow that PHP turns into a `float` (e.g. `PHP_INT_MAX + 1`) is caught by
  the same re-validation and also throws.

### `set_flag` / `clear_flag` — flag mutation

```json
{"op": "set_flag",   "flag": "met_elder"}
{"op": "clear_flag", "flag": "met_elder"}
```

- Class: `FlagEffect`. Required key: `flag` (string). No optional keys.
- Application: `withFlag()` / `withoutFlag()`. Setting an already-set flag or
  clearing an absent one is a well-defined no-op on the resulting state (the
  flag list is de-duplicated and sorted on every write) and produces **no
  diff entry** — `StateDiff` records outcomes, not attempts.

### `give_item` / `remove_item` — inventory mutation

```json
{"op": "give_item",   "item": "rope", "qty": 1}
{"op": "remove_item", "item": "rope", "qty": 1}
```

- Class: `ItemEffect`. Required key: `item` (string). Optional key: `qty`
  (positive int, **default `1`**). `qty` says how many to give or take, never
  how many to end up with — zero and negative values are rejected at parse
  time as authoring mistakes, not instructions.
- Application: `give_item` is `withItem($item, itemQty($item) + $qty)`.
  `remove_item` is `withItem($item, max(0, itemQty($item) - $qty))`.
- **`remove_item` never goes below zero, and a quantity of zero is not kept
  as an entry.** `GameState::withItem()` removes the inventory key entirely
  whenever the resulting quantity is `<= 0`, so `itemQty()` on a removed item
  returns to `0` and the key never reappears in `toArray()['inventory']`
  (and therefore never reappears as a `StateDiff` entry whose `to` is
  anything but the implicit absence — a removed key is reported with
  `"to": 0`, see *`StateDiff`* below).

### `goto` — redirect to another scene

```json
{"op": "goto", "scene": "cave"}
```

- Class: `GoToScene`. Required key: `scene` (string). No optional keys.
- Application: `EffectApplier::apply()` stops the effect list the moment it
  reaches a `goto` and returns an `EffectResult` with `redirectTo` set to the
  named scene. **Effects after a `goto` in the same list never run.** A list
  containing two `goto` nodes honours the first one only.
- **`goto` does not mutate state itself.** It only ever sets `redirectTo`;
  whatever `set`/`add`/`give_item`/etc. effects ran *before* it in the same
  list did mutate state, and that mutation is kept and diffed normally — only
  the `goto` node itself is state-inert.
- The redirect it produces is consumed by `Transition`, which resolves it
  instead of the scene's declared `next` (for a choice-effect `goto`) or
  instead of continuing past the current scene (for an `on_enter` `goto`).
  See *The redirect budget* below for the limit on how many of these a single
  turn may chain.

## Cross-cutting effect/condition rules

- **A missing variable resolves to its schema default, never `null`**,
  everywhere a variable is read: condition evaluation, `add`/`sub`'s current
  value, and `StateDiff`'s `from`/`to` for a variable absent from one side.
  There is exactly one implementation of this rule
  (`VariableSchema::resolve()`), so every caller is right about it or none
  are.
- **A name the schema does not declare is always an error**, even if the
  state carries a value under that name. The schema is authoritative, not the
  state.
- **`remove_item` never goes below zero; a zero quantity drops the key.**
  Covered above.
- **`goto` returns a redirect and does not itself mutate state**, and effects
  after it in the same list never run. Covered above.

## The `visited` operator, precisely

`VariableSchema`-style resolution does not apply here — `visited` reads
`GameState::$visited`, a separate map keyed by scene, maintained by exactly
one method: `GameState::withVisit(string $scene)`, which increments the
scene's visit counter **and** appends the scene to `history` in the same
call. `Transition` is the only caller of `withVisit()`.

**The counter is incremented *after* the target scene's `on_enter` effects
have run**, and only once a redirect chain (if any) has fully settled. The
exact order inside `Transition::run()` for reaching a resting scene `X` is:

1. apply `X`'s `on_enter` effects (possibly redirecting elsewhere, see
   below);
2. once settled on a final scene, `state = state->withVisit(finalScene)`;
3. emit `SceneEntered`.

Two consequences follow directly, and both are deliberate rather than
incidental:

1. **A scene that redirects out of its own `on_enter` is never marked
   visited, and never appended to `history`.** It was *transited through*,
   not *entered* — its `on_enter` effects really ran and are still reported
   in an `EffectsApplied` event, but the scene itself leaves no trace in
   `visited` or `history`, and no `SceneEntered` is emitted for it.
   `SceneEntered` fires exactly once per `Transition::run()`, for the scene
   the turn finally came to rest on.
2. **Because the visit counter for the *current* resting scene is not
   incremented until after its own `on_enter` has already run, nothing
   executing inside that `on_enter` can observe its own entry as having
   already happened.** This is not a limitation an author works around with
   clever AST — **it is not expressible at all**, and this document says so
   rather than implying otherwise. Effects carry no conditions: there is no
   conditional effect operator, so an `on_enter` effect list has no way to
   branch on anything, including its own scene's visit count. The counter's
   timing therefore cannot be observed from inside `on_enter`; it can only be
   observed from **choice conditions** and **`next` rule guards** evaluated
   on a *later* turn, once the scene the player is now standing on is the
   same one being asked about.

Concrete, verified example (`tests/Unit/Engine/TransitionTest.php::testASceneIsNotVisitedUntilAfterItsOwnOnEnter`).
Scene `x` offers two choices guarded by its own visit count:

```json
{
  "key": "x",
  "on_enter": [{"op": "set_flag", "flag": "entered_x"}],
  "choices": [
    {"key": "again", "condition": {"op": "visited", "scene": "x", "times": 1}},
    {"key": "third", "condition": {"op": "visited", "scene": "x", "times": 3}}
  ],
  "next": "x"
}
```

- Turn 1 (arriving at `x` for the first time): `x`'s `on_enter` runs, then
  `visited.x` becomes `1`, then `SceneEntered` is emitted. The `on_enter`
  effect itself never saw `visited.x`.
- Turn 2 (now standing on `x`, picking `again`): the `again` choice's
  condition is evaluated **before** this turn's own effects or `on_enter` run,
  against the state left by turn 1 — where `visited.x` is already `1`. The
  choice is available. After this turn runs, `visited.x` becomes `2`.
- Turn 3 (picking `third`): `visited.x` is `2`, so `{"op": "visited", "scene":
  "x", "times": 3}` is `false` and `ChoiceUnavailableException::conditionFailed()`
  is thrown — the choice has not yet become available, exactly as far as `x`
  has actually been entered.

This is the mechanism an author uses for "first time here" logic: not a
condition *inside* `on_enter` (impossible), but a **choice condition or
`next` guard checked on the next turn**, phrased as
`{"op": "not", "of": {"op": "visited", "scene": "X", "times": 1}}` if the
intent is "the first time the player is standing here with a choice to make".

## The redirect budget

`Transition::MAX_REDIRECTS = 5`. There is **one counter per
`Transition::run()` call**, and it works like this:

- If the chosen choice's effects contain a `goto`, that redirect sets the
  counter to `1` immediately — it is **not a free redirect on top of the
  budget**; it spends the first of the five. The scene's declared `next` is
  not resolved at all in this case.
- Otherwise the declared `next` is resolved normally and the counter starts
  at `0`.
- Every `goto` encountered in a target scene's `on_enter` effects increments
  the counter by one.
- The guard runs after every increment (including the choice-effect `goto`'s
  initial set to `1`): if the counter exceeds `5`, `RedirectLoopException` is
  thrown, naming the limit and the last scene reached before the throw.

So **five real redirects complete a turn successfully; the sixth throws**.
A choice-effect `goto` followed by four `on_enter` redirects (five total)
succeeds; a fifth `on_enter` redirect after that (six total) throws.

**There is no cycle detection.** A legitimate `A → B → A` re-entry inside the
budget is allowed and completes normally — the budget guards against
non-termination by counting, not by detecting repetition. An author who
writes an infinite redirect loop will hit `RedirectLoopException` on the
sixth hop rather than have the Engine detect the cycle structurally.

`SceneEntered.redirects` carries the final count for the turn (`0` when no
redirect happened at all), so the budget is observable in the event log, not
only in a thrown exception.

## `GameState` — the state envelope

```json
{
  "v": 1,
  "vars":      {"gold": 10, "hp": 95},
  "flags":     ["brave", "wise"],
  "inventory": {"rope": 1, "torch": 2},
  "visited":   {"cave": 1, "crossroads": 2},
  "history":   ["crossroads", "cave", "crossroads"]
}
```

- All six keys are required by `fromArray()`, in this field order. Unknown
  keys are rejected.
- `v` must equal `GameState::VERSION` (currently `1`); any other value throws
  `MalformedNodeException::unsupportedStateVersion()`.
- `vars`, `inventory` and `visited` are maps, key-sorted with `SORT_STRING`
  (not PHP's default `SORT_REGULAR`, which would compare numeric-looking keys
  numerically and make ordering depend on what a key happens to look like).
- `flags` is a list, de-duplicated and sorted with `SORT_STRING`.
- `history` is append-only and keeps insertion order — it is the one
  collection whose order is its content, and it is never re-sorted.
- `GameState::initial()` returns five empty collections. There is exactly one
  notion of "default" in the Engine (the schema, consulted at read time), so
  no default is seeded into a fresh state, and the starting scene is not
  marked visited until it is actually transitioned to.
- An empty map serializes as `[]`, not `{}`, because PHP has one array type
  for both. The Engine's own round-trip (`toArray()` → `json_encode` →
  `json_decode` → `fromArray()`) is exact regardless; a consumer reading the
  JSON directly (e.g. a `jsonb` column) should not be surprised by `"vars":
  []` on a state with no variables set.
- A decimal-integer-like key (e.g. an item named `"10"`) is stored by PHP as
  an `int` array key internally; the Engine casts it back to `string`
  wherever it is read, so the round trip is stable and the declared type
  stays `array<string, …>`.

## `VariableSchema` — the variable declaration

```json
{
  "gold": {"type": "int",    "default": 0},
  "hp":   {"type": "int",    "default": 100},
  "name": {"type": "string", "default": ""}
}
```

`type` is one of `int`, `float`, `string`, `bool`. A `default` the declared
type does not accept throws `TypeMismatchException` when the
`VariableDefinition` is constructed. A duplicate variable name across two
definitions throws `MalformedNodeException::duplicateKey()`.

A `float` variable declared with an integral default serializes as
`{"type": "float", "default": 1}` — JSON has no way to say `1.0` — and is read
back as a `float`, because the default is canonicalised when the
`VariableDefinition` is constructed. See the acceptance rule under
*Conventions*.

## `StateDiff` — the `EffectsApplied` payload

```json
{
  "vars":      {"hp": {"from": 100, "to": 95}},
  "flags":     {"added": ["brave"], "removed": ["afraid"]},
  "inventory": {"rope": {"from": 0, "to": 1}, "torch": {"from": 2, "to": 0}},
  "visited":   {"cave": {"from": 0, "to": 1}}
}
```

`StateDiff::between(GameState $before, GameState $after, VariableSchema $schema)`
is a **structural, post-hoc comparison** of two states — it records
*outcomes*, not a log of attempted effects. Rules:

- Four categories, always emitted in this order when present: `vars`,
  `flags`, `inventory`, `visited`.
- **Empty categories are omitted entirely.** Within `flags`, an empty `added`
  or `removed` is also omitted, and `flags` itself is omitted if both are
  empty. A completely unchanged diff serializes as `{}`.
- A variable absent from the before-state diffs from its **schema default**
  (not `null`), because the diff is a view over variable state and must obey
  the same missing-variable rule as everything else — `StateDiff::between()`
  takes the `VariableSchema` for exactly this reason.
- A variable the schema does **not** declare is **skipped**, not reported and
  not an error. The diff runs on every effect phase of every turn, including a
  phase with no effects, so a state carrying a name a newer story version has
  dropped stays playable. This is the one place the "an undeclared name is an
  error even when the state carries a value" rule does not apply: the diff
  observes state, it does not resolve a name an author wrote. An AST node that
  names an undeclared variable still raises `UnknownVariableException`.
- `inventory` and `visited` diff against `itemQty()` / `visitCount()`, whose
  natural absent-value is `0`; a removed inventory key is therefore reported
  as `{"from": <n>, "to": 0}`, not omitted.
- An unchanged entry (the `from` equals the `to`) produces **no diff entry at
  all** — e.g. `remove_item` clamped at `0` when the item was already absent,
  or `set_flag` on an already-set flag, shows nothing for that key.
- `EffectsApplied` is emitted **once per phase** (the chosen choice's effects,
  then the target scene's `on_enter`) and is **skipped entirely when the diff
  is empty**, so a single turn can legitimately produce zero, one, or two
  `EffectsApplied` events.
- `visited` can never appear non-empty inside an `EffectsApplied` payload
  today: no effect operator writes `visited`, and `Transition` updates it
  (via `withVisit()`) outside both effect phases. The category exists anyway
  because `StateDiff` is a general-purpose state diff.

## Events and the turn result

```json
{"event": "choice_made",     "scene": "crossroads", "choice": "north", "turn": 7}
{"event": "effects_applied", "scene": "crossroads", "phase": "choice",   "diff": {}, "turn": 7}
{"event": "effects_applied", "scene": "cave",       "phase": "on_enter", "diff": {}, "turn": 7}
{"event": "scene_entered",   "scene": "cave",       "redirects": 0, "turn": 7}
{"event": "ending_reached",  "scene": "the_end",    "turn": 7}
```

Event order is fixed: `choice_made`, then zero or one `effects_applied` with
`phase: "choice"`, then zero or more `effects_applied` with `phase:
"on_enter"` in redirect order, then exactly one `scene_entered`, then —
only when the resting scene is an ending — `ending_reached`. No event carries
a timestamp; `turn` is the caller's own counter, and the Engine never reads a
clock.

```json
{
  "state":     {"v": 1, "vars": {}, "flags": [], "inventory": {}, "visited": {}, "history": []},
  "scene":     "cave",
  "is_ending": false,
  "events":    []
}
```

## Error behaviour

Every exception the Engine raises extends the abstract
`Storyteller\Engine\Exception\EngineException` (which extends
`\RuntimeException`), so a caller can catch one type to catch the lot. The
concrete classes, all under `src/Engine/Exception/`:

| Exception | Raised when |
|---|---|
| `MalformedNodeException` | A structural problem in an authored array: a required key is missing (`missingKey`); a present value is the wrong PHP/JSON type (`wrongType`) — including an empty `of` list for `and`/`or`, reported as `wrongType(..., 'non-empty list', 'empty list')`; a key is present that nobody declared (`unknownKey`); the same name is declared twice in a collection keyed by it — a duplicate variable name, a duplicate choice key (`duplicateKey`); `qty`/`times` is present but not a positive int (`notAPositiveInt`); a `GameState` envelope's `v` is not the version the Engine understands (`unsupportedStateVersion`). |
| `UnknownOperatorException` | A node's `op` string is not one of the declared `ConditionOp` (`condition()`) or `EffectOp` (`effect()`) cases. Also raised defensively by the evaluator and applier if they are ever handed a `Condition`/`Effect` subclass they have no case for — the AST hierarchy is open, not sealed, so this is reachable by construction even though `fromArray()` can never produce such a node. |
| `UnknownVariableException` | A variable name is used (in a condition, an effect, or `GameState::var()`) that `VariableSchema` does not declare — even if the state happens to carry a value under that name. |
| `TypeMismatchException` | An evaluation-time type violation: a literal does not match a variable's declared type (`value()`), or an ordering (`lt`/`lte`/`gt`/`gte`) or arithmetic (`add`/`sub`) operator is applied to a variable whose declared type is not numeric (`notNumeric()`). Also raised when `add`/`sub` would push an `int` variable's value or its underlying PHP type outside what the declaration accepts (e.g. a fractional step, or an overflow into `float`), and when a `VariableDefinition`'s own `default` does not fit its declared `type`. |
| `UnknownSceneException` | A scene key the `StoryGraph` does not know is asked for — the current scene at the start of a transition, or any scene reached via `next` or a `goto` redirect. |
| `ChoiceUnavailableException` | The named choice does not exist on the current scene (`notFound()`), or it exists but its `condition` evaluates to `false` (`conditionFailed()`). The two are **not** distinguished by their message: every instance carries `public readonly ChoiceUnavailableReason $reason`, either `NotFound` (`"not_found"`) or `ConditionFailed` (`"condition_failed"`). They are different answers at an HTTP boundary — a client cannot retry its way out of the first, while the second is a state conflict and legitimately racy — so a caller branches on `$reason`, never on the prose. |
| `NoNextSceneException` | A scene's `next` rule list (including an empty one) ran out with no rule matching and no unconditional fallback present. |
| `RedirectLoopException` | A single `Transition::run()` spent more than `Transition::MAX_REDIRECTS` (5) redirects. Carries the limit and the last scene reached. |

Structural errors (`MalformedNodeException`, `UnknownOperatorException` from
a bad `op` string) are parse-time: they fire while compiling authored content
into AST nodes, before any `GameState` is involved. Type and resolution
errors (`TypeMismatchException`, `UnknownVariableException`,
`ChoiceUnavailableException`, `UnknownSceneException`, `NoNextSceneException`,
`RedirectLoopException`) are evaluation-time: they fire while a `Transition`
is running against a real state and schema.

## The RNG forward contract (not an operator)

`Storyteller\Engine\Random\Rng` and its implementation `SeededRng` exist as a
deliberate forward contract for a future `chance` condition or `roll` effect
— `Transition::run()` already threads an `int $turn` for exactly this reason.
**No condition or effect operator documented above uses it**; it is wired
into nothing in this feature. `intBetween(int $turn, int $index, int $min,
int $max)` and `fraction(int $turn, int $index)` are pure functions of
`(seed, turn, index)` rather than a stateful generator, so an Engine that must
never hold mutable state can offer determinism without special-casing
randomness.
