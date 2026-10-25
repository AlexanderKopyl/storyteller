# 05 — Test report: engine-core

- **Feature slug:** `engine-core`
- **Date:** 2026-10-25
- **Tested at:** `d266d70` (start) → `20a9aa8` (end); 10 commits, **`tests/` only — no file
  under `src/` is modified by this phase** (`git diff --stat d266d70..HEAD` touches
  `tests/Unit/Engine/**` and this document only)
- **Environment:** PHP 8.5.3, PHPUnit 12.5.40, PHPStan 2.x level 8, deptrac 4.7,
  php-cs-fixer 3.95.27. Coverage run inside the `php` container (PCOV); the four gates run on
  the host.

## Verdict

**PASS**

All four gates are green at `20a9aa8`, line coverage is unchanged at 100 %, and the three
gaps the review assigned to this phase (**M6**, **r1**, **r2**) are closed with tests that
were each falsified against the behaviour they name. No production defect was found that
blocks the feature. One **documentation defect stands and needs the implementer**: the
`VariableType` docblock still asserts that widening an int to a float "loses nothing", which
r2's new tests prove false above 2⁵³. The behaviour is now pinned either way, so the fix is a
comment, not a format change.

## Check results

| Command | Result | Detail (verbatim from the command) |
|---------|--------|------------------------------------|
| `composer test` | **pass** | `OK (542 tests, 7935 assertions)` — was `OK (480 tests, 7690 assertions)` |
| `composer phpstan` | **pass** | ` [OK] No errors ` (level 8 over `src`, `tests`, `config`, `public`; 84 files) |
| `composer deptrac` | **pass** | `Violations 0 · Skipped violations 0 · Uncovered 0 · Allowed 0 · Warnings 0 · Errors 0` |
| `composer cs-check` | **clean** | `Found 0 of 87 files that can be fixed in 0.280 seconds, 20.00 MB memory used` |
| `composer cs-fix` | **not run** | `cs-check` reported 0 fixable files at every step, so there was nothing to fix. |
| `composer coverage` (container) | **pass** | `OK (540 tests, 7917 assertions)` · `Classes: 100.00% (44/44)` · `Methods: 100.00% (189/189)` · `Lines: 100.00% (655/655)` |

`composer qa` exits **0**. The baseline the orchestrator established (`cs-check` 0 of 85,
`phpstan` `[OK] No errors`, `deptrac` all zero, `test` 480/7690, coverage 100 % 655/655) was
re-run at the start of this phase and reproduced exactly; the file counts move from 85 to 87
because this phase adds two test files.

Coverage is run with `--testsuite=unit` (540 of the 542 cases; the other two are
`tests/Integration`). As predicted, the line figure did not move: every new test exercises
already-covered lines. **The value added here is not coverage, it is discrimination** — see
*Falsification probes*.

## Tests added

27 new test methods / **62 new test cases** (542 − 480), 245 new assertions.

| File | Test | Covers |
|------|------|--------|
| `tests/Unit/Engine/Random/SeededRngTest.php` | `testTheGoldenValuesOfTheDocumentedSeedTriplesNeverChange` | **M6** — determinism across processes and PHP versions |
| `tests/Unit/Engine/State/GameStateTest.php` | `testTwoSemanticallyEqualStatesDifferUnderStrictArrayComparison` | **r1** — the `toArray()` asymmetry, in executable form |
| `tests/Unit/Engine/State/GameStateTest.php` | (2 assertions added to `testAFullStateWithAnIntegralFloatSurvivesAJsonEncodeDecodeCycle`) | **r1** — why that test's `assertEquals` is loose |
| `tests/Unit/Engine/State/VariableTypeTest.php` | `testWideningAnIntIntoAFloatDeclarationIsLossyAbove2Pow53` + 2 provider rows | **r2** — the real limit of the int→float widening |
| `tests/Unit/Engine/ConditionEvaluatorTest.php` | `testAFloatDeclaredVariableCannotTellTwoIntegersAbove2Pow53Apart` | **r2** — the same limit as an author meets it |
| `tests/Unit/Engine/ConditionEvaluatorTest.php` | `testEveryOperatorAnswersFromTheSchemaDefaultOnAnEmptyState` (28 rows) | operators — every operator against the schema default |
| `tests/Unit/Engine/ConditionEvaluatorTest.php` | `testADefaultIsResolvedWithItsDeclaredPhpTypeAndIsNeverNull` | operators — "never null", and never the wrong PHP type |
| `tests/Unit/Engine/ConditionEvaluatorTest.php` | `testDeeplyNestedLogicalOperatorsEvaluateToTheBottom` | operators — 64/65 nested `not`, 32 alternating `and`/`or` |
| `tests/Unit/Engine/ConditionEvaluatorTest.php` | `testASingleChildAndOrIsItsChild` | operators — the smallest legal arity |
| `tests/Unit/Engine/EffectApplierTest.php` | `testRemoveItemIsClampedAtZero` (6 rows) | operators — the inventory floor, incl. nothing held |
| `tests/Unit/Engine/EffectApplierTest.php` | `testSubMayDriveAVariableNegativeAndNothingClampsIt` | operators — `sub` below zero is permitted, and pinned |
| `tests/Unit/Engine/EffectApplierTest.php` | `testAnArithmeticStepOfZeroAndASetToTheCurrentValueProduceNoDiff` | operators / D-DIFF — outcomes, not attempts |
| `tests/Unit/Engine/EffectApplierTest.php` | `testVariableFlagAndItemKeysMayBeUnicodeOrEmpty` | operators — unicode and empty-string keys |
| `tests/Unit/Engine/TransitionTest.php` | `testAGotoBackIntoTheSceneBeingEnteredIsStoppedByTheBudgetNotByCycleDetection` | transition — cycles, D-REDIRECT |
| `tests/Unit/Engine/TransitionTest.php` | `testVisitedCountsAcrossSeveralTurnsAndNeverCountsASceneRedirectedThrough` | transition — D-VISIT over four turns |
| `tests/Unit/Engine/TransitionTest.php` | `testSceneAndChoiceKeysMayBeUnicode` | transition — unicode graph keys end to end |
| `tests/Unit/Engine/NextSceneResolverTest.php` | `testAFallbackPlacedFirstMakesEveryLaterRuleUnreachable` | transition — unreachable rules |
| `tests/Unit/Engine/NextSceneResolverTest.php` | `testResolutionIsRepeatableAndLeavesTheStateUntouched` | determinism |
| `tests/Unit/Engine/DeterminismTest.php` | `testCanonicalOrderingIsByteOrderAndIsTheSameInEveryLocale` | **determinism** — ordering is byte order, not collation |
| `tests/Unit/Engine/SessionConcurrencyTest.php` *(new)* | `testTwoChoicesSubmittedAgainstTheSameStateAreComputedIndependently` | concurrency |
| | `testRerunningTheSameTurnAgainstTheSameStateIsByteIdentical` | concurrency / determinism |
| | `testReplayingAnAcceptedChoiceAgainstItsOwnResultAppliesItTwice` | concurrency — **replay is not idempotent** |
| | `testAStaleChoiceWhoseGuardTheFirstTurnClosedIsRefused` | concurrency — the 409 case, by type |
| | `testTheEventLogCarriesNoOrderingKeyOfItsOwn` | concurrency — event-log ordering |
| `tests/Unit/Engine/ContentVersioningTest.php` *(new)* | `testTheSameTurnFollowsWhicheverVersionItWasHandedAndNoOther` | versioning |
| | `testAVariableTheNewerSchemaNoLongerDeclaresIsCarriedThroughATurn` | versioning (M2 at version level) |
| | `testAConditionNamingTheRetiredVariableFailsLoudlyUnderTheNewSchema` | versioning |
| | `testVisitsToASceneARetiredVersionDeletedAreStillCountedAndReadable` | versioning |
| | `testAStateWrittenByAnEarlierBuildLoadsAndPlays` | versioning — the frozen `v: 1` envelope |

### How the M6 golden values were obtained

By running the **committed** `SeededRng` under PHP 8.5.3 and printing the results (a
throwaway script, not committed), then transcribing them as literals:

```
sha256('storyteller/engine-core|1|0') = c0433713bd5a0f31d0990ce24b8793365e4e153e0a8bf2c1f03b23087976e51b
intBetween(1, 0, 1, 6)               = 3
intBetween(7, 3, 0, 100)             = 29
intBetween(1, 0, 0, PHP_INT_MAX)     = 8603048871373097032
intBetween(42, 9, -10, 10)           = -1
fraction(1, 0)                       = 0.869706102612091
fraction(7, 3)                       = 0.06122881568830785
fromInt(42)->intBetween(1, 0, 1, 6)  = 3
fromInt(42)->fraction(1, 0)          = 0.627412892597092
```

The floats are `var_export` output under the project's `serialize_precision = -1`, i.e. the
shortest representation that round-trips, so `assertSame` on the literal is exact. The
SHA-256 digest is asserted alongside the draws on purpose: when this test fails after a PHP
upgrade, a changed digest means `hash()` moved and an unchanged digest means
`Xoshiro256StarStar` or `Randomizer` moved. Either way a stored seed no longer replays, and
the right response is a seeding migration — not a new expected value. The test's docblock
says so.

## Edge cases exercised

- **Engine determinism:** two independently built engines on two independently built states
  (pre-existing); re-running the same turn is byte-identical including event payloads;
  `NextSceneResolver` answers identically across instances and leaves the state untouched;
  and — the gap this phase closed — **the canonical ordering is a byte order, not a
  collation order**. `SORT_STRING` and `SORT_LOCALE_STRING` agree in the `C` locale, so no
  existing test could tell them apart; the new case asserts a literal byte order that Czech
  collation reorders (`chmel` before `cukr`, `Z` first, multi-byte keys last) **and**
  byte-identical encodings under every locale the machine can set. It is the only test in the
  suite that fails when `SORT_STRING` is swapped for `SORT_LOCALE_STRING`. On a host with
  `cs_CZ`/`tr_TR`/`en_US`/`de_DE` installed, five locales run; inside the container only `C`
  is settable (hence 7917 vs 7929 assertions for the same 540 cases) — the literal byte-order
  assertion is what keeps it honest there.
- **Condition / effect operators:** all 13 condition operators answered from the schema
  **default** on `GameState::initial()`, each comparison paired true/false around the
  default, plus the resolved default's PHP type; missing variable, wrong-type literal,
  unknown operator, non-comparison operator inside `Comparison`, and an unknown node class
  (pre-existing); boundaries at 2⁵³ for both `Int` and `Float` declarations; `remove_item` at
  six points around the floor including "nothing held at all" (no diff entry, so no event);
  `sub` below zero unclamped; `add 0` / `sub 0` / a `set` to the current value producing no
  diff; unicode and empty-string keys for variables, flags and items; 64/65-deep `not`
  chains and 32 alternating `and`/`or` levels; one-child `and`/`or`. The empty-`of` case for
  `and`, `or` and `not` was already rejected at parse time (`ConditionTest`), so nothing was
  added there.
- **Transition resolution:** no valid transition (pre-existing); multiple matches → first
  wins (pre-existing); a fallback written first making every later rule unreachable, which
  the Engine deliberately does not diagnose; **cycles** — a `goto` into the scene being
  entered and a two-scene ping-pong, both ended by the budget and not by any cycle check,
  pinned on the exact message next to the acyclic six-hop chain that produces the same one;
  unknown scene from `next`, from a choice `goto` and from an `on_enter` `goto`
  (pre-existing). The budget at exactly 5 and exactly 6, and `EffectsApplied` emitted 0, 1
  and 2 times in one turn, were already covered (`testFiveRedirectsSucceed`,
  `testSixRedirectsThrowRedirectLoopException`,
  `testEffectsAppliedIsEmittedOncePerNonEmptyPhase`) and were not duplicated.
- **Session concurrency:** two choices submitted against one state are computed
  independently and neither mutates the shared input; re-running an unconfirmed turn is
  byte-identical (so a retry is safe); **a replayed accepted choice is applied a second
  time** — the Engine has no de-duplication and cannot have one, since it cannot tell a retry
  from a repeat move; a stale choice whose guard has since closed is refused with
  `ChoiceUnavailableReason::ConditionFailed`; no event carries an id, a sequence number or a
  timestamp, so list order is the only ordering the Engine provides.
- **Content versioning:** the same state and choice reach different scenes under v1 and v2 in
  one process and in both orders (nothing caches, nothing consults a "current" version); a
  variable the newer schema retired is carried through a turn untouched while a condition
  still naming it fails loudly; visits to a scene a later version deleted stay counted and
  readable, because `visited`/`history` are state and never touch the graph; a literal `v: 1`
  envelope "written by an earlier build" loads and plays, and an envelope from a future build
  is refused.

## Falsification probes

Every added test was checked against a temporary mutation of the behaviour it names. Each
mutation was applied by file copy and reverted by file copy; `git status src/` is clean and
`src/` is byte-identical to `d266d70`.

| Mutation (temporary, reverted) | Result |
|---|---|
| `SeededRng` seed separator `'|'` → `'#'` | only `testTheGoldenValuesOfTheDocumentedSeedTriples…` fails (1 of 10 in the file) |
| `GameState::create()` widens every int to float | `testTwoSemanticallyEqualStates…` fails |
| `VariableType::canonicalise()` refuses widening above 2⁵³ (the review's alternative fix) | all 3 r2 cases fail |
| `VariableSchema::resolve()` returns a zero value instead of the declared default | 11 evaluator cases fail |
| `LogicalNot` stops negating | deep-nesting case fails |
| `LogicalOr` reads only its first child | deep-nesting and single-child cases fail |
| `GameState::withItem()` drops a key only below 0 | 6 inventory cases fail (incl. the new "exactly the stock") |
| `sub` clamped at 0 | `testSubMayDriveAVariableNegative…` fails |
| `ksort(..., SORT_STRING)` removed | unicode-key case fails |
| `SORT_STRING` → `SORT_LOCALE_STRING` everywhere in `GameState` | **only** `testCanonicalOrderingIsByteOrder…` fails |
| `Transition` gains cycle detection | `testAGotoBackIntoTheSceneBeingEntered…` fails |
| `Transition` marks transited scenes visited | 3 cases fail, incl. the new four-turn one |
| `StateDiff` stops skipping undeclared names (reverts M2) | versioning case errors |
| `GameState::fromArray()` accepts any envelope version | 2 cases fail |

## Failures found

No production failure. Three things surfaced while authoring and are recorded for honesty:

| Test | Failure | Suspected cause | Owner |
|------|---------|-----------------|-------|
| `testAGotoBackIntoTheSceneBeingEntered…` | my first expectation said the ping-pong cycle names `"c"` | wrong expectation: the sixth increment refuses the hop back to `b`, so `b` is named. Corrected in the test; the behaviour is the documented one (and is review **m3** — the message names the scene *refused*, not the last entered) | tester (fixed) |
| `testResolutionIsRepeatableAndLeavesTheStateUntouched` | `phpstan` error `staticMethod.alreadyNarrowedType`: "`assertSame()` with `'ridge'` and `'ridge'` will always evaluate to true" | PHPStan **proves** `NextSceneResolver::resolve()` pure and folds the second call. Rewritten to collect the three answers and compare the list, so the assertion is executed rather than proved. Fixed forward in `f672491`; a compliment to the design, not a defect | tester (fixed) |
| `EffectApplier` `remove_item` clamp | removing `max(0, …)` from `EffectApplier` leaves the **entire suite green** | not a defect: `GameState::withItem()` already drops a key at `$qty <= 0`, so the applier's clamp is a second guard for a rule the state type already holds. Worth knowing that this line carries no test weight — it is unfalsifiable by construction, which is the right outcome for defence in depth but is relevant to review **m2** | note only |

## Product issues found and deliberately **not** fixed

1. **`VariableType`'s docblock is still false above 2⁵³** (`src/Engine/State/VariableType.php:13-16`,
   and the `canonicalise()` docblock at `:45-53`). It justifies the whole int→float asymmetry
   with "widening loses nothing"; `Float->accepts(9007199254740993)` is `true` and
   `canonicalise()` returns `9007199254740992.0`, a different number, silently. Pinned by
   `testWideningAnIntIntoAFloatDeclarationIsLossyAbove2Pow53` and by
   `testAFloatDeclaredVariableCannotTellTwoIntegersAbove2Pow53Apart`, which shows the
   consequence an author meets: `eq` reports that a variable holding `9007199254740993`
   equals `9007199254740992`. **Needs the implementer** — one comment; the review's own
   preferred wording is in r2. If the alternative fix (refuse the widening above 2⁵³) is
   taken instead, all three new cases go red and must be rewritten, which is the correct
   signal, not a nuisance.
2. **Replay is not idempotent, by construction** — re-submitting an accepted choice applies
   it again, under the same turn number, with a second full set of events. This is correct
   for a pure function and is now written down as an executable obligation for `src/Play`
   (`SessionConcurrencyTest`). Not a defect; it must not be discovered against a database.

## Coverage gaps not closed

| Gap | Why not closed | Suggested follow-up |
|-----|----------------|---------------------|
| **r3 / B1-b** — a type-disagreeing loaded state (`hp => "10"` under an `int` declaration) makes `ConditionEvaluator::compare()` answer silently while `EffectApplier` throws | Pinning the current four-way behaviour would freeze a policy the reviewer wants changed, and the fix (`VariableSchema::assertState()` at the load boundary) is a `src/` change this phase may not make | Whoever closes r3 should land the review's four probe rows as the test, in the same commit |
| **M1** — no entry point for a story's first scene (`Transition::enter()`) | The capability does not exist; there is nothing to test | Must land before `src/Play` is planned, with its own tests (start-scene `on_enter`, start-scene `goto`, the visit/history entry) |
| **M4** — evaluation-time exceptions carry no scene / choice / phase | Requires a `src/` change | Assert the scene and choice keys appear in the message of a failure raised inside a choice-effect phase |
| **m4** — `EnginePurityTest` does not yet forbid `getenv`, `spl_object_id`/`spl_object_hash`, `php_uname`, `Random\Engine\Secure` or file I/O | Extending the guard's constant list is a judgement about what "no I/O" means, and `tests/` changes that tighten a production guard are better landed with the owner of the rule | One array entry plus one sample pair in `testThePatternsMatchTheCallFormsTheyClaimTo` per name |
| Mutation testing is manual | 14 probes were run by hand (table above); there is no Infection run and adding a dev dependency needs a plan entry (standing rule 9) | Consider `infection/infection` in a later plan; the suite is now strong enough that an MSI figure would be meaningful |
| No integration or persistence test of the JSONB round trip | `src/Play` and `src/Content` do not exist; the round trip is tested at `json_encode`/`json_decode` level only | Re-assert the frozen envelope against a real `jsonb` column when `Play` lands — `ContentVersioningTest::testAStateWrittenByAnEarlierBuildLoadsAndPlays` is the literal to reuse |
| Concurrency is modelled, not raced | The Engine has no I/O, so there is nothing to race; real interleaving lives in `Play`'s transaction boundary | `Play` needs a test with two concurrent writers on one `play_session` row, asserting the loser is rejected rather than merged |
| The `visited` category of `StateDiff` is still unreachable through any effect | By design (D-DIFF); `StateDiffTest` covers the branch directly | Revisit if an effect operator ever writes `visited` |
| Deep nesting is bounded at 64/32 levels | Chosen as "well past what a human will type" without being slow; PHP's own recursion limit is not the Engine's to define | If Content ever compiles generated conditions, add a depth limit to the parser and test it there |
