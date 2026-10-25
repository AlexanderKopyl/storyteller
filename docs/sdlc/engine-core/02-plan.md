# 02 — Implementation plan: engine-core

- **Feature slug:** `engine-core`
- **Based on:** `docs/sdlc/engine-core/01-analysis.md` (Gate A passed)
- **Date:** 2026-10-25

## Approach

Build `src/Engine` bottom-up in sixteen commits, each one layer of the dependency graph
inside the Engine: coverage wiring, exceptions, the `mixed`-narrowing reader, the variable
schema, state, the condition AST, the effect AST, the evaluator, the applier, the
resolver, the graph contract, the events, `Transition`, the cross-cutting guard tests, the
RNG, and the AST spec. Each step lands a test file first and the production file second
(standing rule 2), and each leaves `cs-check`, `phpstan`, `deptrac` and `test` green.

Coverage wiring is step 1 on purpose: PHPUnit without a driver does not fail, it prints a
notice and exits 0, so a 100 %-line criterion bolted on at the end would never have been
falsifiable. Putting it first makes every subsequent Engine commit measurable the moment it
lands.

Two properties drive the shape. First, the serialized forms — the condition/effect AST, the
`GameState` array and the `StoryGraph` node shapes — are **frozen cross-context contracts**
the moment `src/Content` compiles into them and `src/Play` persists them, so every key name,
default and error case is written out literally in this document rather than described.
Second, `src/Engine` gets **zero outgoing dependencies**: the exception family stays inside
Engine (OQ-4), `src/Shared` is untouched (OQ-3), and no vendor class, no clock and no global
is imported. That is what makes `composer deptrac` mean something for the first time.

### Gate A decisions carried in as fixed input

All fourteen open questions are settled. OQ-2 is **(a), already implemented and verified by
the user** — PCOV 1.0.12 is installed in the dev image and `php --ri pcov` reports it
Enabled. **The `Dockerfile` is not touched by this plan.** All other questions take the
analysis's recommended default. Three of them were accepted without being individually
confirmed and are restated as labelled decisions below so Gate B can correct them cheaply:
**D-DIFF** (OQ-6), **D-REDIRECT** (OQ-8) and **D-VISIT** (OQ-9b).

### Plan-level decisions (made here so the implementer does not have to)

- **P1 — services live at the `Storyteller\Engine\` root; data lives in sub-namespaces.**
  `ConditionEvaluator`, `EffectApplier`, `EffectResult`, `NextSceneResolver`, `Transition`
  and `TransitionResult` sit directly under `src/Engine/`. Everything else is grouped:
  `Exception/`, `Support/`, `State/`, `Ast/`, `Graph/`, `Event/`, `Random/`. Subdirectories
  under `src/Engine/` are safe — the deptrac collector is `src/Engine/.*` and
  `BoundedContextLayoutTest::CONTEXT_DIRECTORIES` only requires `src/Engine` itself to exist.
- **P2 — `final readonly class` everywhere, with a `private` constructor wherever a
  canonical invariant must hold.** A promoted readonly property cannot be re-assigned in the
  constructor body (R5), so `GameState` and `VariableSchema` take already-canonical arrays
  through a private constructor and expose named static factories that canonicalise. `with*`
  methods build `new self(...)`; no `__clone()` reinitialisation anywhere, because
  `new self(...)` is the more obviously deterministic of the two.
- **P3 — the schema is authoritative for variable resolution, and resolution lives in one
  place:** `VariableSchema::resolve(string $name, GameState $state)`. A name absent from the
  schema throws `UnknownVariableException` **even if the state carries a value**; a name in
  the schema but absent from the state returns the schema default, never `null`. Both the
  evaluator and the applier call this one method, so AC-15's "resolves to the schema default"
  has exactly one implementation to be right about.
- **P4 — `GameState::withVisit()` updates `visited` and `history` together and is the only
  writer of either.** R12's divergence risk is removed structurally rather than by a test.
  `Transition` is the only caller.
- **P5 — `GameState::initial()` takes no schema and returns five empty collections.** There
  is exactly one notion of "default" in the Engine — the schema, consulted at read time
  (P3) — so a second one seeded into the state at construction cannot drift from it. Matches
  OQ-9c: the start scene is not marked visited.
- **P6 — all JSON AST and state keys are `snake_case`**, matching the operator names
  (`set_flag`, `has_item`). So `on_enter`, not `onEnter`; `is_ending`, not `isEnding`. This
  is a frozen format decision, not a style preference — changing it after the first published
  `story_version` is a JSONB data migration.
- **P7 — `fromArray()` rejects unknown keys.** Any key outside an op's allowed set throws
  `MalformedNodeException`. A typo in authored YAML then fails at compile time in Content
  instead of silently doing nothing at play time. Cost: one `rejectUnknownKeys()` call per
  node class.
- **P8 — structural validation is parse-time, type validation is evaluation-time** (OQ-5's
  split, applied consistently). `fromArray` checks shape only: required keys present, `qty` /
  `times` a positive int, `value` a scalar (or a number for `add`/`sub`). Whether the literal
  matches the *variable's declared type* is checked by the evaluator/applier against the
  schema, because the schema need not be in hand when the AST is parsed.
- **P9 — `SceneNode` has no `is_ending` flag.** `StoryGraph::isEnding()` is the only source,
  so the two cannot diverge (the R12 lesson applied again). `StoryGraph` keeps exactly the
  three methods the analysis names.
- **P10 — `SceneNode::fromArray()` / `toArray()` and `ChoiceNode::fromArray()` /
  `toArray()` ship.** The analysis only requires that the compiled snapshot *contain* shapes
  the Engine accepts; shipping the readers puts the entire snapshot format in one place and
  leaves Content with nothing to invent. Two small methods each, fully coverable.
- **P11 — every Engine test class carries `#[CoversClass]`** (or `#[CoversNothing]` for the
  two scanning guards). `requireCoverageMetadata` stays `false`, so this is not enforced by
  config — but with `#[CoversClass]` present PHPUnit attributes coverage **only** to the
  listed classes, which makes the 100 % figure mean "directly covered by its own test"
  rather than "incidentally executed". That is the stronger reading of AC-21 and it is the
  reason every production class below has a named test file. A test may list more than one
  `#[CoversClass]` where a class has no standalone behaviour (the AST node classes).
- **P12 — `Rng` is index-addressed and pure, not a stateful generator.** A stateful
  generator cannot be `readonly` and would put mutable state inside the Engine. Instead
  `intBetween(int $turn, int $index, int $min, int $max)` is a pure function of
  `(seed, turn, index)`. This keeps `SeededRng` `final readonly`, satisfies "no static mutable
  state" literally, and makes the OQ-13 property tests trivially non-flaky.
- **P13 — no PHP-8.5-exclusive syntax.** No pipe operator, no `#[\NoDiscard]`, no attributes
  on constants. Typed class constants (8.3) are used, following the precedent already set by
  `BoundedContextLayoutTest::CONTEXT_DIRECTORIES`.

### Three decisions accepted by default — flagged explicitly for Gate B

These were accepted as part of "all other open questions take the recommended defaults"
without being confirmed individually. Each is cheap to change now and expensive later.

**D-DIFF (OQ-6) — `EffectsApplied.diff` is a structural, category-keyed before/after diff.**
It is computed by `StateDiff::between($before, $after)` — a post-hoc O(size of state)
comparison, not an incremental log of applied nodes. It therefore records *outcomes*, not
*attempts*: a `remove_item` clamped at 0 shows `{"from":0,"to":0}`… in fact shows nothing,
because an unchanged entry produces no diff entry at all. Four categories, emitted in the
fixed order `vars`, `flags`, `inventory`, `visited`; **empty categories are omitted
entirely**, and within `flags`, an empty `added` or `removed` is omitted too. The event is
emitted **once per phase** (choice effects, then target `on_enter`) and **skipped when the
diff is empty**, so a turn can legitimately produce zero, one or two `EffectsApplied` events.
*Consequence worth seeing:* `visited` can never be non-empty in an `EffectsApplied` payload
today, because no effect operator writes it and `Transition` updates `visited` outside both
phases. The category is kept anyway — `StateDiff` is a general state diff that Play will
want, and `StateDiffTest` covers the `visited` branch directly, so it is not dead code under
the coverage bar.

**D-REDIRECT (OQ-8) — one counter per `Transition::run()`, limit 5, the choice-effect
`goto` included.** `Transition::MAX_REDIRECTS = 5`. The counter starts at 0; a `goto` in the
choice effects sets it to 1; every `goto` in a target scene's `on_enter` increments it. The
guard runs after every increment: `if ($redirects > self::MAX_REDIRECTS) throw
RedirectLoopException`. So five real redirects complete normally and the sixth throws. The
"allowed once more" phrasing in the original request is dropped as superseded — there is one
limit, not a limit of 1 with a guard of 5 behind it. There is no cycle detection: a legitimate
A→B→A re-entry inside the budget is allowed. Tests exist at exactly 5 (passes) and exactly 6
(throws), and `SceneEntered::$redirects` carries the final count so the budget is observable
in the event log rather than only in a thrown exception.

**D-VISIT (OQ-9b) — `visited` is incremented *after* the target scene's `on_enter` effects
run, and the surprising consequence is deliberate.** The order inside `Transition` is:
apply `on_enter` → resolve any redirect → (once settled) `withVisit(target)` → emit
`SceneEntered`. Therefore **`{"op":"visited","scene":"X","times":1}` evaluated inside scene
X's own `on_enter` is `false` on the first entry** and `true` on every later entry. An author
writing "first time here" inside `on_enter` writes `{"op":"not","of":{"op":"visited",
"scene":"X","times":1}}` and it works — which is the whole point, and is unanswerable under
the alternative (increment before `on_enter`). This must be stated in `docs/engine/ast.md`
on the `visited` operator, and `TransitionTest` pins it with a scene whose `on_enter`
branches on its own visit count across two consecutive runs.
*Two sub-decisions that belong to this plan rather than to the user, flagged for the same
review:* (i) **a scene that redirects out of its own `on_enter` is not marked visited and
does not append to `history`** — it was transited, not entered; its `EffectsApplied` is still
emitted because those effects really happened. (ii) **`SceneEntered` is emitted only for the
final resting scene**, once per `Transition::run()`, never for an intermediate redirect hop.

## Files per bounded context

### Shared

| Action | Path | Purpose |
|--------|------|---------|
| — | — | **No change (OQ-3).** `src/Shared` stays empty, so the `Engine` layer ends this feature with zero outgoing layer-crossing dependencies. |

### Engine

49 new production files, all under `src/Engine/**`. The step that lands each one is in the
last column and matches the ordered step list below.

| Action | Path | Purpose | Step |
|--------|------|---------|------|
| create | `src/Engine/Exception/EngineException.php` | `abstract class EngineException extends \RuntimeException` — the Engine's only error root (OQ-4); keeps the layer dependency-free. | 2 |
| create | `src/Engine/Exception/MalformedNodeException.php` | Structural AST / state-array errors: missing key, wrong PHP type, unknown key (P7), bad `v`. | 2 |
| create | `src/Engine/Exception/UnknownOperatorException.php` | An `op` string that is not a `ConditionOp` / `EffectOp` case. | 2 |
| create | `src/Engine/Exception/UnknownVariableException.php` | A variable name absent from `VariableSchema` (P3). | 2 |
| create | `src/Engine/Exception/TypeMismatchException.php` | Evaluation-time type violations (OQ-5a, OQ-5b). | 2 |
| create | `src/Engine/Exception/UnknownSceneException.php` | A scene key the `StoryGraph` does not know (OQ-10). | 2 |
| create | `src/Engine/Exception/ChoiceUnavailableException.php` | Choice absent from the scene, or present with a false condition. | 2 |
| create | `src/Engine/Exception/NoNextSceneException.php` | A `next` rule list with no match and no fallback, or an empty one. | 2 |
| create | `src/Engine/Exception/RedirectLoopException.php` | The D-REDIRECT budget exceeded. | 2 |
| create | `src/Engine/Support/ArrayReader.php` | The single `mixed`-narrowing reader every `fromArray()` goes through (R4). See *PHPStan strategy*. | 3 |
| create | `src/Engine/State/VariableType.php` | `enum VariableType: string` — `Int`, `Float`, `String`, `Bool`. | 4 |
| create | `src/Engine/State/VariableDefinition.php` | One variable: name, type, default. | 4 |
| create | `src/Engine/State/VariableSchema.php` | Key-sorted map of definitions; owns type assertion and variable resolution (P3). | 4 |
| create | `src/Engine/State/GameState.php` | The immutable five-field state with canonical ordering (OQ-12) and `v: 1` serialization (OQ-11). | 5 |
| create | `src/Engine/State/StateDiff.php` | The D-DIFF payload type. | 5 |
| create | `src/Engine/Ast/ConditionOp.php` | `enum ConditionOp: string` — the 13 condition ops. | 6 |
| create | `src/Engine/Ast/Condition.php` | `abstract readonly class Condition` + the `fromArray()` dispatcher. | 6 |
| create | `src/Engine/Ast/Comparison.php` | `eq`, `neq`, `lt`, `lte`, `gt`, `gte`. | 6 |
| create | `src/Engine/Ast/FlagCheck.php` | `flag`, `not_flag`. | 6 |
| create | `src/Engine/Ast/HasItem.php` | `has_item`. | 6 |
| create | `src/Engine/Ast/Visited.php` | `visited`. | 6 |
| create | `src/Engine/Ast/LogicalAnd.php` | `and`. (`And` is a reserved word and cannot be a class name.) | 6 |
| create | `src/Engine/Ast/LogicalOr.php` | `or`. | 6 |
| create | `src/Engine/Ast/LogicalNot.php` | `not`. | 6 |
| create | `src/Engine/Ast/EffectOp.php` | `enum EffectOp: string` — the 8 effect ops. | 7 |
| create | `src/Engine/Ast/Effect.php` | `abstract readonly class Effect` + the `fromArray()` dispatcher. | 7 |
| create | `src/Engine/Ast/SetVar.php` | `set`. | 7 |
| create | `src/Engine/Ast/Arithmetic.php` | `add`, `sub`. | 7 |
| create | `src/Engine/Ast/FlagEffect.php` | `set_flag`, `clear_flag`. | 7 |
| create | `src/Engine/Ast/ItemEffect.php` | `give_item`, `remove_item`. | 7 |
| create | `src/Engine/Ast/GoToScene.php` | `goto`. (`Goto` is a reserved word.) | 7 |
| create | `src/Engine/ConditionEvaluator.php` | Evaluates a `Condition` against a `GameState`. | 8 |
| create | `src/Engine/EffectApplier.php` | Applies a `list<Effect>`, returns `EffectResult`. | 9 |
| create | `src/Engine/EffectResult.php` | New state + optional `redirectTo` + `StateDiff`. | 9 |
| create | `src/Engine/Graph/NextRule.php` | One `next` rule: target scene + optional guard condition. | 10 |
| create | `src/Engine/NextSceneResolver.php` | `string\|list<NextRule>` → the resolved scene key. | 10 |
| create | `src/Engine/Graph/ChoiceNode.php` | Authored choice: key, optional condition, effects. | 11 |
| create | `src/Engine/Graph/SceneNode.php` | Authored scene: key, `on_enter`, choices, `next`. | 11 |
| create | `src/Engine/Graph/StoryGraph.php` | **The cross-context interface.** Exactly three methods; implemented by Content later, never by production code in this feature. | 11 |
| create | `src/Engine/Event/EngineEvent.php` | `interface EngineEvent` — `name()` + `toArray()`. | 12 |
| create | `src/Engine/Event/EffectPhase.php` | `enum EffectPhase: string` — `Choice`, `OnEnter` (D-DIFF). | 12 |
| create | `src/Engine/Event/ChoiceMade.php` | Emitted once, after the choice is validated. | 12 |
| create | `src/Engine/Event/EffectsApplied.php` | Emitted per non-empty phase (D-DIFF). | 12 |
| create | `src/Engine/Event/SceneEntered.php` | Emitted once, for the final resting scene (D-VISIT ii). | 12 |
| create | `src/Engine/Event/EndingReached.php` | Emitted only when `StoryGraph::isEnding()` is true. | 12 |
| create | `src/Engine/Transition.php` | The orchestrator. Owns D-REDIRECT and D-VISIT. | 13 |
| create | `src/Engine/TransitionResult.php` | State + resting scene + `isEnding` + ordered events. | 13 |
| create | `src/Engine/Random/Rng.php` | `interface Rng` — the deliberate forward contract (OQ-13). | 15 |
| create | `src/Engine/Random/SeededRng.php` | Pure, index-addressed implementation over `Random\Randomizer` (P12). | 15 |

`src/Engine/.gitkeep` and `tests/Unit/Engine/.gitkeep` are **left in place**; removing them
is an unnecessary change and neither tool sees them.

### Content

| Action | Path | Purpose |
|--------|------|---------|
| — | — | **No file created or changed.** `StoryGraph`, `SceneNode`, `ChoiceNode`, `NextRule`, `Condition::fromArray()` and `Effect::fromArray()` become the contract Content must satisfy in a later feature. `Content -> Engine` is already an allowed deptrac edge. |

### Play

| Action | Path | Purpose |
|--------|------|---------|
| — | — | **No file created or changed.** `GameState::toArray()` / `fromArray()` define the future `play_session` state column; `TransitionResult` and the four events define the event-log payloads. `Play -> Engine` is already allowed. |

### Api

| Action | Path | Purpose |
|--------|------|---------|
| — | — | **No file created or changed.** deptrac deliberately has no `Api -> Engine` edge; nothing here assumes one. |

### Root and tooling (no bounded context)

| Action | Path | Purpose | Step |
|--------|------|---------|------|
| change | `docker/php/php.ini` | Pin `pcov.enabled` and `pcov.directory` explicitly instead of relying on PCOV's auto-detection. | 1 |
| change | `composer.json` | Add the `coverage` script and its `scripts-descriptions` entry. **`qa` is not changed.** | 1 |
| change | `phpunit.xml` | Add the `<coverage>` element; `<source>` stays `src` (see step 1). | 1 |
| change | `Makefile` | Add the `coverage` target and extend `.PHONY`. | 1 |
| create | `tests/Unit/Engine/**` | 24 test files + 1 test double — listed in *Tests to write first*. | 2–15 |
| create | `docs/engine/ast.md` | The AST spec CLAUDE.md's domain-model rule points at (OQ-14, R13). | 16 |

**`.gitignore` is deliberately not changed.** Line 2 is `/var/`, which already ignores
`var/coverage`. Adding a redundant entry would be noise; this deviates from the Gate A
instruction only in that the outcome it asked for is already true. Verified by reading the
file.

## Public interfaces

Final signatures. Everything is `declare(strict_types=1)`, namespace as shown, `final`
unless stated otherwise. Where a docblock type is given it is **required** — it is what
carries PHPStan level 8.

### Exceptions (step 2)

```php
namespace Storyteller\Engine\Exception;

abstract class EngineException extends \RuntimeException {}

final class MalformedNodeException extends EngineException
{
    public static function missingKey(string $path, string $key): self;
    public static function wrongType(string $path, string $key, string $expected, string $actual): self;
    public static function unknownKey(string $path, string $key): self;
    public static function notAPositiveInt(string $path, string $key): self;
    public static function unsupportedStateVersion(int $version, int $supported): self;
}

final class UnknownOperatorException extends EngineException
{
    public static function condition(string $op): self;
    public static function effect(string $op): self;
}

final class UnknownVariableException extends EngineException
{
    public static function named(string $name): self;
}

final class TypeMismatchException extends EngineException
{
    public static function value(string $variable, VariableType $expected, string $actual): self;
    public static function notNumeric(string $variable, VariableType $actual, string $op): self;
}

final class UnknownSceneException extends EngineException
{
    public static function named(string $sceneKey): self;
}

final class ChoiceUnavailableException extends EngineException
{
    public static function notFound(string $sceneKey, string $choiceKey): self;
    public static function conditionFailed(string $sceneKey, string $choiceKey): self;
}

final class NoNextSceneException extends EngineException
{
    public static function noRuleMatched(string $sceneKey): self;
}

final class RedirectLoopException extends EngineException
{
    public static function exceeded(int $limit, string $lastScene): self;
}
```

Two named constructors on `ChoiceUnavailableException` rather than two classes: AC-16 lists
"unavailable choice" once, AC-19 lists both situations, and the message distinguishes them.
Both are tested. `TypeMismatchException` imports `VariableType` from
`Storyteller\Engine\State` — an intra-Engine, intra-layer dependency, allowed by deptrac.

### `Support/ArrayReader` (step 3)

```php
namespace Storyteller\Engine\Support;

final readonly class ArrayReader
{
    /** @param array<array-key, mixed> $data */
    public function __construct(private array $data, private string $path) {}

    public function requireString(string $key): string;
    public function requireInt(string $key): int;
    public function requirePositiveInt(string $key): int;
    public function optionalPositiveInt(string $key, int $default): int;
    public function requireScalar(string $key): int|float|string|bool;
    public function requireNumber(string $key): int|float;

    /** @return array<array-key, mixed> */
    public function requireArray(string $key): array;

    /** @return array<array-key, mixed>|null */
    public function optionalArray(string $key): ?array;

    /** @return list<array<array-key, mixed>> */
    public function requireArrayList(string $key): array;

    /** @return list<array<array-key, mixed>> */
    public function optionalArrayList(string $key, array $default = []): array;

    /** @return list<string> */
    public function requireStringList(string $key): array;

    /** @return array<string, int> */
    public function requireIntMap(string $key): array;

    /** @return array<string, int|float|string|bool> */
    public function requireScalarMap(string $key): array;

    public function has(string $key): bool;

    public function rejectUnknownKeys(string ...$allowed): void;

    /** Nested reader for a child object; the path is extended for error messages. */
    public function child(string $key): self;
}
```

Every method either returns a precisely typed value or throws
`MalformedNodeException` naming `$path` and `$key`. `$path` is a human-readable breadcrumb
such as `condition.of[1]`, built by `child()` and by the AST dispatchers.

### `State/` (steps 4 and 5)

```php
namespace Storyteller\Engine\State;

enum VariableType: string
{
    case Int = 'int';
    case Float = 'float';
    case String = 'string';
    case Bool = 'bool';

    public function accepts(int|float|string|bool $value): bool;
    public function isNumeric(): bool;   // true for Int and Float only
}
```

Reserved words are legal as enum case names (cases are class constants). If any tool
objects, the fallback is a pure rename to `IntType` / `FloatType` / `StringType` /
`BoolType` — report it, do not change the backing values.

```php
final readonly class VariableDefinition
{
    public function __construct(
        public string $name,
        public VariableType $type,
        public int|float|string|bool $default,
    ) {}   // throws TypeMismatchException if $type does not accept $default

    /** @param array<array-key, mixed> $data */
    public static function fromArray(string $name, array $data): self;

    /** @return array{type: string, default: int|float|string|bool} */
    public function toArray(): array;
}

final readonly class VariableSchema
{
    /** @param array<string, VariableDefinition> $byName key-sorted, already canonical */
    private function __construct(private array $byName) {}

    /** @param list<VariableDefinition> $definitions */
    public static function of(array $definitions): self;   // canonicalises; duplicate name => MalformedNodeException
    public static function empty(): self;

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self;

    /** @return array<string, array{type: string, default: int|float|string|bool}> */
    public function toArray(): array;

    public function has(string $name): bool;
    public function typeOf(string $name): VariableType;                 // throws UnknownVariableException
    public function defaultOf(string $name): int|float|string|bool;     // throws UnknownVariableException

    /** P3: the single variable-resolution point. */
    public function resolve(string $name, GameState $state): int|float|string|bool;

    /** throws UnknownVariableException or TypeMismatchException */
    public function assertAccepts(string $name, int|float|string|bool $value): void;

    /** @return list<string> sorted */
    public function names(): array;
}
```

```php
final readonly class GameState
{
    public const int VERSION = 1;

    /**
     * @param array<string, int|float|string|bool> $vars      key-sorted (SORT_STRING)
     * @param list<string>                         $flags     sorted + de-duplicated
     * @param array<string, int>                   $inventory key-sorted, every value >= 1
     * @param array<string, int>                   $visited   key-sorted, every value >= 1
     * @param list<string>                         $history   insertion order, append-only
     */
    private function __construct(
        public array $vars,
        public array $flags,
        public array $inventory,
        public array $visited,
        public array $history,
    ) {}

    public static function initial(): self;   // five empty collections (P5, OQ-9c)

    /**
     * @param array<string, int|float|string|bool> $vars
     * @param list<string>                         $flags
     * @param array<string, int>                   $inventory
     * @param array<string, int>                   $visited
     * @param list<string>                         $history
     */
    public static function create(array $vars, array $flags, array $inventory, array $visited, array $history): self;

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self;

    /**
     * @return array{
     *     v: int,
     *     vars: array<string, int|float|string|bool>,
     *     flags: list<string>,
     *     inventory: array<string, int>,
     *     visited: array<string, int>,
     *     history: list<string>
     * }
     */
    public function toArray(): array;

    public function hasVar(string $name): bool;
    public function var(string $name): int|float|string|bool;   // throws UnknownVariableException when absent
    public function hasFlag(string $name): bool;
    public function itemQty(string $item): int;                 // 0 when absent
    public function visitCount(string $scene): int;             // 0 when absent

    public function withVar(string $name, int|float|string|bool $value): self;
    public function withFlag(string $name): self;      // no-op-equivalent new instance if already set
    public function withoutFlag(string $name): self;
    public function withItem(string $item, int $qty): self;   // $qty <= 0 removes the key entirely
    public function withVisit(string $scene): self;           // P4: increments visited AND appends to history
}
```

`withVar` does not consult a schema — `GameState` has none. Schema enforcement is the
applier's job (`VariableSchema::assertAccepts`), which keeps the state type free of a
dependency it cannot always satisfy.

**Canonicalisation, pinned exactly:** `ksort($map, SORT_STRING)` for `vars`, `inventory`
and `visited` — `SORT_STRING` not the default `SORT_REGULAR`, because the default compares
numeric-looking keys numerically and would make the ordering depend on the key *contents*.
Flags: `$flags = array_values(array_unique($flags)); sort($flags, SORT_STRING);`. `history`
is never reordered.

```php
final readonly class StateDiff
{
    /**
     * @param array<string, array{from: int|float|string|bool, to: int|float|string|bool}> $vars
     * @param array{added: list<string>, removed: list<string>}                            $flags
     * @param array<string, array{from: int, to: int}>                                     $inventory
     * @param array<string, array{from: int, to: int}>                                     $visited
     */
    private function __construct(
        public array $vars,
        public array $flags,
        public array $inventory,
        public array $visited,
    ) {}

    public static function between(GameState $before, GameState $after): self;
    public static function empty(): self;
    public function isEmpty(): bool;

    /** @return array<string, mixed> non-empty categories only, in the order vars, flags, inventory, visited */
    public function toArray(): array;
}
```

### `Ast/` — conditions (step 6)

```php
namespace Storyteller\Engine\Ast;

enum ConditionOp: string
{
    case Eq = 'eq';
    case Neq = 'neq';
    case Lt = 'lt';
    case Lte = 'lte';
    case Gt = 'gt';
    case Gte = 'gte';
    case Flag = 'flag';
    case NotFlag = 'not_flag';
    case HasItem = 'has_item';
    case Visited = 'visited';
    case AndOp = 'and';
    case OrOp = 'or';
    case NotOp = 'not';

    public function isComparison(): bool;   // Eq..Gte
    public function isOrdering(): bool;     // Lt, Lte, Gt, Gte  -> numeric-only (OQ-5a)
}

abstract readonly class Condition
{
    abstract public function op(): ConditionOp;

    /** @return array<string, mixed> */
    abstract public function toArray(): array;

    /** @param array<array-key, mixed> $node */
    final public static function fromArray(array $node, string $path = 'condition'): self;
}
```

`fromArray` reads `op` as a string, maps it through `ConditionOp::tryFrom()` (null =>
`UnknownOperatorException::condition()`), then delegates to the matching concrete class's
own `private static function parse(ArrayReader $r, ConditionOp $op): self`. The seven
concrete classes:

```php
final readonly class Comparison extends Condition
{
    public function __construct(
        private ConditionOp $op,         // Eq | Neq | Lt | Lte | Gt | Gte
        public string $var,
        public int|float|string|bool $value,
    ) {}
}

final readonly class FlagCheck extends Condition
{
    public function __construct(private ConditionOp $op, public string $flag) {}   // Flag | NotFlag
}

final readonly class HasItem extends Condition
{
    public function __construct(public string $item, public int $qty = 1) {}
}

final readonly class Visited extends Condition
{
    public function __construct(public string $scene, public int $times = 1) {}
}

final readonly class LogicalAnd extends Condition
{
    /** @param non-empty-list<Condition> $of */
    public function __construct(public array $of) {}
}

final readonly class LogicalOr extends Condition
{
    /** @param non-empty-list<Condition> $of */
    public function __construct(public array $of) {}
}

final readonly class LogicalNot extends Condition
{
    public function __construct(public Condition $of) {}
}
```

An empty `of` list for `and` / `or` is `MalformedNodeException` — there is no sensible
identity element to pick, and silently returning `true` for an empty `and` is the kind of
hidden policy standing rule 4's spirit rejects.

### `Ast/` — effects (step 7)

```php
enum EffectOp: string
{
    case Set = 'set';
    case Add = 'add';
    case Sub = 'sub';
    case SetFlag = 'set_flag';
    case ClearFlag = 'clear_flag';
    case GiveItem = 'give_item';
    case RemoveItem = 'remove_item';
    case GoTo = 'goto';

    public function isArithmetic(): bool;   // Add, Sub
}

abstract readonly class Effect
{
    abstract public function op(): EffectOp;

    /** @return array<string, mixed> */
    abstract public function toArray(): array;

    /** @param array<array-key, mixed> $node */
    final public static function fromArray(array $node, string $path = 'effect'): self;

    /**
     * @param  list<array<array-key, mixed>> $nodes
     * @return list<Effect>
     */
    final public static function listFromArray(array $nodes, string $path): array;
}

final readonly class SetVar extends Effect
{
    public function __construct(public string $var, public int|float|string|bool $value) {}
}

final readonly class Arithmetic extends Effect
{
    public function __construct(private EffectOp $op, public string $var, public int|float $value) {}
}

final readonly class FlagEffect extends Effect
{
    public function __construct(private EffectOp $op, public string $flag) {}
}

final readonly class ItemEffect extends Effect
{
    public function __construct(private EffectOp $op, public string $item, public int $qty = 1) {}
}

final readonly class GoToScene extends Effect
{
    public function __construct(public string $scene) {}
}
```

`Condition::listFromArray()` is not needed (no condition list is authored outside `of`),
but `Effect::listFromArray()` is, because `on_enter` and `choices[].effects` are both lists.

### `Graph/` (steps 10 and 11)

```php
namespace Storyteller\Engine\Graph;

final readonly class NextRule
{
    public function __construct(public string $scene, public ?Condition $if = null) {}

    public function isFallback(): bool;   // $if === null

    /** @param array<array-key, mixed> $node */
    public static function fromArray(array $node, string $path = 'next'): self;

    /**
     * @param  list<array<array-key, mixed>> $nodes
     * @return list<NextRule>
     */
    public static function listFromArray(array $nodes, string $path = 'next'): array;

    /** @return array{scene: string, if?: array<string, mixed>} */
    public function toArray(): array;
}

final readonly class ChoiceNode
{
    /** @param list<Effect> $effects */
    public function __construct(
        public string $key,
        public ?Condition $condition = null,
        public array $effects = [],
    ) {}

    public function isAlwaysAvailable(): bool;   // $condition === null

    /** @param array<array-key, mixed> $node */
    public static function fromArray(array $node, string $path = 'choice'): self;

    /** @return array{key: string, condition?: array<string, mixed>, effects: list<array<string, mixed>>} */
    public function toArray(): array;
}

final readonly class SceneNode
{
    /**
     * @param list<Effect>           $onEnter
     * @param list<ChoiceNode>       $choices
     * @param string|list<NextRule>  $next   an empty list means "no next" (ending or dead end)
     */
    public function __construct(
        public string $key,
        public array $onEnter = [],
        public array $choices = [],
        public string|array $next = [],
    ) {}

    public function hasChoice(string $choiceKey): bool;
    public function choice(string $choiceKey): ChoiceNode;   // throws ChoiceUnavailableException::notFound

    /** @param array<array-key, mixed> $node */
    public static function fromArray(array $node, string $path = 'scene'): self;

    /**
     * @return array{
     *     key: string,
     *     on_enter: list<array<string, mixed>>,
     *     choices: list<array<string, mixed>>,
     *     next: string|list<array<string, mixed>>
     * }
     */
    public function toArray(): array;
}

/**
 * THE cross-context interface. Implemented by src/Content in a later feature; the only
 * implementation created here is InMemoryStoryGraph, under tests/ (AC-10).
 */
interface StoryGraph
{
    public function hasScene(string $sceneKey): bool;

    /** @throws UnknownSceneException */
    public function scene(string $sceneKey): SceneNode;

    /** @throws UnknownSceneException */
    public function isEnding(string $sceneKey): bool;
}
```

Duplicate choice keys inside one scene are `MalformedNodeException` at parse time —
`choice()` would otherwise be ambiguous.

`next` as a union rather than a normalised `list<NextRule>` is deliberate: AC-17 requires
"a plain `string` resolves to itself" to be literally true, and the union is the authored
shape Content will compile to. The branch is two lines in the resolver and is tested.

### Services (steps 8, 9, 10, 13)

```php
namespace Storyteller\Engine;

final readonly class ConditionEvaluator
{
    public function __construct(private VariableSchema $schema) {}

    public function evaluate(Condition $condition, GameState $state): bool;
}
```

Semantics, pinned:

- `eq` / `neq` — `$schema->assertAccepts($var, $literal)` first (so comparing an `int`
  variable against `1.0` is a `TypeMismatchException`, not a silent `false`), then `===` /
  `!==` on the resolved value.
- `lt` / `lte` / `gt` / `gte` — `assertAccepts` first, then **`$schema->typeOf($var)->isNumeric()`
  or `TypeMismatchException::notNumeric()`** (OQ-5a: `string` and `bool` are rejected), then
  `<`, `<=`, `>`, `>=`.
- `flag` / `not_flag` — `$state->hasFlag()` / its negation. No schema involvement; flags are
  an open set.
- `has_item` — `$state->itemQty($item) >= $qty`.
- `visited` — `$state->visitCount($scene) >= $times`. See D-VISIT.
- `and` — every child true, short-circuiting on the first false. `or` — any child true,
  short-circuiting on the first true. `not` — negation of its single child.
- Variable resolution always goes through `VariableSchema::resolve()` (P3).
- **Float equality is exact.** `eq` on a `float` variable compares with `===`; `0.1 + 0.2`
  does not equal `0.3`. No epsilon is introduced — an epsilon is hidden policy and is not
  obviously portable. Documented on the `eq` entry in `docs/engine/ast.md` (R11).

```php
final readonly class EffectApplier
{
    public function __construct(private VariableSchema $schema) {}

    /** @param list<Effect> $effects */
    public function apply(array $effects, GameState $state): EffectResult;
}

final readonly class EffectResult
{
    public function __construct(
        public GameState $state,
        public ?string $redirectTo,
        public StateDiff $diff,
    ) {}

    public function isRedirect(): bool;   // $redirectTo !== null
}
```

Semantics, pinned:

- `set` — `$schema->assertAccepts($var, $value)`, then `withVar`.
- `add` / `sub` — resolve the current value (P3); if
  `!$schema->typeOf($var)->isNumeric()` throw `TypeMismatchException::notNumeric()` (OQ-5b:
  no string concatenation, no boolean arithmetic); compute `$current + $value` /
  `$current - $value`; then **`assertAccepts` the result**. An `int` variable plus `0.5`
  therefore throws rather than silently promoting (OQ-5b). An `int` + `int` overflow becomes
  a PHP float and is caught by the same assertion — tested.
- `set_flag` / `clear_flag` — `withFlag` / `withoutFlag`. Clearing an absent flag produces no
  diff entry.
- `give_item` — `withItem($item, $state->itemQty($item) + $qty)`.
- `remove_item` — `withItem($item, max(0, $state->itemQty($item) - $qty))`; `withItem` removes
  the key entirely at 0, so quantity never goes below 0 and a zero entry never persists.
- `qty` defaults to 1 and must be a positive int (parse-time, P8).
- `goto` — sets `redirectTo` and **returns immediately**: the remaining effects in the list
  are not applied (OQ-7). State is not mutated by `goto` itself. A list containing two `goto`
  nodes therefore honours the first.
- `diff` is `StateDiff::between($stateAtEntry, $stateAtReturn)` (D-DIFF).

```php
final readonly class NextSceneResolver
{
    public function __construct(private ConditionEvaluator $evaluator) {}

    /**
     * @param  string|list<NextRule> $next
     * @throws NoNextSceneException
     */
    public function resolve(string|array $next, GameState $state, string $sceneKey): string;
}
```

A plain string resolves to itself. A list is walked in order and the **first** rule whose
`if` evaluates true wins, even if a later rule would also match; a rule with no `if` is a
fallback and matches unconditionally at its position (so a fallback placed first makes
everything after it unreachable — authored, not the Engine's problem). An empty list, or a
list that runs out with no fallback, throws `NoNextSceneException::noRuleMatched($sceneKey)`.
The resolver holds no graph and validates nothing beyond "a rule matched" (OQ-10).

```php
final readonly class Transition
{
    public const int MAX_REDIRECTS = 5;   // D-REDIRECT

    public function __construct(
        private StoryGraph $graph,
        private ConditionEvaluator $evaluator,
        private EffectApplier $applier,
        private NextSceneResolver $resolver,
    ) {}

    public function run(GameState $state, string $currentScene, string $choiceKey, int $turn): TransitionResult;
}

final readonly class TransitionResult
{
    /** @param list<EngineEvent> $events */
    public function __construct(
        public GameState $state,
        public string $sceneKey,
        public bool $isEnding,
        public array $events,
    ) {}

    /**
     * @return array{
     *     state: array<string, mixed>,
     *     scene: string,
     *     is_ending: bool,
     *     events: list<array<string, mixed>>
     * }
     */
    public function toArray(): array;
}
```

**`Transition::run()` — the exact algorithm.** This is the behavioural contract; the
implementer follows it literally.

1. `hasScene($currentScene)` is false → `UnknownSceneException::named()` (OQ-10).
2. `$scene = $graph->scene($currentScene)`.
3. `$choice = $scene->choice($choiceKey)` → `ChoiceUnavailableException::notFound` if absent.
4. `$choice->condition !== null` and the evaluator returns false →
   `ChoiceUnavailableException::conditionFailed`.
5. Emit `ChoiceMade($currentScene, $choiceKey, $turn)`.
6. `$r = $applier->apply($choice->effects, $state)`; `$state = $r->state`; if
   `!$r->diff->isEmpty()` emit `EffectsApplied($currentScene, EffectPhase::Choice, $r->diff, $turn)`.
7. If `$r->isRedirect()`: `$target = $r->redirectTo`, `$redirects = 1`, guard. The declared
   `next` is **not** resolved (AC-19). Otherwise `$target = $resolver->resolve($scene->next,
   $state, $currentScene)`, `$redirects = 0`.
8. `hasScene($target)` is false → `UnknownSceneException::named()` (OQ-10).
9. Loop:
   a. `$targetScene = $graph->scene($target)`.
   b. `$r2 = $applier->apply($targetScene->onEnter, $state)`; `$state = $r2->state`; if the
      diff is non-empty emit `EffectsApplied($target, EffectPhase::OnEnter, $r2->diff, $turn)`.
   c. If `$r2->isRedirect()`: `$redirects++`; guard; `$target = $r2->redirectTo`; validate
      `hasScene($target)`; continue. Otherwise break.
10. `$state = $state->withVisit($target)` — **after** `on_enter` (D-VISIT), and only for the
    scene the loop settled on.
11. Emit `SceneEntered($target, $redirects, $turn)`.
12. `$isEnding = $graph->isEnding($target)`; if true emit `EndingReached($target, $turn)`.
13. Return `new TransitionResult($state, $target, $isEnding, $events)`.

The guard is one private method, called after every increment and after step 7's
initialisation, so there is exactly one place the off-by-one can live:

```php
private function guardRedirects(int $redirects, string $scene): void
{
    if ($redirects > self::MAX_REDIRECTS) {
        throw RedirectLoopException::exceeded(self::MAX_REDIRECTS, $scene);
    }
}
```

**Event order is fixed and asserted literally** (AC-19): `ChoiceMade`, then zero or one
`EffectsApplied(Choice)`, then zero-or-more `EffectsApplied(OnEnter)` in redirect order, then
`SceneEntered`, then — only for an ending — `EndingReached`.

### `Event/` (step 12)

```php
namespace Storyteller\Engine\Event;

interface EngineEvent
{
    public function name(): string;

    /** @return array<string, mixed> with an 'event' key equal to name() */
    public function toArray(): array;
}

enum EffectPhase: string
{
    case Choice = 'choice';
    case OnEnter = 'on_enter';
}

final readonly class ChoiceMade implements EngineEvent
{
    public function __construct(public string $sceneKey, public string $choiceKey, public int $turn) {}
}

final readonly class EffectsApplied implements EngineEvent
{
    public function __construct(public string $sceneKey, public EffectPhase $phase, public StateDiff $diff, public int $turn) {}
}

final readonly class SceneEntered implements EngineEvent
{
    public function __construct(public string $sceneKey, public int $redirects, public int $turn) {}
}

final readonly class EndingReached implements EngineEvent
{
    public function __construct(public string $sceneKey, public int $turn) {}
}
```

No event carries a timestamp: `int $turn` is the caller's counter and Play adds time when
it logs (analysis, *Assumptions made*).

### `Random/` (step 15)

```php
namespace Storyteller\Engine\Random;

/**
 * A deliberate forward contract for a future `chance` condition / `roll` effect. Nothing in
 * this feature calls it; `Transition::run()` already threads `int $turn` for exactly this
 * reason. Index-addressed rather than sequential (plan decision P12), so an implementation
 * is a pure function of (seed, turn, index) and needs no mutable state.
 */
interface Rng
{
    /** Caller's contract: $min <= $max. */
    public function intBetween(int $turn, int $index, int $min, int $max): int;

    /** @return float in [0.0, 1.0) */
    public function fraction(int $turn, int $index): float;
}

final readonly class SeededRng implements Rng
{
    public function __construct(private string $seed) {}

    public static function fromInt(int $seed): self;
}
```

Implementation note, binding: derive a 32-byte engine seed with
`hash('sha256', $this->seed . '|' . $turn . '|' . $index, true)` and feed it to
`new \Random\Randomizer(new \Random\Engine\Xoshiro256StarStar($bytes))`. `Random\*` is a PHP
built-in and is invisible to deptrac by design — `Xoshiro256StarStar` and `Mt19937` were
explicitly probed clean during `project-skeleton`. **`Random\Engine\Secure` must never be
used.** `intBetween` does not guard `$min <= $max`: the Engine trusts its inputs, and adding
a guard would add an uncoverable-without-ceremony branch for a contract violation.

## JSON AST node shapes / DTO shapes

All keys are `snake_case` (P6). Unknown keys are rejected (P7). Optional keys are marked.

### Conditions

```json
{"op": "eq",        "var": "hp",   "value": 100}
{"op": "neq",       "var": "name", "value": "ash"}
{"op": "lt",        "var": "hp",   "value": 10}
{"op": "lte",       "var": "hp",   "value": 10}
{"op": "gt",        "var": "gold", "value": 0}
{"op": "gte",       "var": "gold", "value": 0}
{"op": "flag",      "flag": "met_elder"}
{"op": "not_flag",  "flag": "met_elder"}
{"op": "has_item",  "item": "rope",       "qty": 1}
{"op": "visited",   "scene": "crossroads", "times": 1}
{"op": "and",       "of": [ {"op": "flag", "flag": "a"}, {"op": "flag", "flag": "b"} ]}
{"op": "or",        "of": [ {"op": "flag", "flag": "a"}, {"op": "flag", "flag": "b"} ]}
{"op": "not",       "of": {"op": "flag", "flag": "a"}}
```

`qty` and `times` are optional, default `1`, and must be an int `>= 1`. `of` is a **list**
for the n-ary `and` / `or` (minimum one element) and a **single object** for the unary
`not` — the shape mirrors the arity, and the reader has two distinct methods for it.
`value` may be any JSON scalar at parse time; whether it matches the variable's declared
type is an evaluation-time check (P8).

### Effects

```json
{"op": "set",         "var": "hp",   "value": 100}
{"op": "add",         "var": "hp",   "value": 5}
{"op": "sub",         "var": "hp",   "value": 5}
{"op": "set_flag",    "flag": "met_elder"}
{"op": "clear_flag",  "flag": "met_elder"}
{"op": "give_item",   "item": "rope", "qty": 1}
{"op": "remove_item", "item": "rope", "qty": 1}
{"op": "goto",        "scene": "cave"}
```

`add` / `sub` require `value` to be a JSON **number** at parse time (`MalformedNodeException`
otherwise); `set` accepts any scalar.

### Scene, choice and next rule

```json
{
  "key": "crossroads",
  "on_enter": [ {"op": "set_flag", "flag": "saw_crossroads"} ],
  "choices": [
    {
      "key": "north",
      "condition": {"op": "has_item", "item": "lantern"},
      "effects": [ {"op": "sub", "var": "hp", "value": 1} ]
    },
    { "key": "south", "effects": [] }
  ],
  "next": "cave"
}
```

`next` is either a string or an ordered list of rules:

```json
"next": [
  {"scene": "cave",  "if": {"op": "flag", "flag": "brave"}},
  {"scene": "home"}
]
```

`key` is required. `on_enter`, `choices` and `effects` are optional and default to `[]`.
`condition` and `if` are optional; absent means "always". `next` is optional and defaults to
the empty list, which `NextSceneResolver` treats as `NoNextSceneException`. `toArray()`
always emits `on_enter`, `choices` and `next`, and omits `condition` / `if` when null.

### `GameState`

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

All six keys are required by `fromArray()`; `v !== 1` throws
`MalformedNodeException::unsupportedStateVersion()` (OQ-11). `toArray()` emits them in
exactly this order. `vars`, `inventory` and `visited` are key-sorted with `SORT_STRING`;
`flags` is sorted and de-duplicated; `history` keeps insertion order (OQ-12).

Two serialization facts recorded now rather than discovered later:

- **An empty map serializes as `[]`, not `{}`.** `json_encode(['vars' => []])` yields
  `{"vars":[]}`. `json_decode(..., true)` gives `[]` either way, so the Engine round-trip is
  exact; a Postgres `jsonb` column will show `[]` where a reader might expect `{}`. If Play
  needs `{}` it casts at its own boundary. Out of scope here, but not a surprise later.
- **PHP coerces decimal-integer-like array keys to int.** An item named `"10"` is stored under
  key `int(10)`. The declared types stay `array<string, …>` and the reader casts keys with
  `(string) $k`, which satisfies PHPStan without a suppression; both sides coerce identically,
  so the round-trip is stable. `GameStateTest` pins this with an item key of `"10"`. No
  identifier-format validation is added — that would be unrequested authoring policy.

### `VariableSchema`

```json
{
  "gold": {"type": "int",    "default": 0},
  "hp":   {"type": "int",    "default": 100},
  "name": {"type": "string", "default": ""}
}
```

`type` is one of `int`, `float`, `string`, `bool`. A `default` the type does not accept is
`TypeMismatchException` at construction.

### `StateDiff` (the `EffectsApplied` payload — D-DIFF)

```json
{
  "vars":      {"hp": {"from": 100, "to": 95}},
  "flags":     {"added": ["brave"], "removed": ["afraid"]},
  "inventory": {"rope": {"from": 0, "to": 1}, "torch": {"from": 2, "to": 0}},
  "visited":   {"cave": {"from": 0, "to": 1}}
}
```

A removed inventory key appears with `"to": 0`. Empty categories are omitted; an empty
`added` or `removed` is omitted; `flags` itself is omitted when both are empty. An entirely
empty diff is `{}` and never reaches the log, because the event is skipped.

### Events and `TransitionResult`

```json
{"event": "choice_made",     "scene": "crossroads", "choice": "north", "turn": 7}
{"event": "effects_applied", "scene": "crossroads", "phase": "choice",   "diff": {}, "turn": 7}
{"event": "effects_applied", "scene": "cave",       "phase": "on_enter", "diff": {}, "turn": 7}
{"event": "scene_entered",   "scene": "cave",       "redirects": 0, "turn": 7}
{"event": "ending_reached",  "scene": "the_end",    "turn": 7}
```

```json
{
  "state":     { "v": 1, "vars": {}, "flags": [], "inventory": {}, "visited": {}, "history": [] },
  "scene":     "cave",
  "is_ending": false,
  "events":    [ ]
}
```

## PHPStan level 8 strategy for `fromArray` (R4)

**Rule: no `phpstan-baseline.neon`, no `@phpstan-ignore*`, no `mixed` in any public
signature, no config change.** Standing rule 4 forbids all four, and AC-3 checks for them in
the diff. The strategy is to make narrowing a *design element* rather than cleanup.

**1. Exactly one place does the narrowing.** `Support/ArrayReader` is the only class in
`src/Engine` whose property type contains `mixed`. Every `fromArray()` in the feature builds
one and reads through it; no other file ever touches a raw decoded array.

**2. The narrowing mechanism is `is_*()`, which PHPStan understands natively.** Each reader
method is four lines and needs no annotation beyond its return type:

```php
public function requireString(string $key): string
{
    $value = $this->data[$key] ?? throw MalformedNodeException::missingKey($this->path, $key);

    if (!is_string($value)) {
        throw MalformedNodeException::wrongType($this->path, $key, 'string', get_debug_type($value));
    }

    return $value;   // PHPStan: string
}
```

`??` with a `throw` expression is PHP 8.0 and keeps the happy path on one line. `get_debug_type()`
is a built-in and gives a useful message for free.

**3. Collections are narrowed element by element into a fresh array, so PHPStan infers the
precise shape from the writes rather than from the source.**

```php
/** @return array<string, int> */
public function requireIntMap(string $key): array
{
    $raw = $this->requireArray($key);
    $out = [];

    foreach ($raw as $k => $v) {
        if (!is_int($v)) {
            throw MalformedNodeException::wrongType($this->path . '.' . $key, (string) $k, 'int', get_debug_type($v));
        }

        $out[(string) $k] = $v;
    }

    return $out;   // PHPStan: array<string, int>
}
```

The `(string) $k` cast is what makes PHPStan produce `array<string, int>` from an
`array<array-key, mixed>` source — it reasons from the static type of the key expression. The
runtime coercion of decimal-integer-like keys back to `int` is documented above and is
harmless because it is symmetric. `requireArrayList()` uses the same shape with `$out[] =
$item` after `is_array($item)`, which PHPStan infers as a `list<array<array-key, mixed>>`.

**4. Public entry points accept `array<array-key, mixed>`, never `mixed`.**
`Condition::fromArray(array $node)`, `Effect::fromArray(array $node)`,
`GameState::fromArray(array $data)` and the three graph readers all take a plain `array` with
an `@param array<array-key, mixed>` docblock. That accepts the result of
`json_decode($json, true)` after the caller's own `is_array()` check — which is Content's and
Play's job, not the Engine's (standing rule 5: validate at the boundary).

**5. The op dispatchers narrow through the enum, not through a string.**
`ConditionOp::tryFrom($r->requireString('op')) ?? throw UnknownOperatorException::condition(...)`
yields a `ConditionOp`, and the `match` over it is exhaustive, so PHPStan proves the return
type without a default branch returning `mixed`.

**6. `tests/` is in `phpstan.neon` paths and is held to level 8 too.** Every data provider
declares a precise return type, e.g.

```php
/** @return iterable<string, array{0: array<array-key, mixed>, 1: bool}> */
public static function conditionProvider(): iterable
```

and `iterable<string, array{…}>` (not `array`), so the named-key form PHPUnit reports in
failures is preserved.

**7. Standing rule 3 is operational here.** `composer phpstan` runs after **every** step, not
at the end — the reader lands in step 3 precisely so that its narrowing is proven green
before thirteen classes depend on it. If a narrowing turns out to need a suppression, that is
a plan defect: stop and report rather than adding one.

## Determinism test design (R6)

"Identical" has to be pinned before it can be asserted. PHPUnit's `assertEquals` on nested
objects compares property by property and is **key-order-insensitive for arrays**, while
`assertSame` on arrays **is** key-order-sensitive. The design uses both deliberately, plus
`json_encode`, which is the form that actually lands in JSONB.

`tests/Unit/Engine/DeterminismTest.php`, three cases:

**1. `testIdenticalInputsProduceIdenticalTransitionResults` — same inputs, two runs.**
Two `Transition` instances are constructed from two **independently built** object graphs
(two `InMemoryStoryGraph` instances over identical fixture data, two schemas, two evaluators,
two appliers, two resolvers), so nothing is shared by identity. Same `GameState`, same scene,
same choice, same `$turn = 7`. Asserts, in order:
- `assertNotSame($a, $b)` — proves the comparison is not trivially satisfied by identity;
- `assertEquals($a, $b)` — deep object-graph equality, event list included;
- `assertSame($a->toArray(), $b->toArray())` — key-order-sensitive, so this pins the
  serialized form, which `assertEquals` alone would not;
- `assertSame(json_encode($a->toArray(), JSON_THROW_ON_ERROR), json_encode($b->toArray(), JSON_THROW_ON_ERROR))`
  — byte equality of the actual wire format.

**2. `testDifferentEffectOrderingsReachAnIdenticalState` — the test R6 says must be
meaningful.** Scene `start` has two choices, `a` and `b`, carrying the **same multiset of
commutative effects in a different order**:

```
a: [ set_flag wise, give_item rope 1, add gold 5, set_flag brave, give_item torch 2 ]
b: [ give_item torch 2, set_flag brave, add gold 5, give_item rope 1, set_flag wise ]
```

Both choices' scenes resolve `next` to the same scene `hub`, so the runs also agree on the
resting scene. Asserts:
- `assertSame('hub', $ra->sceneKey)` and the same for `$rb` — the runs converge;
- `assertEquals($ra->state, $rb->state)` — object-graph equality;
- `assertSame($ra->state->toArray(), $rb->state->toArray())` — **this is the assertion the
  OQ-12 canonical ordering exists for**; it fails without `ksort`/`sort`;
- `assertSame(json_encode(...), json_encode(...))` on both states;
- `assertSame(['brave', 'wise'], $ra->state->toArray()['flags'])` — pins the *rule* (sorted,
  de-duplicated), not merely that the two agree, so the test cannot pass vacuously by both
  runs being wrong in the same way.

The full `TransitionResult` is deliberately **not** compared between the two runs, because
`ChoiceMade.choice` legitimately differs; the comparison is scoped to state and scene.

**3. `testStateIsIndependentOfTheOrderWithMethodsAreApplied`** (in `GameStateTest`, listed
there) — the same logical state built by applying `withVar` / `withFlag` / `withItem` /
`withVisit` in two different orders, asserted with `assertSame($a->toArray(), $b->toArray())`.
This isolates the canonicalisation from the rest of the engine, so a failure points at
`GameState` rather than at `Transition`.

A fourth determinism guard is structural rather than behavioural and lives in
`EnginePurityTest` (AC-9): the source scan for `rand`, `mt_rand`, `random_int`, `shuffle`,
`time`, `microtime`, `date`, `uniqid`, superglobals, and — via reflection — static properties.

## Tests to write first

Engine steps are TDD: **the failing test file is its own sub-step, committed-ready before
the production file exists in the working tree.** Within a step the implementer writes the
test, runs `composer test` to see it fail for the stated reason, then writes the production
file; the step is one commit containing both.

24 test files plus one double, all under `tests/Unit/Engine/**` (OQ-1), namespace
`Storyteller\Tests\Unit\Engine\*`. Every file carries `#[CoversClass]` for the classes it
owns (P11); the two scanning guards carry `#[CoversNothing]`.

| Test file | Test name | Asserts | Step |
|-----------|-----------|---------|------|
| `Exception/EngineExceptionTest.php` | `testEveryEngineExceptionExtendsTheAbstractRoot` | data-provider over all 8 concrete classes: each `instanceof EngineException` and `instanceof \RuntimeException`; `EngineException` is abstract (`ReflectionClass::isAbstract()`). | 2 |
| | `testNamedConstructorsProduceTheDocumentedMessage` | data-provider over all 13 named constructors: each returns its own class and a message containing the identifying argument (scene key, variable name, op string, limit). Also the 100 %-coverage vehicle for the exception family. | 2 |
| | `testNoEngineExceptionExtendsAFrameworkType` | reflection: no exception class's parent chain leaves `Storyteller\Engine` except `\RuntimeException` / `\Exception`. Pins OQ-4. | 2 |
| `Support/ArrayReaderTest.php` | `testRequireStringReturnsTheValue` / `…RejectsAMissingKey` / `…RejectsAWrongType` | happy path; `MalformedNodeException` with the path and key in the message. | 3 |
| | `testRequireIntRejectsAFloatAndABooleanAndANumericString` | `1.0`, `true`, `'1'` all throw — no PHP juggling leaks in. | 3 |
| | `testRequirePositiveIntRejectsZeroAndNegatives` | `0` and `-1` throw `notAPositiveInt`. | 3 |
| | `testOptionalPositiveIntReturnsTheDefaultWhenAbsent` | absent key returns the supplied default; present key is still validated. | 3 |
| | `testRequireScalarAcceptsIntFloatStringBoolAndRejectsNullAndArray` | the four scalars pass; `null` and `[]` throw. | 3 |
| | `testRequireNumberAcceptsIntAndFloatAndRejectsStringAndBool` | OQ-5b's parse-time half. | 3 |
| | `testRequireArrayListRejectsANonListAndANonArrayElement` | a map with string keys throws; a list containing a scalar throws. | 3 |
| | `testRequireIntMapCastsKeysToStringAndRejectsNonIntValues` | returns `array<string,int>`; a float value throws. | 3 |
| | `testRejectUnknownKeysThrowsOnTheFirstExtraKey` | P7; the message names the offending key. | 3 |
| | `testChildExtendsThePathInErrorMessages` | a failure two levels down reports `a.b.c`, not `c`. | 3 |
| `State/VariableTypeTest.php` | `testCaseListIsExactlyTheFourDeclaredTypes` | `VariableType::cases()` backing values are exactly `['int','float','string','bool']` in that order. | 4 |
| | `testAcceptsMatchesTheDeclaredType` | provider over the 4×4 grid: `Int` accepts `1` only (not `1.0`, not `'1'`, not `true`); `Float` accepts `1.0` only — **`Float` does not accept an int**, which is what makes OQ-5b's "no silent promotion" enforceable. | 4 |
| | `testIsNumericIsTrueForIntAndFloatOnly` | OQ-5a's gate. | 4 |
| `State/VariableDefinitionTest.php` | `testRejectsADefaultTheTypeDoesNotAccept` | `TypeMismatchException`. | 4 |
| | `testRoundTripsThroughToArrayAndFromArray` | `fromArray($name, $def->toArray())` equals `$def`. | 4 |
| `State/VariableSchemaTest.php` | `testOfKeySortsDefinitions` | `names()` is sorted regardless of input order. | 4 |
| | `testOfRejectsDuplicateNames` | `MalformedNodeException`. | 4 |
| | `testTypeOfAndDefaultOfThrowForAnUnknownVariable` | `UnknownVariableException`. | 4 |
| | `testResolveReturnsTheStateValueWhenPresent` | P3 happy path. | 4 |
| | `testResolveReturnsTheSchemaDefaultWhenTheStateHasNoValue` | **AC-15: never `null`.** | 4 |
| | `testResolveThrowsForANameNotInTheSchemaEvenWhenTheStateCarriesIt` | P3: the schema is authoritative. | 4 |
| | `testAssertAcceptsThrowsTypeMismatchForAWrongTypedValue` | OQ-5. | 4 |
| | `testEmptySchemaHasNoNames` / `testRoundTripsThroughToArray` | coverage of `empty()` and the serialized shape. | 4 |
| `State/GameStateTest.php` | `testInitialIsEmptyAndDoesNotMarkAnyScene` | all five collections empty — OQ-9c. | 5 |
| | `testVarsInventoryAndVisitedAreKeySorted` | built out of order, `toArray()` keys come back sorted. | 5 |
| | `testFlagsAreSortedAndDeduplicated` | `withFlag('b')->withFlag('a')->withFlag('b')` gives `['a','b']`. | 5 |
| | `testHistoryPreservesInsertionOrderAndIsUnbounded` | OQ-9a: 50 visits, all retained, in order, duplicates intact. | 5 |
| | `testWithVisitIncrementsVisitedAndAppendsHistoryTogether` | P4 / R12: `visitCount(x)` equals the number of `x` entries in `history` after a mixed sequence. | 5 |
| | `testEveryWithMethodReturnsANewInstanceAndLeavesTheReceiverUnchanged` | **AC-20**, provider over all six `with*` methods: `assertNotSame` plus a field assertion on the original. | 5 |
| | `testWithItemRemovesTheKeyAtZeroOrBelow` | key absent from `toArray()['inventory']`. | 5 |
| | `testVarThrowsForAnAbsentVariable` | `UnknownVariableException`. | 5 |
| | `testToArrayCarriesVersionOne` | **OQ-11**: `['v' => 1]` and the key order `v, vars, flags, inventory, visited, history`. | 5 |
| | `testFromArrayRejectsAnUnknownVersion` | `v: 2` throws `unsupportedStateVersion`. | 5 |
| | `testFromArrayRejectsAMissingKey` | each of the six keys removed in turn throws. | 5 |
| | `testRoundTripsForAFullStateAnEmptyStateAndAFloatVariable` | **AC-14**, three cases, `assertEquals` plus `assertSame` on `toArray()`. | 5 |
| | `testRoundTripsAnItemKeyThatLooksLikeAnInteger` | item `"10"`: `toArray()` → `json_encode` → `json_decode` → `fromArray` is stable. Pins the documented key coercion. | 5 |
| | `testStateIsIndependentOfTheOrderWithMethodsAreApplied` | determinism design case 3. | 5 |
| `State/StateDiffTest.php` | `testBetweenIdenticalStatesIsEmpty` | `isEmpty()` true, `toArray()` is `[]`. | 5 |
| | `testVarsDiffRecordsFromAndTo` | only changed variables appear. | 5 |
| | `testFlagsDiffSplitsAddedAndRemoved` | both lists sorted; each omitted when empty; `flags` omitted when both empty. | 5 |
| | `testInventoryDiffRecordsARemovedKeyWithToZero` | D-DIFF. | 5 |
| | `testVisitedDiffIsRecorded` | the category no `EffectsApplied` can currently produce, covered directly. | 5 |
| | `testToArrayOmitsEmptyCategoriesInAFixedOrder` | key order is `vars, flags, inventory, visited`. | 5 |
| `Ast/ConditionOpTest.php` | `testCaseListIsExactlyTheThirteenDeclaredOperators` | **AC-12**: `array_map(fn($c) => $c->value, ConditionOp::cases())` is exactly `['eq','neq','lt','lte','gt','gte','flag','not_flag','has_item','visited','and','or','not']`. A silently added, removed or renamed op fails here. | 6 |
| | `testIsComparisonAndIsOrderingPartitionTheCases` | `isOrdering()` is true for exactly `lt,lte,gt,gte`. | 6 |
| `Ast/ConditionTest.php` | `testEveryOperatorParsesFromItsDocumentedShape` | provider over all 13 shapes from *JSON AST node shapes*: `fromArray` returns the expected class and `op()`. | 6 |
| | `testEveryOperatorRoundTripsThroughToArray` | `Condition::fromArray($c->toArray())` equals `$c`, provider over 13. | 6 |
| | `testQtyAndTimesDefaultToOne` | `has_item` / `visited` without the key. | 6 |
| | `testQtyAndTimesRejectZeroAndNonInteger` | `MalformedNodeException`. | 6 |
| | `testAndOrNestChildrenAndNotTakesASingleObject` | nested three deep; `not` with a list throws, `and` with an object throws. | 6 |
| | `testAndOrRejectAnEmptyOfList` | `MalformedNodeException`. | 6 |
| | `testUnknownOperatorThrows` | `{"op":"xor"}` → `UnknownOperatorException`. | 6 |
| | `testMissingOpKeyAndNonStringOpThrowMalformedNode` | two cases. | 6 |
| | `testARequiredKeyMissingThrowsMalformedNode` | **AC-16**: provider dropping `var`, `value`, `flag`, `item`, `scene`, `of` in turn. | 6 |
| | `testAnUnknownKeyIsRejected` | P7: `{"op":"flag","flag":"a","extra":1}` throws and names `extra`. | 6 |
| | `testErrorPathNamesTheNestedPosition` | a malformed grandchild reports `condition.of[1].of`. | 6 |
| `Ast/EffectOpTest.php` | `testCaseListIsExactlyTheEightDeclaredOperators` | **AC-12**: `['set','add','sub','set_flag','clear_flag','give_item','remove_item','goto']`. | 7 |
| | `testIsArithmeticIsTrueForAddAndSubOnly` | — | 7 |
| `Ast/EffectTest.php` | `testEveryOperatorParsesFromItsDocumentedShape` | provider over all 8. | 7 |
| | `testEveryOperatorRoundTripsThroughToArray` | provider over all 8. | 7 |
| | `testAddAndSubRejectANonNumericValueAtParseTime` | `"5"` and `true` throw `MalformedNodeException` — P8's parse-time half of OQ-5b. | 7 |
| | `testSetAcceptsAnyScalarAtParseTime` | the type check is deferred to the applier. | 7 |
| | `testQtyDefaultsToOneAndRejectsZero` | `give_item` / `remove_item`. | 7 |
| | `testUnknownOperatorThrows` / `testMissingKeyThrows` / `testUnknownKeyIsRejected` | AC-16, P7. | 7 |
| | `testListFromArrayPreservesOrderAndRejectsANonObjectElement` | `on_enter` / `effects` parsing. | 7 |
| `ConditionEvaluatorTest.php` | `testEveryOperatorHasASatisfiedAndAnUnsatisfiedCase` | **AC-15**, provider covering all 13 ops twice. | 8 |
| | `testComparisonUsesTheSchemaDefaultWhenTheStateHasNoValue` | AC-15's default clause, end to end. | 8 |
| | `testOrderingOperatorsRejectStringAndBoolVariables` | **OQ-5a**: `lt` on a `string` var and on a `bool` var throws `TypeMismatchException::notNumeric`. | 8 |
| | `testComparisonRejectsALiteralOfTheWrongDeclaredType` | `eq` on an `int` var with `1.0` throws rather than returning false. | 8 |
| | `testEqOnAFloatIsAnExactComparison` | R11 pinned as behaviour: `0.1 + 0.2` is not `eq 0.3`. | 8 |
| | `testUnknownVariableThrows` | AC-16. | 8 |
| | `testAndShortCircuitsOnTheFirstFalseAndOrOnTheFirstTrue` | a child that would throw is never evaluated — proves short-circuiting rather than inferring it. | 8 |
| | `testNestedAndOrNotEvaluateCorrectly` | AC-15's nesting clause, three levels. | 8 |
| | `testHasItemAndVisitedAreTestedBelowAtAndAboveTheirThreshold` | AC-15: `qty`/`times` of 1 (default), 2 and 3 against stock of 2. | 8 |
| `EffectApplierTest.php` | `testEveryOperatorChangesStateAsDocumented` | provider over all 8. | 9 |
| | `testSetRejectsAValueOfTheWrongDeclaredType` | `TypeMismatchException`. | 9 |
| | `testAddAndSubRejectStringAndBoolVariables` | **OQ-5b**: no concatenation, no boolean arithmetic. | 9 |
| | `testAddAFloatToAnIntVariableThrowsRatherThanPromoting` | **OQ-5b**: `int` var `+ 0.5` → `TypeMismatchException`. | 9 |
| | `testIntegerOverflowIsCaughtAsATypeMismatch` | `PHP_INT_MAX + 1` becomes a float and fails `assertAccepts`. | 9 |
| | `testRemoveItemNeverGoesBelowZeroAndRemovesTheKeyAtZero` | **AC-18**. | 9 |
| | `testGiveItemAndRemoveItemDefaultToAQuantityOfOne` | **AC-18**. | 9 |
| | `testClearingAnAbsentFlagProducesNoDiffEntry` | D-DIFF records outcomes, not attempts. | 9 |
| | `testGotoSetsRedirectToAndDoesNotMutateState` | **AC-18**: `assertSame($before->toArray(), $result->state->toArray())`. | 9 |
| | `testGotoShortCircuitsTheRemainingEffects` | **AC-18 / OQ-7**: an effect after the `goto` did not run. | 9 |
| | `testAListWithTwoGotoNodesHonoursTheFirst` | OQ-7's explicit second case. | 9 |
| | `testAnEmptyEffectListProducesAnEmptyDiffAndNoRedirect` | the skip condition for `EffectsApplied`. | 9 |
| | `testTheDiffCoversAllFourCategoriesAcrossOnePhase` | D-DIFF shape end to end. | 9 |
| `EffectResultTest.php` | `testIsRedirectReflectsRedirectTo` | both branches. | 9 |
| `Graph/NextRuleTest.php` | `testFallbackIsARuleWithoutACondition` | `isFallback()`. | 10 |
| | `testRoundTripsWithAndWithoutACondition` | `if` omitted from `toArray()` when null. | 10 |
| | `testListFromArrayPreservesOrder` / `testRejectsAMissingScene` | — | 10 |
| `NextSceneResolverTest.php` | `testAPlainStringResolvesToItself` | **AC-17**. | 10 |
| | `testTheFirstMatchingRuleWinsEvenWhenALaterRuleWouldAlsoMatch` | **AC-17**. | 10 |
| | `testAFallbackIsUsedOnlyWhenNoEarlierRuleMatched` | **AC-17**: two assertions, one where an earlier rule matches. | 10 |
| | `testNoMatchAndNoFallbackThrows` | **AC-17**: `NoNextSceneException`. | 10 |
| | `testAnEmptyRuleListThrows` | the "ending / dead end" encoding. | 10 |
| `Graph/ChoiceNodeTest.php` | `testIsAlwaysAvailableWhenNoCondition` / `testRoundTrips` / `testRejectsAMissingKey` | P10. | 11 |
| `Graph/SceneNodeTest.php` | `testParsesAFullSceneFromItsDocumentedShape` | P10, the snapshot format. | 11 |
| | `testOptionalKeysDefaultToEmpty` | `on_enter`, `choices`, `next`. | 11 |
| | `testNextAcceptsBothAStringAndARuleList` | the union. | 11 |
| | `testChoiceThrowsForAnAbsentKey` | `ChoiceUnavailableException::notFound`. | 11 |
| | `testDuplicateChoiceKeysAreRejected` | `MalformedNodeException`. | 11 |
| | `testRoundTripsThroughToArray` | — | 11 |
| `Double/InMemoryStoryGraph.php` | *(not a test)* | The only `StoryGraph` implementation in the repo; lives under `tests/` (**AC-10**). Built from a `list<SceneNode>` plus a `list<string>` of ending keys; `scene()` and `isEnding()` throw `UnknownSceneException` for an unknown key. | 11 |
| `Event/EngineEventsTest.php` | `testEffectPhaseCaseListIsExactlyChoiceAndOnEnter` | backing values `choice`, `on_enter`. | 12 |
| | `testEveryEventExposesItsNameAndSerializesWithAnEventKey` | provider over the four events; `toArray()['event'] === name()`. | 12 |
| | `testEffectsAppliedEmbedsTheDiffAndThePhase` | D-DIFF payload. | 12 |
| | `testNoEventCarriesATimestamp` | reflection: no property named `at`/`time`/`timestamp`; Play owns time. | 12 |
| `TransitionTest.php` | `testHappyPathAppliesChoiceEffectsResolvesNextRunsOnEnterAndUpdatesVisited` | **AC-19**: the full order, asserted on state and on the event list. | 13 |
| | `testEmittedEventsAreInTheDocumentedOrder` | **AC-19**: exact `list` of event class names and payloads. | 13 |
| | `testEffectsAppliedIsEmittedOncePerNonEmptyPhase` | **D-DIFF**: a turn with both phases non-empty gives two; one empty phase gives one; both empty gives none. | 13 |
| | `testAChoiceWhoseConditionIsFalseThrows` | `ChoiceUnavailableException::conditionFailed`. | 13 |
| | `testAChoiceKeyAbsentFromTheSceneThrows` | `ChoiceUnavailableException::notFound`. | 13 |
| | `testAnUnknownCurrentSceneThrows` | **OQ-10**. | 13 |
| | `testAnUnknownResolvedSceneThrows` | **OQ-10**: a dangling `goto` and a dangling `next` target, two cases. | 13 |
| | `testAGotoInTheChoiceEffectsOverridesTheDeclaredNext` | **AC-19**. | 13 |
| | `testAGotoInOnEnterRedirectsAgain` | **AC-19**. | 13 |
| | `testFiveRedirectsSucceed` | **D-REDIRECT / AC-19**: chain of exactly 5, `SceneEntered.redirects === 5`. | 13 |
| | `testSixRedirectsThrowRedirectLoopException` | **D-REDIRECT / AC-19**: limit+1. | 13 |
| | `testTheChoiceEffectGotoCountsTowardTheRedirectBudget` | D-REDIRECT's load-bearing clause: 1 choice `goto` + 5 `on_enter` gotos throws. | 13 |
| | `testVisitedIsFalseInsideTheSceneOwnOnEnterOnFirstEntryAndTrueOnReEntry` | **D-VISIT**, the surprising consequence, pinned. | 13 |
| | `testASceneRedirectedThroughIsNotMarkedVisitedButItsEffectsStillApply` | D-VISIT (i). | 13 |
| | `testIsEndingIsTrueExactlyWhenTheGraphSaysSo` | **AC-19**, both branches, and `EndingReached` emitted only then. | 13 |
| | `testNoNextSceneExceptionPropagatesFromTheResolver` | AC-16. | 13 |
| `TransitionResultTest.php` | `testToArrayNestsStateSceneIsEndingAndEvents` | the wire shape Play persists. | 13 |
| `DeterminismTest.php` | three cases | see *Determinism test design*. **AC-13**. | 14 |
| `EnginePurityTest.php` | `testNoEngineSourceUsesANonDeterministicFunction` | **AC-9**: scans `src/Engine/**/*.php` for `rand`, `mt_rand`, `random_int`, `shuffle`, `time`, `microtime`, `date`, `uniqid` as call sites, and for `$_GET`/`$_POST`/`$_SERVER`/`$_ENV`/`$_REQUEST`/`$GLOBALS`. | 14 |
| | `testThePatternsMatchTheCallFormsTheyClaimTo` | follows the `BoundedContextLayoutTest` precedent: positive samples (`time()`, `  mt_rand(1,2)`) must match, negative samples (`strtotime(`, `$this->date(`, `Foo::date(`, the word `update` containing `date`) must not. Stops the scan going vacuous. | 14 |
| | `testNoEngineClassDeclaresAStaticProperty` | reflection over every class in `src/Engine`: `getProperties(ReflectionProperty::IS_STATIC)` is empty. | 14 |
| | `testEveryConcreteEngineClassIsFinal` | reflection: every class that is not an interface, enum or abstract class is `final`. | 14 |
| | `testEveryEngineClassHasACoveringTestFile` | a file-existence map from `src/Engine/**` to `tests/Unit/Engine/**`, with an explicit allow-list for the classes covered by a shared test (the AST node classes, the events). Keeps AC-21 honest even if the coverage report is not read. | 14 |
| `Random/SeededRngTest.php` | `testTheSameSeedTurnAndIndexAlwaysProduceTheSameValue` | **OQ-13 property**, not a golden number. | 15 |
| | `testTwoInstancesWithTheSameSeedAgreeOnAWholeSequence` | 16 draws across indices 0–15 compared element-wise. | 15 |
| | `testADifferentTurnProducesADifferentSequence` | the 16-draw sequences for turn 1 and turn 2 differ. Deterministic, so it cannot be flaky. | 15 |
| | `testADifferentIndexProducesADifferentValueWithinATurn` | — | 15 |
| | `testADifferentSeedProducesADifferentSequence` | — | 15 |
| | `testIntBetweenAlwaysReturnsAValueInRangeIncludingTheDegenerateRange` | 200 draws within `[3, 9]`; `intBetween(t, i, 4, 4)` is always 4. | 15 |
| | `testFractionIsAlwaysInTheHalfOpenUnitInterval` | 200 draws, `>= 0.0` and `< 1.0`. | 15 |
| | `testFromIntProducesAUsableSeed` | covers the named constructor. | 15 |
| | `testNothingInSrcEngineReferencesRng` | reflection/scan: the forward-contract claim is asserted, so an accidental Engine dependency on randomness is caught. | 15 |

Expected suite size after provider expansion: roughly 230–280 test cases, against the 24 the
skeleton left behind — AC-11's "substantially higher" made concrete. The implementer records
the actual count in the step-15 commit message.

## Migrations

- **Needed: no.**
- Up: n/a. No Doctrine entity, no table, no column, no index, no JSONB write path. Standing
  rule 6 is not engaged and `migrations/` gains no class.
- Down / rollback: n/a — nothing to roll back at the database level.
- Backfill: n/a.
- **What this feature does to the future schema, stated so the first migration is not a
  guess:** `GameState::toArray()` fixes the shape of the future `play_session.state` JSONB
  column (six keys, `v: 1`); `Condition::fromArray()` / `Effect::fromArray()` /
  `SceneNode::fromArray()` fix the shape of the compiled snapshot in
  `story_version`; `TransitionResult::toArray()['events']` fixes the event-log payload. Once
  a `story_version` row is published immutable, a change to any key name above becomes a
  JSONB data migration rather than a code change — which is why P6 (snake_case) and OQ-11
  (`v: 1`) are decided here rather than later.
- `history` is append-only and unbounded inside the Engine (OQ-9a), so the future JSONB row
  grows without limit across a long playthrough. Capping or windowing is **Play's** decision
  at the persistence boundary; the Engine must not silently drop data. Recorded here so the
  first `play_session` migration is written with that in mind.

## Deptrac impact

- **New cross-context edges: none.** `Engine -> Shared` is already in the ruleset and this
  feature does not even use it, because OQ-3 keeps `src/Shared` empty and OQ-4 keeps
  `EngineException` inside Engine. The Engine layer ends this feature with **zero outgoing
  layer-crossing dependencies** — the strongest form of the acceptance criterion.
- **`deptrac.yaml` change required: NO.** Confirmed by reading the file: the `Engine` layer
  (`directory: src/Engine/.*`) and its ruleset entry (`Engine: [Shared]`) are already correct.
  AC-6 checks this with `git diff --stat`. **A proposal to edit `deptrac.yaml` during
  implementation is a signal that something is being imported into `src/Engine` that should
  not be — report it, do not grant a layer.** Granting `Engine` the `Framework` or `Dev`
  layer to make an import work is a standing-rule-4 violation.
- **What a correct run should report once the Engine layer is non-empty.** Today
  `composer deptrac` is green by vacancy: `project-skeleton` left it at
  `Violations 0 / Skipped 0 / Uncovered 0 / Allowed 0`. After step 13 it must report:
  - `Violations 0`, `Skipped violations 0`, `Uncovered 0`, `Errors 0` — unchanged;
  - **`Allowed` greater than zero for the first time.** The edges counted are intra-`Engine`
    (`Transition -> GameState`, `ConditionEvaluator -> Condition`, …), which deptrac allows
    by default for a same-layer pair; expect it in the low hundreds. **If `Allowed` is still
    0 after step 13, that is not success — it means the analyser is not seeing `src/Engine`
    at all. Report it.** This is AC-4.
  - **No `Engine -> Shared` entry appears**, because nothing in `src/Engine` imports from
    `Storyteller\Shared`.
  - `\RuntimeException`, `\Random\Randomizer` and `\Random\Engine\Xoshiro256StarStar`
    produce **no entry at all** — `ignore_uncovered_internal_classes: true` plus the
    `php_internal` carve-out in the `Framework` layer. That is by design, not a gap.
- **The two gaps deptrac structurally cannot close are now load-bearing rather than
  theoretical** (analysis N2):
  1. vendor *functions* and *constants* are invisible to deptrac, so
     `use function Symfony\Component\String\u;` inside `src/Engine` would be 0 violations.
     `tests/Unit/Shared/BoundedContextLayoutTest.php` is the only thing covering that, and
     **this feature is the first time it has real input**. It must pass unmodified (AC-8).
  2. `tests/` is outside deptrac's `paths` (`./src` only), which is correct and must stay
     that way — and is why `InMemoryStoryGraph` lives under `tests/` (AC-10).
- **A one-off enforcement probe is part of step 13's verification** (not committed):
  temporarily add `use Symfony\Component\Uid\Uuid;` to one `src/Engine` file, confirm that
  `composer deptrac` now fails **and** that `composer test` fails via
  `BoundedContextLayoutTest`, then revert. This converts "the gate parses" into "the gate
  bites" and closes the self-test follow-up the skeleton review left open.

## Ordered steps

Each step is one commit, independently committable, and must leave all four gates green:
`composer cs-check`, `composer phpstan`, `composer deptrac`, `composer test`. Standing rule 3
applies within a step too — run `composer cs-fix` and `composer phpstan` as you go, not only
at the end. **Prerequisite for every step: `make up` (the `php` container must be running, or
`guard-running` exits 1). Step 1 additionally needs `make build` first**, because it changes
`docker/php/php.ini`, which is `COPY`ed into the image rather than bind-mounted.

1. **Wire coverage so the 100 % criterion is measurable from the first Engine commit** —
   files: `docker/php/php.ini`, `composer.json`, `phpunit.xml`, `Makefile` — tests: none
   (no production code changes) — verify: `make build && make up`; `docker compose exec -T php php --ri pcov`
   reports `pcov.directory => /app/src` **as a configured value, not auto-detected**;
   `composer coverage` exits 0 and prints a text summary; `composer qa` still exits 0 and its
   runtime is unchanged — commit: `build: wire PCOV line coverage scoped to src/Engine`
   — routing: `sonnet` because it touches no bounded context, writes no migration, defines no
   cross-context interface and no input-validation surface; the exact file contents are below.
   - `docker/php/php.ini` gains, with the comment:
     ```ini
     ; PCOV is the line-coverage driver (see `composer coverage`). Both settings are pinned
     ; rather than left to PCOV's auto-detection, which reports /app/src only because that is
     ; where it happened to find the first include. pcov.directory is the INSTRUMENTATION
     ; scope; the per-run report scope is narrowed to src/Engine by --coverage-filter.
     ; pcov.enabled stays 1: it is PHP_INI_SYSTEM, so `phpunit -d pcov.enabled=1` (which goes
     ; through ini_set) cannot turn it on at runtime. The cost on a non-coverage run is the
     ; compile-time hook only.
     pcov.enabled = 1
     pcov.directory = /app/src
     ```
   - `composer.json` gains one script and one description; **`qa` is deliberately not
     changed**, so the gate's runtime does not double:
     ```json
     "coverage": "vendor/bin/phpunit --testsuite=unit --coverage-filter src/Engine --coverage-text --coverage-clover var/coverage/clover.xml --coverage-html var/coverage/html"
     ```
     ```json
     "coverage": "Run the unit suite with line coverage scoped to src/Engine"
     ```
     `--coverage-filter` overrides `<source><include>` for that run, which is what scopes the
     figure to `src/Engine` without excluding future contexts from `<source>`.
   - `phpunit.xml` gains, directly after `</testsuites>`:
     ```xml
     <coverage ignoreDeprecatedCodeUnits="true" disableCodeCoverageIgnore="true"/>
     ```
     `disableCodeCoverageIgnore="true"` is the load-bearing one: a stray `@codeCoverageIgnore`
     cannot be used to manufacture 100 %. `<source>` is **not** changed — it still includes
     `src`, and scoping happens on the CLI. If PHPUnit 12.5's XSD rejects either attribute,
     drop that attribute and report it; never drop the element or the schema reference.
   - `Makefile` gains `coverage` to `.PHONY` and:
     ```makefile
     coverage: guard-running
     	$(EXEC) composer coverage
     ```
   - **No `.gitignore` change**: `/var/` already ignores `var/coverage`. Verify with
     `git check-ignore -v var/coverage/html` and record the result in the commit message.
   - **If PHPUnit rejects `--coverage-filter src/Engine` while that directory holds only
     `.gitkeep`**, record it in the commit message, verify step 1 with `composer coverage`
     minus the filter, and re-verify the filtered form at the end of step 2. Do **not** change
     the script to work around it.

2. **Engine: the exception family** — files: `tests/Unit/Engine/Exception/EngineExceptionTest.php`
   (first), then the 9 files under `src/Engine/Exception/` — tests: the three cases in the
   catalogue — verify: `composer qa` exits 0; `composer coverage` reports **100.00 %** for
   `src/Engine`; `composer deptrac` now reports a non-zero `Allowed` count (AC-4's first
   evidence) — commit: `feat(engine): add the engine exception family`
   — routing: `opus` because it touches `src/Engine/**`.

3. **Engine: the `mixed`-narrowing array reader** — files:
   `tests/Unit/Engine/Support/ArrayReaderTest.php` (first), then `src/Engine/Support/ArrayReader.php`
   — tests: the eleven cases in the catalogue — verify: `composer qa` exits 0 with **no new
   `@phpstan-ignore` and no `phpstan-baseline.neon`** (`test ! -f phpstan-baseline.neon`,
   `rg -c 'phpstan-ignore' src tests` returns nothing); `composer coverage` 100 % —
   commit: `feat(engine): add the validated array reader for AST parsing`
   — routing: `opus` because it touches `src/Engine/**` **and** it is the single file the
   whole level-8 strategy rests on (R4); getting it wrong forces a suppression in thirteen
   downstream classes.

4. **Engine: the variable schema** — files: `tests/Unit/Engine/State/VariableTypeTest.php`,
   `tests/Unit/Engine/State/VariableDefinitionTest.php`,
   `tests/Unit/Engine/State/VariableSchemaTest.php` (first), then
   `src/Engine/State/VariableType.php`, `VariableDefinition.php`, `VariableSchema.php` —
   tests: the fourteen cases in the catalogue — verify: `composer qa` exits 0; `composer coverage`
   100 % — commit: `feat(engine): add VariableType, VariableDefinition and VariableSchema`
   — routing: `opus` because it touches `src/Engine/**` and fixes OQ-5's type rules, which
   every operator then inherits.
   - Note the ordering dependency: `VariableSchema::resolve()` references `GameState`, which
     does not exist until step 5. **Land `resolve()` in step 5, not step 4** — step 4 ships
     `has`, `typeOf`, `defaultOf`, `assertAccepts`, `names`, `of`, `empty`, `fromArray`,
     `toArray`, and step 5 adds `resolve()` together with its two tests. This keeps every step
     independently green.

5. **Engine: `GameState` and `StateDiff`** — files: `tests/Unit/Engine/State/GameStateTest.php`,
   `tests/Unit/Engine/State/StateDiffTest.php` (first), then `src/Engine/State/GameState.php`,
   `src/Engine/State/StateDiff.php`, plus `VariableSchema::resolve()` and its two tests —
   tests: the twenty cases in the catalogue — verify: `composer qa` exits 0; `composer coverage`
   100 %; AC-14 round-trip and AC-20 immutability both green — commit:
   `feat(engine): add the immutable GameState and its structural diff`
   — routing: `opus` because it touches `src/Engine/**` **and** `GameState::toArray()` is the
   public interface that crosses into Play as the future `play_session` JSONB column; OQ-11
   and OQ-12 are both realised here.

6. **Engine: the condition AST** — files: `tests/Unit/Engine/Ast/ConditionOpTest.php`,
   `tests/Unit/Engine/Ast/ConditionTest.php` (first), then `src/Engine/Ast/ConditionOp.php`,
   `Condition.php`, `Comparison.php`, `FlagCheck.php`, `HasItem.php`, `Visited.php`,
   `LogicalAnd.php`, `LogicalOr.php`, `LogicalNot.php` — tests: the eleven cases in the
   catalogue — verify: `composer qa` exits 0; `composer coverage` 100 %; AC-12's 13-case list
   assertion green — commit: `feat(engine): add the condition AST and its 13 operators`
   — routing: `opus` because it touches `src/Engine/**` **and** `Condition::fromArray()` is
   the format `src/Content` compiles YAML into — a public interface crossing two contexts,
   frozen once a `story_version` is published.

7. **Engine: the effect AST** — files: `tests/Unit/Engine/Ast/EffectOpTest.php`,
   `tests/Unit/Engine/Ast/EffectTest.php` (first), then `src/Engine/Ast/EffectOp.php`,
   `Effect.php`, `SetVar.php`, `Arithmetic.php`, `FlagEffect.php`, `ItemEffect.php`,
   `GoToScene.php` — tests: the nine cases in the catalogue — verify: `composer qa` exits 0;
   `composer coverage` 100 %; AC-12's 8-case list assertion green — commit:
   `feat(engine): add the effect AST and its 8 operators`
   — routing: `opus` — same two reasons as step 6.

8. **Engine: `ConditionEvaluator`** — files: `tests/Unit/Engine/ConditionEvaluatorTest.php`
   (first), then `src/Engine/ConditionEvaluator.php` — tests: the nine cases in the catalogue,
   covering all 13 ops satisfied and unsatisfied — verify: `composer qa` exits 0;
   `composer coverage` 100 %; AC-15 fully green — commit:
   `feat(engine): add the condition evaluator`
   — routing: `opus` because it touches `src/Engine/**` and it is where OQ-5a becomes
   observable behaviour.

9. **Engine: `EffectApplier` and `EffectResult`** — files:
   `tests/Unit/Engine/EffectApplierTest.php`, `tests/Unit/Engine/EffectResultTest.php`
   (first), then `src/Engine/EffectApplier.php`, `src/Engine/EffectResult.php` — tests: the
   fourteen cases in the catalogue — verify: `composer qa` exits 0; `composer coverage` 100 %;
   AC-18 fully green, including OQ-7's short-circuit and the two-`goto` list — commit:
   `feat(engine): add the effect applier with goto short-circuit semantics`
   — routing: `opus` because it touches `src/Engine/**` and realises OQ-5b and OQ-7.

10. **Engine: `NextRule` and `NextSceneResolver`** — files:
    `tests/Unit/Engine/Graph/NextRuleTest.php`, `tests/Unit/Engine/NextSceneResolverTest.php`
    (first), then `src/Engine/Graph/NextRule.php`, `src/Engine/NextSceneResolver.php` —
    tests: the eight cases in the catalogue — verify: `composer qa` exits 0;
    `composer coverage` 100 %; AC-17 fully green — commit:
    `feat(engine): add next-rule resolution with ordered first-match semantics`
    — routing: `opus` because it touches `src/Engine/**` and `NextRule`'s array shape is part
    of the snapshot format Content must produce.

11. **Engine: the `StoryGraph` contract, plus the in-memory double** — files:
    `tests/Unit/Engine/Graph/ChoiceNodeTest.php`, `tests/Unit/Engine/Graph/SceneNodeTest.php`
    (first), then `src/Engine/Graph/ChoiceNode.php`, `src/Engine/Graph/SceneNode.php`,
    `src/Engine/Graph/StoryGraph.php`, and `tests/Unit/Engine/Double/InMemoryStoryGraph.php`
    — tests: the nine cases in the catalogue — verify: `composer qa` exits 0;
    `composer coverage` 100 %; **AC-10**: `rg -l 'implements StoryGraph' src` returns nothing
    and the double is under `tests/`; `rg 'PHPUnit' src/Engine` returns nothing — commit:
    `feat(engine): add the StoryGraph contract that Content will implement`
    — routing: `opus` because it touches `src/Engine/**` **and** `StoryGraph` is *the*
    cross-context interface of this feature — the thing `src/Content` is written against and
    the reason this plan ran at the capability floor.

12. **Engine: the four events** — files: `tests/Unit/Engine/Event/EngineEventsTest.php`
    (first), then `src/Engine/Event/EngineEvent.php`, `EffectPhase.php`, `ChoiceMade.php`,
    `EffectsApplied.php`, `SceneEntered.php`, `EndingReached.php` — tests: the four cases in
    the catalogue — verify: `composer qa` exits 0; `composer coverage` 100 % — commit:
    `feat(engine): add the four engine events and their serialized shapes`
    — routing: `opus` because it touches `src/Engine/**` **and** the event `toArray()` shapes
    become Play's persisted event-log format — a public interface crossing two contexts.

13. **Engine: `Transition` and `TransitionResult`** — files:
    `tests/Unit/Engine/TransitionTest.php`, `tests/Unit/Engine/TransitionResultTest.php`
    (first), then `src/Engine/Transition.php`, `src/Engine/TransitionResult.php` — tests: the
    seventeen cases in the catalogue — verify: `composer qa` exits 0; `composer coverage`
    100 %; **AC-4**: `composer deptrac` reports a non-zero `Allowed` count with 0 violations
    and 0 uncovered; **the one-off enforcement probe** described under *Deptrac impact*
    (add a Symfony import to one Engine file, confirm `composer deptrac` **and**
    `composer test` both fail, revert, confirm green again) — record the probe's output in
    the commit message — commit:
    `feat(engine): add Transition with redirect-budget and visit semantics`
    — routing: `opus` because it touches `src/Engine/**`, owns D-REDIRECT and D-VISIT, and
    `TransitionResult` is what Play consumes.

14. **Engine: determinism and purity guards** — files:
    `tests/Unit/Engine/DeterminismTest.php`, `tests/Unit/Engine/EnginePurityTest.php` — no
    production file — tests: the three determinism cases and the five purity cases in the
    catalogue — verify: `composer qa` exits 0; **AC-13** and **AC-9** green; deliberately
    inserting `mt_rand(` into one Engine file makes `EnginePurityTest` fail (then revert);
    deliberately removing the `sort()` from `GameState`'s flag canonicalisation makes
    `testDifferentEffectOrderingsReachAnIdenticalState` fail (then revert) — record both
    probe outcomes in the commit message, because a guard test that cannot fail is worth
    nothing — commit: `test(engine): pin determinism and the purity invariants`
    — routing: `opus` because the tests reason about every file under `src/Engine/**` and
    about the determinism contract itself; a vacuous guard here is invisible.

15. **Engine: the deterministic RNG forward contract** — files:
    `tests/Unit/Engine/Random/SeededRngTest.php` (first), then `src/Engine/Random/Rng.php`,
    `src/Engine/Random/SeededRng.php` — tests: the nine cases in the catalogue — verify:
    `composer qa` exits 0; `composer coverage` reports **100.00 % of lines for `src/Engine`**
    — this is the step where AC-21 is finally claimed, and the implementer pastes the text
    summary and the total test count into the commit message; `composer deptrac` still 0
    violations / 0 uncovered (`Random\*` must produce no entry at all) — commit:
    `feat(engine): add the seeded RNG forward contract (unused by design)`
    — routing: `opus` because it touches `src/Engine/**`; the class docblock must state that
    it is a deliberate forward contract for a future `chance` / `roll` operator (OQ-13/R10),
    and `Random\Engine\Secure` must never appear.

16. **Document the AST spec** — files: `docs/engine/ast.md` — tests: none — verify: the
    document lists all 21 operators with the exact array shape, required and optional keys,
    defaults and error cases from *JSON AST node shapes* above; it states the float-equality
    caveat on `eq` (R11), the D-VISIT consequence on `visited`, the OQ-7 short-circuit on
    `goto`, the D-REDIRECT budget, the `GameState` `v: 1` envelope and the `StateDiff` shape;
    `composer qa` still exits 0 — commit: `docs: add the engine AST specification`
    — routing: `sonnet` because it touches no file under `src/**`, writes no migration,
    defines no new interface and no validation surface: every shape it transcribes is already
    fixed verbatim in this plan. If the implementer finds a shape that is *not* fixed here,
    that is a plan defect — stop and report rather than inventing one.

### Steps that touch a context boundary

Steps **5**, **6**, **7**, **10**, **11**, **12** and **13** each define or extend a
serialized form or an interface that `src/Content` or `src/Play` must later satisfy. Step
**11** is the hinge: `StoryGraph` is the interface another bounded context implements.
`deptrac.yaml` is touched by **no** step.

## Routing hints for the orchestrator

| Trigger | Steps affected |
|---------|----------------|
| touches `src/Engine/**` | **2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 15** — and **14**, whose tests reason about every file in it |
| Doctrine migration | none — this feature writes no migration and creates no entity |
| public interface crossing two bounded contexts | **5** (`GameState::toArray()` → Play's JSONB state), **6**, **7** (`Condition`/`Effect::fromArray()` → Content's compiler target), **10** (`NextRule`), **11** (**`StoryGraph`** — the interface Content implements), **12** (event payloads → Play's event log), **13** (`TransitionResult` → Play) |
| auth / input validation / security surface | none — the Engine trusts its inputs by design (standing rule 5); all external-input validation belongs to `src/Api`, which this feature does not touch. Note that AST *structural* validation in `fromArray` is a format check against trusted content, not a security boundary |
| open question from `01-analysis.md` still unanswered | none — all 14 are settled at Gate A. **D-DIFF, D-REDIRECT and D-VISIT are settled-by-default and flagged for Gate B**, which raises the review stakes on steps **9** (D-DIFF, OQ-7), **13** (D-REDIRECT, D-VISIT) and **16** (where they are written down), but does not leave them unanswered |
| plan-specified high risk | **3** (R4 — the level-8 narrowing strategy every later step inherits), **13** (R8 — the off-by-one in the redirect budget), **14** (R6 — a determinism test that passes vacuously is worse than none) |

**Summary:** run steps **2–15** at `opus`; run steps **1** and **16** at `sonnet`. No step
may run at `haiku` or `fable`. The two `sonnet` steps are safe at that tier only because
their file contents are written out verbatim above — step 1's four edits are quoted in full,
and step 16 transcribes shapes already frozen in *JSON AST node shapes*. If either
implementer finds itself inventing a config key or an array shape, that is a plan defect:
stop and report rather than improvise.

## Rollback

Nothing in this feature reaches production: no deployment target, no schema, no migration,
no data, no API surface, no DI registration (plan decision P4 of `project-skeleton` keeps
`Storyteller\Engine\` out of `config/services.yaml`, and this feature does not change that).
Rollback is purely local.

- **Per step:** `git revert <sha>`. Every step is one commit carrying one logical layer plus
  its tests, and no step depends on uncommitted state from another. Reverting step *n* breaks
  every later step, so revert from the top down.
- **Whole feature:** revert steps 16 → 1 in order, or delete the branch. The tree returns to
  the `project-skeleton` end state, where `src/Engine/` holds only `.gitkeep` and all four
  gates are green by vacancy.
- **Step 1 is the only step with an environment side effect.** Reverting
  `docker/php/php.ini` requires `make build && make up` to take effect; PCOV itself stays in
  the image (it was installed by the user's earlier, separate Dockerfile change, which this
  feature does not own and does not revert). Reverting the `composer.json` script and the
  `Makefile` target is immediate. `rm -rf var/coverage` removes the generated reports, which
  are git-ignored by the existing `/var/` rule.
- **No consumer exists yet**, which is the real reason rollback is cheap: `src/Content`,
  `src/Play` and `src/Api` contain no code that imports from `src/Engine`, so removing the
  Engine cannot break anything downstream. That stops being true with the next feature —
  after Content compiles into these AST shapes and Play persists `GameState::toArray()`,
  changing a key name becomes a JSONB data migration rather than a revert. **This plan is the
  last cheap moment to change the serialized formats**, which is why D-DIFF, D-REDIRECT and
  D-VISIT are flagged for Gate B rather than assumed.

## Still open — for the user, not for the implementer

Seven items. None blocks implementation; each is a decision the plan made or declined to
make, surfaced rather than buried.

1. **Confirm D-DIFF, D-REDIRECT and D-VISIT.** They were accepted as part of "take the
   recommended defaults" without individual confirmation. D-VISIT's consequence is the one to
   look at hardest: `visited(X, 1)` is **false** inside scene X's own `on_enter` on first
   entry. Plus the two sub-decisions this plan made inside D-VISIT — a scene redirected
   *through* is not marked visited, and `SceneEntered` fires only for the resting scene.
2. **`.gitignore` needs no change.** Line 2 is `/var/`, which already ignores `var/coverage`.
   The Gate A instruction asked for the entry; the outcome it wanted is already true, so the
   plan adds no redundant line. Say so if you want it written down anyway.
3. **The 100 % figure is read, not enforced.** PHPUnit 12 has no `--coverage-min` /
   fail-under flag. Automating it needs either a new dev dependency (standing rule 9 — must
   be justified in a plan document first) or a small PHP script over
   `var/coverage/clover.xml`. This plan does neither: AC-21 is verified by reading the
   `--coverage-text` summary at steps 2–15, and `EnginePurityTest::testEveryEngineClassHasACoveringTestFile`
   provides a structural backstop. Worth a follow-up feature if the bar should be machine-held.
4. **`CLAUDE.md` names `TransitionResolver`; this feature ships `NextSceneResolver` plus
   `Transition`.** The analysis and the original request both use the latter pair. That makes
   CLAUDE.md's Engine row stale after step 13. A one-line correction is a separate,
   user-approved edit — this plan does not touch `CLAUDE.md`.
5. **`#[CoversClass]` changes what 100 % means** (P11). With the attribute present, PHPUnit
   attributes coverage only to the listed classes, so the figure becomes "every line is
   reached by a test that declares it covers that class" — stricter than plain line coverage,
   and the reason every production class has a named test file. Confirm you want the stricter
   reading; the alternative is to drop the attributes and accept incidental coverage.
6. **Class count is 49, above the analysis's 30–40 estimate.** The overage is four classes
   the analysis did not enumerate: `ArrayReader` (R4's mitigation), `VariableDefinition`,
   `StateDiff` (D-DIFF's payload type) and `EffectPhase` (D-DIFF's phase marker). Each is
   small and each replaces an untyped array that PHPStan level 8 would have fought.
7. **An empty map serializes as `[]`, not `{}`** in the `GameState` and `StateDiff` JSON.
   Harmless for the Engine round-trip; it will look odd in a `jsonb` column. Play can cast at
   its boundary. Flagged now so the first `play_session` migration is not surprised.
