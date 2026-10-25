# 05 — Test report: project-skeleton

- **Feature slug:** `project-skeleton`
- **Date:** 2026-10-25
- **Tested at:** `b63e7a1` (clean tree) → `b4ba08d` (this phase's single commit)
- **Tester model:** `opus` (two bounded contexts — Engine and Shared — plus the dependency-rule mechanism)
- **Where:** every gate result below was observed **inside the container** (`docker compose exec -T php`, PHP **8.5.12**, Composer 2.10.3). The host (PHP 8.5.3) was used only for the parity run recorded at the end.

## Verdict

**PASS**

Four gates green in the container, individually and in aggregate. The deptrac dependency rule
is proven to **bite inside the container**, not only on the host: a framework import in
`src/Engine` exits **1** with a `DependsOnDisallowedLayer … (Framework)` violation. One
review-side mitigation (N2) was closed with new tests. No gate was weakened, no production
code was touched, and no failure needs the implementer.

Two caveats that are part of the verdict, not footnotes:

- **Two of the four gates are still ceremonial on today's tree** — see §"Which gates are
  proven and which are ceremonial". `composer phpstan` certifies little more than that the
  config parses; `composer deptrac` reports `Allowed 0`, i.e. not one layered token exists.
  Deptrac is nevertheless *proven in mechanism* by the probe table below, which is a stronger
  statement than "it exited 0".
- **N1 is open and was deliberately not fixed** (out of Gate D scope, implementer work on the
  dependency graph). It is a real major: `require-dev` packages fall inside the `Framework`
  layer and are therefore *affirmatively allowed* inside `Content`, `Play` and `Api`.

## Check results

All commands run as `docker compose exec -T php composer <script>` unless stated otherwise.
Exit codes are the ones observed, captured with `$?` on an unpiped invocation.

| Command | Result | Exit | Detail |
|---------|--------|------|--------|
| `composer test` | **pass** | **0** | `OK (6 tests, 44 assertions)` — 0 failures, 0 errors, 0 skipped, 0 risky, 0 warnings (`failOnRisky`/`failOnWarning`/`failOnSkipped` all active). Was 4 tests / 19 assertions before this phase. |
| `composer phpstan` | **pass** | **0** | `[OK] No errors`, level 8, 7 files analysed. |
| `composer deptrac` | **pass** | **0** | `Violations 0 · Skipped 0 · Uncovered 0 · Allowed 0 · Warnings 0 · Errors 0`, with `--report-uncovered --fail-on-uncovered`. |
| `composer cs-check` | **clean** | **0** | `Found 0 of 8 files that can be fixed`. |
| `composer cs-fix` | **not run — not needed** | — | `cs-check` was already clean, so there was nothing to fix. Running the write variant would have been a no-op mutation of the tree. |
| `make qa` (aggregate, in container) | **pass** | **0** | All four gates in order: cs-check → phpstan → deptrac → phpunit. This is the feature's acceptance criterion. |
| `composer validate --strict` | **pass** | **0** | |
| `php bin/console about` | **pass** | **0** | |
| `php bin/console doctrine:migrations:status` | **pass** | **0** | With `postgres` healthy (criterion 16). |
| `git status --porcelain` | **empty** | — | Before the phase, after every probe run, and after the commit. |

Host parity run (macOS, PHP 8.5.3): `composer qa` → exit **0**, tree clean.

**Why each gate was also run individually:** a green aggregate cannot distinguish "all four
passed" from "one member is vacuous and the rest are cheap". Each of `cs-check`, `phpstan`,
`deptrac` and `test` was invoked on its own and its exit code captured separately, before
`make qa` was run at all.

## Priority 1 — the deptrac violation probe, inside the container

Every deptrac result in `03-implementation-notes.md` and `04-review.md` that I could verify
the provenance of was produced on host PHP 8.5.3 (the fix-loop notes assert container parity
for P1–P6; this phase re-derives it rather than inheriting it). The acceptance criterion is
`make qa` green **in the container**, so the adversarial probe belongs there too.

Method: throwaway `.php` files written under `src/`, `composer deptrac` run **inside the
container** (`/app`, PHP 8.5.12), exit code captured on an unpiped invocation, files deleted
by an `EXIT` trap. Nothing was staged and nothing was committed.

| # | Probe | Expected | **Observed exit** | Report |
|---|-------|----------|-------------------|--------|
| P1 | `src/Engine` → `Symfony\Component\Routing\Route` | fail | **1** | Violations 2 · Uncovered 0 |
| P2 | `src/Engine` → `Doctrine\ORM\EntityManagerInterface` | fail | **1** | Violations 1 · Uncovered 0 |
| P3 | `src/Shared` → `Symfony\Component\Uid\Uuid` | fail | **1** | Violations 2 · Uncovered 0 |
| P4 | `src/Api/Controller` → `Storyteller\Engine\…` (`Api -> Engine` deliberately absent) | fail | **1** | Violations 1 |
| P5 | `src/Play/Application` → Engine + Content (**positive control**) | pass | **0** | Violations 0 · **Allowed 2** |
| P6 | `src/Engine` → `DateTimeImmutable`, `JsonException`, `Random\Randomizer`, `Random\Engine\Mt19937`, `BcMath\Number` | pass | **0** | Violations 0 · Uncovered 0 |
| P7 | `src/Engine` → `use function Symfony\Component\String\u;` | *fail would be ideal* | **0** | Violations 0 · Uncovered 0 · Warnings 0 — the known deptrac limitation (N2), confirmed in the container too |

Exact violation text from P1, in the container:

```
 -------------------------- -----------------------------------------------------------------------------------
  Reason                     Engine
 -------------------------- -----------------------------------------------------------------------------------
  DependsOnDisallowedLayer   Storyteller\Engine\TesterProbe must not depend on Symfony\Component\Routing\Route
                             You are depending on token that is a part of a layer that you are not allowed to
                             depend on. (Framework)
                             /app/src/Engine/TesterProbe.php:13
  DependsOnDisallowedLayer   Storyteller\Engine\TesterProbe must not depend on Symfony\Component\Routing\Route
                             You are depending on token that is a part of a layer that you are not allowed to
                             depend on. (Framework)
                             /app/src/Engine/TesterProbe.php:11
 -------------------------- -----------------------------------------------------------------------------------
```

Note the paths: `/app/src/...`, i.e. the container's own filesystem, not a host run reported
second-hand.

**P5 is the load-bearing control.** `Allowed 2` proves rules were *evaluated and permitted*,
not that the analyser found nothing to look at — without it, "exit 0" is indistinguishable
from a config that collected no tokens at all.

**Cleanup proven.** After the probe script and after the single follow-up probe used to
capture the violation text, `git status --porcelain` was **empty** and `src/Engine` /
`src/Shared` contained only their tracked `.gitkeep`. The probe script lives in the session
scratchpad, not in the repository.

## Tests added

One file changed, one commit: `b4ba08d` — *test: scan src/Shared and `use function` imports
for framework contamination*. No `src/` file was created, edited or deleted outside the
deleted probes.

| File | Test | Covers |
|------|------|--------|
| `tests/Unit/Shared/BoundedContextLayoutTest.php` | `testFrameworkFreeContextsImportNoFrameworkNamespace` *(now data-provided over `src/Engine` **and** `src/Shared`)* | N2 mitigation: framework contamination of the two framework-free contexts |
| `tests/Unit/Shared/BoundedContextLayoutTest.php` | `testForbiddenImportPatternsMatchTheImportFormsTheyClaimTo` *(new)* | The scan's own regexes — 9 forbidden samples must match, 5 allowed samples must not |
| `tests/Unit/Shared/BoundedContextLayoutTest.php` | `testEveryBoundedContextDirectoryExists` *(unchanged)* | Layout criterion 13 |

Suite: **4 → 6 tests, 19 → 44 assertions.** Net new test cases: **3** (one new method, plus
the provider splitting the scan into two cases).

### What changed in the scan, and why

Per **N2**, deptrac cannot see vendor *functions*: `use function Symfony\Component\String\u;`
in `src/Engine` yields 0 violations, 0 uncovered and 0 warnings, because the `Framework`
layer's `must: classLike` collector rejects a `FunctionReference` and
`UncoveredDependentHandler` only records `ClassLikeToken`s. The reviewer verified that neither
a `functionName` collector nor `analyser.types: [class, function, function_call]` catches it.
I re-confirmed the symptom in the container (probe P7) and did **not** re-litigate the cause.

Per **B1's evidence**, `src/Shared` is in scope for the same no-framework rule (plan decision
P3 withholds `Framework` from `Engine` *and* `Shared`), but the committed test scanned
`src/Engine` only.

Both gaps are now closed:

- forms matched: `use X`, `use function X`, `use const X` (grouped imports and irregular
  whitespace included);
- vendor roots: `Symfony`, `Doctrine`, `Psr`, `Monolog`, `PHPUnit` — `PHPUnit` added so the
  test side carries a small part of **N1**'s weight for the two contexts it covers;
- a `\b` after each root keeps `SymfonyLike\Thing` out of scope;
- contexts scanned: `src/Engine` **and** `src/Shared`, each as its own test case.

Class, interface, trait, enum, attribute, docblock-type and inline fully-qualified-name
imports are deliberately **not** duplicated here — the reviewer proved deptrac already catches
all of them, and a second regex layer would rot. `use function` / `use const` and
`src/Shared` are this test's unique value, and the file's docblock says so.

### Red first, then green — the evidence

| Probe file | Committed test | Extended test |
|---|---|---|
| `src/Engine/TesterProbe.php` with `use function Symfony\Component\String\u;` | scanned, **0 of 3 patterns match** → silently passes | **FAIL** |
| `src/Shared/TesterProbe.php` with `use Symfony\Component\Uid\Uuid;` | **not scanned at all** (Engine-only) | **FAIL** |

With both probes in place the extended suite reported `Tests: 4, Assertions: 43, Failures: 2`
with the two expected messages. The probes were deleted and the suite returned to
`OK (6 tests, 44 assertions)`, exit 0. The first row is the important one: the old pattern set
*did* scan that file and *did* find nothing.

## Edge cases exercised

The SDLC procedure's five Engine/Play categories have **no code to exercise** in this feature —
by design. The plan is explicit: "no PHP class lands under `src/Engine`, `src/Content`,
`src/Play`, `src/Api` or `src/Shared`", and the only production class in the whole feature is
`Storyteller\Kernel`. Writing determinism or AST-operator tests here would mean inventing
production code, which is not the tester's job. Recorded honestly rather than papered over:

- **Engine determinism:** *n/a — no Engine code exists.* What was verified instead is that the
  determinism rule's preferred idiom will not be collateral damage when it arrives: probe P6
  confirms `Random\Randomizer`, `Random\Engine\Mt19937`, `DateTimeImmutable`, `JsonException`
  and `BcMath\Number` are all **silent** inside `src/Engine` (0 violations, 0 uncovered) in the
  container, so a seeded `Randomizer` and an injected clock are legal where `rand()`/`time()`
  are not reachable through any framework import.
- **Condition / effect operators:** *n/a — the JSON AST spec is explicitly out of scope for this
  phase (02-plan, §Public interfaces).* No operator exists to give a happy path, a missing
  variable, a wrong type, a boundary or an unknown-operator case to.
- **Transition resolution:** *n/a — no `TransitionResolver`, no nodes, no graph.*
- **Session concurrency:** *n/a — no session, no event log, no persistence. Zero Doctrine
  entities and zero migrations; `doctrine:migrations:status` reports 0 available.*
- **Content versioning:** *n/a — no `story_version` table, no published-version concept, no
  compiler.*
- **Architecture invariants (what *is* exercisable here, and was):** the dependency rule in
  both directions (7 probes, container), the two framework-free contexts, three import forms
  × five vendor roots, false-positive samples, PHP built-ins, and the gates' own fail-fast
  behaviour inherited from steps 8/10.

## Failures found

**None.** No gate came back red, and no test left in the tree is failing.

| Test | Failure | Suspected cause | Owner |
|------|---------|-----------------|-------|
| — | — | — | — |

The only failures observed in this phase were the two **deliberate** red-step failures above,
produced by probe files that were deleted in the same command sequence.

## Which gates are proven and which are ceremonial

The reviewer's standing observation, re-assessed against what I ran:

| Gate | Standing | Basis |
|---|---|---|
| `composer deptrac` | **Vacuous on today's tree, proven in mechanism.** | `Allowed 0` on the real tree is the tell: not one layered token exists, so exit 0 certifies nothing by itself. But the counterfactual is now established **in the container**: P1–P4 each exit 1 with correctly-attributed violations and P5 returns `Allowed 2`. This is the gate that is strongest in mechanism and weakest in current coverage. |
| `composer cs-check` | **Protective.** | 8 real files; proven to bite twice during implementation (steps 8 and 10 each removed `declare(strict_types=1);` and observed exit 8, with the dry run confirmed not to rewrite the file). `setRiskyAllowed(true)` is present and load-bearing. Gap: `migrations/` is outside the finder (M1). |
| `composer test` | **Protective, and meaningfully larger than before.** | 6 tests / 44 assertions asserting real invariants. Its unique value over deptrac is now the `use function` / `use const` case and `src/Shared` coverage — both proven red-then-green this phase. `failOnRisky`/`failOnWarning`/`failOnSkipped` make "0 skipped, 0 risky" machine-checked rather than eyeballed. |
| `composer phpstan` | **Ceremonial.** | 7 files, one of which (`config/reference.php`) is 78 KB of generated vendor output that is in the gate's input set only because a kernel booted (M2), one is an array literal, one is three lines, and `src/Kernel.php` is an empty class with a trait. `phpstan-symfony`'s container rules are inert without `containerXmlPath` (P5), and `phpstan-doctrine` has no entity to see. Exit 0 certifies that the config parses and the extensions load — nothing more. |

So: **one gate of four is ceremonial** (`phpstan`), one is vacuous-in-coverage but
proven-in-mechanism (`deptrac`), and two are genuinely protective (`cs-check`, `test`). This
is the honest state of a skeleton, not a defect — but it should not be reported as "four green
gates" without the qualifier.

## Coverage gaps not closed

| Gap | Why not closed | Suggested follow-up |
|-----|----------------|---------------------|
| **N1 — `require-dev` packages are affirmatively `Allowed` inside `Content`, `Play`, `Api`** | **Explicitly out of Gate D scope.** `deptrac.yaml` must not be changed by the tester; the fix is implementer work on the dependency graph. A production class importing `PHPUnit\Framework\Assert` is fatal under `composer install --no-dev` and no gate catches it. Partially mitigated from the test side: `PHPUnit` is now a forbidden root for `src/Engine` and `src/Shared` — but those two contexts are precisely the ones that were already covered. `Content`, `Play` and `Api` remain exposed. | Implementer: add a `Dev` layer (`composer` collector over the six `require-dev` packages), a matching `must_not` in `Framework`'s `bool` collector so the layers stay disjoint, and grant `Dev` to no layer. |
| **Engine determinism, AST operators, transition resolution, session concurrency, content versioning** | No production code exists in any of those areas. Testing them would require inventing the implementation, which is not the tester's role. | The first Engine feature is TDD (standing rule 2); these five categories are its acceptance surface. |
| **Vendor *function* use inside `Content`, `Play`, `Api`** | N2's tool limitation applies everywhere, but those three contexts are *allowed* to use the framework, so a vendor function there is not a violation. No gap in practice. | None. |
| **Inline FQN / docblock contamination of `src/Engine` and `src/Shared` is covered by deptrac only** | Deliberate: the reviewer proved deptrac catches inline FQNs and docblock `@param` types. A second regex layer in the test would duplicate a stronger mechanism and would rot. | None — but if deptrac's `paths` ever stop covering a context, this test does not substitute for it. |
| **M1 — `migrations/` is in no gate** (not phpstan `paths`, not the cs-fixer finder, not deptrac `paths`) | Fixing it means editing `phpstan.neon` / `.php-cs-fixer.dist.php`, i.e. production configuration. Carried forward from the review. | Implementer: add `migrations` to PHPStan's `paths` and the cs-fixer finder; decide deliberately on deptrac (it interacts with `--fail-on-uncovered`). |
| **M2 — PHPStan's input set depends on whether a kernel booted** (6 files on a fresh clone, 7 after any `composer test`) | Same: a `phpstan.neon` edit. | Implementer: `excludePaths: [config/reference.php]`, mirroring the existing `->notPath('reference.php')`. |
| **M3 — the `"Storyteller\\": "src/"` psr-4 rule leaves a layer-free zone at the `src/` root** | Open decision D-1; a `composer.json`/`deptrac.yaml` change. The *consumer* side is already noisy thanks to `--fail-on-uncovered`; the *depender* side is still silent. | Implementer/orchestrator: narrow to `"classmap": ["src/Kernel.php"]`, or add a catch-all `Unlayered` layer granted nothing. |
| **M4 — the unrecorded `.claude/**` permission-surface commit** | Not a test matter. | Record it in `03-implementation-notes.md` or move it outside the feature range. |
| **m1 — `03-implementation-notes.md` records `guard-running` exiting 2; the `Makefile` says `exit 1`** | A transcription error in a record, not a behaviour; editing the notes of an earlier phase is not the tester's call. **I did not re-verify the guard this phase** (it would have required taking the containers down mid-run). | Reconcile the two batch-4 mentions to `1`. |
| **m2–m10** | Carried forward from the review untouched; all are production-config or documentation items. | See `04-review.md`. |

## Environment and provenance

- Container brought up with `make up` (postgres reported **Healthy**) and left running.
- `docker compose exec -T php php -v` → `PHP 8.5.12 (cli)`, `Zend OPcache v8.5.12`.
- `docker compose exec -T php composer --version` → `Composer version 2.10.3`.
- Host: PHP 8.5.3, used only for the `composer qa` parity run (exit 0).
- Nothing destructive was run: no `git reset`, no `--amend`, no rebase, no push, no
  `docker compose down -v`, no `.env*` file read, written or created.
- `deptrac.yaml`, `phpstan.neon`, `.php-cs-fixer.dist.php`, `phpunit.xml`, `composer.json` and
  everything under `src/` are **unchanged** by this phase. The entire diff is one test file.
