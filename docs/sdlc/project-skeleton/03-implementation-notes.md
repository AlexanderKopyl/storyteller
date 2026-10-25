# 03 — Implementation notes: project-skeleton

- **Feature slug:** `project-skeleton`
- **Plan:** `docs/sdlc/project-skeleton/02-plan.md`
- **Date:** 2026-10-24

> **Batch 1 of 4 — steps 1–6 only.** Steps 7–14 (PHPStan config, cs-fixer config, deptrac,
> the `qa` script, Docker, Makefile, README, the `CLAUDE.md` line-3 edit) are deliberately
> not implemented here and belong to later batches, which append to this same document.

> **Batch 2 — steps 7–8 only**, run on `sonnet` per the plan's routing table (both steps
> ship their file contents verbatim; no design judgement was open). Steps 9–14 (deptrac,
> the `qa` script, Docker, Makefile, README, the `CLAUDE.md` line-3 edit) remain for later
> batches.

> **Batch 3 — step 9 only**, run on `opus` per the plan's routing table (`deptrac.yaml` *is*
> the cross-context dependency contract). Step 10 (the aggregate `qa` script) and steps
> 11–14 (Docker, Makefile, README, the `CLAUDE.md` line-3 edit) are deliberately not
> implemented here.

## Progress

| Step | Status | Commit |
|------|--------|--------|
| 1. Initialise the repository | done | `chore: initialize git repository with .gitignore` |
| 2. Bounded-context and test directory tree | done | `chore: create bounded-context and test directory tree` |
| 3. Extend `composer.json` and install | done | `build: add Symfony 7.4, Doctrine and the four quality tools` |
| 4. Boot a minimal Symfony kernel | done (1 deviation) | `feat: boot a minimal Symfony 7.4 kernel` |
| 5. Wire Doctrine with zero entities | done (2 deviations) | `build: configure Doctrine and migrations without entities` |
| 6. PHPUnit suites and the first tests | done | `test: add PHPUnit suites and the kernel boot smoke test` |
| C-1. Pin the Symfony component set to 7.4 (correction, pre-batch-2) | done | `build: pin the transitive Symfony components to the 7.4 LTS line` |
| 7. Add PHPStan level 8 | done | `build: add PHPStan level 8 configuration` |
| 8. Add php-cs-fixer and format the tree | done (1 finding, not a deviation) | `build: enforce PSR-12 and strict types with php-cs-fixer` |
| C-2. Untrack and exclude `config/reference.php` (correction, post-batch-2) | done | `build: untrack and exclude the generated config reference dump` |
| 9. Encode the dependency rule in deptrac | done (0 deviations, 1 finding) | `build: encode the bounded-context dependency rule in deptrac` |
| 10.–14. | not started (later batches) | — |

## Step log

### Step 1 — Initialise the repository

- **Changed:** `.gitignore` (new). The initial commit also tracks the pre-existing working
  tree — `CLAUDE.md`, the partial `composer.json`, `.claude/**` and
  `docs/sdlc/project-skeleton/{01-analysis,02-plan}.md` — because the step's verification
  requires `git status --porcelain` to be empty afterwards.
- **Tests added:** none (TDD: n/a — no `src/` change).
- **Checks:** `composer cs-fix` / `composer phpstan` / `composer test` / `composer deptrac`
  not run — no tool config and no `vendor/` exists before step 3.
- **Verification:** `git rev-parse --is-inside-work-tree` → `true`;
  `git status --porcelain` → empty. Exit 0.
- **Commit:** `chore: initialize git repository with .gitignore`
- **Notes:** `.gitignore` content is the plan's verbatim block, so `/vendor/` is ignored
  before any install (R12/D5). `.idea/` is covered.

### Step 2 — Create the bounded-context and test directory tree

- **Changed:** 20 tracked `.gitkeep` files — `src/Shared`, `src/Engine`,
  `src/Content/{Domain,Application,Infrastructure}`,
  `src/Play/{Domain,Application,Infrastructure}`,
  `src/Api/{Controller,Dto,Security}`, `migrations`,
  `tests/Unit/{Engine,Content,Play,Api,Shared}`,
  `tests/Integration/{Content,Play,Api}`.
- **Tests added:** none. Standing rule 1 is satisfied by construction: `src/Engine/` gains
  no PHP class (D4), only a `.gitkeep`, and `tests/Unit/Engine/` exists for the first real
  Engine test. Step 6's `BoundedContextLayoutTest` is what asserts this structure.
- **Checks:** four gates not run (no `vendor/`, no tool config yet).
- **Verification:** the plan's 20-directory `test -d` loop → exit 0.
- **Notes:** the plan's prose says "the 18 `.gitkeep` files listed in the per-context
  tables"; the tables actually enumerate 19 (plus `migrations/.gitkeep` = 20), and the
  step's own `test -d` loop names 20 directories. The enumerated lists were followed; the
  "18" is a counting slip in the plan prose with no effect on the artifact.

### Step 3 — Extend `composer.json` and install

- **Changed:** `composer.json` (extended, never regenerated — `name`, `description`,
  `type`, `license`, `autoload-dev`, the four `scripts` and `scripts-descriptions` are
  byte-for-byte unchanged), `composer.lock` (new, committed).
- **Tests added:** none.
- **Checks:** `composer validate --strict` ok. Others not yet runnable.
- **Verification:** `composer validate --strict` → exit 0;
  `composer show symfony/framework-bundle | grep -q 'versions : \* v7.4'` → exit 0
  (v7.4.21, LTS); `test -f vendor/autoload_runtime.php` → exit 0.
- **Commit:** `build: add Symfony 7.4, Doctrine and the four quality tools`
- **Notes:** every constraint is the plan's verbatim set. The resolver accepted all of
  them on the first attempt — no constraint was widened, nothing was downgraded, and
  `--ignore-platform-reqs` was never used. Key resolved versions:
  `symfony/framework-bundle v7.4.21`, `symfony/console v7.4.21`, `symfony/runtime v7.4.21`,
  `doctrine/orm 3.7.5`, `doctrine/doctrine-bundle 2.19.1`,
  `doctrine/doctrine-migrations-bundle 3.7.1`, `doctrine/dbal 4.5.0`,
  `phpunit/phpunit 12.5.40`, `phpstan/phpstan 2.3.1`, `deptrac/deptrac 4.7.2`,
  `friendsofphp/php-cs-fixer v3.95.27`. P1 held: no Doctrine 4.0.x-dev drift (R6).
- **Observation for the reviewer (not a deviation — see "Observations" below):** thirteen
  Symfony components resolved onto the **8.1** line transitively, because only the directly
  required packages carry the `7.4.*` pin while transitive constraints permit `^7.4|^8.0`.
  One of them broke Doctrine at step 5.

### Step 4 — Boot a minimal Symfony 7.4 kernel

- **Changed:** `src/Kernel.php`, `config/bundles.php`, `config/services.yaml`,
  `config/routes.yaml`, `config/packages/framework.yaml`, `config/packages/monolog.yaml`,
  `public/index.php`, `bin/console` (mode 755), `.env`, `.env.example`,
  plus one line in `composer.json` (see Deviation D-1).
- **Tests added:** none in this step; the kernel is covered by step 6's `KernelBootTest`.
- **Checks:** `composer test` not yet (no `phpunit.xml` until step 6); `phpstan`, `cs-fix`,
  `deptrac` have no config until steps 7–9.
- **Verification:** `php bin/console about` → exit 0, reporting Symfony **v7.4.21**
  (Long-Term Support: Yes), kernel type `Storyteller\Kernel`, PHP 8.5.3.
- **Commit:** `feat: boot a minimal Symfony 7.4 kernel`
- **Notes:** file bodies are the plan's verbatim content. The `// src/Kernel.php`-style
  path comments in the plan's code blocks were treated as document annotations, not file
  content, and omitted consistently. `config/routes.yaml` holds only its comment, which
  the routing loader reads as an empty collection. `Storyteller\Engine\` is absent from
  `config/services.yaml` (P4).

### Step 5 — Configure Doctrine and migrations without entities

- **Changed:** `config/packages/doctrine.yaml`, `config/packages/doctrine_migrations.yaml`,
  `config/bundles.php` (appended `DoctrineBundle` and `DoctrineMigrationsBundle`).
- **Tests added:** none in this step; covered by step 6's
  `testDoctrineIsWiredWithoutOpeningAConnection`.
- **Checks:** four gates still not runnable (configs land in steps 6–9).
- **Verification:** `php bin/console about` → **exit 0 with no PostgreSQL reachable**, and
  with zero deprecations on stderr. `php bin/console debug:config doctrine_migrations` →
  exit 0, reporting `Storyteller\Migrations: <project>/migrations`; `migrations/` contains
  only `.gitkeep`. `doctrine:migrations:status` → exit 7, see Deviation D-3.
- **Commit:** `build: configure Doctrine and migrations without entities`
- **Notes:** **R1 did not materialise.** The plan's *primary* shape
  (`auto_mapping: false`, `mappings: {}`, `controller_resolver.auto_mapping: false`)
  compiles the container with zero entities. The documented fallback mapping block was
  **not** used, so the commit subject carries no `(fallback mapping used)` suffix.

### Step 6 — Add the PHPUnit suites and the first tests

- **Changed:** `phpunit.xml`, `tests/bootstrap.php`.
- **Tests added:** `tests/Integration/KernelBootTest.php`
  (`testKernelBootsAndBuildsTheContainer`, `testDoctrineIsWiredWithoutOpeningAConnection`)
  and `tests/Unit/Shared/BoundedContextLayoutTest.php`
  (`testEveryBoundedContextDirectoryExists`, `testEngineSourcesImportNoFrameworkNamespace`).
  TDD: n/a — this feature contains no Engine implementation step, so there is no red
  sub-step; the tests are the deliverable.
- **Checks:** `composer test` → **4 tests, 19 assertions, OK** (0 failures, 0 errors,
  0 skipped, 0 risky, 0 warnings, with `failOnRisky`/`failOnWarning`/`failOnSkipped`
  active). `phpstan` / `cs-fix` / `deptrac` not run — configs belong to steps 7–9.
- **Verification:** `composer test` → exit 0; `vendor/bin/phpunit --testsuite=unit` →
  exit 0 (2 tests, 13 assertions); `vendor/bin/phpunit --testsuite=integration` → exit 0
  (2 tests, 6 assertions). The integration suite ran with **no reachable database for the
  application DSN**: `DATABASE_URL` points at host `postgres`, which exists only inside
  Compose and does not resolve on the host (`doctrine:migrations:status` exits 7 proving
  the DSN is unreachable). Criterion 15 is therefore met on the host; it should be
  re-confirmed in the container once step 11 lands.
- **Commit:** `test: add PHPUnit suites and the kernel boot smoke test`
- **Notes:**
  - `BoundedContextLayoutTest` asserts **12** directories. The plan's prose says "all 13
    source/test leaves" but then enumerates twelve (`src/Shared`, `src/Engine`,
    3 × Content, 3 × Play, 3 × Api, `migrations`). The enumeration was followed; "13" is a
    counting slip with no effect on coverage.
  - `testEngineSourcesImportNoFrameworkNamespace` passes vacuously today, so it carries an
    unconditional `assertDirectoryExists` as well — without it PHPUnit would report a test
    with no assertions as *risky*, and `failOnRisky="true"` would turn the suite red.
  - `KernelBootTest` deliberately never calls `getConnection()`, `connect()`,
    `executeQuery()` or any schema API.

### Correction step C-1 — Pin the transitive Symfony components to the 7.4 LTS line

Run between batch 1 and batch 2 on the user's decision, after the batch-1 observation below
was reviewed. It is **not** a numbered plan step; it corrects the dependency set that step 3
resolved and retires deviation D-2.

- **Changed:** `composer.json` (13 packages added to `require` at `7.4.*`, in the existing
  `sort-packages` order), `composer.lock` (re-resolved), `config/packages/doctrine.yaml`
  (removed `orm.enable_native_lazy_objects: true` and its explanatory comment — the file is
  now byte-for-byte the plan's primary shape again).
- **Tests added:** none. No `src/` file changed; standing rule 1 is not engaged. The change
  is covered by the existing `KernelBootTest::testDoctrineIsWiredWithoutOpeningAConnection`,
  which instantiates a real `EntityManager` — the exact call that threw
  `ORMInvalidArgumentException::lazyGhostUnavailable()` before D-2 and that now passes with
  the workaround removed.
- **Re-resolution:** `composer update` scoped to the 13 packages only (no unscoped update, no
  `--with-all-dependencies`, no widened constraint, no `--ignore-platform-reqs`). The resolver
  accepted the full pin set on the first attempt: 13 downgrades, 0 installs, and the removal of
  `symfony/polyfill-deepclone` v1.43.2, which was pulled in only by the Symfony 8 components.
  Resolved versions: `error-handler` v7.4.20, `event-dispatcher` v7.4.17, `filesystem` v7.4.21,
  `finder` v7.4.20, `http-foundation` v7.4.21, `monolog-bridge` v7.4.18, `options-resolver`
  v7.4.8, `process` v7.4.21, `routing` v7.4.21, `stopwatch` v7.4.8, `string` v7.4.21,
  `var-dumper` v7.4.21, `var-exporter` v7.4.18.
- **Standing rule 9 justification.** These are **not new dependencies**. All thirteen were
  already installed after step 3 as transitive requirements of `symfony/framework-bundle`,
  `symfony/console`, `symfony/runtime`, `symfony/monolog-bundle`, `symfony/serializer`,
  `symfony/validator` and `doctrine/orm`. Promoting them to explicit `require` entries adds no
  code to the vendor tree; it only removes the version ambiguity of the transitive
  `^7.4|^8.0` constraints, which is what let them drift onto the 8.1 line. The reason is
  stated here per rule 9: this project is a **Symfony 7.4 LTS** skeleton (`02-plan.md`,
  D1/D7), and a dependency set that is 7.4 only at the edges is not that. The pin makes the
  LTS line a property of the lock file instead of a resolver accident.
- **Verification:** `composer validate --strict` exit 0 · `php bin/console about` exit 0 with
  no reachable database, reporting Symfony **v7.4.21** (Long-Term Support: Yes), PHP 8.5.3 ·
  `composer test` exit 0 (**4 tests, 19 assertions**, 0 failures/errors/skipped/risky) with no
  reachable database (`postgres` does not resolve on the host) ·
  `composer show 'symfony/*'` filtered for a leading `8.` → **no surviving 8.x entry**; every
  Symfony component is on 7.4.x (plus the version-independent `*-contracts` 3.7.x and
  `polyfill-*` 1.x packages, which have no 7.4/8.x line).
- **Commit:** `build: pin the transitive Symfony components to the 7.4 LTS line`
- **Side effect, reported not worked around:** removing `enable_native_lazy_objects` brings
  back the `Doctrine\ORM\Proxy\Autoloader` *deprecation notice* that D-2 had silenced. It is
  logged by Monolog on stderr in dev; `php bin/console about` still exits 0 and no gate is
  affected. Silencing it again would mean re-adding a key the plan does not authorize, so it
  is left in place and flagged for the reviewer. It becomes moot on the move to ORM 4.
- **Not touched:** steps 7–14 (PHPStan, cs-fixer, deptrac, `qa`, Docker, Makefile, README,
  the `CLAUDE.md` line-3 edit) and deviation D-1, which stands.

### Step 7 — Add PHPStan level 8

- **Changed:** `phpstan.neon` (new) — the plan's verbatim content, including level 8,
  `phpVersion: 80500`, the `phpstan-symfony`/`phpstan-doctrine` includes, `paths: [src,
  tests, config, public]`, `tmpDir: var/phpstan`, and `containerXmlPath` left unset and
  commented out (P5/R4).
- **Tests added:** none — config-only step, no `src/` change, standing rule 1 not engaged.
- **Checks:** `composer phpstan` → **exit 0, "No errors"** at level 8 against the existing
  `src/`, `tests/`, `config/`, `public/` tree. `composer test` and `composer cs-fix` not
  re-run as part of this step (no code change); `composer deptrac` out of scope (step 9).
- **Verification:** `composer phpstan` → exit 0; `test ! -f phpstan-baseline.neon` → exit 0
  (no baseline file exists or was created).
- **Commit:** `build: add PHPStan level 8 configuration`
- **Notes:** no code change was needed to reach level 8 — the step-1–6 tree (Kernel,
  config files, the two test classes) was already clean at this level. No baseline was
  added and none will be; per CLAUDE.md standing rule 4, a future level-8 failure must be
  fixed in code, not baselined.

### Step 8 — Add php-cs-fixer and format the tree

- **Changed:** `.php-cs-fixer.dist.php` (new) — the plan's verbatim content: `@PSR12` +
  `declare_strict_types` + five supporting rules, `setRiskyAllowed(true)` (R5), finder over
  `src/`, `tests/`, `config/`, `public/` with `bin/console` and the config file itself
  appended. `config/reference.php` (reformatted by the fixer — see Notes below).
- **Tests added:** none — config-only step.
- **Checks:** `composer cs-fix` → fixed 1 of 9 files (`config/reference.php`);
  `vendor/bin/php-cs-fixer fix --dry-run --diff` → **exit 0**, 0 of 9 files need fixing,
  immediately after the fix. `composer phpstan` re-run → exit 0 (unaffected).
  `composer test` → exit 0, 4 tests / 19 assertions, 0 failures/errors/skipped/risky.
  `composer validate --strict` → exit 0.
- **Verification that `declare_strict_types` actually fires (R5):** `declare(strict_types=1);`
  was temporarily removed from `src/Kernel.php`; `vendor/bin/php-cs-fixer fix --dry-run
  --diff` then reported `src/Kernel.php` as fixable and exited **8** (non-zero), with a diff
  re-adding the exact line. The file was restored immediately afterward and a follow-up
  dry-run confirmed 0 of 9 files need fixing / exit 0 again. This proves `setRiskyAllowed(true)`
  is in effect — without it the rule would not resolve at all, let alone fire.
- **Commit:** `build: enforce PSR-12 and strict types with php-cs-fixer`
- **Notes / finding for the reviewer (not a plan deviation, but worth flagging loudly):**
  `config/reference.php` — committed in step 5 of batch 1, not listed in the plan's file
  tables — is Symfony's auto-generated config-builder IDE-helper file
  (`Symfony\Bundle\FrameworkBundle\FrameworkBundle::build()` registers
  `PhpConfigReferenceDumpPass`, which rewrites `config/reference.php` on **every** container
  compilation with `kernel.debug = true`, i.e. both `dev` and `test`). Consequences observed:
  - The fixer correctly flags it (missing `declare(strict_types=1)`) because it sits inside
    the `config/` finder path the plan specifies; fixing it and committing it is the
    expected behaviour per this batch's instructions ("if step 8's fixer rewrites any file
    committed in steps 1–6 … include those rewrites in step 8's commit").
  - Running `composer test` (or any `php bin/console` invocation) **immediately reverts**
    the fix — Symfony regenerates the file from scratch without the `declare` line every
    time the container is rebuilt in debug mode. This was reproduced twice: fix → dry-run
    clean (exit 0) → `composer test` → file dirtied again (verified via `git status
    --porcelain` and `git diff`) → `git checkout -- config/reference.php` to return the
    working tree to the committed (fixed) state.
  - This is **not a step 7/8 defect**: no cs-fixer rule was narrowed, no finder exclude was
    added, and `setRiskyAllowed` was not touched, per the hard stop-rules. The root cause
    predates batch 2 — the file should arguably never have been committed in step 5 (it is
    exactly the kind of auto-generated artifact `.gitignore` already excludes for `var/` and
    `vendor/`), but amending `.gitignore` or `git rm --cached` on a step-5 file is outside
    this batch's scope (steps 7–8 only) and is reported here rather than improvised.
  - **Practical impact for step 10 (future batch):** the `qa` script's `cs-check` step will
    intermittently fail (depending on whether a kernel boot happened since the last
    `cs-fix`) unless this is resolved — either by gitignoring `config/reference.php` and
    untracking it, or by excluding it from the cs-fixer finder with a recorded plan
    decision. Recommend the orchestrator decide before step 10 is implemented.

### Step 9 — Encode the bounded-context dependency rule in deptrac

- **Changed:** `deptrac.yaml` (new) — the plan's verbatim content from
  §"Exact file contents → `deptrac.yaml`", byte-for-byte, including the two explanatory
  comment blocks. Deptrac v4 top-level `deptrac:` key; the installed package is
  `deptrac/deptrac` 4.7.2 (not the stale `qossmic/deptrac`).
- **Shape:** `paths: [./src]`; five context layers (`Shared`, `Engine`, `Content`, `Play`,
  `Api`) built with `directory` collectors; one `Framework` vendor layer built with the
  `composer` collector over 26 packages. Ruleset: `Shared: []`, `Engine: [Shared]`,
  `Content: [Engine, Shared, Framework]`, `Play: [Engine, Content, Shared, Framework]`,
  `Api: [Play, Content, Shared, Framework]`, `Framework: []`. `Api -> Engine` is absent by
  design; `Framework` is withheld from **both** `Engine` and `Shared` (plan decision P3).
- **Tests added:** none — config-only step, no `src/` file changed, standing rule 1 not
  engaged. The architecture invariant is additionally covered from the test side by
  step 6's `tests/Unit/Shared/BoundedContextLayoutTest.php`.
- **Pre-flight checks against the installed deptrac (no key was invented):**
  - Top-level key confirmed as `deptrac` from
    `vendor/deptrac/deptrac/src/Supportive/DependencyInjection/Configuration.php`
    (`new TreeBuilder('deptrac')`).
  - The `composer` collector's key spelling (`composerPath`, `composerLockPath`,
    `packages`) confirmed from
    `vendor/deptrac/deptrac/src/DefaultBehavior/Layer/ComposerCollector.php` and
    `src/Contract/Config/Collector/ComposerConfig.php`. No correction was needed.
  - All **26** package names were verified present in `composer.lock` before the file was
    written (`ComposerFilesParser::autoloadableNamespacesForRequirements()` throws on an
    unknown package). None had to be deleted; none was added; no wildcard was used.
- **Checks:** `composer deptrac` → exit 0 · `composer test` → exit 0 (4 tests, 19
  assertions) · `composer phpstan` → exit 0, "No errors" at level 8 ·
  `vendor/bin/php-cs-fixer fix --dry-run --diff` → exit 0 ("Found 0 of 8 files") ·
  `composer validate --strict` → exit 0. `deptrac.yaml` is not a PHP file, so the cs-fixer
  finder does not see it.
- **Verification (plan's own):** `composer deptrac` exit 0; `grep -c 'Framework'
  deptrac.yaml` → **5** (the layer name, the comment, the collector block and three ruleset
  entries land on five matching lines), confirming the D4 vendor layer is present.
- **Verification that the ruleset actually bites — the important one.** The plan accepts
  green-by-vacancy (D4/R3), so exit 0 on the real tree proves only that the config parses
  (`Violations 0 / Uncovered 0 / Allowed 0`, one file analysed: `src/Kernel.php`). The rule
  was therefore proved with throwaway probe classes, run and then deleted, never committed:

  | Probe (deleted) | Dependency | Expected | Observed |
  |---|---|---|---|
  | `src/Engine/ThrowawayDeptracProbe.php` | `Doctrine\ORM\EntityManagerInterface`, `Symfony\Component\HttpFoundation\Request` | forbidden (P3: `Engine` has no `Framework` edge) | 2 violations, exit 1 |
  | `src/Engine/ThrowawayEngineProbe.php` | `Storyteller\Content\Domain\ThrowawayContent` | forbidden (no `Engine -> Content` edge) | violation, exit 1 |
  | `src/Api/Controller/ThrowawayApiProbe.php` | `Storyteller\Engine\ThrowawayEngineProbe` | forbidden (`Api -> Engine` deliberately absent) | violation, exit 1 |
  | `src/Play/Application/ThrowawayPlayProbe.php` | `Storyteller\Engine\…` + `Storyteller\Content\…` | **allowed** (positive control) | reported under `Allowed 2`, no violation |

  Exact violation text from run 1 (P3, the `Framework` exclusion):

  ```
  DependsOnDisallowedLayer   Storyteller\Engine\ThrowawayDeptracProbe must not depend on Doctrine\ORM\EntityManagerInterface
                             You are depending on token that is a part of a layer that you are not allowed to depend on. (Framework)
                             /Users/aleksandrkopyl/www/storyteller/src/Engine/ThrowawayDeptracProbe.php:12
  DependsOnDisallowedLayer   Storyteller\Engine\ThrowawayDeptracProbe must not depend on Symfony\Component\HttpFoundation\Request
                             You are depending on token that is a part of a layer that you are not allowed to depend on. (Framework)
                             /Users/aleksandrkopyl/www/storyteller/src/Engine/ThrowawayDeptracProbe.php:16
  ```

  Exact violation text from run 2 (the two context edges):

  ```
  DependsOnDisallowedLayer   Storyteller\Api\Controller\ThrowawayApiProbe must not depend on Storyteller\Engine\ThrowawayEngineProbe
                             You are depending on token that is a part of a layer that you are not allowed to depend on. (Engine)
                             /Users/aleksandrkopyl/www/storyteller/src/Api/Controller/ThrowawayApiProbe.php:11
  DependsOnDisallowedLayer   Storyteller\Engine\ThrowawayEngineProbe must not depend on Storyteller\Content\Domain\ThrowawayContent
                             You are depending on token that is a part of a layer that you are not allowed to depend on. (Content)
                             /Users/aleksandrkopyl/www/storyteller/src/Engine/ThrowawayEngineProbe.php:11
  ```

  All four probe files were deleted; `composer deptrac` returned to exit 0 with
  `Violations 0 / Uncovered 0` and `git status --short` showed no probe file at any point
  of the commit. **The `Framework` vendor layer is not decorative** — it is the thing that
  turns `use Doctrine\…` inside `src/Engine` from a silent pass into a violation.
- **Uncovered-token count: 0.** `vendor/bin/deptrac analyse --report-uncovered` also
  reports `Uncovered 0` and emits **no warning about `src/Kernel.php`**. That is expected
  and is R13 working as designed: `Kernel` matches none of the five `directory` collectors,
  so it belongs to no layer and deptrac never evaluates its dependencies at all (an
  "uncovered" dependency is one *from* a layered token *to* an unlayered one). No layer was
  invented for it.
- **Commit:** `build: encode the bounded-context dependency rule in deptrac` (`13685d4`)
- **Not touched:** `composer.json` (the `deptrac` script already existed from step 3; the
  `cs-check`/`qa` scripts are step 10 and were not added), `.gitignore`,
  `.php-cs-fixer.dist.php`, `CLAUDE.md`. No `skip_violations` key, no baseline, no widened
  layer. No unscoped `composer update`. No history rewriting.

#### Finding for the orchestrator — `.deptrac.cache` is untracked and must be settled before step 10

Running `composer deptrac` writes a cache file at the project root. Deptrac 4.7.2's config
default is `cache_file: '.deptrac.cache'`
(`Configuration::appendCacheFile()`), and `.gitignore` has no entry for it, so every
`composer deptrac` run leaves `?? .deptrac.cache` in `git status --short`.

This is the same class of problem as `config/reference.php` (correction step C-2), and it
lands squarely on **step 10's acceptance criterion 11** ("`git status --porcelain` is empty
after a `composer qa` run"). It is *not* a defect in `deptrac.yaml` and no gate fails today.

I did **not** fix it, because this batch's hard stop-rules forbid touching `.gitignore`, and
changing `cache_file` would deviate from the plan's verbatim `deptrac.yaml`. The file was
deleted after the final verification run so the tree is clean, but it reappears on the next
run. Three options, for the orchestrator to decide before step 10:

1. Add `/.deptrac.cache` to `.gitignore` (mirrors the C-2 treatment; one line, no config
   deviation).
2. Add `cache_file: var/.deptrac.cache` to `deptrac.yaml` — `var/` is already ignored; this
   is a recorded one-line plan deviation.
3. Change the `deptrac` composer script to `--no-cache` (slowest; discards the cache
   benefit entirely).

Option 1 is the recommendation: it matches the precedent already set by C-2 and leaves the
plan's `deptrac.yaml` byte-for-byte intact.

## Deviations from the plan

| Step | Planned | Actually done | Why |
|------|---------|---------------|-----|
| 4 (D-1) | `composer.json` `autoload.psr-4` maps the five context namespaces plus `Storyteller\Migrations\`; `Kernel` lives at `src/Kernel.php` in namespace `Storyteller` | Added one more rule: `"Storyteller\\": "src/"` | The plan's map has **no** rule covering `Storyteller\Kernel`, so `php bin/console about` — the step's own verification — died with `ClassNotFoundError: Attempted to load class "Kernel" from namespace "Storyteller"`. This is the minimal additive fix and the only one that keeps the Kernel at `src/Kernel.php` outside every context directory, which R13 requires. Longer PSR-4 prefixes still win, so the five context mappings are unaffected; `autoload` is not part of `composer.lock`'s content hash, so the lock is untouched and `composer validate --strict` stays green. |
| 5 (D-2) | `doctrine.orm` carries only `auto_generate_proxy_classes`, `auto_mapping: false`, `mappings: {}`, `controller_resolver.auto_mapping: false`; the plan names the permitted keys and forbids adding `enable_lazy_ghost_objects` and friends | Added `enable_native_lazy_objects: true` under `doctrine.orm`, with an explanatory comment | `doctrine/orm` 3.7.5 falls back to Symfony's `LazyGhostTrait` when native lazy objects are off, but `symfony/var-exporter` resolved to **v8.1.9** (transitively allowed by `^6.3.9\|^7.0\|^8.0`) and Symfony 8 **removed** that trait. `ProxyFactory::__construct` then throws `ORMInvalidArgumentException::lazyGhostUnavailable()` on the first real `EntityManager` instantiation — reproduced by `bin/console doctrine:migrations:status` (exit 1). This breaks acceptance criterion 16 **inside the container as well**, so it is not an artifact of the missing database. PHP 8.5 supports native lazy objects and ORM 4.0 makes them mandatory, so this is the forward-correct fix; it also removes the `Doctrine\ORM\Proxy\Autoloader` deprecation (`about` now boots with zero deprecations). Alternative considered and **not** taken at the time: constraining `symfony/var-exporter` to `7.4.*`, which treats the symptom and leaves the rest of the 8.1 drift in place — that is a dependency decision for the orchestrator, not the implementer. **→ RESOLVED, NOT ACCEPTED (correction step C-1).** The user took the dependency decision: all 13 transitives are now pinned to `7.4.*`, `symfony/var-exporter` is back on v7.4.18, `LazyGhostTrait` exists again, and `enable_native_lazy_objects: true` was **removed** from `config/packages/doctrine.yaml`. The root cause replaced the workaround; the file now matches the plan's primary shape verbatim and this deviation no longer exists in the tree. |
| 5 (D-3) | Verify with `php bin/console doctrine:migrations:status` exiting 0 and reporting the migrations directory; the plan states it "reads configuration only and must not require a live database" | Verified with `php bin/console debug:config doctrine_migrations` (exit 0, reports `Storyteller\Migrations: <project>/migrations`) plus `ls migrations` showing zero migration classes | The plan's premise is incorrect for `doctrine/migrations` 3.9.8: `status` (and `list`) read the executed-migrations table through `TableMetadataStorage`, so they open a connection. With the D-2 fix in place the command fails with `SQLSTATE[08006] could not translate host name "postgres"` (exit 7), i.e. purely for want of a database. No PostgreSQL exists in this batch — Docker/Compose is step 11, in a later batch — so the command cannot exit 0 here under any permitted config. **Acceptance criterion 16 is left open for the tester to re-run in the container after step 11.** |

### Observations that are not deviations

- **Mixed Symfony 7.4 / 8.1 component set.** The plan's constraints pin only the directly
  required packages to `7.4.*`; transitive constraints (`^7.4|^8.0`) pulled thirteen
  components onto the 8.1 line: `error-handler`, `event-dispatcher`, `filesystem`,
  `finder`, `http-foundation`, `monolog-bridge`, `options-resolver`, `process`, `routing`,
  `stopwatch`, `string`, `var-dumper`, `var-exporter`. Everything resolved and all gates
  that exist today are green, and `framework-bundle`, `http-kernel`,
  `dependency-injection`, `console` and `runtime` are all on 7.4.21 as the plan's
  verification demands — but `var-exporter` 8.1 is exactly what caused D-2, so this is a
  standing source of further surprises for a "Symfony 7.4 LTS" skeleton. Flagged for the
  reviewer; no constraint was changed in batch 1, since changing one is a plan-level
  decision. **→ Resolved by correction step C-1**: the thirteen are pinned to `7.4.*` and no
  Symfony package is on the 8.x line any more.
- A local, unrelated PostgreSQL happens to listen on `localhost:5432`. It is irrelevant:
  the application DSN targets host `postgres`, which does not resolve on the host, so the
  "no reachable database" condition of criterion 15 genuinely holds.

## Final check state (end of batch 1, steps 1–6)

```
composer test     -> PASS (4 tests, 19 assertions; unit 2/13, integration 2/6; 0 skipped, 0 risky)
composer phpstan  -> NOT RUN - phpstan.neon is step 7 (batch 2)
composer cs-fix   -> NOT RUN - .php-cs-fixer.dist.php is step 8 (batch 2); running it
                     without its config would reformat the tree with default rules
composer deptrac  -> NOT RUN - deptrac.yaml is step 9 (batch 2)
composer qa       -> NOT DEFINED - step 10 (batch 2)
```

> Re-verified unchanged after correction step C-1: `composer test` → 4 tests, 19 assertions, OK.

Supporting verifications: `composer validate --strict` exit 0 · `php bin/console about`
exit 0 (Symfony v7.4.21 LTS, PHP 8.5.3, no database, no deprecations) ·
`git status --porcelain` empty after every commit.

## Final check state (end of batch 2, steps 7–8)

```
composer phpstan  -> PASS exit 0, "No errors" at level 8, no phpstan-baseline.neon created
composer cs-fix   -> PASS exit 0, fixed config/reference.php (see finding below)
cs-fixer --dry-run --diff -> PASS exit 0 immediately after cs-fix (0 of 9 files to fix)
composer test     -> PASS exit 0, 4 tests, 19 assertions, 0 failures/errors/skipped/risky
composer validate --strict -> PASS exit 0
composer deptrac  -> NOT RUN - deptrac.yaml is step 9 (next batch)
composer qa       -> NOT DEFINED - step 10 (next batch)
```

`git status --porcelain` is empty for all files owned by this batch after every commit
(the six pre-existing `.claude/**` modifications are not mine and were left untouched, per
instruction).

### Correction step C-2 — Untrack and exclude the generated config reference dump

Run after batch 2 (steps 7–8), on `sonnet`, as a mechanical correction with the decision
already made by the user: it is not a numbered plan step and does not touch step 9
(`deptrac.yaml`) or steps 10–14.

- **Problem (confirmed, not re-derived here):** `config/reference.php` is Symfony's
  auto-generated config-builder reference dump. `PhpConfigReferenceDumpPass` rewrites it on
  every container compile with `kernel.debug=true`, stripping the
  `declare(strict_types=1)` line php-cs-fixer adds. `composer cs-fix` made it dirty, the
  next `composer test` reverted it, and `make qa` — whose first gate is `cs-fix --dry-run`
  — would pass once and fail on the next run.
- **Changed:** `.gitignore` (added `/config/reference.php`, grouped with the other
  leading-slash generated/local paths, directly after `/var/`); `config/reference.php`
  removed from git's index via `git rm --cached` (left on disk — Symfony regenerates it);
  `.php-cs-fixer.dist.php` (added `->notPath('reference.php')` to the finder chain, with a
  comment explaining why).
- **Tests added:** none — config-only correction, no `src/` change, standing rule 1 not
  engaged.
- **Not touched:** `declare_strict_types`, `setRiskyAllowed(true)`, `@PSR12`, or any other
  fixer rule; `deptrac.yaml`; `CLAUDE.md`; no unscoped `composer update`.
- **Verification, run in the order `make qa` will use:**
  1. `composer cs-fix` → exit 0, "Fixed 0 of 8 files" (8, not 9 — `reference.php` is now
     excluded from the finder's file count).
  2. `vendor/bin/php-cs-fixer fix --dry-run --diff` → exit 0, "Found 0 of 8 files".
  3. `composer test` → exit 0, 4 tests / 19 assertions, OK (this compiles the container and
     regenerates `config/reference.php` on disk, as before).
  4. `vendor/bin/php-cs-fixer fix --dry-run --diff` again → **exit 0, still "Found 0 of 8
     files"** — the regression batch 2 flagged (`composer test` re-dirtying the file after
     a fix) no longer reproduces, because the file is no longer in the finder's scope.
  5. `git status --short` → empty, both before commit (only the three intended files
     staged) and after commit (the regenerated-but-ignored `config/reference.php` does not
     appear as untracked).
  6. `composer phpstan` → exit 0, "No errors" at level 8 (unaffected, config-only change);
     `composer validate --strict` → exit 0.
- **Commit:** `build: untrack and exclude the generated config reference dump`
  (`7c62df8`).
- **Notes:** `config/reference.php` still exists on disk after `git rm --cached` and after
  every subsequent `composer test` run, as required — it was never deleted. No history was
  rewritten: this is a new commit on top of the batch-2 commit, not an amend.

## Final check state (end of batch 3, step 9)

```
composer deptrac           -> PASS exit 0 (Violations 0, Skipped 0, Uncovered 0, Allowed 0,
                              Warnings 0, Errors 0) -- and PROVEN TO BITE: see the probe
                              table in step 9; Engine->Framework, Engine->Content and
                              Api->Engine each produce DependsOnDisallowedLayer and exit 1
composer test              -> PASS exit 0, 4 tests, 19 assertions, 0 failures/errors/skipped/risky
composer phpstan           -> PASS exit 0, "No errors" at level 8, no phpstan-baseline.neon
cs-fixer --dry-run --diff  -> PASS exit 0, "Found 0 of 8 files"
composer validate --strict -> PASS exit 0
composer qa                -> NOT DEFINED - step 10 (next batch)
```

`git status --short` is empty after the step-9 commit, once the generated `.deptrac.cache`
is removed (see the finding above).

## Left for the tester / reviewer

- **D-2 is closed** — resolved by correction step C-1, not accepted; the key is gone from
  `config/packages/doctrine.yaml`. **D-1 still needs an explicit decision**: it contradicts the
  plan's verbatim `autoload.psr-4` map and is deliberately kept, because without it the Kernel
  cannot be autoloaded.
- The Symfony 8.1 transitive drift is **settled**: correction step C-1 pinned the component
  set to 7.4. What is left for the reviewer is the returned `Doctrine\ORM\Proxy\Autoloader`
  deprecation notice (harmless, `about` still exits 0, moot on ORM 4).
- **Acceptance criterion 16 is unverified**: `bin/console doctrine:migrations:status`
  needs the container from step 11 and a healthy `postgres` service. Re-run it there.
- **Finding from batch 2 (`config/reference.php` flaps) — RESOLVED by correction step
  C-2.** The user's decision was implemented: the file is untracked (`git rm --cached`,
  left on disk), gitignored, and excluded from the cs-fixer finder via `->notPath(...)`.
  `composer test` still regenerates it on disk as before, but it no longer re-enters the
  fixer's scope or git's index, so `cs-fix --dry-run` stays at exit 0 across repeated
  `composer test` runs. No fixer rule was weakened. This is settled before batch 3
  (step 9 onward) starts.
- Criteria 1–5, 7–11, 13 (`.gitkeep` tracking is done, but the gates that check it are
  not), 18 (README) all depend on steps 9–14 and are untouched by this batch; criteria 4, 7,
  8, 9 are now checkable (PHPStan level 8 and PSR-12/strict-types enforcement both exist and
  pass) but the aggregate `qa` script (criterion 4's full form) is still step 10.
- Not implemented in batches 1–2 by instruction: `deptrac.yaml`, the `cs-check`/`qa`
  scripts, `Dockerfile`, `compose.yaml`, `docker/**`, `Makefile`, `README.md`, and the
  `CLAUDE.md` line-3 edit. **`deptrac.yaml` landed in batch 3 (step 9).** Still open:
  the `cs-check`/`qa` scripts (step 10), Docker (11), Makefile (12), README (13) and the
  `CLAUDE.md` line-3 edit (14).
- **Batch 3 finding — `.deptrac.cache` is untracked** and will break step 10's criterion 11
  unless gitignored (or redirected into `var/`). Decision needed before step 10; see the
  finding under step 9. Recommended: add `/.deptrac.cache` to `.gitignore`.
- **Green-by-vacancy is no longer the state of knowledge for deptrac.** D4/R3 warned that a
  passing `composer deptrac` on an empty tree proves nothing. Step 9 closed that gap
  empirically with four throwaway probe classes (three negative, one positive control),
  deleted immediately afterwards. The reviewer does not need to re-derive it, but the
  **tester should re-run the probe once inside the container after step 11**, since the
  composer collector reads `composer.lock` by relative path.

---

> **Batch 4 — steps 10, 11 and 12 only**, run on `opus`. Steps 13 (README) and 14 (the
> `CLAUDE.md` line-3 edit) are deliberately **not** implemented here; they are routed
> separately. This section appends to the document; nothing above it was rewritten.

## Progress (batch 4)

| Step | Status | Commit |
|------|--------|--------|
| 10. Add the aggregate `qa` script | done (1 folded-in fix) | `build: add aggregate qa composer script` (`e22d41d`) |
| 11. Add the Docker dev environment | done (2 deviations) | `build: add Docker dev environment (php-fpm 8.5, nginx, postgres 16)` (`dab7be9`) |
| 12. Add the Makefile targets | done | `build: add Makefile dev targets` (`8224bb1`) |

## Step log (batch 4)

### Step 10 — Add the aggregate `qa` composer script

- **Changed:** `composer.json`, `.gitignore`
- **Tests added:** none (no `src/**` change; the step adds a script, not behaviour).
  No `src/Engine` file was touched, so standing rule 1 does not apply.
- **Checks:**
  - `composer validate --strict` → exit 0
  - `composer qa` (host) → exit 0, four gates in order: cs-check (0 of 8 files),
    phpstan (`[OK] No errors`), deptrac (Violations 0), phpunit (4 tests, 19 assertions)
  - `git status --porcelain` after `composer qa` → **empty**
- **Commit:** `build: add aggregate qa composer script`
- **Notes:**
  - `scripts.qa` is a JSON **array** (`@cs-check`, `@phpstan`, `@deptrac`, `@test`), so
    Composer runs them in order and aborts on the first non-zero code. No `&&`, no shell,
    no `|| true`.
  - `qa` calls `@cs-check` (`fix --dry-run --diff`), never `@cs-fix` — criterion 11.
  - **Fail-fast proven, not assumed.** `declare(strict_types=1);` was temporarily removed
    from `src/Kernel.php`; `composer qa` exited **8** at cs-check and phpstan/deptrac/
    phpunit never ran. The file was *not* rewritten by the dry run (verified), then
    restored from a scratchpad copy; `composer qa` returned to exit 0.
  - **Folded-in fix (user decision, same class as C-2):** `/.deptrac.cache` added to the
    generated/local block of `.gitignore`, same leading-slash style. `composer deptrac`
    writes that file at the project root on every run, so without the entry
    `git status --porcelain` is never empty after `composer qa` and step 10's own
    criterion 11 cannot hold. `deptrac.yaml` was left **verbatim**: no `cache_file`, no
    `--no-cache`.

### Step 11 — Add the Docker dev environment

- **Changed:** `Dockerfile`, `compose.yaml`, `docker/php/php.ini`,
  `docker/nginx/default.conf`, `phpunit.xml` (see deviation D-4)
- **Tests added:** none (infrastructure only). The existing suite is what verifies the
  environment: it is executed *inside* the container.
- **Checks:**
  - `docker compose build` (with `HOST_UID`/`HOST_GID`) → exit 0
  - `docker compose up -d` → exit 0; php, nginx and postgres up, **postgres healthy**
  - `docker compose exec -T php php -v` → `PHP 8.5.12 (cli)` with `Zend OPcache v8.5.12`
  - `docker compose exec -T php php -m` → lists `intl`, `pdo_pgsql`, `pgsql`, `zip`,
    `Zend OPcache`
  - `docker compose exec -T php id` → `uid=501(app) gid=20(dialout) groups=20(dialout)`;
    host is `uid=501 gid=20` — **not root**, and matching
  - **R9 probe:** the container wrote `var/.r9-probe`; on the host it is owned by
    `aleksandrkopyl:staff` (501:20). The host appended to it and deleted it **without
    sudo**, both exit 0.
  - `docker compose exec -T php composer qa` → exit 0 (R10: dev dependencies are present,
    so all four `vendor/bin/*` tools exist in the container)
  - `docker compose exec -T php php bin/console about` → exit 0, Symfony **v7.4.21**, LTS
- **Commit:** `build: add Docker dev environment (php-fpm 8.5, nginx, postgres 16)`
- **Notes:**
  - **Acceptance criterion 16 is now verified** (it could not be on the host, where
    `postgres` does not resolve):
    `docker compose exec -T php php bin/console doctrine:migrations:status` → **exit 0**,
    reporting `Migration Namespaces | Storyteller\Migrations | /app/migrations`, with
    `Migrations / Available = 0` and `Versions / Current = 0`. The command does open a
    connection (it reads `doctrine_migration_versions`), which is why it needs the healthy
    `postgres` service — `make qa` still does not, and the suite still passes with postgres
    stopped.
  - The harmless `Doctrine\ORM\Proxy\Autoloader` deprecation notice carried over from
    step 5 is still emitted by `bin/console` in the container; `about` still exits 0.
  - The file is named `compose.yaml` (plan §Exact file contents, R14), not
    `docker-compose.yml`; there is no top-level `version:` key.
  - No `composer install` runs in the image: `vendor/` comes from the bind mount, which is
    what keeps `composer.lock` authoritative and the image cheap (plan).
  - Bare `docker compose build` / `up -d` (without the `HOST_UID=$(id -u)
    HOST_GID=$(id -g)` prefix) falls back to the documented `:-1000` defaults and would
    *not* match a macOS host user. All verification used the prefixed form, which is
    exactly what `make build` / `make up` do. This is a README point for step 13.

### Step 12 — Add the Makefile dev targets

- **Changed:** `Makefile`
- **Tests added:** none (infrastructure only).
- **Checks:**
  - `make up` → exit 0 (postgres waited on and reported Healthy)
  - **`make qa` → exit 0** — the feature's acceptance criterion, run inside the container
  - `make test` → exit 0, `OK (4 tests, 19 assertions)` on PHP 8.5.12
  - `make sh` → opens an interactive `bash` prompt as `app@<cid>:/app$`;
    `id` → `uid=501(app) gid=20(dialout) groups=20(dialout)` (matches the host user)
  - `make down` → exit 0; `docker volume ls` still lists `storyteller_pgdata`
    (no `-v`, criterion 5)
  - With the containers down, `make qa`, `make test` and `make sh` each print
    `php container is not running - run 'make up' first` and exit **2** (non-zero) —
    the `guard-running` target from D8
- **Commit:** `build: add Makefile dev targets`
- **Notes:** build args are `HOST_UID`/`HOST_GID`, never `UID` (read-only in bash).
  `exec -T` is used for `test`/`qa` so the exit code propagates; `sh` keeps the TTY.

## Deviations from the plan (batch 4)

| Step | Planned | Actually done | Why |
|------|---------|---------------|-----|
| 10 | `.gitignore` unchanged (it was finalised in step 1 / C-2) | Added `/.deptrac.cache` to the generated/local block | Explicit user decision folded into step 10, and required by step 10's own criterion 11: `composer deptrac` writes `.deptrac.cache` at the project root on every run, so `git status --porcelain` is otherwise never empty after `composer qa`. Recommended as a finding at the end of batch 3. `deptrac.yaml` left verbatim. |
| 11 | `docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip opcache` | `… pdo_pgsql pgsql intl zip` (opcache dropped, with a comment above the `RUN`) | **The plan's Dockerfile does not build.** `php:8.5.12-fpm` already ships Zend OPcache compiled into the binary, so the ext build produces no shared module and `make install` dies with `cp: cannot stat 'modules/*'`. Isolated empirically: `pgsql`, `intl` and `zip` each build fine; only `opcache` fails. OPcache is still present (`php -m` lists `Zend OPcache`) and still tuned by `docker/php/php.ini`, so no capability is lost. No check was weakened. |
| 11 | `phpunit.xml` unchanged (step 6 file) | Added `<env name="APP_ENV" value="test" force="true"/>` and `<env name="APP_DEBUG" value="1" force="true"/>` alongside the existing `<server>` entries | `compose.yaml`'s `env_file: .env` (plan-mandated) puts a **real** `APP_ENV=dev` in the container's process environment. `KernelTestCase::createKernel()` reads `$_ENV['APP_ENV']` *before* `$_SERVER['APP_ENV']`, so phpunit.xml's `<server>` entries were ignored and the suite booted the **dev** kernel in the container: `composer qa` failed with `Could not find service "test.service_container"` and `Failed asserting that 'test' is identical to 'dev'`. Fixed at the test-suite layer rather than by deleting `env_file`, so `compose.yaml` stays verbatim and the suite is hermetic against *any* ambient `APP_ENV` (a developer's exported var, CI, …). No test was relaxed, skipped or deleted; the same 4 tests / 19 assertions pass on host and in container. |
| 11 | `docker compose build` / `up -d` plain | Ran as `HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose …` | Not a content change — the plan's own step-11 verification line and the Makefile recipes use this prefix. Without it Compose falls back to the `:-1000` defaults and the container user would not match the macOS host user (501:20), defeating R9. |

Environment note, not a deviation: the Docker daemon was not running at the start of the
batch. OrbStack (already installed on this machine) was started with `open -a OrbStack`;
`docker info` then returned 0. Nothing was installed and no configuration was changed.

## Final check state (end of batch 4, steps 10–12)

Host:

```
composer qa                -> PASS exit 0  (cs-check 0/8 · phpstan "No errors" · deptrac
                              Violations 0 · phpunit 4 tests, 19 assertions)
composer validate --strict -> PASS exit 0
git status --porcelain     -> EMPTY after composer qa (the /.deptrac.cache entry)
```

Container (the acceptance criterion):

```
docker compose build       -> PASS exit 0
docker compose up -d       -> PASS exit 0, postgres healthy
make qa                    -> PASS exit 0   <-- acceptance criterion
make test                  -> PASS exit 0, OK (4 tests, 19 assertions)
make sh                    -> PASS, bash as uid=501(app) gid=20 (matches host)
make down                  -> PASS exit 0, storyteller_pgdata volume still present
make qa (containers down)  -> guard error, exit 2 (correctly non-zero)
bin/console about          -> PASS exit 0, Symfony v7.4.21 LTS
doctrine:migrations:status -> PASS exit 0, Storyteller\Migrations -> /app/migrations,
                              0 available  (acceptance criterion 16 now verified)
php -v / php -m            -> PHP 8.5.12, pdo_pgsql pgsql intl zip Zend OPcache
```

R9 after the container run: `git status --porcelain` empty, and
`find . -user root -not -path './vendor/*'` returns nothing.

## Left for the tester / reviewer (batch 4)

- **Steps 13 and 14 are not done** and were explicitly out of this batch: `README.md`
  (criterion 18's README clause, plus the `HOST_UID`/`HOST_GID` override note) and the
  `CLAUDE.md` line-3 platform string.
- **Review the two step-11 deviations deliberately.** The `opcache` drop is forced (the
  image cannot build otherwise) but changes the plan's verbatim Dockerfile. The
  `phpunit.xml` `<env>` addition touches a step-6 file; the alternative was deleting
  `env_file: .env` from `compose.yaml`, which would have diverged from the plan's verbatim
  Compose content instead. Either is defensible — the chosen one is the more hermetic.
- **Criterion 4 is fully met**: `make qa` exits 0 inside the container and its output shows
  cs-fixer `--dry-run`, phpstan, deptrac and phpunit in that order, with fail-fast proven
  by the deliberate break in step 10.
- **Criterion 16 is now closed** — see the step-11 log. Note it *does* need a healthy
  postgres (it reads the migration versions table); `make qa` still does not.
- The batch-3 recommendation stands: **re-run the deptrac violation probe inside the
  container** once, since the composer collector reads `composer.lock` by relative path.
  Deptrac ran green in the container (`Violations 0`, `Errors 0`), which proves the config
  parses and the lock file resolves there, but not that it bites there.
- No history was rewritten in this batch: no `git reset`, no `--amend`, no rebase.
  `config/reference.php` is still untracked and appears in none of the three commits.

---

### Correction C-3 — Bind the dev database to loopback only (post-step-11, batch 4)

- **Changed:** `compose.yaml` (the `postgres` service's `ports:` entry only)
- **Trigger:** a security review of the step-11 compose file, raised by the coordinator
  after step 11 was already committed.
- **Planned:** `ports: - "5432:5432"` (plan §Exact file contents, verbatim)
- **Actually done:** `ports: - "127.0.0.1:5432:5432"`, with a comment explaining why
- **Why:** `"5432:5432"` publishes Postgres on **every** host interface, so on a shared or
  untrusted network (café wifi, a conference LAN) the dev database is reachable from other
  machines — and the credentials are `storyteller`/`storyteller`/`storyteller`. Those
  credentials are a fine dev convenience behind a loopback-only socket and not fine behind
  an exposed one. This is an **authorized** deviation from the plan's verbatim
  `compose.yaml`, deliberate rather than drift.
- **Scope, deliberately minimal:**
  - the port stays **published** — a host-side `psql` or GUI client against the dev
    database is a reasonable thing to want, so the `ports:` block was not dropped;
  - the `storyteller`/`storyteller`/`storyteller` credentials are **unchanged**, so they
    stay consistent with `.env.example` and the README of step 13;
  - `nginx`'s `"8080:80"` is **left alone**: it is meant to be reachable and carries no
    data. It is the only other published port in the file.
- **Checks after the change:**
  - `docker compose config` → exit 0
  - `make up` → exit 0; `docker compose ps` shows
    `postgres  Up (healthy)  127.0.0.1:5432->5432/tcp` and
    `nginx  Up  0.0.0.0:8080->80/tcp` (unchanged)
  - `docker compose port postgres 5432` → `127.0.0.1:5432`
  - **`make qa` → exit 0**, `OK (4 tests, 19 assertions)` — the acceptance criterion
    re-verified after the change
  - `php bin/console doctrine:migrations:status` → exit 0,
    `Storyteller\Migrations -> /app/migrations`, `Available 0` (criterion 16 still holds;
    the app reaches Postgres over the Compose network, not the published port)
- **Commit:** `build: bind the dev database to loopback only` (`14bafa8`)
- **Notes:** fixed **forward** on top of the step-11 commit — no `git commit --amend`, no
  `git reset`, no rebase, exactly as instructed.

---

> **Batch 5 — steps 13 and 14 only**, run on `sonnet` (docs-only; every fact already
> established in batches 1–4). This is the **last implementation batch**: no review or
> test phase follows from here. This section appends to the document; nothing above it
> was rewritten.

## Progress (batch 5)

| Step | Status | Commit |
|------|--------|--------|
| 13. Document setup | done (0 deviations) | `docs: document setup and the quality gates` (`3aa3a45`) |
| 14. Correct platform line in `CLAUDE.md` | done (0 deviations) | `docs: correct platform line to PHP 8.5 / Symfony 7.4 LTS` (`900fdd5`) |

## Step log (batch 5)

### Step 13 — Document setup

- **Changed:** `README.md` (new).
- **Tests added:** none — docs-only step, no `src/**` change, standing rule 1 not engaged.
- **Verification against the repo, not the plan's guess.** The plan's prose predates
  batches 3–4 and several corrections recorded above; each claim in the README was
  checked against the actual tracked files rather than copied from the plan's §"Exact
  file contents" README sketch (which this batch's instructions superseded):
  - `Makefile` (read from disk): targets are `build`, `up`, `down`, `sh`, `test`, `qa`,
    `guard-running`; `guard-running` prints `php container is not running - run 'make up'
    first` and exits non-zero (the Makefile uses `exit 1`; the orchestrator's brief says
    `exit 2` — see Deviation D-5 below) when the `php` service is not in the `running`
    list.
  - `compose.yaml` (read from disk, post-C-3): `postgres.ports` is
    `"127.0.0.1:5432:5432"` (loopback-only, C-3), `nginx.ports` is `"8080:80"`, credentials
    are `POSTGRES_DB/USER/PASSWORD = storyteller/storyteller/storyteller`.
  - `composer.json` (read from disk, post step 10): `scripts` has `test`, `phpstan`,
    `cs-fix`, `deptrac`, `cs-check`, and `qa` as a JSON array
    (`cs-check`, `phpstan`, `deptrac`, `test`).
  - `deptrac.yaml` (read from disk, step 9): five `directory`-collector context layers
    plus one `composer`-collector `Framework` layer; `Framework` is absent from `Engine`'s
    and `Shared`'s ruleset entries (P3).
  - `.gitignore` (read from disk, post C-2/step 10): `/config/reference.php` and
    `/.deptrac.cache` are both present, confirming both are generated-and-gitignored.
  - Container PHP version and extensions: taken from step 11's recorded verification
    (`php -v` → `PHP 8.5.12 (cli)` with `Zend OPcache v8.5.12`; `php -m` → `intl`,
    `pdo_pgsql`, `pgsql`, `zip`, `Zend OPcache`) rather than re-run, per this batch's
    instructions ("you do not need to re-run the full Docker cycle if the notes already
    record it"). OPcache is noted in the README only implicitly via the extension list;
    the "built-in, not a shared module" correction from step 11's deviation log was not
    restated in the README prose, since the README's audience (a new contributor running
    `make up`) does not need that implementation detail — it is already recorded here and
    in the Dockerfile comment.
  - `.env.example` could not be read directly in this batch (tool-level permission denial
    on the sandbox's `.env*` filter, independent of this batch's own instruction that
    `.env.example` is fine to read — see Deviations). The README's `DATABASE_URL` /
    credential claims are therefore sourced from the plan's verbatim `.env`/`.env.example`
    block (step 4) and from step 4's and C-3's own verification log, not from a fresh read
    of the file. Nothing in the README asserts a value that was not already confirmed
    on-disk by an earlier, successfully-read source.
- **Commands actually run in this batch** (vs. taken from earlier notes):
  - `composer qa` (host) → exit 0, both before and after writing `README.md`, confirming
    the aggregate gate was unaffected by adding a Markdown file (no finder in
    `.php-cs-fixer.dist.php` reaches `*.md`).
  - `git status --porcelain` → empty immediately before the step (confirming the batch
    started from a clean tree) and empty again after the commit.
  - `sed -n '3p' CLAUDE.md` and `git diff --stat` / `git show --stat` (step 14, below).
  - **Not re-run in this batch:** the full `make build` / `make up` / `make qa` /
    `make down` Docker cycle, `docker compose exec -T php …`, and any `php bin/console …`
    invocation. All Docker-related and container-side claims in the README (ports,
    credentials, PHP/extension versions, the `HOST_UID`/`HOST_GID` requirement, the
    `guard-running` message) are taken verbatim from the step 10–12 and C-3 entries above,
    which do record having been run with their exit codes.
- **Commit:** `docs: document setup and the quality gates` (`3aa3a45`)
- **Notes:** the README does not mention `config/reference.php` / `.deptrac.cache` beyond
  one short "Generated files" section, per this batch's "do not pad" instruction. It does
  not reproduce `CLAUDE.md`'s table or standing rules — it links to `CLAUDE.md` once
  instead, to avoid two documents drifting apart on the same facts.

### Step 14 — Correct the platform line in `CLAUDE.md`

- **Changed:** `CLAUDE.md`, line 3 only — `PHP 8.3 · Symfony 7` → `PHP 8.5 · Symfony 7.4
  LTS`. No other line, no table row, no standing rule touched, per decision D1 and this
  batch's explicit scope.
- **Tests added:** none — single-line doc correction.
- **Verification:**
  - `sed -n '3p' CLAUDE.md` → `Interactive choice-driven story platform. PHP 8.5 ·
    Symfony 7.4 LTS · PostgreSQL · REST API ·` (exact target string).
  - `git show --stat HEAD` → `CLAUDE.md | 2 +-`, `1 file changed, 1 insertion(+), 1
    deletion(-)`.
  - `composer qa` (host, re-run after this commit) → exit 0; `git status --porcelain` →
    empty.
- **Commit:** `docs: correct platform line to PHP 8.5 / Symfony 7.4 LTS` (`900fdd5`)
- **Not touched:** the dependency-rule table, the standing rules, the quality-bar block,
  line 4 (`Vue 3 (CDN) frontend for the MVP.`). `.claude/skills/sdlc/SKILL.md` line 102,
  which carries the same stale platform string, was left alone per D2 (a follow-up for
  the user to approve separately) and this batch's own instruction not to fix anything
  else noticed in `CLAUDE.md` or elsewhere.

## Deviations from the plan (batch 5)

| Step | Planned | Actually done | Why |
|------|---------|---------------|-----|
| 13 | n/a (new finding) | Reported rather than fixed: `make qa`/`make test`/`make sh` with the `php` container stopped exit with code **1** (`guard-running`'s `exit 1`), not **2** as this batch's orchestrating instructions assumed the Makefile does ("exits 2"). Also batch 4's own log (step 12) records the same guard exiting "**2**"; re-reading the committed `Makefile` on disk shows `exit 1`. The README was written to describe the guard's *message* and *non-zero* behaviour without asserting a specific exit code, so it stays true either way. `Makefile` is out of this batch's scope (hard stop-rule: do not touch `Makefile`), so the discrepancy is reported, not corrected. |
| 13 | n/a (new finding) | Could not read `.env.example` directly — the sandbox denied the `Read`/`Bash` tool on it as "in a directory denied by your permission settings," even though this batch's own instructions say `.env.example` is fine to read. Worked around by citing the plan's verbatim `.env`/`.env.example` block (step 4, unchanged since) and the step-4/C-3 verification log instead of a fresh read. Reported here as instructed ("anything you stopped on rather than working around") — this is a tool-permission boundary, not a project rule, and was not bypassed. |

## Final check state (end of batch 5, steps 13–14)

```
composer qa                -> PASS exit 0 (cs-check 0/8 · phpstan "No errors" · deptrac
                               Violations 0 · phpunit 4 tests, 19 assertions)
git status --porcelain     -> EMPTY after each commit
CLAUDE.md diff (step 14)   -> exactly 1 file changed, 1 insertion(+), 1 deletion(-)
```

Docker-cycle checks (`make up` / `make qa` / `make down` inside the container) were not
re-run in this batch; they are taken from the step 10–12 and C-3 entries above, which
record their own exit codes.

## Left for the tester / reviewer (batch 5)

- **All 14 plan steps plus corrections C-1, C-2, C-3 are now implemented.** This was the
  last implementation batch; the next phases are review and test, not further
  implementation.
- **Open discrepancy to confirm:** `guard-running`'s actual exit code is `1` (read
  directly from the committed `Makefile`), not `2` — batch 4's own notes state "2" for the
  same behaviour. This is a documentation-accuracy question about earlier notes, not a
  behavioural question about the Makefile (which this batch did not touch); the reviewer
  should reconcile the two mentions rather than treat it as a new defect.
- **D-1 (step 4, `Storyteller\\` → `src/` PSR-4 rule) remains an open decision** per the
  batch-4 log — unaffected by this batch.
- **Acceptance criterion 18** (README + no real secret in `.env`/`.env.example`) is now
  fully addressable: the README documents setup and credentials are clearly marked
  dev-only; the `.env`/`.env.example` content itself was not re-inspected in this batch
  (see Deviations) but was already verified unchanged since step 4 in earlier batches.
- No history was rewritten in this batch: two new commits on top of `f0534fa`, no
  `git reset`, no `--amend`, no rebase.

---

# Gate C fix loop 1 — blockers B1 and B2

- **Scope:** `04-review.md` blockers **B1** and **B2** only. Majors M1–M4 and minors
  m1–m10 were deliberately **not** touched; they are carried forward as open items.
- **Date:** 2026-10-24 · **Model:** `opus` (capability floor: B1 is the cross-context
  dependency contract, B2 is the container's environment/secret surface).
- **No history was rewritten:** two new commits on top of `c01a311`. No `git reset`, no
  `--amend`, no rebase, no force push.

## Progress

| Item | Status | Commit |
|------|--------|--------|
| B1 — dependency rule does not fire for most vendor packages | done | `build: make the dependency rule fire for every vendor package` (`ba4740c`) |
| B2 — `.env.local` inert inside the container | done | `build: let .env.local override inside the container` (`9e2c12b`) |
| Notes | done | `docs: record the Gate C fix loop for B1 and B2` |

## B1 — make the dependency rule fire for every vendor dependency

- **Changed:** `deptrac.yaml` (the `Framework` layer; a new explicit
  `ignore_uncovered_internal_classes: true`), `composer.json` (the `deptrac` script and
  its description).
- **Tests added:** none — no `src/**` production code changed. The evidence is the probe
  table below, run as throwaway files exactly as step 9 did. `src/Engine/**` was touched
  only by probes that were created, measured and deleted within the same command; nothing
  under `src/` is in either commit, so standing rule 1 is not engaged.
- **Checks (host):** `composer cs-fix` 0 (Fixed 0 of 8) · `composer phpstan` 0 (`[OK] No
  errors`) · `composer deptrac` 0 · `composer test` 0 (4 tests, 19 assertions) ·
  `composer qa` 0 · `composer validate --strict` 0 · `git status --porcelain` clean.

### Reproduction, before the fix

A probe class in `src/Engine` with `use Symfony\Component\Routing\Route;`:

```
composer deptrac -> EXIT=0   Violations 0 · Uncovered 2 · Allowed 0
```

Confirmed on the host before any edit. The gate counted the breach and passed.

### Mechanism chosen, and why

Both halves the review suggested, because each covers a hole the other does not.

1. **The `Framework` layer is now exhaustive by construction.** The hand-written
   ~25-package `composer` collector is gone. In its place, a `bool` collector:

   - `must`: `classLike` `.*` — every class, interface, trait or enum.
   - `must_not`: `classLike` `^Storyteller\\` — except our own code, which belongs to the
     five context layers (or, for `src/Kernel.php`, deliberately to no layer at all).
   - `must_not`: `php_internal` `.*` — and except PHP built-ins, so the Engine stays free
     to use `DateTimeImmutable`, `JsonException` and friends.

   There is no list to keep in sync with `composer.json`, so the layer cannot go stale
   when a package is added, removed or upgraded. That is the direct answer to the brief's
   constraint 3: hand-enumeration is what failed, so the enumeration is gone rather than
   corrected.

2. **`composer deptrac` now runs with `--report-uncovered --fail-on-uncovered`.** With
   (1) in place, a vendor class can no longer be uncovered — so what this flag actually
   catches is the *other* unlayered zone: a `Storyteller\…` class that matches none of the
   five `directory` collectors. That class of hole becomes a hard failure with an
   actionable report instead of a counted-and-ignored statistic.

**Why `ignore_uncovered_internal_classes: true` is now written out explicitly.** It is
deptrac's default, but `--fail-on-uncovered` depends on it: without it, every
`DateTimeImmutable` in a layered class would fail the gate. It is spelled out in the file
with that reason so nobody flips it without understanding the consequence.

**The cost, stated plainly.** The `Framework` layer can no longer tell one vendor package
from another. A future narrow grant — review **m2**'s "let `Shared` use `symfony/uid`
alone" — now needs its own layer with a `composer` collector *plus* a matching `must_not`
entry in `Framework`, so the two layers stay disjoint (a token in two layers is a deptrac
warning and makes both rulesets apply). That is strictly more work than editing one list,
and it is the right trade: the expensive case is the rare, deliberate, reviewed one, and
the cheap case — "a package was added and nobody updated deptrac" — can no longer go
wrong. The reasoning is written into `deptrac.yaml` next to the collector.

**What was NOT changed, deliberately:**

- P3 survives: `Framework` is still withheld from `Engine` and from `Shared`
  (`Shared: []`, `Engine: [Shared]`).
- The five context edges are byte-for-byte unchanged, and **`Api -> Engine` is still
  absent** (probe P5 proves it).
- `src/Kernel.php` is still in no layer and still boots. `--fail-on-uncovered` does not
  touch it: `DependencyLayersAnalyser` iterates `foreach ($dependerLayers as …)`, so a
  dependency whose *depender* is unlayered dispatches no event at all. No `Kernel` layer
  was needed, no collector was broadened to cover it. Verified by `bin/console about`
  exit 0 on host and in the container, and by `composer deptrac` exit 0 on the clean tree.
- No `skip_violations`, no baseline, no lowered level, no widened layer, no dropped probe.

### Probe table

Each probe: file(s) created under `src/`, `composer deptrac` run, exit code and report
recorded, files deleted. Nothing was committed. P4/P5/P6 create the *target* class as a
real file too, so the target resolves to its context layer and the probe measures the
dependency rule rather than an uncovered token.

**Host** (macOS, PHP 8.5.3, CWD = project root):

| # | Probe | Expected | Exit | Report |
|---|-------|----------|------|--------|
| P1 | `src/Engine` → `Symfony\Component\Routing\Route` | fail (**was passing**) | **1** | `DependsOnDisallowedLayer … (Framework)` ×2 · Violations 2 · Uncovered 0 |
| P2 | `src/Engine` → `Doctrine\ORM\EntityManagerInterface` | fail | **1** | `DependsOnDisallowedLayer … (Framework)` · Violations 1 · Uncovered 0 |
| P3 | `src/Shared` → `Symfony\Component\String\UnicodeString` | fail (**was passing**) | **1** | `DependsOnDisallowedLayer … (Framework)` ×2 · Violations 2 · Uncovered 0 |
| P4 | `src/Engine` → `Storyteller\Content\Domain\ProbeStory` | fail | **1** | `DependsOnDisallowedLayer … (Content)` ×2 · Violations 2 |
| P5 | `src/Api/Controller` → `Storyteller\Engine\ProbeState` | fail | **1** | `DependsOnDisallowedLayer … (Engine)` ×2 · Violations 2 |
| P6 | `src/Play/Application` → Engine + Content (positive control) | **pass** | **0** | Violations 0 · Uncovered 0 · **Allowed 2** |

**Container** (`docker compose exec -T php`, PHP 8.5.12, CWD = `/app`): the same script,
piped into the container, produced **identical exit codes and identical violation text**
for all six probes, including `Allowed 2` on P6. This matters because the old `composer`
collector read `composer.lock` by a CWD-relative path; the new collector reads nothing
from disk, so the host/container equivalence is now structural rather than incidental.

`git status --short` after each probe run showed only the in-progress blocker edits and
the untracked `04-review.md` — no probe file survived.

The `Allowed 2` on P6 is the load-bearing part of the control: it proves rules were
actually *evaluated* and permitted, not that the analyser found nothing to look at.

## B2 — `.env.local` now overrides inside the container

- **Changed:** `compose.yaml` (removed the `env_file: - .env` block from the `php`
  service, replaced by a comment explaining why it must not come back), `phpunit.xml`
  (comment only — the `<env>` lines themselves are kept verbatim).
- **Tests added:** none; this is container configuration. The existing suite is the
  regression check and it passes unchanged on host and in the container.
- **Checks (container):** `make up` ok · `make qa` **exit 0** · `doctrine:migrations:status`
  **exit 0** · `bin/console about` exit 0 (Symfony v7.4.21, env `dev`) · `make down` ok,
  `storyteller_pgdata` still present · `find . -user root -not -path './vendor/*'` empty.

### Why the block had to go

`env_file:` promotes `.env`'s values to the container's **real process environment**.
`Dotenv::populate()` skips any variable already present in `$_ENV`, so the committed
`.env` won unconditionally and the git-ignored `.env.local` — the override `.env:1` and
the README both tell developers to use — could never take effect in the only supported
runtime.

### Verification that the override now works

With a temporary, git-ignored, secret-free `.env.local`:

```
docker compose exec -T php printenv APP_ENV        -> no output, exit 1
docker compose exec -T php printenv DATABASE_URL   -> no output, exit 1   (ambient env gone)

bin/console debug:dotenv
  Scanned Files: ✓ .env.local  ✓ .env
  APP_SECRET     overridden_from_env_local_not_a_secret   (.env.local wins over .env)
  DATABASE_URL   …/storyteller_envlocal_probe             (.env.local wins over .env)

bin/console doctrine:migrations:status
  doctrine.INFO: Connecting with parameters … "dbname":"storyteller_envlocal_probe"
  -> exit 7, FATAL: database "storyteller_envlocal_probe" does not exist
```

That last line is the strongest form of the proof available: the override did not merely
show up in a debug command, it reached Doctrine's actual connection parameters. The
fabricated database name is why the command failed, and that failure is the evidence.

The file was then **deleted** (`find . -maxdepth 1 -name '.env.local' -type f -delete`).
It was never staged; `git check-ignore -v .env.local` reported `.gitignore:4:/.env.local`.
It contained a placeholder string, never a real secret.

After deletion, re-verified in the container:

```
bin/console debug:dotenv            -> exit 0, ".env.local" shown as ⨯ (absent)
                                       APP_SECRET back to change_me_in_env_local
bin/console doctrine:migrations:status -> exit 0
make qa                             -> exit 0
```

### What was kept

The step-11b `<env name="APP_ENV">` / `<env name="APP_DEBUG">` entries in `phpunit.xml`
are **unchanged**. Only their comment was corrected: it cited `env_file` as the reason
they were needed, which is no longer true. They are kept because they are independently
right — `KernelTestCase` reads `$_ENV` before `$_SERVER`, so they make the suite hermetic
against any ambient `APP_ENV` (an exported shell variable, a CI runner). This fix removed
the thing that *required* them, not the hardening itself. `make qa` exit 0 after the
change confirms they still do their job with no env_file in play.

Nothing broke in the container as a result of the removal: the app boots, the suite
passes, and `doctrine:migrations:status` exits 0.

## Deviations from the plan (Gate C fix loop 1)

| Item | Planned | Actually done | Why |
|------|---------|---------------|-----|
| B1 | The plan (step 9, §`deptrac.yaml`) prescribes a `composer` collector with a verbatim 26-package list, and says "every package name listed must exist in `composer.lock`… do not replace the list with a wildcard." | The `composer` collector and its list were **removed** and replaced by a `bool` collector (`classLike .*` minus `^Storyteller\\` minus `php_internal`). | The plan's instruction is the defect the review found: it checks list ⊆ lock, which is the wrong direction — the property that matters is lock ⊆ list, and C-1 broke it nine packages at a time. "Do not replace the list with a wildcard" was written to stop the list being *weakened*; this change strengthens it (every vendor package is now covered, not 26 of 59) and keeps P3 intact. Recorded as a deliberate, reviewed departure from a prescriptive plan section, authorised by the Gate C fix brief. |
| B1 | The plan does not specify any `deptrac analyse` flags. | Added `--report-uncovered --fail-on-uncovered` to the `deptrac` composer script. | Explicitly requested by the fix brief and by review B1. Strengthens the gate; standing rule 4 is not in tension. |
| B1 | n/a | `ignore_uncovered_internal_classes: true` written out explicitly (it is already deptrac's default). | Documentation, not behaviour change: `--fail-on-uncovered` is only safe because of it. |
| B2 | The plan's `compose.yaml` (§`compose.yaml`, step 11) contains `env_file: - .env` verbatim. | The block was removed. | Review B2. The plan's shape makes `.env.local` inert in the container, contradicting the plan's own `.env` text ("override in .env.local"). Fixing the environment rather than patching around it was the call the review says step 11b should have escalated for. |
| scope | — | **Majors and minors left untouched on purpose.** | The brief scopes this loop to B1 and B2 and states the user has not approved work on the rest. |

**Overlap with an open major, reported as instructed.** Fixing B1 necessarily touches
something **M3** describes. M3's complaint is that D-1's `"Storyteller\\": "src/"` psr-4
rule makes the layer-free zone at the `src/` root *generically reachable*, and it notes
that the hole is silent "because the `deptrac` script does not pass `--fail-on-uncovered`".
Adding that flag makes the hole **noisy** — a future `src/Support/Clock.php` used by a
context would now fail `composer deptrac` as an uncovered dependency instead of exiting 0.
M3's own suggested fix ("if the psr-4 rule is kept, pair it with `--fail-on-uncovered`")
is therefore now satisfied. **M3 itself is NOT fixed:** `composer.json` still carries
`"Storyteller\\": "src/"`, the narrow classmap alternative was not adopted, and the open
decision D-1 asks for is still open. No other major or minor was affected.

## Final check state (Gate C fix loop 1)

Host (macOS, PHP 8.5.3):

```
composer validate --strict -> exit 0
composer cs-fix            -> exit 0  (Fixed 0 of 8 files)
composer phpstan           -> exit 0  ([OK] No errors)
composer deptrac           -> exit 0  (Violations 0 · Uncovered 0 · Allowed 0)
composer test              -> exit 0  (OK, 4 tests, 19 assertions)
composer qa                -> exit 0  (all four, in order)
git status --porcelain     -> clean except the untracked, out-of-scope
                              docs/sdlc/project-skeleton/04-review.md
```

Container (php 8.5.12):

```
make up                                -> exit 0 (postgres healthy)
make qa                                -> exit 0  <- the feature's acceptance criterion
bin/console about                      -> exit 0 (Symfony v7.4.21, env dev)
bin/console doctrine:migrations:status -> exit 0
B1 probe table (P1-P6)                 -> 1,1,1,1,1,0 — identical to the host
make down                              -> exit 0, storyteller_pgdata volume survives
find . -user root -not -path './vendor/*' -> empty
```

## Left for the tester / reviewer (Gate C fix loop 1)

- **Both blockers are closed and proven**, on the host *and* in the container. The probe
  table is the evidence for B1; `debug:dotenv` plus Doctrine's connection parameters are
  the evidence for B2.
- **Still open, untouched, not approved for work:** M1 (`migrations/` outside every gate),
  M2 (PHPStan analysing the generated `config/reference.php`), M3 (the `Storyteller\ =>
  src/` psr-4 rule — now *noisy* thanks to `--fail-on-uncovered`, but still present), M4
  (the unrecorded `.claude/**` commit), and all ten minors including m1's
  `guard-running` exit-code transcription slip and m2's `symfony/uid`-in-`Shared` decision.
- **New follow-up created by this loop:** m2 is now slightly more expensive to resolve —
  see the "cost" paragraph under B1. Deciding m2 before the first `src/Shared` class is
  worth more than it was.
- `docs/sdlc/project-skeleton/04-review.md` is **untracked** and was deliberately not
  committed: the fix brief forbids touching the review document. Whoever owns the phase
  docs should decide whether it is committed.

---

## Post-Gate-D fixes: N1, M3

**Model:** `opus` — both steps edit `deptrac.yaml` and the dependency graph, which is the
SDLC capability floor. Two findings, two logical steps, two commits. All verification ran
inside the container (`docker compose exec php`, PHP 8.5.12); host PHP was not used.

### Step N1 — `require-dev` packages are no longer inside `Framework`

**Commit:** `ff3afca` — `Shared: deny require-dev packages from src/ via a Dev deptrac layer`

**Files touched:** `deptrac.yaml`, `tests/Unit/Shared/DevPackageIsolationTest.php` (new).

#### What the plan said, and why it could not be followed verbatim

Review N1 prescribed "a `Dev` layer with a `composer` collector over the six `require-dev`
package names". **That mechanism cannot work**, established by reading deptrac 4.7.2's
source rather than assuming:

- `ComposerFilesParser::extractNamespaces()`
  (`vendor/deptrac/deptrac/src/DefaultBehavior/Layer/Helpers/ComposerFilesParser.php`)
  resolves a package to its `autoload.psr-0` / `autoload.psr-4` keys and nothing else.
- The packages that matter most declare neither. Verified against the installed tree:
  `phpunit/phpunit` → `{"classmap": ["src/"], "files": [...]}`, every `sebastian/*` →
  `{"classmap": ["src/"]}`, `phpstan/phpstan` → `{"files": ["bootstrap.php"]}`,
  `theseer/tokenizer`, `phar-io/*`, `jetbrains/phpstorm-stubs` likewise. A `composer`
  collector naming `phpunit/phpunit` therefore resolves to **zero** namespaces and matches
  nothing — the exact symbol N1 is about, `PHPUnit\Framework\Assert`, would still pass.

**Deviation, recorded:** the layer uses `classLike` namespace-prefix collectors instead of
a `composer` collector. Namespace prefixes are the only mechanism in deptrac that can reach
a classmap-autoloaded package.

#### The dev-requirements question, answered rather than guessed

deptrac's `composer` collector **does** read dev requirements:
`ComposerFilesParser::getPackagesFromLockFile()` merges `composer.lock`'s `packages-dev`
into the same map as `packages`, and `ComposerCollector` calls
`autoloadableNamespacesForRequirements($packages, true)` with `includeDev` **hard-coded to
`true`** — `ComposerConfig::toArray()` exposes no such key, so there is no YAML option to
set either way in 4.7.2. The option governs whether a named package's own `autoload-dev`
section is included, not whether dev requirements are visible. So that half of the
mechanism was never the problem; the psr-4-only namespace resolution was.

#### How far the layer reaches — the transitive decision

**Decision: the full transitive dev-only closure, not the six direct packages.**
`composer.lock`'s `packages-dev` holds 51 entries, and that set *is* the exact closure:
composer places a package there only when nothing in `require` reaches it; a package needed
by both sides lives in `packages`. Naming only the six would leave `sebastian/*`,
`myclabs/deep-copy`, `nikic/php-parser` and `theseer/tokenizer` inside `Framework` —
measured below, that gap is real and not theoretical.

The 51 packages collapse to 20 namespace prefixes (`PHPUnit\`, `SebastianBergmann\`,
`PhpCsFixer\`, `PHPStan\`, `Deptrac\`, `PhpParser\`, `React\`, `Composer\{Pcre,Semver,
XdebugHandler}\`, `phpDocumentor\`, `TheSeer\`, `PharIo\`, `DeepCopy\`, `Clue\`,
`Evenement\`, `Ergebnis\`, `Fidry\`, `staabm\`, `Symfony\Polyfill\Php8[01]\`). Each is
annotated in `deptrac.yaml` with the package it comes from.

**Cost, stated plainly and written into the comment block:** unlike `Framework`, this list
is *not* exhaustive by construction and goes stale when a dev dependency is added, removed
or upgraded into a new namespace. `tests/Unit/Shared/DevPackageIsolationTest.php` pays that
cost: it recomputes the dev-only namespace set from `composer.lock` plus
`vendor/composer/autoload_classmap.php` and `autoload_psr4.php`, compiles the layer's
values exactly as deptrac does (`/<value>/i`), and fails in three directions — a dev
namespace the layer misses, a **production** namespace it captures, and a prefix that
matches no installed dev symbol (dead/stale). It also asserts `Dev` is granted to no layer,
that `Framework` excludes `Dev`, and that `paths` is still `./src` only.

That test earned its keep on first run: `^Symfony\Polyfill\Php8` also matched
**`symfony/polyfill-php86`**, which is a *production* package in this lock. Narrowed to
`^Symfony\Polyfill\Php8[01]\`. A hand-written prefix list would have shipped that bug.

`Framework` gained `must_not: {type: layer, value: Dev}` rather than a copy of the 20
patterns, so the two layers cannot drift apart. No cycle: `Dev` never refers to `Framework`.

#### Red-first evidence (exit codes observed)

| Probe | Before `Dev` | After `Dev` |
|-------|--------------|-------------|
| `src/Content/Domain/N1DirectProbe.php` → `PHPUnit\Framework\Assert` | `Violations 0, Allowed 2`, **exit 0** (jointly with the next row) | `DependsOnDisallowedLayer … (Dev)`, **exit 1** |
| `src/Api/Dto/N1TransitiveProbe.php` → `SebastianBergmann\Diff\Differ` | *same run*, allowed | `DependsOnDisallowedLayer … (Dev)`, **exit 1** |
| both probes deleted | — | `Violations 0 / Uncovered 0 / Allowed 0`, **exit 0** |

**The transitive probe is the honest answer to the brief's question:** a six-package `Dev`
layer would *not* have caught `SebastianBergmann\Diff\Differ`; the 51-package closure does.
Confirmed in both directions — it was `Allowed` before the fix and a violation after.

Positive controls, re-run against the final config: `Content` → `Doctrine\ORM\
EntityManagerInterface` + `Symfony\Component\Routing\Route` and `Play` → `Content` +
`Engine` give `Violations 0, Allowed 4`, **exit 0**; `Engine` → `DateTimeImmutable`,
`Random\Randomizer`, `BcMath\Number` produces no entry at all (`php_internal`), exit 0.

#### What N1 does **not** cover

- Vendor **functions and constants** remain invisible to deptrac (review N2, unchanged):
  `use function PhpCsFixer\…;` in `src/` is still not a deptrac violation. The import scan
  in `BoundedContextLayoutTest` covers `use function` / `use const` for the `PHPUnit` root,
  but only inside `src/Engine` and `src/Shared`.
- A dev package reached through a **runtime string** (`new ('PHPUnit\\'.$x)`) is invisible,
  as it is to any static analyser.
- `Dev` is namespace-shaped, so a dev package that ever starts sharing a namespace root
  with a production package needs a narrower prefix. `DevPackageIsolationTest`'s
  production-side assertion is what turns that from silent to red — it already did once.
- `migrations/` is still outside `paths` (M1, open), so a migration importing PHPUnit is
  not covered by this.

### Step M3 — a class in an unlayered namespace no longer escapes the rule

**Commit:** `4badf1c` — `Shared: layer src/ outside the five contexts so it cannot escape the rule`

**Files touched:** `deptrac.yaml`, `tests/Unit/Shared/UnlayeredSourceTest.php` (new).

#### Mechanism chosen, and why

The review offered two candidates. **Chosen: the catch-all deptrac layer.** An `Unlayered`
layer collects `src/.*` minus `src/(Shared|Engine|Content|Play|Api)/.*` minus
`src/Kernel\.php$`, and is granted nothing and granted to nobody.

Rejected: a unit test asserting `src/` has no unlayered top-level directory. It would hold
the *layout*, but any file it did allow would still be unconstrained, and it would need the
five-context list kept in sync in a second place. The catch-all puts the answer inside the
mechanism that owns the dependency rule, and it constrains the code rather than the
directory name.

`src/Kernel.php` stays deliberately unlayered (decision R13) through a one-file `must_not`.
Confirmed with `deptrac debug:token 'Storyteller\Kernel'` → layers `---`, i.e. none.

#### Red-first evidence (exit codes observed)

| State | `src/Support/M3Probe.php` in `Storyteller\Support` importing `Doctrine\ORM\EntityManagerInterface` **and** `Symfony\Component\HttpFoundation\Request` |
|-------|------|
| Before `Unlayered` | `Violations 0 / Skipped 0 / Uncovered 0 / Allowed 0 / Warnings 0`, **exit 0** — reported nowhere at all, exactly as M3 describes |
| After `Unlayered` | two `DependsOnDisallowedLayer` rows naming the probe and both imports `(Framework)`, `Violations 2`, **exit 1** |
| Probe deleted | `Violations 0 / Uncovered 0 / Allowed 0`, **exit 0**; `debug:layer Unlayered` lists no tokens |

`UnlayeredSourceTest` applies the collector's regexes the way `DirectoryCollector` does
(`#<value>#i` against a normalised path) over 11 representative paths, and asserts the
ruleset grants `Unlayered` nothing and grants it to nobody. Proven non-vacuous: deleting
the `src/Kernel\.php$` carve-out from `deptrac.yaml` makes it **fail 1, exit 1**
(`classifies "/app/src/Kernel.php" wrongly`); the file was restored from a scratchpad copy
and `git diff --stat` confirmed the single expected hunk.

#### What M3's fix does **not** cover — explicitly

- **An unlayered class that depends on nothing is still silent.** deptrac reports
  dependencies, not the existence of a file. A `src/Support/Constants.php` with no imports
  passes.
- **The layer-free zone still exists.** This makes code placed there unable to import
  anything; it does not stop the directory being created. The narrow cure the reviewer
  preferred — dropping `"Storyteller\\": "src/"` and classmapping `src/Kernel.php` — is a
  `composer.json` change and is **left open**, deliberately out of scope for this step.
- **The Kernel carve-out is file-wide, not class-wide.** A second class declared inside
  `src/Kernel.php` would inherit the exemption.
- Still outside `paths`: `migrations/`, `config/`, `public/`, `bin/` (M1 for migrations).

### Checks after each step

| Check | After N1 (`ff3afca`) | After M3 (`4badf1c`) |
|-------|----------------------|----------------------|
| `composer cs-fix` | exit 0, fixed 0 of 9 | exit 0, fixed 0 of 10 |
| `composer phpstan` | exit 0, `[OK] No errors`, level 8 | exit 0, `[OK] No errors` |
| `composer test` | exit 0, `OK (12 tests, 1913 assertions)` | exit 0, `OK (24 tests, 2020 assertions)` |
| `composer deptrac` | exit 0, Violations 0 / Uncovered 0 | exit 0, Violations 0 / Uncovered 0 |
| `make qa` | — | **exit 0** |

Nothing was weakened to get there: PHPStan is still level 8 with no baseline, cs-fixer
config is untouched, and deptrac gained two deny-only layers — the five context edges in
the ruleset are byte-identical, `Api -> Engine` is still absent, and `Framework` is still
withheld from `Engine` and `Shared`.

### Deviations

1. **N1's prescribed mechanism was not usable.** The review specified a `composer`
   collector; deptrac 4.7.2 resolves that collector through psr-0/psr-4 autoload keys only,
   and phpunit and friends autoload by classmap. Implemented with `classLike` namespace
   prefixes plus a derivation test instead. Reason and evidence above; the comment block in
   `deptrac.yaml` records it at the point of use.
2. **N1's scope was widened from six packages to the 51-package dev-only closure.** The
   brief asked for a deliberate decision on transitive reach; the transitive probe shows
   six would have been insufficient.
3. **A test accompanies each step** (CLAUDE.md rule 2) even though neither step touches
   `src/Engine`. Both are config-pinning tests, not product code.
4. **No capability-floor mismatch.** Both steps were flagged as dependency-graph work and
   were run at `opus`. Neither touched `src/Engine/**`, a migration, a cross-context public
   interface, or `src/Api`'s security surface.

### Still open after this work

M1 (`migrations/` outside every gate), M2 (PHPStan analysing the generated
`config/reference.php`), M4 (the unrecorded `.claude/**` commit), N2, N3, N4 and m1–m10 are
untouched. M3's narrow cure — dropping the `Storyteller\ => src/` psr-4 rule — remains the
better long-term fix and is unresolved.
