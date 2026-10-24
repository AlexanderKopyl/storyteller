# 01 — Analysis: project-skeleton

- **Feature slug:** `project-skeleton`
- **Date:** 2026-10-24 (open questions closed at Gate A, same day)
- **Request:** Stand up the runnable Symfony 7.4 LTS / PHP 8.5 project skeleton for Storyteller — Composer manifest with the framework, ORM, serializer, validator, uid, yaml and monolog packages plus the four quality tools; a Docker dev environment (php-fpm 8.5, nginx, postgres 16) with a Makefile; the empty bounded-context folder tree; a `deptrac.yaml` encoding the dependency rule; PHPUnit config with unit and integration suites and a single kernel-boot smoke test; Doctrine wired to PostgreSQL with a migrations directory but no entities; `declare(strict_types=1)` enforced by php-cs-fixer; and `.env.example`, `.gitignore` and a README. No product logic. Acceptance is `make qa` green inside the container.

## Scope

**In scope**

- `git init` on the repository root, as the first implementation step, followed by one small commit per plan step (standing rule 7). Decided at Gate A — see D5.
- `composer.json`: extend the existing partial manifest (do not replace it — the `Storyteller\*` PSR-4 map and the four scripts are already there) with `require`/`require-dev` entries, a `qa` script, `config.platform.php`, and `config.allow-plugins` as needed.
- Runtime packages: `symfony/framework-bundle`, `symfony/orm-pack`, `symfony/serializer`, `symfony/validator`, `symfony/uid`, `symfony/yaml`, `symfony/monolog-bundle`, all on the 7.4 LTS line where versioned; plus `symfony/console`, `symfony/dotenv` and `symfony/runtime` as required-but-unlisted additions (D7).
- Dev packages: `phpunit/phpunit` (`^12.5`, per D6), `phpstan/phpstan` + `phpstan/phpstan-symfony` + `phpstan/phpstan-doctrine`, `friendsofphp/php-cs-fixer`, `deptrac/deptrac`.
- Tool configs: `phpstan.neon` (level 8, `phpVersion: 80500`, both extensions), `.php-cs-fixer.dist.php` (PSR-12 + `declare_strict_types`), `deptrac.yaml` (five context layers + vendor layers + ruleset), `phpunit.xml` (unit + integration suites).
- Symfony application plumbing that is otherwise absent because the project does not use Flex: `src/Kernel.php`, `config/bundles.php`, `config/packages/*.yaml` (framework, doctrine, doctrine_migrations, monolog), `config/routes.yaml`, `config/services.yaml`, `public/index.php`, `bin/console`, `var/` ignored.
- Folder tree with `.gitkeep`: `src/Content/{Domain,Application,Infrastructure}`, `src/Engine`, `src/Play/{Domain,Application,Infrastructure}`, `src/Api/{Controller,Dto,Security}`, `src/Shared`, `migrations/`, and the test tree `tests/Unit/{Engine,Content,Play,Api,Shared}` + `tests/Integration/{Content,Play,Api}` (D3).
- Docker: `Dockerfile` (php 8.5-fpm with `pdo_pgsql`, Composer, non-root user), `compose.yaml` (php, nginx, postgres 16), `docker/nginx/*.conf`, `docker/php/*.ini`, `Makefile` with `up`, `down`, `sh`, `test`, `qa`.
- One smoke test asserting the kernel boots, placed under `tests/Integration/` — it needs the container compiled, not just autoloading.
- `.env`/`.env.example`, `.gitignore`, `README.md` with setup steps.
- **One single-line edit to `CLAUDE.md`**: line 3 only, correcting the platform string (D1).

**Out of scope (explicitly not doing)**

- Any Engine logic: no `GameState`, `ConditionEvaluator`, `EffectApplier`, `TransitionResolver`, no JSON AST spec.
- Any Doctrine entity, repository, or generated migration. The `migrations/` directory is created empty.
- Any API endpoint, DTO, controller, route or security configuration beyond what the kernel needs to boot.
- The Vue 3 frontend, asset pipeline, or anything under `public/` other than `index.php`.
- CI pipeline definition (GitHub Actions etc.) — not requested.
- Authentication, `symfony/security-bundle`, messenger, mailer, caching backends.
- Production Docker image hardening, TLS, multi-stage prod target.
- Any PHPStan baseline file (standing rule: no baseline additions without a note; on an empty skeleton none is needed).
- `symfony/flex` and `symfony/test-pack` (D7).
- Any edit to `CLAUDE.md` beyond line 3, and any edit at all to `.claude/skills/sdlc/SKILL.md` (D1, D2).

## Affected bounded contexts

| Context   | Affected? | What changes                      |
|-----------|-----------|-----------------------------------|
| `Shared`  | yes (structurally) | Directory `src/Shared/` created with `.gitkeep`. No classes. Becomes a deptrac layer that everything may depend on and that depends on nothing. |
| `Engine`  | yes (structurally) | Directory `src/Engine/` created with `.gitkeep`. No classes — deliberately, because adding a placeholder class there would trigger standing rule 1 (no `src/Engine` change without a `tests/Unit/Engine` unit test). Becomes the most restricted deptrac layer. |
| `Content` | yes (structurally) | `src/Content/{Domain,Application,Infrastructure}` created with `.gitkeep`. No classes. |
| `Play`    | yes (structurally) | `src/Play/{Domain,Application,Infrastructure}` created with `.gitkeep`. No classes. |
| `Api`     | yes (structurally) | `src/Api/{Controller,Dto,Security}` created with `.gitkeep`. No classes. Note: `src/Kernel.php` sits at the `Storyteller\` root, **not** inside `Api`, so it must not be captured by the `Api` deptrac layer. |

No context gains behaviour. The feature creates the boundaries rather than crossing them.

## Existing code touched

| File | Why it is relevant |
|------|--------------------|
| `/Users/aleksandrkopyl/www/storyteller/composer.json` | Already exists and already pins `"php": ">=8.5"`, the five `Storyteller\*` PSR-4 namespaces, `Storyteller\Tests\` → `tests/`, and the `test`/`phpstan`/`cs-fix`/`deptrac` scripts with `scripts-descriptions`. Must be **extended, not regenerated** — `composer init`/`composer create-project` would discard it. The `deptrac` script already assumes `vendor/bin/deptrac --config-file=deptrac.yaml`, and the `phpstan` script already passes `--memory-limit=1G`. The `Storyteller\Tests\` → `tests/` mapping already works for the `tests/Unit` + `tests/Integration` layout chosen in D3. |
| `/Users/aleksandrkopyl/www/storyteller/CLAUDE.md` | Line 3 states "PHP 8.3 · Symfony 7", contradicting the request and the existing `composer.json`. **This line is the one and only `CLAUDE.md` edit authorized for this feature** (D1). The file is also the authority for the dependency rule that `deptrac.yaml` must encode, and for standing rules 1, 4, 6 and 9 which constrain this work — none of which change. |
| `/Users/aleksandrkopyl/www/storyteller/.claude/skills/sdlc/SKILL.md` | Line 102 repeats the stale "PHP 8.3 / Symfony 7" string. Flagged as a follow-up item only; **not touched** (D2). |
| `/Users/aleksandrkopyl/www/storyteller/.idea/` | PhpStorm project directory present in the working tree; `.gitignore` must cover it (it ships its own `.idea/.gitignore`, which is not sufficient on its own). |

There is no `src/`, `tests/`, `vendor/`, `composer.lock`, `deptrac.yaml`, `phpunit.xml`, `Dockerfile`, `Makefile`, `.env*`, `.gitignore` or `README.md` yet, and the directory is **not yet a git repository** — initializing it is now in scope (D5).

## Data & schema impact

- Story content / JSON AST changes: none. The AST spec is not defined in this phase.
- `story_version` snapshot shape: not introduced. No table, no JSONB column.
- New tables / columns / indexes: **none**. Doctrine is configured, not exercised.
- Migration needed: **no**. A `migrations/` directory is created (with `.gitkeep`) and registered under `doctrine_migrations.migrations_paths`, but zero migration classes are written and none are run. Standing rule 6 therefore does not bite yet.
- Postgres 16 is provisioned in Compose with a named volume; the database is created empty. `make qa` must not require a reachable database — see Risk R2.

## Dependency-rule impact

This feature **creates** the dependency graph; it is the artifact under test.

Required edges (as `deptrac.yaml` ruleset entries):

```
Shared  : []                              # depends on nothing
Engine  : [Shared]
Content : [Engine, Shared]
Play    : [Engine, Content, Shared]
Api     : [Play, Content, Shared]
```

The request's ruleset and `CLAUDE.md`'s dependency rule **agree exactly**. `CLAUDE.md` writes the graph without `Shared` and then adds "Everything may depend on `Shared`" prose plus "No context may depend upward or sideways"; expanding that prose yields precisely the five lines above, including `Shared: []`. There is no conflict to resolve and no new or questionable edge.

Two things the ruleset as stated does **not** yet cover:

1. **`Api -> Engine` is absent, deliberately.** Neither source lists it. `Api` reaches the Engine through `Play`. The planner should not "helpfully" add it.
2. **"Symfony, Doctrine and HTTP types must never appear inside `src/Engine`"** (CLAUDE.md) is *not* expressible with the five source layers alone. Deptrac ignores tokens that belong to no layer, so a `use Doctrine\ORM\EntityManagerInterface;` inside `src/Engine` would pass a five-layer ruleset silently. Making that rule real needs additional vendor layers built with deptrac's `composer` collector (the same mechanism deptrac uses on itself), e.g. a `Framework` layer collecting `symfony/*`, `doctrine/*`, `psr/*`, which no `Engine` ruleset entry permits. **These vendor layers ship in this feature** (D4). Verified syntax (deptrac 4 `deptrac.yaml`): top-level `deptrac:` key with `paths`, `analyser.types`, `layers[].collectors[].type: directory|composer`, `ruleset`.

**Green-by-vacancy warning.** With `src/**` containing only `.gitkeep` files, deptrac analyses zero tokens and exits 0 unconditionally. `make qa` passing therefore proves the config parses and the binary runs — it does **not** prove the rules are enforced. Accepted knowingly per D4: the vendor layers mean the rule bites the moment the first real class lands, and no placeholder classes are added to manufacture a signal.

## Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| **R1 — Doctrine ORM with zero entities breaks the kernel boot.** DoctrineBundle validates `orm.mappings[].dir` at container-compile time; an `auto_mapping: true` default pointing at a non-existent `src/Entity` throws during compilation, failing the smoke test that is the whole point of the phase. | high | high | Either set `doctrine.orm.auto_mapping: false` with `mappings: {}`, or create the mapped directory with a `.gitkeep` and `is_bundle: false`. Decide in the plan; assert the kernel boots in the smoke test. |
| **R2 — `make qa` needs a live Postgres.** If the smoke test boots the kernel in an env that eagerly opens a connection (or if `doctrine:migrations` config validation resolves the DSN), `qa` becomes order-dependent on `make up` and the DB healthcheck. | med | med | Keep the connection lazy (DoctrineBundle default), never call `getConnection()->connect()` in the smoke test, and give the postgres service a healthcheck with `depends_on: condition: service_healthy` so `make qa` is deterministic if a connection ever is needed. |
| **R3 — deptrac is trivially green on an empty tree** (see above), so the stated acceptance criterion certifies nothing about the dependency rule. | high | med | Resolved by D4: ship the `composer`-collector vendor layers now so the rule bites on the first real class; accept green-by-vacancy in the interim rather than adding placeholder classes. A deliberate-violation self-test remains an optional follow-up. |
| **R4 — PHPStan level 8 is also trivially green** on a tree with no `src` classes, and `phpstan-symfony` adds no value without `containerXmlPath` pointing at the compiled dev container XML. | high | low | Include `src/`, `tests/` and `config/` in `phpstan.neon` `paths`, set `containerXmlPath: var/cache/dev/Storyteller_KernelDevDebugContainer.xml` (exact filename depends on the kernel class/namespace — verify after the first `cache:warmup`), and accept that depth arrives with the first real code. |
| **R5 — `declare_strict_types` is a RISKY php-cs-fixer rule.** Confirmed from upstream rule docs. A config that enables it without `setRiskyAllowed(true)` fails to resolve, and the obvious "fix" is to drop the rule — which standing rule 4 forbids. | med | med | `.php-cs-fixer.dist.php` must call `->setRiskyAllowed(true)` and enable `declare_strict_types` explicitly alongside `@PSR12`. Note the rule only *adds* the declaration to files it sees; it does not police files excluded from the finder. |
| **R6 — `symfony/orm-pack` pins its three Doctrine packages at `*`.** Packagist currently exposes `doctrine/doctrine-bundle` 4.0.x-dev and `doctrine/orm` 4.0.x-dev; an unconstrained resolve can drift onto a major whose config keys differ from every tutorial and from this plan. | med | med | Either require `doctrine/doctrine-bundle`, `doctrine/orm` and `doctrine/doctrine-migrations-bundle` explicitly with `^` constraints instead of relying on the pack, or add them to `composer.json` after the pack installs. Commit `composer.lock`. |
| **R7 — host/container PHP patch drift.** Host is 8.5.3, the `php:8.5-fpm` image is currently 8.5.12 (Docker Hub, verified). A lock resolved on the host can mismatch the container platform and vice versa. | med | low | Set `config.platform.php` in `composer.json` (e.g. `"8.5.0"`) so resolution is identical in both places, pin the image to a patch tag rather than `8.5-fpm`, and commit `composer.lock`. |
| **R8 — the base image lacks the extensions the stack needs.** `php:8.5-fpm` ships without `pdo_pgsql`, `intl`, `zip` or `opcache`; Composer is not in the image either. | high | med | Dockerfile installs `libpq-dev` + `docker-php-ext-install pdo_pgsql pgsql`, `libicu-dev` + `intl` (serializer/validator comfort), `opcache`, and copies Composer from the `composer:2` image. |
| **R9 — root-owned `vendor/` and `var/` on a macOS bind mount.** If the container runs as root, `composer install` inside it leaves files the host IDE and the host `composer` cannot write, and the next host-side command fails confusingly. | high | med | Create a non-root user in the image with a build-arg UID/GID matching the host (`id -u`/`id -g`), run php-fpm and all `make` targets as that user, and document the override in the README. |
| **R10 — `make qa` must run against a dev-dependency install.** A Dockerfile that runs `composer install --no-dev` (the production reflex) makes `vendor/bin/phpunit`, `phpstan`, `php-cs-fixer` and `deptrac` all missing, and the acceptance criterion cannot pass. | med | high | Single dev-targeted image for this phase; install **with** dev dependencies. State it explicitly in the plan so a later prod stage does not silently inherit it. |
| **R11 — PHPUnit 13 is very new** (13.4.3, requires `php >=8.4.1`). Symfony 7.4's `KernelTestCase` is primarily exercised against PHPUnit 11/12; a schema or lifecycle incompatibility would surface exactly in the one smoke test this phase delivers. | med | med | Resolved by D6: pin `^12.5` now; revisit `^13` as a follow-up once there is a real suite to validate it against. |
| **R12 — no git repository.** Scaffolding roughly 30 files with no VCS means no clean rollback if the resolve goes wrong, and standing rule 7 ("small commits, one logical step each") is unsatisfiable. | high | med | Resolved by D5: `git init` is the first implementation step, with `.gitignore` committed before `composer install` so `vendor/` is never staged, then one commit per plan step. |
| **R13 — `src/Kernel.php` mis-assigned to a deptrac layer.** A `directory: src/Api/.*` collector is safe, but a careless `classNameRegex` on `Storyteller\\Api` plus a kernel placed in `src/Api` would make the `Api` layer depend on Symfony and the HttpKernel in ways the ruleset must then permit. | low | med | Keep `Kernel` at `src/Kernel.php` in the `Storyteller\` root namespace, outside every context layer, and use `directory` collectors only. |
| **R14 — Compose file format.** Compose v5.5.0 treats the top-level `version:` key as obsolete and warns; older tutorials still include it. | low | low | Name the file `compose.yaml` and omit `version:`. |

## Open questions

**None remain open.** All nine were put to the user at Gate A on 2026-10-24 and all nine were answered by accepting the recommended default. They are recorded below as decisions, with the original reasoning retained, so the planner can see *why* each was settled and does not reopen it.

1. **D1 — RESOLVED: the request plus `composer.json` are authoritative on the platform version, and `CLAUDE.md` line 3 is corrected as part of this feature.** Evidence that settled it: the committed `composer.json` already pins `"php": ">=8.5"`; the host toolchain is PHP 8.5.3; and the user restated 8.5 / 7.4 LTS explicitly in the request. The "8.3" line is stale copy predating the manifest, and leaving it would make every future agent reason against the wrong platform. Line 3 becomes `Interactive choice-driven story platform. PHP 8.5 · Symfony 7.4 LTS · PostgreSQL · REST API ·`. **This is the single `CLAUDE.md` edit authorized for this feature** — one line, no standing rule touched, no other line touched.
2. **D2 — RESOLVED: `.claude/skills/sdlc/SKILL.md` is left untouched.** It carries the same stale "PHP 8.3 / Symfony 7" string on line 102, but it is agent/tooling configuration rather than project source, and changing it is the user's call, not the pipeline's. Carried as a follow-up item in the summary for the user to approve separately.
3. **D3 — RESOLVED: suite-first, context-second test layout.** `tests/Unit/{Engine,Content,Play,Api,Shared}` and `tests/Integration/{Content,Play,Api}`, with `phpunit.xml` suites `unit` → `tests/Unit` and `integration` → `tests/Integration`. This is the only layout that satisfies standing rule 1 literally (`tests/Unit/Engine/**`), gives each of the two requested suites a directory of its own, and provides a home for the kernel smoke test — which is an integration test, not a unit test, and therefore lands in `tests/Integration/`. The request's literal `tests/{Engine,Play,Api}` (which also omitted `Content` and `Shared`) gives way; **`CLAUDE.md` standing rule 1 stands unchanged.**
4. **D4 — RESOLVED: deptrac ships with vendor layers now; no placeholder classes.** `deptrac.yaml` carries `composer`-collector vendor layers (a `Framework` layer over `symfony/*`, `doctrine/*`, `psr/*`, `monolog/*`) alongside the five context layers, so "no Symfony or Doctrine inside `src/Engine`" is encoded from day one and enforced the moment the first real class lands. `composer deptrac` being green-by-vacancy in the meantime is accepted knowingly. Explicitly **no** marker class under `src/Engine` just to give deptrac something to analyse: standing rule 1 would then demand a unit test for a class with no behaviour, which is worse than a vacuous check. A `make deptrac-selftest` target asserting deptrac *fails* on a deliberately-violating fixture remains an optional follow-up, not part of this feature.
5. **D5 — RESOLVED: `git init` is in scope.** `.gitignore` is a listed deliverable and is meaningless without a repository; standing rule 7 presumes commits; and R12 had no other mitigation. The implementer runs `git init` as the first step, commits `.gitignore` before `composer install` so `vendor/` is never staged, and makes one small commit per plan step.
6. **D6 — RESOLVED: PHPUnit `^12.5`.** PHPUnit 13.4.3 is available and its `php >=8.4.1` floor is satisfied, but it is new enough that Symfony 7.4's `KernelTestCase` has less mileage on it (R11), and the entire acceptance of this phase rests on one kernel-boot test. Moving to `^13` is a follow-up for when there is a real suite to validate it against.
7. **D7 — RESOLVED: add `symfony/console`, `symfony/dotenv` and `symfony/runtime`; no Flex, no test-pack.** Booting a kernel and running `bin/console` needs console and runtime, and `.env.example` is pointless without Dotenv, so these three are required-but-unlisted and each gets a one-line justification in `02-plan.md` per standing rule 9. `symfony/flex` is excluded because it would rewrite `composer.json`, install recipes and take ownership of `config/` — hand-written config is more predictable for a skeleton. `symfony/test-pack` is excluded because `framework-bundle` already supplies `KernelTestCase`. The planner lists the final package set for Gate B approval.
8. **D8 — RESOLVED: `make test` / `make qa` use `docker compose exec`.** The README states `make up` as a prerequisite, and the Makefile emits a clear error if the container is not running. `exec` keeps the feedback loop fast, which is what a dev-loop target is for; `run --rm` was the slower, self-contained alternative.
9. **D9 — RESOLVED: nginx ships, but `qa` must not depend on it.** The request lists nginx and it is included so a browser can reach `public/index.php` later, but `make qa` is CLI-only and must stay green regardless of the nginx service's state.

## Acceptance criteria

Every criterion below is checkable by a command. Criteria 1–3 are the environment; 4–11 are the four quality gates; 12–18 are the deliverables' substance.

1. `docker compose build` completes, and `docker compose up -d` brings up `php`, `nginx` and `postgres` with postgres reporting healthy.
2. `docker compose exec php php -v` reports PHP 8.5.x, and `docker compose exec php php -m` lists `pdo_pgsql`.
3. `docker compose exec php id -u` does **not** return `0`, and files created by the container inside the bind mount are writable by the host user without `sudo`.
4. **`make qa` exits 0 inside the container** and its output shows all four sub-checks running: `php-cs-fixer --dry-run`, `phpstan`, `deptrac`, `phpunit` — in that order, with `qa` failing if any one of them fails (no `|| true`, no swallowed exit codes).
5. `make up`, `make down`, `make sh`, `make test` and `make qa` all exist in the `Makefile` and each does what its name says; `make down` does not delete the postgres volume.
6. `composer test` runs PHPUnit and reports at least one passing test and zero failures, errors, skipped or risky tests.
7. `composer phpstan` exits 0 at **level 8** with `phpVersion: 80500` set, with `phpstan/phpstan-symfony` and `phpstan/phpstan-doctrine` loaded as extensions, and **no** `phpstan-baseline.neon` present in the repository.
8. `composer cs-fix -- --dry-run --diff` exits 0 on a freshly formatted tree, and `.php-cs-fixer.dist.php` enables `@PSR12` together with `declare_strict_types` and `setRiskyAllowed(true)`.
9. Every `.php` file under `src/`, `tests/`, `config/`, `public/` and `bin/` begins with `declare(strict_types=1);`, and deliberately removing it from one file makes criterion 8 fail.
10. `composer deptrac` exits 0, and `deptrac.yaml` defines exactly the layers `Engine`, `Content`, `Play`, `Api`, `Shared` with the ruleset `Shared: []`, `Engine: [Shared]`, `Content: [Engine, Shared]`, `Play: [Engine, Content, Shared]`, `Api: [Play, Content, Shared]` — matching `CLAUDE.md` with no extra edge, in particular **no `Api -> Engine`**.
11. `composer qa` (and `make qa`) runs cs-fix in `--dry-run` mode specifically — it must never rewrite files as a side effect of a verification run.
12. `composer.json` declares `"php": ">=8.5"`, and `composer.lock` is committed and resolves `symfony/framework-bundle` on the `7.4.*` line.
13. The directory tree exists with a tracked `.gitkeep` in each leaf: `src/Content/{Domain,Application,Infrastructure}`, `src/Engine`, `src/Play/{Domain,Application,Infrastructure}`, `src/Api/{Controller,Dto,Security}`, `src/Shared`, `migrations`, and the test directories per Open Question 3.
14. `phpunit.xml` defines two test suites named `unit` and `integration`; `vendor/bin/phpunit --testsuite=unit` and `--testsuite=integration` each run independently and exit 0.
15. The kernel smoke test boots the real `Kernel` (via `KernelTestCase::bootKernel()` or equivalent), asserts the container is available, and **passes without a reachable PostgreSQL server**.
16. `docker compose exec php bin/console about` exits 0 and reports Symfony 7.4.x; `bin/console doctrine:migrations:status` exits 0 and reports the configured migrations directory with zero migrations available.
17. `ls src/*/**.php` finds no domain classes: there is no entity, no controller, no repository, and nothing at all under `src/Engine` except `.gitkeep`.
18. `.gitignore` covers at least `/vendor/`, `/var/`, `/.env.local`, `/.env.*.local`, `/.phpunit.cache/`, `/.php-cs-fixer.cache`, `.idea/`; `.env.example` contains `APP_ENV`, `APP_SECRET` and a `DATABASE_URL` pointing at the Compose postgres service with **no real secret value**; `README.md` documents clone → `make up` → `make qa` end to end.

## Assumptions made

- **Verified today against Packagist and Docker Hub** (all of the following were checked, not assumed):
  - `symfony/framework-bundle` **v7.4.21** is the current 7.4 LTS patch and requires `php >=8.2` — PHP 8.5 is supported, and it already pulls `symfony/polyfill-php85`. Same 7.4.21 availability for `symfony/serializer`, `symfony/validator`, `symfony/uid`, `symfony/yaml`.
  - `symfony/monolog-bundle` **v4.1.0** requires `php >=8.2` and `symfony/* ^7.3 || ^8.0` — compatible with 7.4.
  - `symfony/orm-pack` **v2.7.0** is a metapackage requiring `doctrine/orm`, `doctrine/doctrine-bundle`, `doctrine/doctrine-migrations-bundle` at `*` (see R6). Current Doctrine lines declare `php ^8.4`, so PHP 8.5 is fine.
  - `phpstan/phpstan` **2.3.1**; its `conf/parametersSchema.neon` accepts `phpVersion` as an int in `[70100, 80699]`, so **`phpVersion: 80500` is valid** for this major. `phpstan/phpstan-symfony` **2.1.0** and `phpstan/phpstan-doctrine` **2.0.28** both require `phpstan ^2.0` — both extensions are valid for the current PHPStan major.
  - `friendsofphp/php-cs-fixer` **v3.95.27** declares `ConfigInterface::PHP_VERSION_SYNTAX_SUPPORTED = '8.5'`, so it runs on PHP 8.5 **without** needing `setUnsupportedPhpVersionAllowed(true)` or `PHP_CS_FIXER_IGNORE_ENV`. (Had it capped lower, the escape hatch would have been a config option, not a weakening of PSR-12 — but it is not needed.)
  - Deptrac's package name is **`deptrac/deptrac`** (current **4.7.2**, `php ^8.2`). The older `qossmic/deptrac` / `qossmic/deptrac-shim` names are stale (shim last released 1.0.2) and should not be used. Deptrac 4's config is a top-level `deptrac:` key containing `paths`, `analyser`, `layers` and `ruleset`, with `directory` and `composer` collector types — confirmed against deptrac's own `deptrac.yaml`.
  - `phpunit/phpunit` **13.4.3** (requires `php >=8.4.1`) and the **12.5.x** line are both available; `^12.5` is the version pinned per D6.
  - Docker Hub has `php:8.5-fpm` (currently resolving to **8.5.12**) and `postgres:16` (currently **16.15**).
- `make qa` is assumed to mean `docker compose exec php composer qa`, i.e. the Makefile target is a thin wrapper and `composer qa` is the real definition — this keeps the four checks runnable identically on the host and in CI later.
- "php-fpm 8.5" is read as the official `php:8.5-fpm` Debian image extended by a project `Dockerfile`, not a hand-rolled base.
- "postgres 16" is read as the official `postgres:16` image with a named volume for `/var/lib/postgresql/data`.
- The `Storyteller\` vendor namespace and the `Storyteller\Tests\` → `tests/` mapping already in `composer.json` are correct and are kept; the kernel is `Storyteller\Kernel` at `src/Kernel.php`. Under D3 the test namespaces are `Storyteller\Tests\Unit\*` and `Storyteller\Tests\Integration\*`, which the existing PSR-4 mapping already covers without change.
- `composer.lock` will be committed (it is an application, not a library).
- No CI provider is configured, so "green" means green locally in the container; the Makefile targets are written so a CI job could call them unchanged.
