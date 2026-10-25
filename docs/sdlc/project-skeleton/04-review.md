# 04 — Review: project-skeleton

- **Feature slug:** `project-skeleton`
- **Reviewed against:** `docs/sdlc/project-skeleton/02-plan.md`, `docs/sdlc/project-skeleton/01-analysis.md`, `docs/sdlc/project-skeleton/03-implementation-notes.md` and `CLAUDE.md`
- **Diff reviewed:** initial review `b31155c..c01a311` (26 commits); **re-review after fix loop 1** adds `ba4740c`, `9e2c12b`, `4641f14` (+ `0b9d157`, which tracks this document unchanged)
- **Date:** 2026-10-24 · **re-reviewed:** 2026-10-25
- **Reviewer model:** `opus` both passes (mandatory tier: the diff defines the cross-context dependency contract, the container surface and the project's security posture)

## Gate results observed by the reviewer

### Fix loop 1 re-run (host, PHP 8.5.3) — current

| Command | Exit code | Observed |
|---|---|---|
| `composer qa` | **0** | |
| `composer cs-check` | **0** | Found 0 of 8 files |
| `composer phpstan` | **0** | `[OK] No errors`, 7 files |
| `composer deptrac` | **0** | now with `--report-uncovered --fail-on-uncovered`; `Violations 0 / Uncovered 0 / Allowed 0` |
| `composer test` | **0** | `OK (4 tests, 19 assertions)` |
| `composer validate --strict` | **0** | |
| `git status --porcelain` | **empty** | before and after `composer qa` |

### Initial review (unchanged, for the record)

All six commands above exited **0** at `c01a311` as well, with `deptrac` still on the old
script. Docker was reachable in both passes; the container cycle was not re-run by me.

## Verdict

**blockers: 0 open (2 raised, 2 resolved) · majors: 5 · minors: 13**

**Both blockers are genuinely closed, and closed at the root rather than masked.** I re-derived
each from the source and from live probes rather than trusting the notes: B1's new collector makes
`src/Shared` — which previously had *no* protection of any kind — fail on
`Symfony\Component\Uid\Uuid`, and B2's removal of `env_file` is the fix I asked for rather than a
second workaround. Nothing was weakened to get green: the ruleset diff is empty, `Framework` is
still withheld from `Engine` and `Shared`, no baseline or `skip_violations` appeared, and the only
CLI change makes the gate *stricter*. The new mechanism survived adversarial probing better than I
expected — in particular it now catches docblock types and inline fully-qualified names, which the
old regex backstop never did, and it correctly leaves `Random\Randomizer`, `BcMath\Number` and
`DateTimeImmutable` alone inside the Engine.

**No new blocker.** The fix introduces one new major (N1) and three minors (N2–N4). N1 is the
honest answer to "what is now *mis*-allowed": `Framework` is defined as "any non-`Storyteller`,
non-internal class-like", which includes the six `require-dev` packages, so a production class
importing `PHPUnit\Framework\Assert` is now affirmatively **Allowed** — I confirmed it with a probe.
That is not a regression (it passed before too, as uncovered), but the fix had `--fail-on-uncovered`
in one hand and neutralised it with the other for this one case, and the error it hides is
production-fatal under `composer install --no-dev`.

M1–M4 and m1–m10 are carried forward untouched; none was in scope for the fix loop. **M3 is now
partially addressed** — `--fail-on-uncovered` closes its *consumer* side — but M3 itself remains
open; see the note under M3.

## Fix loop 1 — verification and adversarial review

### B1 and B2: closed, with evidence

Everything below was produced by me, on the host, with throwaway files written **outside the
repository** (in the session scratchpad) and the project's `vendor/bin/deptrac` pointed at them.
No file in the repository was created, edited or deleted at any point.

| Probe (scratch tree, committed `deptrac.yaml`) | Result | Means |
|---|---|---|
| `src/Shared` → `Symfony\Component\Uid\Uuid` | **Violation** `(Framework)` | B1's worst half closed. `src/Shared` had **no** check before — not deptrac (uncovered, and no `--fail-on-uncovered`), not the unit test (it scans `src/Engine` only). |
| `src/Engine` → `\Doctrine\ORM\Query::class`, inline fully qualified, no `use` | **Violation** `(Framework)` | Closes a gap I named in the initial review: the regex backstop cannot see inline FQNs. |
| `src/Engine` → `@param \Symfony\Component\HttpFoundation\Request` in a **docblock only** | **Violation** `(Framework)` | Stronger than I assumed; docblock types are emitted and classified. |
| `src/Engine` → `DateTimeImmutable`, `Random\Randomizer`, `Random\Engine\Mt19937`, `BcMath\Number` | **silent** (0 violations, 0 uncovered) | No false positives. Critically, the seeded-`Randomizer` pattern that CLAUDE.md's determinism rule all but mandates is unaffected. |
| `src/Engine` → `Storyteller\Kernel` | **Uncovered ×2, exit 1** | `--fail-on-uncovered` is live and bites. |
| `src/Content` → `Symfony\Component\Routing\Route` | **Allowed** | Content/Play/Api keep their framework grant; no false failure. |
| Whole scratch run | **exit 1** | |
| Committed tree, real `composer deptrac` | **exit 0**, `Violations 0 / Uncovered 0` | No false positive on the real tree. |

For **B2** I did not re-run the container (the fix-loop notes record `printenv APP_ENV` →
no output, and a temporary `.env.local` winning for `APP_SECRET` and `DATABASE_URL` in
`debug:dotenv` *and* in an actual `doctrine:migrations:status` connection). What I verified is that
the change is the **root-cause** fix and not a second patch: `compose.yaml` no longer has an
`env_file:` block at all, the replacement is a comment explaining why it must not come back, and
`phpunit.xml`'s `<env>` entries were **kept** with their comment corrected to say they are
hardening in their own right rather than the thing that makes the container work. That is exactly
the disposition I recommended. `Dotenv::populate()`'s
`if (!isset($loadedVars[$name]) && !$overrideExistingVars && isset($_ENV[$name])) { continue; }`
can no longer fire for `.env` values because nothing puts them in `$_ENV` ahead of Dotenv.

### Adversarial review of the new `Framework` collector

Treated as new code by an untrusted author. Mechanism: `bool` collector,
`must: classLike '.*'`, `must_not: classLike '^Storyteller\\'`, `must_not: php_internal '.*'`.

- **Semantics are what the comment claims.** `BoolCollector::satisfy()` returns false if *any*
  `must` fails or *any* `must_not` matches, so the layer is exactly "class-like ∧ ¬ours ∧
  ¬internal". `AbstractTypeCollector::satisfy()` returns false for anything that is not a
  `ClassLikeReference`, and `ClassLikeType::TYPE_CLASSLIKE` matches classes, interfaces, traits and
  enums alike. The `^Storyteller\\` regex is compiled as `/^Storyteller\\/i` and matches the
  single-backslash token string correctly.
- **`Framework` and the context layers are provably disjoint**, which is the invariant the whole
  design rests on: `must_not: classLike '^Storyteller\\'` excludes every first-party token, and the
  five `directory` collectors only ever match first-party files. No token can be in both.
- **`ignore_uncovered_internal_classes: true` does not swallow anything it should not.**
  `UncoveredDependentHandler::ignoreUncoveredInternalClass()` and `PhpInternalCollector::satisfy()`
  read the **same** source of truth — `JetBrains\PHPStormStub\PhpStormStubsMap::CLASSES` — so the
  "is it a built-in?" question cannot be answered two different ways by the two mechanisms. It
  suppresses *only* `Uncovered` records for `ClassLikeToken`s present in that map (plus the
  `ReturnTypeWillChange` special case); it has no effect whatsoever on `Violations`. The
  file's comment ("without it every `DateTimeImmutable` in the Engine would fail") is accurate: the
  `php_internal` `must_not` puts built-ins in no layer, and `--fail-on-uncovered` would then fail on
  them. Both halves are needed and both are present.
- **`Content`, `Play` and `Api` behave correctly** — their `Framework` grant is intact, and the
  broader layer means *more* of their vendor use is now affirmatively allowed rather than
  unclassified. No false failure; see N1 for the one thing that is now allowed and should not be.
- **Nothing in `tests/` can fall foul** of `--fail-on-uncovered`: `paths:` is `./src` only, so
  tests are never parsed.
- **`src/Kernel.php` is unaffected as a *depender***, as the commit message claims — deptrac only
  evaluates a dependency when the *depender* is layered, which my `Storyteller\Support\Unlayered`
  probe confirms (see M3). As a *dependent* it is now `Uncovered`, i.e. a context importing the
  Kernel fails. Both behaviours are correct.
- **No mis-classification found** beyond N1. In particular I specifically hunted for internal
  classes the stubs map might miss and turn into spurious `Framework` violations inside the Engine:
  I probed 33 of them against the locked `jetbrains/phpstorm-stubs` v2026.2 map (1482 classes) —
  including `Random\*`, `BcMath\Number`, `PgSql\Connection`, `Pdo\Pgsql`, `Uri\Rfc3986\Uri`,
  `Collator`, `ZipArchive` — and **all 33 are present**. See N3 for the version coupling this
  creates.

### Is the accepted tradeoff sound? (`Framework` can no longer distinguish packages)

**Yes — I agree it is the right trade for a foundation, and it does not store up a worse problem.**
Three reasons, the third of which I verified rather than assumed:

1. **For a foundation, exhaustiveness beats granularity.** A granular layer has to be kept in sync
   by hand, and its failure mode is a *false green* — which is not hypothetical here: it is
   precisely what B1 was. The list rotted within three commits of C-1 adding nine packages, and
   nothing in the project could have noticed. The coarse layer has no state to rot.
2. **The granularity is deferred, not lost.** deptrac composes: a narrow grant is still expressible
   as a dedicated layer with a `composer` collector plus a matching `must_not` in the `Framework`
   bool collector. The cost — one extra layer and one extra `must_not` — is paid only when someone
   actually wants a narrow grant, and P3 explicitly *wants* that to be a deliberate, reviewed
   change. Making it cost a reviewed config edit is the feature, not the bug.
3. **The new load-bearing invariant fails loudly, not silently.** My one real worry was that the
   disjointness requirement is now unenforced: if a future `Uid` layer is added and the matching
   `must_not` is forgotten, `symfony/uid` sits in two layers at once. I built exactly that scenario
   in the scratch tree — `Uid` layer with a `composer` collector over `symfony/uid`, `Shared: [Uid]`,
   **no** `must_not` — and `Shared -> Uuid` came back as a
   `DependsOnDisallowedLayer … (Framework)` **violation**, exit 1. The grant simply does not work
   until the `must_not` is added. A forgotten invariant that produces a loud, correctly-attributed
   failure on the first run is an acceptable invariant. (The fix-loop notes describe this case as
   "a deptrac warning"; it is in fact a violation — `Warnings 0` — which is safer than described.
   Noted in N4.)

This supersedes nothing in **m2**, which stays open and now carries a known price: letting `Shared`
use `symfony/uid` alone costs a layer plus a `must_not`, not a list edit.

## Findings

### Blockers

#### B1 — The `Framework` layer covered 26 of the 59 installed framework packages, so Engine/Shared purity was not enforced for the other 33 — **RESOLVED (`ba4740c`)**
- **File:** `deptrac.yaml:61-76` (was `:43-69`); `composer.json:62` (the `deptrac` script)
- **Category:** dependency rule
- **Problem (as found):** `ComposerCollector` resolves only the namespaces of the packages it is
  handed — `ComposerFilesParser::autoloadableNamespacesForRequirements()` loops over `$requirements`
  and nothing else. I enumerated `composer.lock` against the list: **33 installed
  `symfony/*`/`doctrine/*`/`psr/*` packages were outside the layer**, including
  `Symfony\Component\Routing\`, `Symfony\Component\String\`, `Symfony\Component\Cache\`,
  `Symfony\Bridge\Doctrine\`, all four `Symfony\Contracts\*`, `Doctrine\Common\`, `Psr\Cache\` and
  `Psr\EventDispatcher\`. Nine of them were promoted to explicit `require` entries by correction
  C-1 and `deptrac.yaml`, written afterwards at step 9 from the plan's pre-C-1 verbatim list, was
  never reconciled. A token in no layer is reported as *Uncovered*, not as a violation, and the
  script did not pass `--fail-on-uncovered`, so `composer deptrac` exited **0** on all of them.
  `src/Engine` had a partial backstop (the `use`-statement regex in `BoundedContextLayoutTest`);
  **`src/Shared` had none.**
- **Resolution:** both halves I suggested were taken. The enumerated collector is replaced by a
  `bool` collector that is exhaustive by construction — "class-like ∧ not `^Storyteller\\` ∧ not
  `php_internal`" — so there is no list left to go stale; and the script now runs
  `--report-uncovered --fail-on-uncovered`, with `ignore_uncovered_internal_classes: true` written
  out explicitly because the flag depends on it. The two changes are correctly *coupled*: the
  exhaustive layer is what makes `--fail-on-uncovered` tolerable, and the flag is what gives the
  remaining unlayered zone teeth.
- **Verified closed, by me:** see the probe table above — `Shared -> Uuid` violates where it
  previously passed; `Engine -> \Doctrine\ORM\Query::class` (inline FQN) and an
  `@param \Symfony\…\Request` docblock both violate, neither of which the regex backstop could ever
  see; built-ins including `Random\Randomizer` stay silent; the real tree is still `Violations 0 /
  Uncovered 0`, exit 0. **No check was weakened** (standing rule 4): the ruleset diff is empty, the
  only CLI change adds strictness, and no baseline or `skip_violations` was introduced.
- **Residual:** N1 (dev-only packages affirmatively allowed in Content/Play/Api), N2 (vendor
  *functions* remain invisible — a deptrac limitation, not a defect in this fix), N3 (the
  built-ins guarantee is pinned to the stubs map version).

#### B2 — `env_file: .env` made `.env.local` incapable of overriding anything inside the container — **RESOLVED (`9e2c12b`)**
- **File:** `compose.yaml:16-20` (the block is gone, replaced by a comment); `phpunit.xml:30-34`
- **Category:** security
- **Problem (as found):** `env_file: - .env` injected `APP_ENV`, `APP_SECRET` and `DATABASE_URL`
  into the container's real process environment, and `Dotenv::populate()` skips any variable already
  in `$_ENV`, so the committed `.env` won unconditionally and the git-ignored `.env.local` that
  `.env:1` and the README both tell developers to use for secrets could never take effect in the
  only supported runtime. It was also the root cause the step-11b `phpunit.xml` `<env>` entries
  patched over.
- **Resolution:** the `env_file:` block is removed outright — the root-cause fix, not a second
  workaround — and replaced by a comment that explains the Dotenv mechanics so it does not come
  back. Dotenv reads `/app/.env` then `/app/.env.local` through the bind mount, exactly as on the
  host. The `<env>` entries in `phpunit.xml` are **kept**, with their comment corrected to describe
  them as hardening against any ambient `APP_ENV` rather than as a container workaround. That is
  precisely the disposition I recommended.
- **Verified closed:** the fix-loop notes record, in the container, `printenv APP_ENV` /
  `DATABASE_URL` → no output (exit 1), `bin/console about` → exit 0 in `dev`, and a temporary
  `.env.local` winning for `APP_SECRET` **and** `DATABASE_URL` both in `debug:dotenv` and in a real
  `doctrine:migrations:status` connection to the overridden database — i.e. the override reaches
  application config, not just a debug command — followed by deleting the file and re-confirming
  `make qa` → 0. I verified on the host that `composer qa` → 0 and the tree is clean. Nothing was
  weakened; `.env` is still tracked and still secret-free.
- **Residual:** m3 stands (identical `.env.example`, unguarded `APP_SECRET`) — now actually
  fixable, because the override mechanism it depends on finally works.

### Majors

#### N1 — `Framework` now affirmatively allows `require-dev` packages inside `Content`, `Play` and `Api` *(new — introduced by the B1 fix)*
- **File:** `deptrac.yaml:61-76`
- **Problem / Why / Suggested fix:** The new layer is "any class-like that is not ours and not a PHP
  built-in", which includes the six `require-dev` packages — `phpunit/phpunit`,
  `friendsofphp/php-cs-fixer`, `phpstan/*`, `deptrac/deptrac` — and every dev package added later.
  I probed it: `src/Content/Domain` importing `PHPUnit\Framework\Assert` alongside
  `Symfony\Component\Routing\Route` reports `Allowed 2`, no violation, exit 0 on that file. A
  production class importing a dev-only package is fatal under `composer install --no-dev`, and no
  gate in this project catches it: PHPStan analyses with the dev autoloader, cs-fixer does not care,
  and deptrac now blesses it. **This is not a regression** — before the fix the same import was
  *Uncovered* and also passed — but the fix introduced `--fail-on-uncovered`, which *would* have
  caught it, and then exempted it with the blanket collector. It is the one class of dependency that
  is now mis-*allowed* rather than merely unclassified, which is exactly what the re-review brief
  asked me to hunt for. Fix: add a `Dev` layer with a `composer` collector over the six `require-dev`
  package names, add a matching `must_not: {type: composer, …}` to `Framework`'s `bool` collector so
  the two stay disjoint, and grant `Dev` to **no** layer — then `src/**` → PHPUnit becomes a
  `DependsOnDisallowedLayer` violation. This is cheap, and it is a useful first exercise of the
  "narrow layer + matching `must_not`" pattern the new `deptrac.yaml` comment already documents as
  the supported way to carve exceptions.

#### M1 — `migrations/` is covered by zero quality gates *(open, carried forward)*
- **File:** `phpstan.neon:10-14`; `.php-cs-fixer.dist.php:5-19`; `deptrac.yaml:4-5`
- **Problem / Why / Suggested fix:** `phpstan.neon` analyses `src`, `tests`, `config`, `public`;
  the cs-fixer finder covers `src`, `tests`, `config`, `public` plus `bin/console`; deptrac's
  `paths` is `./src` only. `migrations/` is in none of them, yet it is PSR-4 mapped
  (`composer.json:50`), registered with DoctrineMigrationsBundle
  (`config/packages/doctrine_migrations.yaml:3`), and CLAUDE.md standing rule 6 routes *every*
  schema change through it. The first migration class will ship without `declare(strict_types=1)`
  enforcement, without level-8 analysis of its `$schema`/`addSql` code, and without any dependency
  check — in a project whose core data structure is a JSONB snapshot that migrations will
  manipulate. Add `migrations` to `phpstan.neon` `paths` and to the cs-fixer finder's `->in([...])`.
  Decide deliberately whether `migrations/` joins deptrac's `paths` — note this interacts with the
  new `--fail-on-uncovered`: `Storyteller\Migrations\*` is excluded from `Framework` by the
  `^Storyteller\\` rule and matches no `directory` collector, so adding the path without adding a
  layer would surface it as uncovered. Record the decision either way.

#### M2 — PHPStan analyses the generated, gitignored `config/reference.php`, so the gate's input set depends on whether a kernel booted *(open, carried forward)*
- **File:** `phpstan.neon:10-14`; `.gitignore:3`; `.php-cs-fixer.dist.php:12-15`
- **Problem / Why / Suggested fix:** `vendor/bin/phpstan analyse --debug` lists
  `config/bundles.php`, **`config/reference.php`**, `public/index.php`, `src/Kernel.php`,
  `tests/Integration/KernelBootTest.php`, `tests/Unit/Shared/BoundedContextLayoutTest.php`,
  `tests/bootstrap.php` — 7 files, one of which is Symfony's 78 KB auto-generated config-builder
  dump, rewritten by `PhpConfigReferenceDumpPass` on every debug-mode container compile and
  gitignored since C-2. C-2 removed it from git's index and from the cs-fixer finder but **not**
  from PHPStan's paths, so it fixed one half of a two-sided problem. `composer phpstan` therefore
  analyses 6 files on a fresh clone and 7 after any `composer test`, and the day a bundle emits a
  config builder PHPStan dislikes, the gate goes red on a file nobody wrote and nobody can edit. A
  non-reproducible gate is a weakened gate. Add `excludePaths: [config/reference.php]` to
  `phpstan.neon`, mirroring the `->notPath('reference.php')` already in `.php-cs-fixer.dist.php:15`.

#### M3 — Deviation D-1 (`"Storyteller\\": "src/"`) opens a layer-free zone at the `src/` root *(open; **partially addressed** by the B1 fix)*
- **File:** `composer.json:44`; `deptrac.yaml:15-44` (five `directory` collectors, nothing else)
- **Problem / Why / Suggested fix:** D-1 was *necessary* — the plan's PSR-4 map genuinely has no
  rule that autoloads `Storyteller\Kernel` — but the chosen fix is broader than the problem.
  `Storyteller\ => src/` makes every future top-level directory under `src/` autoloadable, while
  deptrac's collectors only match `src/{Shared,Engine,Content,Play,Api}/.*`. `src/Kernel.php` in no
  layer is itself **sound** (one file, framework-by-definition, never imported by a context, and
  R13 reasoned it correctly); what is not sound is that the escape hatch became *generic*.
  **What the fix changed:** `--fail-on-uncovered` closes the *consumer* side — I probed
  `src/Engine` → `Storyteller\Kernel` and it is now `Uncovered`, exit 1, so a layered class can no
  longer quietly depend on an unlayered first-party class. **What it does not change:** the
  *depender* side. I probed a `src/Support/Unlayered.php` in namespace `Storyteller\Support`
  importing `Doctrine\ORM\EntityManagerInterface` and `Symfony\Component\HttpFoundation\Request`:
  **nothing was reported at all** — not a violation, not uncovered, not a warning — because deptrac
  only evaluates dependencies whose depender is layered. A new top-level directory under `src/` is
  still a one-file route to code that no context rule constrains. Prefer the narrow fix: drop the
  `Storyteller\ => src/` psr-4 rule and autoload the kernel with
  `"autoload": {"classmap": ["src/Kernel.php"]}`, which loads exactly one class and leaves the
  `src/` root unmappable. A cheap belt-and-braces alternative is a catch-all `Unlayered` layer over
  `src/(?!Shared|Engine|Content|Play|Api).*` granted nothing, so the depender side fails too.

#### M4 — An unplanned commit widens the agent permission surface inside this feature's history *(open, carried forward)*
- **File:** `.claude/settings.json` and `.claude/agents/{analyst,implementer,planner,reviewer,tester}.md` (commit `9bcc496`, between step 8 and step 9)
- **Problem / Why / Suggested fix:** The plan authorises exactly one edit outside the new files —
  `CLAUDE.md` line 3 (D1) — and D2 explicitly declines to touch anything under `.claude/` on the
  grounds that it "is the user's call, not the pipeline's". Commit `9bcc496` nevertheless changes
  `.claude/settings.json` and all five agent definitions mid-feature (+216/-7), and
  `03-implementation-notes.md` does not mention it anywhere: it is the only commit in the range that
  no phase document accounts for. The content broadens the allowlist substantially — bare `Write`,
  `Edit`, `Bash(php *)`, `Bash(cp *)`, `Bash(mv *)`, `Bash(npm|npx|yarn|pnpm *)` — a change to the
  project's safety posture that every future SDLC run inherits. The deny side is a sensible
  counterweight, but one of its rules already has a measurable cost: `Read(**/.env*)` blocks
  `.env.example`, which forced step 13 to write the README's `DATABASE_URL` and credential claims
  from the plan document rather than from the file (recorded as a batch-5 deviation). I am not
  asserting the change is wrong — it may well have been a separate, explicit user decision — but it
  must not sit unrecorded in a feature's history. Either record it in `03-implementation-notes.md`
  as an out-of-band change with its rationale, or move it onto its own commit outside the feature
  range. Separately, narrow the `.env*` deny rules so `.env.example` — a deliberately secret-free,
  committed file — is readable.

### Minors

#### N2 — Vendor *functions* are invisible to the dependency rule, and to the test backstop too *(new — a residual of the B1 fix, not a defect in it)*
- **File:** `deptrac.yaml:61-76`; `tests/Unit/Shared/BoundedContextLayoutTest.php:49-53`
- **Problem / Suggested fix:** A probe in `src/Engine` with `use function
  Symfony\Component\String\u;` and a call to `u($s)->upper()` produces **no violation, no uncovered
  entry and no warning**. Two mechanisms conspire: the `Framework` layer's `must: classLike` returns
  false for a `FunctionReference` (`AbstractTypeCollector::satisfy()` rejects non-class-like
  references), and `UncoveredDependentHandler::invoke()` only records an `Uncovered` when the
  dependent `instanceof ClassLikeToken`, so function dependencies are dropped rather than reported.
  The unit test misses it too: `/^use\s+Symfony\\/m` does not match `use function Symfony\…`.
  **I verified this is deptrac's limitation, not a configuration mistake**: I re-ran the same probe
  with a `functionName` collector added to the `Framework` layer, and again with
  `analyser.types: [class, function, function_call]`, and neither caught it. So there is no cheap
  deptrac-side fix and the fix loop should not be faulted for it. The practical mitigation is the
  test side: add `'/^use\s+function\s+(Symfony|Doctrine|Psr|Monolog)\\\\/m'` to
  `FORBIDDEN_ENGINE_IMPORTS`, and — worth doing anyway — extend the same scan to `src/Shared`, which
  still has no test-side purity check even though deptrac now covers its class imports. Low
  likelihood (Symfony and Doctrine expose very few namespaced functions), recorded so it is a known
  edge rather than a surprise.

#### N3 — The "PHP built-ins are exempt" guarantee is pinned to `jetbrains/phpstorm-stubs` *(new)*
- **File:** `deptrac.yaml:7-10`; `composer.lock` (`jetbrains/phpstorm-stubs` v2026.2)
- **Problem / Suggested fix:** Both `PhpInternalCollector` and `UncoveredDependentHandler` decide
  "is this a built-in?" from `PhpStormStubsMap::CLASSES`, a transitive dependency of deptrac. Under
  the new collector, a built-in that the map does *not* know is no longer merely uncovered — it is
  classified as `Framework`, i.e. a hard **violation** inside `Engine` or `Shared`. I probed 33
  built-ins against the locked v2026.2 map (1482 classes) and **all 33 are present**, including
  `Random\Randomizer`, `Random\Engine\Mt19937`, `BcMath\Number`, `Uri\Rfc3986\Uri`,
  `PgSql\Connection`, `Pdo\Pgsql` and `Collator` — so there is no false-positive risk today, and in
  particular the seeded-`Randomizer` idiom that CLAUDE.md's determinism rule implies is safe.
  Deptrac's own constraint, however, is `2024.3 || 2025.3 || 2026.1 || v2026.2`, so a future resolve
  could in principle land on an older map that predates PHP 8.5's additions. Nothing to change —
  `composer.lock` is committed — but record it, so that if the gate ever flags a PHP built-in inside
  the Engine the diagnosis ("stale stubs map") is one line away rather than an afternoon.

#### N4 — Two small inaccuracies in the fix-loop notes and the `deptrac.yaml` comment *(new)*
- **File:** `docs/sdlc/project-skeleton/03-implementation-notes.md` (Gate C fix loop 1, "the cost, stated plainly"); `deptrac.yaml:12-13`
- **Problem / Suggested fix:** (a) The notes say a token in two layers "is a deptrac warning and
  makes both rulesets apply". I built that scenario (a `Uid` layer with a `composer` collector over
  `symfony/uid`, `Shared: [Uid]`, no matching `must_not`) and the result is a
  `DependsOnDisallowedLayer … (Framework)` **violation**, exit 1, with `Warnings 0` — i.e. louder
  and safer than described, which strengthens rather than weakens the argument. (b) The retained
  comment "deptrac's default emitter set already covers use / class / new / attribute / parameter /
  return" is incomplete: the default `analyser.types` is `[class, function]`, and it is the function
  half that N2 falls through. Correct both so the next reader is not reasoning from a slightly wrong
  model of the tool.

#### m1 — `03-implementation-notes.md` records the wrong `guard-running` exit code *(open)*
- **File:** `docs/sdlc/project-skeleton/03-implementation-notes.md:638` and `:676`; `Makefile:27`
- **Problem / Suggested fix:** Batch 4's step-12 log says the guard "exit **2** (non-zero)" and its
  §"Final check state" repeats `make qa (containers down) -> guard error, exit 2`. The committed
  `Makefile:27` is `{ echo "php container is not running - run 'make up' first"; exit 1; }`, so the
  real code is **1**. Batch 5 caught the discrepancy, correctly declined to touch the Makefile
  (out of scope) and wrote `README.md:41-43` to assert only "exit non-zero" — accurate either way
  and the right call — but the batch-4 text was never reconciled. **Severity: minor, deliberately.**
  It is a transcription error in a record, not a behavioural defect: the Makefile is correct, the
  README is correct, and no gate or acceptance criterion depends on the value. It is recorded
  because the notes are the audit trail a later agent will trust. Correct the two batch-4 mentions
  to `1`.

#### m2 — `Shared: []` forbids the `symfony/uid` that was required specifically for ids *(open; price now known)*
- **File:** `deptrac.yaml:81`; `composer.json:28`; `02-plan.md` P3
- **Problem / Suggested fix:** CLAUDE.md puts "value objects, **ids**, clock, errors" in
  `src/Shared`; the rule-9 justification for `symfony/uid` is "Story / session / version identifiers
  (UUIDv7)"; and `Shared` has no `Framework` edge. I confirmed by probe that the first `StoryId`
  value object in `src/Shared` wrapping `Symfony\Component\Uid\Uuid` now **fails** `composer deptrac`
  — which is P3 working as designed, and is new since the fix (it passed silently before). P3
  anticipated this as "a reviewed, deliberate ruleset change", so it is a scheduled decision rather
  than a defect; but it is a required dependency with no legal home, in tension with standing
  rule 9. Settle it before the first `Shared` class: either keep ids framework-free (hand-rolled
  UUIDv7 in `Shared`, `symfony/uid` only in `Content`/`Play`/`Api` at the persistence and
  serialisation edge), or add a dedicated `Uid` layer with a `composer` collector over
  `symfony/uid`, a matching `must_not` in `Framework`, and `Shared: [Uid]`. Do **not** grant
  `Shared` the whole `Framework` layer — that defeats P3. Post-fix the second option costs a layer
  plus a `must_not` rather than a one-line list edit; see §"Is the accepted tradeoff sound?".

#### m3 — `.env` is tracked and byte-identical to `.env.example` *(open; now actually fixable)*
- **File:** `.env:1-4`, `.env.example:1-4`
- **Problem / Suggested fix:** I inspected the tracked `.env`: `APP_ENV=dev`,
  `APP_SECRET=change_me_in_env_local`, and a `DATABASE_URL` with the `storyteller/storyteller` dev
  credentials pointing at the Compose-internal host `postgres`. **No real secret is committed** and
  acceptance criterion 18 holds. Committing `.env` while gitignoring `.env.local` / `.env.*.local`
  (`.gitignore:4-6`) is correct Symfony practice, and with B2 fixed the `.local` half finally works
  in the container. Two residual notes: (a) the two files are byte-identical, so `.env.example`
  carries no additional information and will silently drift the first time `.env` gains a key —
  consider dropping it or reducing it to keys with empty values; (b) `APP_SECRET` is a fixed,
  world-readable constant with nothing forcing a non-dev context to override it. Nothing in the
  skeleton uses it (no sessions, no CSRF, no `security-bundle`), so the impact today is nil — but
  the first feature that adds signed cookies or CSRF inherits a published secret. Add a `when@prod`
  guard or a documented deployment check before that happens.

#### m4 — Version banners are not suppressed in nginx or PHP *(open)*
- **File:** `docker/php/php.ini:1-8`; `docker/nginx/default.conf:2-4`
- **Problem / Suggested fix:** `expose_php` is not `Off`, so every FPM response carries
  `X-Powered-By: PHP/8.5.12`; nginx has no `server_tokens off;`, so it advertises `nginx/1.27.x`.
  Dev-only today and low impact, but these are the two one-line hardening defaults that never get
  added later if they are not added now. `display_errors = Off` is already correctly set.

#### m5 — No `when@prod` configuration anywhere, and `auto_generate_proxy_classes` is unconditional *(open)*
- **File:** `config/packages/doctrine.yaml:10`; `config/packages/monolog.yaml`
- **Problem / Suggested fix:** In `prod`, Monolog would have no handlers at all and Doctrine would
  regenerate proxies on every request. Production hardening is explicitly out of scope per the
  analysis, so this is correctly deferred — recorded only so the first deployment feature does not
  assume the config is production-shaped. Wrap `auto_generate_proxy_classes` in
  `when@dev`/`when@test` when a prod target lands.

#### m6 — `composer qa` omits `composer validate --strict` *(open)*
- **File:** `composer.json:64-69`
- **Problem / Suggested fix:** Every batch, and the fix loop, ran `composer validate --strict` by
  hand and reported it — a good signal that it belongs in the aggregate. Add a `validate` script and
  `@validate` as the first entry of `qa`; it is the cheapest check of all.

#### m7 — `deptrac.yaml`'s paths are CWD-relative *(open; partly obsoleted by the fix)*
- **File:** `deptrac.yaml:4-5`
- **Problem / Suggested fix:** `paths: ./src` and the `directory` collector values are resolved
  against the working directory, so `composer deptrac` works only from the project root — fine
  today (the composer script and the Makefile both run there), worth knowing before anyone wires
  deptrac into a hook or a CI step with a different CWD. The original half of this finding — the
  unused `composerPath` key in the `composer` collector — is gone with that collector.

#### m8 — `tests/` is outside deptrac's `paths` *(open)*
- **File:** `deptrac.yaml:2-5`
- **Problem / Suggested fix:** Deliberate and documented in the file's own comment, and correct for
  now. Revisit once integration tests start crossing contexts: nothing stops `tests/Unit/Engine/**`
  from importing `Storyteller\Api\…`, which would make an Engine unit test non-pure without any
  gate noticing. Note that adding `tests` to `paths` would now interact with
  `--fail-on-uncovered`, so it needs its own layer design rather than a one-line path edit.

#### m9 — `apt-get purge -y --auto-remove` with no package names is a no-op *(open)*
- **File:** `Dockerfile:23`
- **Problem / Suggested fix:** Inherited verbatim from the plan. With no arguments it purges nothing
  and only autoremoves orphans, so `libpq-dev`, `libicu-dev` and `libzip-dev` stay in the image.
  Harmless for a dev-only image (arguably desirable, since rebuilding an extension needs them), but
  the line reads as cleanup that is not happening. Either name the `-dev` packages or drop the
  `purge` and keep only `rm -rf /var/lib/apt/lists/*`.

#### m10 — Known-accepted residuals, recorded so they are not rediscovered *(open)*
- **Files:** `config/packages/doctrine.yaml` (C-1); `phpstan.neon:16-18` (P5); `bin/console`
- **Problem / Suggested fix:** (a) C-1's removal of `enable_native_lazy_objects` reintroduces the
  `Doctrine\ORM\Proxy\Autoloader` deprecation notice on stderr in dev; `about` still exits 0, no
  gate is affected, and it becomes moot on ORM 4 — accepting it rather than re-adding an
  unauthorised config key was the right call. (b) `phpstan-symfony` is loaded without
  `containerXmlPath`, so its container/service-map rules are inert; P5's reasoning is sound and the
  commented line is in place — enable it with the first real services. (c) `bin/console` is reached
  by the cs-fixer finder via `->append()` but not by PHPStan (no `.php` extension); correct and
  documented, just invisible.

## Are the gates green by vacancy? *(re-assessed after the fix)*

| Gate | Verdict | Evidence |
|---|---|---|
| `composer cs-check` | **Protective.** | 8 files, proven to bite twice (steps 8 and 10 each removed `declare(strict_types=1);` and observed exit 8, with the dry run confirmed not to rewrite the file). `setRiskyAllowed(true)` is present and load-bearing. Gap: `migrations/` (M1). |
| `composer test` | **Protective, modestly.** | 4 tests, 19 assertions, asserting real invariants rather than implementation. `failOnRisky`/`failOnWarning`/`failOnSkipped` make criterion 6 machine-checked. Its Engine-import scan is no longer the *only* live purity check — deptrac now does more than it does (including inline FQNs and docblocks). Remaining unique value: the `use function` case it could cover but does not (N2). |
| `composer deptrac` | **Still vacuous on today's tree, but no longer vacuous in mechanism.** | `Violations 0 / Uncovered 0 / **Allowed 0**` on the real tree — zero *allowed* dependencies remains the tell that not one layered token exists. What changed is the counterfactual: I verified by probe that `Engine -> Symfony\Component\Routing`, `Shared -> symfony/uid`, an inline `\Doctrine\ORM\Query::class`, a docblock `@param`, and a layered class reaching an unlayered `Storyteller\…` all now fail, where before the fix the first two silently passed. This is the gate that moved most. |
| `composer phpstan` | **Decorative today, and thinner than it looks** — unchanged by the fix. | 7 files, of which one is generated vendor output (M2), one is an array literal, one is three lines, and `src/Kernel.php` is an empty class with a trait. `phpstan-symfony`'s container rules are inert without `containerXmlPath` (P5) and `phpstan-doctrine` has no entity to see. Not a defect — the honest state of a skeleton — but `composer phpstan` exiting 0 certifies that the config parses and the extensions load, nothing more. Gap: `migrations/` (M1). |

One of four gates is still ceremonial on today's tree; the one that matters most for a foundation
is now correct in mechanism and proven to fire.

## Checklist

| Area | Result | Note |
|------|--------|------|
| Dependency rule (deptrac) | **pass** (was fail) | Five context edges byte-identical after the rewrite; `Api -> Engine` still absent; `Framework` still withheld from both `Engine` and `Shared` (P3 intact and now actually enforced for `Shared`); the layer is exhaustive by construction and the gate fails on unlayered dependencies. Residual: N1, N2, and M3's depender side. |
| Engine purity & determinism | **pass** | No PHP file under `src/Engine`; excluded from `config/services.yaml` per P4; no `rand()`/`time()`/statics. Purity is now enforced by deptrac for classes, interfaces, traits, enums, attributes, docblock types and inline FQNs, and `Random\Randomizer`/`DateTimeImmutable` are correctly exempt — so the determinism rule's preferred idiom is not collateral damage. |
| Plan conformance | **pass with recorded deviations** | 14 steps, C-1/C-2/C-3, and fix loop 1, each logged with reasoning and verification. The fix loop's two deviations from the plan's prescriptive `deptrac.yaml`/`compose.yaml` sections are recorded and are review-directed, which is the right basis. One unrecorded commit remains (M4). |
| Engine changes have unit tests | **n/a — correctly** | No `src/Engine/**` change beyond `.gitkeep`; the fix loop's probes were created, measured and deleted outside any commit. `tests/Unit/Engine/.gitkeep` is ready. |
| Input validation & auth | **n/a** | No API surface in scope. `symfony/validator` installed and `framework.validation` enabled, so standing rule 5 has its tooling ready. |
| Error handling | **pass** | `handle_all_throwables: true`, `php_errors.log: true`, `display_errors = Off` in the container. `composer qa` is a JSON array with no `|| true`; fail-fast proven at exit 8. Monolog has no `when@prod` handler (m5). |
| Performance (N+1, indexes, loads) | **n/a** | No entities, queries or migrations. `auto_generate_proxy_classes: true` unconditional (m5). |
| Naming & PSR-12 | **pass** | `composer cs-check` exit 0 over 8 files; namespaces match paths; ubiquitous language consistent with CLAUDE.md. `migrations/` unformatted-by-omission (M1). |
| Standing rule 4 (nothing weakened to go green) | **pass** | Verified for the fix loop specifically: empty ruleset diff, no baseline, no `skip_violations`, no widened constraint, no relaxed fixer rule, and the only CLI change *adds* two strictness flags. |

## Good things worth keeping

- **The fix loop fixed root causes, not symptoms, in both cases.** B1 removed the enumeration rather
  than correcting it — "hand-enumeration is what failed, so the enumeration is gone" is the right
  diagnosis — and B2 deleted `env_file` rather than adding a second compensating patch, while
  *keeping* the `phpunit.xml` `<env>` lines on their independent merits and correcting their comment
  to say so. That is a precise, non-cargo-cult response to review.
- **The cost of the chosen mechanism is stated in the artifact itself**, not only in the notes:
  `deptrac.yaml` carries an `EXHAUSTIVE BY CONSTRUCTION — do not replace this with a package list`
  comment, the history of why the list failed, and the exact shape a future narrow grant must take.
  A future maintainer who reaches for the old pattern is told why not, in the file they are editing.
- **`ignore_uncovered_internal_classes: true` was written out explicitly with its reason** even
  though it is the default, precisely because `--fail-on-uncovered` now depends on it. Making an
  implicit default explicit at the moment it becomes load-bearing is exactly right.
- **Probe-driven evidence, twice.** Step 9 and the fix loop both proved a green check would bite by
  creating, measuring and deleting throwaway classes, with the exact violation text recorded. It is
  why this re-review could focus on what the mechanism *mis*-classifies rather than on whether it
  works at all. The same discipline shows in steps 8 and 10 deliberately breaking formatting to
  watch cs-fixer and `qa` fail at exit 8.
- **C-1 replaced a workaround with its root cause**, and **C-3 improved on an approved plan**
  (loopback-binding the dev database, keeping the port usable, and documenting in the README why it
  must not be reverted).
- **Refusing to manufacture a signal, throughout.** No placeholder class under `src/Engine`, no
  PHPStan baseline, no `skip_violations`, no narrowed finder root, no widened constraint, no
  `--ignore-platform-reqs`. Standing rule 4 was respected under real pressure, repeatedly.
- **The notes name their own uncertainty** — `.deptrac.cache`, the `config/reference.php` flap, the
  `guard-running` exit code, and the fix loop's own "this satisfies part of M3 but does not fix M3".
  Self-reported partial credit is rarer and more useful than it sounds.
