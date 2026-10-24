# 03 — Implementation notes: project-skeleton

- **Feature slug:** `project-skeleton`
- **Plan:** `docs/sdlc/project-skeleton/02-plan.md`
- **Date:** 2026-10-24

> **Batch 1 of 4 — steps 1–6 only.** Steps 7–14 (PHPStan config, cs-fixer config, deptrac,
> the `qa` script, Docker, Makefile, README, the `CLAUDE.md` line-3 edit) are deliberately
> not implemented here and belong to later batches, which append to this same document.

## Progress

| Step | Status | Commit |
|------|--------|--------|
| 1. Initialise the repository | done | `chore: initialize git repository with .gitignore` |
| 2. Bounded-context and test directory tree | done | `chore: create bounded-context and test directory tree` |
| 3. Extend `composer.json` and install | done | `build: add Symfony 7.4, Doctrine and the four quality tools` |
| 4. Boot a minimal Symfony kernel | done (1 deviation) | `feat: boot a minimal Symfony 7.4 kernel` |
| 5. Wire Doctrine with zero entities | done (2 deviations) | `build: configure Doctrine and migrations without entities` |
| 6. PHPUnit suites and the first tests | done | `test: add PHPUnit suites and the kernel boot smoke test` |
| 7.–14. | not started (later batches) | — |

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

## Deviations from the plan

| Step | Planned | Actually done | Why |
|------|---------|---------------|-----|
| 4 (D-1) | `composer.json` `autoload.psr-4` maps the five context namespaces plus `Storyteller\Migrations\`; `Kernel` lives at `src/Kernel.php` in namespace `Storyteller` | Added one more rule: `"Storyteller\\": "src/"` | The plan's map has **no** rule covering `Storyteller\Kernel`, so `php bin/console about` — the step's own verification — died with `ClassNotFoundError: Attempted to load class "Kernel" from namespace "Storyteller"`. This is the minimal additive fix and the only one that keeps the Kernel at `src/Kernel.php` outside every context directory, which R13 requires. Longer PSR-4 prefixes still win, so the five context mappings are unaffected; `autoload` is not part of `composer.lock`'s content hash, so the lock is untouched and `composer validate --strict` stays green. |
| 5 (D-2) | `doctrine.orm` carries only `auto_generate_proxy_classes`, `auto_mapping: false`, `mappings: {}`, `controller_resolver.auto_mapping: false`; the plan names the permitted keys and forbids adding `enable_lazy_ghost_objects` and friends | Added `enable_native_lazy_objects: true` under `doctrine.orm`, with an explanatory comment | `doctrine/orm` 3.7.5 falls back to Symfony's `LazyGhostTrait` when native lazy objects are off, but `symfony/var-exporter` resolved to **v8.1.9** (transitively allowed by `^6.3.9\|^7.0\|^8.0`) and Symfony 8 **removed** that trait. `ProxyFactory::__construct` then throws `ORMInvalidArgumentException::lazyGhostUnavailable()` on the first real `EntityManager` instantiation — reproduced by `bin/console doctrine:migrations:status` (exit 1). This breaks acceptance criterion 16 **inside the container as well**, so it is not an artifact of the missing database. PHP 8.5 supports native lazy objects and ORM 4.0 makes them mandatory, so this is the forward-correct fix; it also removes the `Doctrine\ORM\Proxy\Autoloader` deprecation (`about` now boots with zero deprecations). Alternative considered and **not** taken: constraining `symfony/var-exporter` to `7.4.*`, which treats the symptom and leaves the rest of the 8.1 drift in place — that is a dependency decision for the orchestrator, not the implementer. |
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
  reviewer; no constraint was changed, since changing one is a plan-level decision.
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

Supporting verifications: `composer validate --strict` exit 0 · `php bin/console about`
exit 0 (Symfony v7.4.21 LTS, PHP 8.5.3, no database, no deprecations) ·
`git status --porcelain` empty after every commit.

## Left for the tester / reviewer

- **Decide on D-2 and D-1.** Both are small and documented, but both contradict the plan's
  verbatim file contents and should be explicitly accepted or re-routed.
- **Decide whether the Symfony 8.1 transitive drift is acceptable** for a 7.4 LTS skeleton,
  or whether a later batch should pin the Symfony component set.
- **Acceptance criterion 16 is unverified**: `bin/console doctrine:migrations:status`
  needs the container from step 11 and a healthy `postgres` service. Re-run it there.
- Criteria 1–5, 7–11, 13 (`.gitkeep` tracking is done, but the gates that check it are
  not), 18 (README) all depend on steps 7–14 and are untouched by this batch.
- Not implemented here by instruction: `phpstan.neon`, `.php-cs-fixer.dist.php`,
  `deptrac.yaml`, the `cs-check`/`qa` scripts, `Dockerfile`, `compose.yaml`,
  `docker/**`, `Makefile`, `README.md`, and the `CLAUDE.md` line-3 edit.
