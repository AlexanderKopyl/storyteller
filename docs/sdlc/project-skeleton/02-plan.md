# 02 — Implementation plan: project-skeleton

- **Feature slug:** `project-skeleton`
- **Based on:** `docs/sdlc/project-skeleton/01-analysis.md` (Gate A passed; D1–D9 are settled decisions and are not reopened here)
- **Date:** 2026-10-24

## Approach

Build the skeleton outward from the repository root in toolchain-dependency order: version control first (D5), then the directory tree that every later config references, then the dependency manifest, then the Symfony plumbing that makes a kernel bootable, then the four quality gates one at a time, then the aggregate `qa` script, then Docker, then docs. Each of the fourteen steps lands exactly one logical artifact and is verified by a single command whose exit code can be checked, so standing rule 7 holds and a bad step can be reverted with one `git revert`.

The shape is deliberately **Flex-free and hand-written** (D7): every config file in this plan is written out in full below, so the implementer never has to invent a key or guess a schema. Two risks drive the ordering. R1 (DoctrineBundle failing container compilation with zero entities) is isolated into its own step so the kernel-boot step before it is already proven green. R10 (a `--no-dev` image making `vendor/bin/*` absent) is handled by a single dev-targeted Docker stage.

The feature creates the bounded-context boundaries rather than crossing them: no context gains behaviour, no PHP class lands under `src/Engine`, `src/Content`, `src/Play`, `src/Api` or `src/Shared`, and the only new production class in the whole feature is `Storyteller\Kernel` — which lives at the `Storyteller\` root, outside every deptrac layer (R13).

### Plan-level decisions (made here so the implementer does not have to)

These supplement D1–D9; they are decisions the analysis left to the plan.

- **P1 — `symfony/orm-pack` is replaced by its three constituents.** R6 mitigation option A. The pack is a metapackage whose only content is three `*` constraints; requiring `doctrine/orm`, `doctrine/doctrine-bundle` and `doctrine/doctrine-migrations-bundle` directly with `^` constraints removes the drift onto a 4.0.x-dev major whose config keys differ from this plan. Functionally identical, strictly more deterministic.
- **P2 — no `ext-*` entries in `composer.json`.** `composer install` runs on the host in step 3 (before the image exists in step 11). Requiring `ext-pdo_pgsql` would make the host install fail or need `--ignore-platform-req`, which is exactly the kind of weakening standing rule 4 forbids habituating. The Dockerfile guarantees the extensions (R8), and acceptance criterion 2 checks them there.
- **P3 — `Shared` gets no `Framework` edge.** The vendor layer from D4 is granted to `Content`, `Play` and `Api`, but **not** to `Engine` and **not** to `Shared`. `Engine` depends on `Shared`, so a framework-contaminated `Shared` would reintroduce the exact contamination D4 exists to prevent. `Shared: []` therefore also matches acceptance criterion 10 literally. If `Shared` ever genuinely needs `symfony/uid`, that is a reviewed, deliberate ruleset change — not something this skeleton pre-authorises.
- **P4 — `Engine` is not auto-registered in the DI container.** `config/services.yaml` registers `Storyteller\Api\`, `Storyteller\Content\`, `Storyteller\Play\` and `Storyteller\Shared\` as service resources and deliberately omits `Storyteller\Engine\`. Engine objects are constructed explicitly by the contexts that use them; keeping them out of autowiring keeps the framework-free rule honest.
- **P5 — `phpstan.neon` ships without `containerXmlPath`.** R4 accepts that phpstan-symfony adds little depth on an empty tree. Setting it would make `composer phpstan` depend on a warmed `var/cache/dev` container, i.e. on step ordering inside `qa` — unacceptable for acceptance criterion 4. The exact path is left as a commented line for the follow-up once real services exist.
- **P6 — the `unit` suite gets one real test.** An empty `<testsuite>` is a fragile way to satisfy acceptance criterion 14. `tests/Unit/Shared/BoundedContextLayoutTest.php` is pure PHP, needs no product code, and encodes criteria 13 and 17 as assertions — it also partially covers the green-by-vacancy gap D4 knowingly accepts. It is not the deliberate-violation deptrac self-test, which stays a follow-up.

## Files per bounded context

### Shared

| Action | Path | Purpose |
|--------|------|---------|
| create | `src/Shared/.gitkeep` | Materialise the `Shared` context directory so the PSR-4 map, the DI resource and the deptrac `Shared` layer all have a real target. No classes. |
| create | `tests/Unit/Shared/.gitkeep` | Unit-test home for the context (D3). Removed in practice by the file below landing in the same directory — keep the `.gitkeep` anyway so the directory survives if the test is ever moved. |
| create | `tests/Unit/Shared/BoundedContextLayoutTest.php` | The one unit test (P6): asserts the context tree exists and that `src/Engine` imports no framework. |

### Engine

| Action | Path | Purpose |
|--------|------|---------|
| create | `src/Engine/.gitkeep` | Materialise the most restricted context directory. **No PHP file lands here in this feature** — a placeholder class would trigger standing rule 1 for a class with no behaviour (D4). |
| create | `tests/Unit/Engine/.gitkeep` | The directory standing rule 1 names literally, ready for the first Engine test. |

### Content

| Action | Path | Purpose |
|--------|------|---------|
| create | `src/Content/Domain/.gitkeep` | Context sub-layer, no classes. |
| create | `src/Content/Application/.gitkeep` | Context sub-layer, no classes. |
| create | `src/Content/Infrastructure/.gitkeep` | Context sub-layer, no classes. |
| create | `tests/Unit/Content/.gitkeep` | Unit suite home (D3). |
| create | `tests/Integration/Content/.gitkeep` | Integration suite home (D3). |

### Play

| Action | Path | Purpose |
|--------|------|---------|
| create | `src/Play/Domain/.gitkeep` | Context sub-layer, no classes. |
| create | `src/Play/Application/.gitkeep` | Context sub-layer, no classes. |
| create | `src/Play/Infrastructure/.gitkeep` | Context sub-layer, no classes. |
| create | `tests/Unit/Play/.gitkeep` | Unit suite home (D3). |
| create | `tests/Integration/Play/.gitkeep` | Integration suite home (D3). |

### Api

| Action | Path | Purpose |
|--------|------|---------|
| create | `src/Api/Controller/.gitkeep` | Context sub-layer, no classes, no routes. |
| create | `src/Api/Dto/.gitkeep` | Context sub-layer; future home of validated input DTOs (standing rule 5). |
| create | `src/Api/Security/.gitkeep` | Context sub-layer, no security configuration in this feature. |
| create | `tests/Unit/Api/.gitkeep` | Unit suite home (D3). |
| create | `tests/Integration/Api/.gitkeep` | Integration suite home (D3). |

### Root and infrastructure (no bounded context)

`src/Kernel.php` sits at the `Storyteller\` root on purpose (R13) and belongs to **no** deptrac layer.

| Action | Path | Purpose |
|--------|------|---------|
| create | `.gitignore` | Step 1, before any install, so `vendor/` is never staged (R12/D5). |
| change | `composer.json` | Extend the existing manifest — never regenerate. |
| create | `composer.lock` | Committed; it is an application (R6/R7). |
| create | `src/Kernel.php` | `Storyteller\Kernel`, MicroKernelTrait. |
| create | `config/bundles.php` | FrameworkBundle, MonologBundle (step 4); DoctrineBundle, DoctrineMigrationsBundle (step 5). |
| create | `config/services.yaml` | Per-context service resources, Engine excluded (P4). |
| create | `config/routes.yaml` | Empty; the Api context ships no routes here. |
| create | `config/packages/framework.yaml` | Framework config + `when@test: test: true`. |
| create | `config/packages/monolog.yaml` | Dev → stderr, test → file. |
| create | `config/packages/doctrine.yaml` | R1 mitigation, exact shape below. |
| create | `config/packages/doctrine_migrations.yaml` | Registers `migrations/`; zero migration classes. |
| create | `public/index.php` | Runtime front controller. |
| create | `bin/console` | Runtime console entry point. |
| create | `.env`, `.env.example` | No real secret (criterion 18). |
| create | `migrations/.gitkeep` | Empty migrations directory (no migration is written — see Migrations). |
| create | `phpunit.xml` | Two suites: `unit`, `integration` (D3). |
| create | `tests/bootstrap.php` | Autoload + `Dotenv::bootEnv`. |
| create | `tests/Integration/KernelBootTest.php` | The smoke test (criterion 15). |
| create | `phpstan.neon` | Level 8, `phpVersion: 80500`, both extensions. |
| create | `.php-cs-fixer.dist.php` | `@PSR12` + `declare_strict_types` + `setRiskyAllowed(true)` (R5). |
| create | `deptrac.yaml` | The dependency rule, five context layers + `Framework` vendor layer (D4). |
| create | `Dockerfile` | Single `dev` target, php 8.5-fpm, with dev dependencies (R8/R9/R10). |
| create | `compose.yaml` | php, nginx, postgres 16; no `version:` key (R14). |
| create | `docker/php/php.ini` | CLI/FPM ini overrides. |
| create | `docker/nginx/default.conf` | Serves `public/index.php`; `qa` never depends on it (D9). |
| create | `Makefile` | `up`, `down`, `sh`, `test`, `qa` via `exec` (D8). |
| create | `README.md` | clone → `make up` → `make qa`. |
| change | `CLAUDE.md` | **Line 3 only** (D1). |

## Public interfaces

The feature introduces exactly one production class. There are no DTOs, no JSON AST nodes, no value objects and no cross-context interfaces.

```php
// src/Kernel.php
namespace Storyteller;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
```

```php
// tests/Integration/KernelBootTest.php
namespace Storyteller\Tests\Integration;

final class KernelBootTest extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
{
    public function testKernelBootsAndBuildsTheContainer(): void;
    public function testDoctrineIsWiredWithoutOpeningAConnection(): void;
}

// tests/Unit/Shared/BoundedContextLayoutTest.php
namespace Storyteller\Tests\Unit\Shared;

final class BoundedContextLayoutTest extends \PHPUnit\Framework\TestCase
{
    public function testEveryBoundedContextDirectoryExists(): void;
    public function testEngineSourcesImportNoFrameworkNamespace(): void;
}
```

JSON AST node shapes / DTO shapes:

```json
{}
```

None. The AST spec is explicitly out of scope for this phase.

## Tests to write first

Both test files land in step 6. There is no Engine implementation in this feature, so the TDD red-step rule has nothing to bite on — but the smoke test is still written before step 7–10 depend on it.

| Test file | Test name | Asserts |
|-----------|-----------|---------|
| `tests/Integration/KernelBootTest.php` | `testKernelBootsAndBuildsTheContainer` | `self::bootKernel()` succeeds; `self::$kernel->getEnvironment() === 'test'`; `self::$kernel->isDebug()` is `true`; `self::getContainer()` returns a `Psr\Container\ContainerInterface`; `self::getContainer()->has('doctrine')` is `true`. Proves the container compiles with zero entities (R1). |
| `tests/Integration/KernelBootTest.php` | `testDoctrineIsWiredWithoutOpeningAConnection` | `self::getContainer()->get('doctrine')` is a `Doctrine\Persistence\ManagerRegistry`; `$registry->getManager()` is a `Doctrine\ORM\EntityManagerInterface`. Instantiating the manager must not open a socket — the test is required to pass with the postgres service stopped (criterion 15, R2). Do **not** call `getConnection()->connect()`, `executeQuery()` or any schema API. |
| `tests/Unit/Shared/BoundedContextLayoutTest.php` | `testEveryBoundedContextDirectoryExists` | `is_dir()` is true for all 13 source/test leaves: `src/Shared`, `src/Engine`, `src/Content/{Domain,Application,Infrastructure}`, `src/Play/{Domain,Application,Infrastructure}`, `src/Api/{Controller,Dto,Security}`, `migrations`. Covers criterion 13. |
| `tests/Unit/Shared/BoundedContextLayoutTest.php` | `testEngineSourcesImportNoFrameworkNamespace` | Recursively globs `src/Engine/**/*.php`; for each file asserts the contents match neither `/^use\s+Symfony\\\\/m`, `/^use\s+Doctrine\\\\/m` nor `/^use\s+Psr\\\\/m`. Passes vacuously today (there are no files) and starts biting the moment the first Engine class lands — the same gap D4 accepts for deptrac, closed cheaply from the test side. |

Engine steps are TDD: the failing test is its own sub-step before the implementation. **This feature contains no Engine implementation step**, so no red sub-step applies.

## Migrations

- **Needed: no.**
- Up: n/a. Zero Doctrine entities exist, therefore zero schema. `migrations/` is created with a tracked `.gitkeep` and registered under `doctrine_migrations.migrations_paths`, but no migration class is written and `doctrine:migrations:migrate` is never run.
- Down / rollback: n/a — nothing to roll back. Dropping the dev database is `docker compose down -v`, which is destructive and therefore deliberately **not** what `make down` does (criterion 5).
- Backfill: n/a.
- Standing rule 6 is untouched by this feature. The first migration arrives with the first entity, in a later feature.

## Deptrac impact

- **New cross-context edges:** none in the "new behaviour" sense — this feature *creates* the whole graph. The edges encoded are exactly the five the analysis derived from `CLAUDE.md`:
  `Shared: []`, `Engine: [Shared]`, `Content: [Engine, Shared]`, `Play: [Engine, Content, Shared]`, `Api: [Play, Content, Shared]`.
  **`Api -> Engine` is deliberately absent** (analysis §Dependency-rule impact, point 1). Do not add it.
- **`deptrac.yaml` change required: yes** — the file is created in this feature (step 9). It does not exist yet.
- Per D4 the file additionally carries a `Framework` vendor layer built with the `composer` collector, granted to `Content`, `Play` and `Api` only. `Engine` and `Shared` are not granted it (P3) — that absence *is* the machine-checkable form of "Symfony, Doctrine and HTTP types must never appear inside `src/Engine`".
- **Green-by-vacancy is expected and accepted** (D4/R3): with only `.gitkeep` under `src/**`, deptrac analyses zero tokens and exits 0. `composer deptrac` passing proves the config parses; it does not yet prove enforcement. No placeholder class is added to manufacture a signal.

---

## Exact file contents

Everything below is prescriptive. Where a value is marked *verify*, the implementer runs the named command and reports a mismatch rather than improvising.

### `composer.json` (step 3 — extend, never regenerate)

Keep the existing `name`, `description`, `type`, `license`, `autoload`, `autoload-dev`, the four existing `scripts` and `scripts-descriptions` verbatim. Add:

```json
"require": {
    "php": ">=8.5",
    "doctrine/doctrine-bundle": "^2.17",
    "doctrine/doctrine-migrations-bundle": "^3.4",
    "doctrine/orm": "^3.5",
    "symfony/console": "7.4.*",
    "symfony/dotenv": "7.4.*",
    "symfony/framework-bundle": "7.4.*",
    "symfony/monolog-bundle": "^4.1",
    "symfony/runtime": "7.4.*",
    "symfony/serializer": "7.4.*",
    "symfony/uid": "7.4.*",
    "symfony/validator": "7.4.*",
    "symfony/yaml": "7.4.*"
},
"require-dev": {
    "deptrac/deptrac": "^4.7",
    "friendsofphp/php-cs-fixer": "^3.95",
    "phpstan/phpstan": "^2.3",
    "phpstan/phpstan-doctrine": "^2.0",
    "phpstan/phpstan-symfony": "^2.1",
    "phpunit/phpunit": "^12.5"
},
"autoload": {
    "psr-4": {
        "Storyteller\\Shared\\": "src/Shared/",
        "Storyteller\\Engine\\": "src/Engine/",
        "Storyteller\\Content\\": "src/Content/",
        "Storyteller\\Play\\": "src/Play/",
        "Storyteller\\Api\\": "src/Api/",
        "Storyteller\\Migrations\\": "migrations/"
    }
},
"config": {
    "sort-packages": true,
    "platform": {
        "php": "8.5.0"
    },
    "allow-plugins": {
        "symfony/runtime": true
    }
}
```

Notes that are **not** optional:

- `deptrac/deptrac` — **not** `qossmic/deptrac` and **not** `qossmic/deptrac-shim`. Those names are stale (shim last released 1.0.2).
- `config.platform.php = "8.5.0"` makes host (8.5.3) and container (8.5.12) resolve identically (R7).
- `allow-plugins` lists `symfony/runtime` because it ships a Composer plugin that generates `vendor/autoload_runtime.php`, which `public/index.php` and `bin/console` require. Without the allow-plugins entry, Composer silently skips the plugin and both entry points fatal.
- No `ext-*` entries (P2). No `symfony/flex`, no `symfony/test-pack`, no `symfony/orm-pack` (D7, P1).
- **If the resolver rejects any constraint above, stop and report it.** Do not widen a constraint, do not add `--ignore-platform-reqs`, do not fall back to `*` — standing rule 4 and standing rule 9.

#### Standing-rule-9 justification for every added package

| Package | Why it is here |
|---------|----------------|
| `symfony/framework-bundle` | The kernel, DI container and `KernelTestCase` the smoke test extends. |
| `symfony/console` | `bin/console` and every `php bin/console …` command named in `CLAUDE.md` (D7 — required but unlisted in the request). |
| `symfony/dotenv` | `.env` / `.env.example` are listed deliverables and are inert without it; `tests/bootstrap.php` calls `Dotenv::bootEnv()` (D7). |
| `symfony/runtime` | Supplies `vendor/autoload_runtime.php`, the entry-point contract used by `public/index.php` and `bin/console` (D7). |
| `symfony/serializer` | Named in the request; the Api context will serialise responses. |
| `symfony/validator` | Standing rule 5 — all external input is validated in `src/Api` with DTO constraints. |
| `symfony/uid` | Story / session / version identifiers (UUIDv7) without a DB round-trip. |
| `symfony/yaml` | Story content is authored as YAML and compiled to a JSONB snapshot. |
| `symfony/monolog-bundle` | Named in the request; gives the kernel a logger so boot does not warn. |
| `doctrine/orm`, `doctrine/doctrine-bundle` | PostgreSQL persistence for `story_version`, play sessions and the event log. Required directly rather than via `symfony/orm-pack` (P1/R6). |
| `doctrine/doctrine-migrations-bundle` | Standing rule 6 — all schema change goes through migrations. |
| `phpunit/phpunit` ^12.5 | `composer test`. `^13` deferred by D6/R11. |
| `phpstan/phpstan` ^2.3 | `composer phpstan` at level 8. |
| `phpstan/phpstan-symfony` ^2.1 | Container- and service-aware static analysis for `src/Api`. |
| `phpstan/phpstan-doctrine` ^2.0 | Entity/repository-aware static analysis for `src/Content` and `src/Play`. |
| `friendsofphp/php-cs-fixer` ^3.95 | `composer cs-fix`; 3.95 declares `PHP_VERSION_SYNTAX_SUPPORTED = '8.5'`, so no escape hatch is needed. |
| `deptrac/deptrac` ^4.7 | `composer deptrac` — the dependency rule check `CLAUDE.md` mandates. |

#### The `qa` script (step 10 — acceptance criteria 4 and 11)

Added to `scripts`:

```json
"cs-check": "vendor/bin/php-cs-fixer fix --dry-run --diff",
"qa": [
    "@cs-check",
    "@phpstan",
    "@deptrac",
    "@test"
]
```

and to `scripts-descriptions`:

```json
"cs-check": "Verify PSR-12 formatting without writing files",
"qa": "Run all four quality gates in order: cs-check, phpstan, deptrac, test"
```

Why this exact shape:

- A Composer script defined as a **JSON array** runs its entries in order and **aborts on the first non-zero exit code**, propagating that code as `composer qa`'s own exit code. No `&&` chain, no shell, no subshell that could swallow a failure. There is no `|| true` anywhere, and `qa` must never be rewritten to use one.
- The order is cs-check → phpstan → deptrac → test: cheapest and most deterministic first, the slowest (PHPUnit, which boots a kernel) last.
- `qa` calls `@cs-check` (`--dry-run --diff`), **never** `@cs-fix`. A verification run must not mutate the working tree (criterion 11). `composer cs-fix` stays available as the separate, intentional write command.
- `composer cs-fix -- --dry-run --diff` must also work (criterion 8) — the existing `cs-fix` script already forwards extra arguments.

### `deptrac.yaml` (step 9 — full intended content)

```yaml
deptrac:
  # Only src/ is analysed. tests/ legitimately depends on the framework and on every
  # context; giving it a layer is out of scope for this feature.
  paths:
    - ./src

  # analyser.types is deliberately NOT set: deptrac's default emitter set already covers
  # use / class / new / attribute / parameter / return, which is what the rule needs.

  layers:
    - name: Shared
      collectors:
        - type: directory
          value: src/Shared/.*

    - name: Engine
      collectors:
        - type: directory
          value: src/Engine/.*

    - name: Content
      collectors:
        - type: directory
          value: src/Content/.*

    - name: Play
      collectors:
        - type: directory
          value: src/Play/.*

    - name: Api
      collectors:
        - type: directory
          value: src/Api/.*

    # D4: vendor layer. Without it, `use Doctrine\ORM\EntityManagerInterface;` inside
    # src/Engine belongs to no layer and deptrac passes silently.
    - name: Framework
      collectors:
        - type: composer
          composerPath: ./composer.json
          composerLockPath: ./composer.lock
          packages:
            - symfony/framework-bundle
            - symfony/console
            - symfony/dotenv
            - symfony/runtime
            - symfony/serializer
            - symfony/validator
            - symfony/uid
            - symfony/yaml
            - symfony/monolog-bundle
            - symfony/http-foundation
            - symfony/http-kernel
            - symfony/dependency-injection
            - symfony/config
            - symfony/event-dispatcher
            - symfony/filesystem
            - symfony/finder
            - doctrine/orm
            - doctrine/dbal
            - doctrine/persistence
            - doctrine/collections
            - doctrine/doctrine-bundle
            - doctrine/doctrine-migrations-bundle
            - doctrine/migrations
            - monolog/monolog
            - psr/log
            - psr/container

  ruleset:
    # Exactly the five context edges from CLAUDE.md. Note the deliberate absence of
    # Api -> Engine: Api reaches the Engine through Play.
    Shared: []
    Engine:
      - Shared
    Content:
      - Engine
      - Shared
      - Framework
    Play:
      - Engine
      - Content
      - Shared
      - Framework
    Api:
      - Play
      - Content
      - Shared
      - Framework
    Framework: []
```

Reading notes for the implementer and the reviewer:

- `src/Kernel.php` matches none of the `directory` collectors, so it is in no layer and is ignored — intentional (R13). Never add a `className`-regex collector for `Storyteller\Api` that could swallow it.
- Acceptance criterion 10 ("exactly the layers … with no extra edge") is about **context-to-context** edges. The `Framework` layer and its appearance on three ruleset lines are mandated by D4 and are not an extra context edge. The five context lines are byte-for-byte what the criterion names.
- `Engine` and `Shared` have no `Framework` entry. That is the enforcement (P3). Adding `Framework` to `Engine` to make a future check pass is a standing-rule-4 violation.
- Every package name listed must exist in `composer.lock`. If one does not, **delete that line** — do not add packages that are not locked, and do not replace the list with a wildcard.
- If deptrac rejects a key in the `composer` collector block, read the exact spelling from deptrac's own config at `vendor/deptrac/deptrac/deptrac.yaml` and report the correction; do not drop the vendor layer.

### `config/packages/doctrine.yaml` (step 5 — R1 mitigation, primary shape)

This is the shape that must boot with **zero entities and no reachable database**:

```yaml
doctrine:
    dbal:
        # serverVersion is set so DBAL never opens a connection just to detect the
        # platform. The connection stays lazy: nothing connects at compile or boot time.
        url: '%env(resolve:DATABASE_URL)%'
        server_version: '16'
        charset: utf8

    orm:
        auto_generate_proxy_classes: true
        # The entire R1 mitigation: auto_mapping would point a mapping driver at a
        # non-existent src/Entity and throw during container compilation.
        auto_mapping: false
        mappings: {}
        controller_resolver:
            auto_mapping: false

when@test:
    doctrine:
        dbal:
            # No dbname suffix, no DAMA bundle: the test suite never touches the database.
            url: '%env(resolve:DATABASE_URL)%'
```

- Do **not** add `enable_lazy_ghost_objects`, `report_fields_where_declared` or `identity_generation_preferences` — they are version-sensitive and unnecessary here.
- Do **not** add an `orm.entity_managers` block. One implicit default manager is enough.

**Documented fallback, and its exact trigger.** If and only if `php bin/console about` fails during container compilation with a DoctrineBundle error naming a missing mapping (for example `You must configure at least one entity manager` or `Mapping configurations should contain at least one…`), replace `mappings: {}` with exactly:

```yaml
        mappings:
            Content:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Content/Domain'
                prefix: 'Storyteller\Content\Domain'
                alias: Content
```

That directory exists (it carries a `.gitkeep` from step 2) and is in the PSR-4 map, so the attribute driver scans an empty but valid directory. Record which branch was taken in the step-5 commit message. These are the only two permitted shapes; anything else is a plan deviation and must be reported.

### `config/packages/doctrine_migrations.yaml` (step 5)

```yaml
doctrine_migrations:
    migrations_paths:
        'Storyteller\Migrations': '%kernel.project_dir%/migrations'
    enable_profiler: false
```

Zero migration classes are written. `bin/console doctrine:migrations:status` must exit 0 and report this directory with no migrations available (criterion 16) — it reads configuration only and must not require a live database.

### `config/packages/framework.yaml` (step 4)

```yaml
framework:
    secret: '%env(APP_SECRET)%'
    http_method_override: false
    handle_all_throwables: true
    php_errors:
        log: true
    validation:
        enabled: true
        email_validation_mode: html5
    serializer:
        enabled: true
    uid:
        default_uuid_version: 7
        time_based_uuid_version: 7

when@test:
    framework:
        # Required for KernelTestCase::getContainer() to return the test container.
        test: true
```

No `session`, no `cache` pools, no `messenger`, no `mailer` — all out of scope.

### `config/packages/monolog.yaml` (step 4)

```yaml
when@dev:
    monolog:
        handlers:
            main:
                type: stream
                path: 'php://stderr'
                level: debug
                channels: ['!event']

when@test:
    monolog:
        handlers:
            main:
                type: stream
                path: '%kernel.logs_dir%/%kernel.environment%.log'
                level: error
```

Writing test logs to `var/` (git-ignored) rather than stdout keeps PHPUnit's output strictness quiet.

### `config/services.yaml` (step 4 — P4)

```yaml
parameters:

services:
    _defaults:
        autowire: true
        autoconfigure: true

    # Engine is deliberately NOT registered: its objects are framework-free and are
    # constructed explicitly by the contexts that use them (plan decision P4).
    Storyteller\Shared\:
        resource: '../src/Shared/'

    Storyteller\Content\:
        resource: '../src/Content/'

    Storyteller\Play\:
        resource: '../src/Play/'

    Storyteller\Api\:
        resource: '../src/Api/'
```

### `config/routes.yaml` (step 4)

```yaml
# No routes in this feature. The Api context ships none yet.
```

### `config/bundles.php` (steps 4 and 5)

Step 4 creates it with the first two entries; step 5 appends the two Doctrine entries.

```php
<?php

declare(strict_types=1);

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
];
```

### `src/Kernel.php`, `public/index.php`, `bin/console` (step 4)

```php
<?php
// src/Kernel.php

declare(strict_types=1);

namespace Storyteller;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
```

```php
<?php
// public/index.php

declare(strict_types=1);

use Storyteller\Kernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static function (array $context): Kernel {
    return new Kernel((string) $context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
```

```php
#!/usr/bin/env php
<?php
// bin/console

declare(strict_types=1);

use Storyteller\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static function (array $context): Application {
    return new Application(new Kernel((string) $context['APP_ENV'], (bool) $context['APP_DEBUG']));
};
```

The explicit `(string)` / `(bool)` casts are not decoration: `array $context` has `mixed` values, and passing them unchanged to `Kernel::__construct(string, bool)` is a PHPStan level 8 error. `bin/console` must be `chmod +x`. It has no `.php` extension, so neither PHPStan nor the cs-fixer finder reaches it by default — the fixer config below appends it explicitly.

### `.env` and `.env.example` (step 4 — criterion 18)

Identical content; `.env` is the file Dotenv reads, `.env.example` is the documented copy. Neither contains a real secret.

```dotenv
# Committed defaults. Never put a real secret here — override in .env.local (git-ignored).
APP_ENV=dev
APP_SECRET=change_me_in_env_local
DATABASE_URL="postgresql://storyteller:storyteller@postgres:5432/storyteller?serverVersion=16&charset=utf8"
```

The `postgres` host resolves inside Compose only; nothing in `make qa` connects, so host-side commands are unaffected (R2). Avoid `$` characters in any value — Compose interpolates `env_file` values.

### `.gitignore` (step 1 — criterion 18)

```gitignore
/vendor/
/var/
/.env.local
/.env.local.php
/.env.*.local
/.phpunit.cache/
/.phpunit.result.cache
/.php-cs-fixer.cache
/public/bundles/
.idea/
.DS_Store
```

### `phpunit.xml` (step 6 — D3, criteria 6 and 14)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         cacheDirectory=".phpunit.cache"
         colors="true"
         failOnRisky="true"
         failOnWarning="true"
         failOnSkipped="true"
         beStrictAboutOutputDuringTests="true"
         requireCoverageMetadata="false">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>

    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>

    <php>
        <ini name="display_errors" value="1"/>
        <ini name="error_reporting" value="-1"/>
        <server name="APP_ENV" value="test" force="true"/>
        <server name="APP_DEBUG" value="1" force="true"/>
        <server name="SHELL_VERBOSITY" value="-1"/>
        <server name="KERNEL_CLASS" value="Storyteller\Kernel"/>
    </php>
</phpunit>
```

`KERNEL_CLASS` is mandatory: `KernelTestCase` otherwise guesses `App\Kernel` and fails. `failOnRisky` / `failOnWarning` / `failOnSkipped` are what make criterion 6 ("zero skipped or risky") machine-checked rather than eyeballed. If PHPUnit rejects an attribute against its own XSD, remove that attribute and report it — do not drop the schema reference.

### `tests/bootstrap.php` (step 6)

```php
<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
```

### `phpstan.neon` (step 7 — criterion 7)

```neon
includes:
    - vendor/phpstan/phpstan-symfony/extension.neon
    - vendor/phpstan/phpstan-symfony/rules.neon
    - vendor/phpstan/phpstan-doctrine/extension.neon
    - vendor/phpstan/phpstan-doctrine/rules.neon

parameters:
    level: 8
    phpVersion: 80500
    paths:
        - src
        - tests
        - config
        - public
    tmpDir: var/phpstan
    # P5 / R4: containerXmlPath is intentionally unset so `composer phpstan` does not
    # depend on a warmed dev cache. Enable it with the first real services:
    # symfony:
    #     containerXmlPath: var/cache/dev/Storyteller_KernelDevDebugContainer.xml
```

No `phpstan-baseline.neon` is created, and none may be created in this feature (criterion 7). `bin` is not in `paths` because `bin/console` has no `.php` extension and PHPStan would skip it silently anyway.

### `.php-cs-fixer.dist.php` (step 8 — R5, criteria 8 and 9)

```php
<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/config',
        __DIR__ . '/public',
    ])
    ->append([
        __FILE__,
        __DIR__ . '/bin/console',
    ]);

return (new PhpCsFixer\Config())
    // declare_strict_types is a RISKY rule: without this line the config does not
    // resolve at all. Dropping the rule (or this line) to make a check pass is a
    // standing-rule-4 violation — never do it.
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
```

Explicitly, per standing rule 4: if `composer cs-check` fails, the fix is to reformat the offending file, never to delete a rule, narrow the finder, add an `exclude`, or turn `setRiskyAllowed` off. `declare_strict_types` only adds the declaration to files the finder sees — which is why `bin/console` and this config file are appended by hand. The cache file stays at the default project-root location, matching `/.php-cs-fixer.cache` in `.gitignore`.

### `Dockerfile` (step 11 — R7, R8, R9, R10)

```dockerfile
# syntax=docker/dockerfile:1

# Single dev-targeted stage. R10: dev dependencies MUST be present, otherwise
# vendor/bin/{phpunit,phpstan,php-cs-fixer,deptrac} are missing and `make qa` cannot pass.
# A future production stage must not inherit this behaviour silently.
FROM php:8.5.12-fpm AS dev

ARG HOST_UID=1000
ARG HOST_GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libicu-dev \
        libzip-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip opcache \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-storyteller.ini

# R9: a non-root user whose UID/GID match the host, so vendor/ and var/ written inside
# the container stay writable from macOS without sudo. GID 20 (staff) already exists in
# Debian, hence the tolerant groupadd.
RUN (groupadd -g "${HOST_GID}" app || true) \
    && useradd -u "${HOST_UID}" -g "${HOST_GID}" -m -s /bin/bash app

WORKDIR /app
USER app
```

The image tag is pinned to a patch version, not `8.5-fpm` (R7). No `composer install` runs in the image: the bind mount supplies `vendor/` from step 3, which keeps the image cheap and the lock authoritative.

### `compose.yaml` (step 11 — R2, R14, D9)

```yaml
name: storyteller

services:
  php:
    build:
      context: .
      dockerfile: Dockerfile
      target: dev
      args:
        HOST_UID: "${HOST_UID:-1000}"
        HOST_GID: "${HOST_GID:-1000}"
    user: "${HOST_UID:-1000}:${HOST_GID:-1000}"
    working_dir: /app
    volumes:
      - ./:/app
    env_file:
      - .env
    depends_on:
      postgres:
        condition: service_healthy

  nginx:
    image: nginx:1.27-alpine
    ports:
      - "8080:80"
    volumes:
      - ./:/app:ro
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - php

  postgres:
    image: postgres:16
    environment:
      POSTGRES_DB: storyteller
      POSTGRES_USER: storyteller
      POSTGRES_PASSWORD: storyteller
    ports:
      - "5432:5432"
    volumes:
      - pgdata:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U storyteller -d storyteller"]
      interval: 5s
      timeout: 3s
      retries: 10

volumes:
  pgdata:
```

No top-level `version:` key (R14). The build args are named `HOST_UID`/`HOST_GID`, **not** `UID`/`GID`: `UID` is read-only in bash, so a `UID=$(id -u) docker compose …` prefix in a Makefile recipe would abort. D9: nginx exists so a browser can reach `public/index.php` later, and nothing in `make qa` touches it.

### `docker/php/php.ini` and `docker/nginx/default.conf` (step 11)

```ini
; docker/php/php.ini
memory_limit = 512M
date.timezone = UTC
display_errors = Off
error_reporting = E_ALL
opcache.enable = 1
opcache.enable_cli = 0
opcache.validate_timestamps = 1
```

```nginx
# docker/nginx/default.conf
server {
    listen 80;
    server_name _;
    root /app/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass php:9000;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }

    client_max_body_size 8m;
}
```

### `Makefile` (step 12 — D8, criterion 5)

```makefile
DC   := docker compose
EXEC := $(DC) exec -T php

.PHONY: build up down sh test qa guard-running

build:
	HOST_UID=$$(id -u) HOST_GID=$$(id -g) $(DC) build

up:
	HOST_UID=$$(id -u) HOST_GID=$$(id -g) $(DC) up -d --build

# No -v: the pgdata volume survives `make down` (acceptance criterion 5).
down:
	$(DC) down

sh: guard-running
	$(DC) exec php bash

test: guard-running
	$(EXEC) composer test

qa: guard-running
	$(EXEC) composer qa

guard-running:
	@$(DC) ps --status running --services | grep -qx php \
		|| { echo "php container is not running - run 'make up' first"; exit 1; }
```

`exec -T` disables TTY allocation so the exit code propagates cleanly (and a CI job could call these targets unchanged). `guard-running` is the "clear error if the container is not running" D8 asks for. `make qa` is a thin wrapper around `composer qa`, so the four gates are identical on host, in container and in a future CI job.

### `CLAUDE.md` (step 14 — D1, the one authorized edit)

Line 3 only. Before:

```
Interactive choice-driven story platform. PHP 8.3 · Symfony 7 · PostgreSQL · REST API ·
```

After:

```
Interactive choice-driven story platform. PHP 8.5 · Symfony 7.4 LTS · PostgreSQL · REST API ·
```

Line 4 (`Vue 3 (CDN) frontend for the MVP.`) is untouched. No other line, no standing rule, no table row changes. `.claude/skills/sdlc/SKILL.md` line 102 carries the same stale string and is **not** touched (D2) — it is carried as a follow-up for the user to approve separately.

---

## Ordered steps

Each step is independently committable. Note the skeleton caveat: a quality gate cannot be green before its configuration exists, so each step below names the verification command that applies **at that point**. From step 10 onward, `composer qa` must be green after every subsequent step.

1. **Initialise the repository** — files: `.gitignore` — tests: none — verify: `git rev-parse --is-inside-work-tree` prints `true` and `git status --porcelain` is empty after the commit — commit: `chore: initialize git repository with .gitignore`
   — routing: `sonnet` because single-file, no context boundary, exact content supplied (D5/R12).
2. **Create the bounded-context and test directory tree** — files: the 18 `.gitkeep` files listed in the per-context tables plus `migrations/.gitkeep` — tests: none — verify: `for d in src/Shared src/Engine src/Content/Domain src/Content/Application src/Content/Infrastructure src/Play/Domain src/Play/Application src/Play/Infrastructure src/Api/Controller src/Api/Dto src/Api/Security migrations tests/Unit/Engine tests/Unit/Content tests/Unit/Play tests/Unit/Api tests/Unit/Shared tests/Integration/Content tests/Integration/Play tests/Integration/Api; do test -d "$d" || exit 1; done` — commit: `chore: create bounded-context and test directory tree`
   — routing: `opus` because it creates `src/Engine/**` (literal trigger) and lays down the context boundaries every later config depends on; a wrong path here silently breaks deptrac, PSR-4 and D3 at once.
3. **Extend `composer.json` and install** — files: `composer.json`, `composer.lock` — tests: none — verify: `composer validate --strict` exits 0 **and** `composer show symfony/framework-bundle | grep -q 'versions : \* v7.4'` **and** `test -f vendor/autoload_runtime.php` — commit: `build: add Symfony 7.4, Doctrine and the four quality tools`
   — routing: `sonnet` because every constraint, the platform pin and the allow-plugins entry are written out above; the stop-rule is absolute — if resolution fails, report, never widen a constraint.
4. **Boot a minimal Symfony kernel** — files: `src/Kernel.php`, `config/bundles.php` (framework + monolog), `config/services.yaml`, `config/routes.yaml`, `config/packages/framework.yaml`, `config/packages/monolog.yaml`, `public/index.php`, `bin/console` (+`chmod +x`), `.env`, `.env.example` — tests: none yet — verify: `php bin/console about` exits 0 and reports Symfony 7.4.x — commit: `feat: boot a minimal Symfony 7.4 kernel`
   — routing: `sonnet` because all file bodies are supplied verbatim and no bounded context gains code.
5. **Wire Doctrine with zero entities** — files: `config/packages/doctrine.yaml`, `config/packages/doctrine_migrations.yaml`, `config/bundles.php` (append the two Doctrine bundles) — tests: none yet — verify: `php bin/console about` exits 0 **with the postgres service not running**, and `php bin/console doctrine:migrations:status` exits 0 reporting `/app/migrations` (or the host path) with zero available migrations — commit: `build: configure Doctrine and migrations without entities` (append `(fallback mapping used)` if the documented R1 fallback branch was taken)
   — routing: `opus` because this is R1 (high/high): the container-compile behaviour of DoctrineBundle with no entities decides whether the smoke test — the whole acceptance of the phase — can exist, and it carries a conditional two-branch config.
6. **Add the PHPUnit suites and the first tests** — files: `phpunit.xml`, `tests/bootstrap.php`, `tests/Integration/KernelBootTest.php`, `tests/Unit/Shared/BoundedContextLayoutTest.php` — tests: the four cases in the table above — verify: `composer test` exits 0 with 0 failures/errors/skipped/risky, `vendor/bin/phpunit --testsuite=unit` exits 0, `vendor/bin/phpunit --testsuite=integration` exits 0, and the integration suite still exits 0 with postgres stopped — commit: `test: add PHPUnit suites and the kernel boot smoke test`
   — routing: `opus` because criterion 15 (boot with no reachable database) is the phase's acceptance hinge and interacts with step 5, and `BoundedContextLayoutTest` encodes the cross-context architecture invariant.
7. **Add PHPStan level 8** — files: `phpstan.neon` — tests: none — verify: `composer phpstan` exits 0 and `test ! -f phpstan-baseline.neon` — commit: `build: add PHPStan level 8 configuration`
   — routing: `sonnet` because the config is supplied verbatim and the only judgement call (P5, no `containerXmlPath`) is already made.
8. **Add php-cs-fixer and format the tree** — files: `.php-cs-fixer.dist.php`, plus whatever reformatting `composer cs-fix` produces across `src/`, `tests/`, `config/`, `public/`, `bin/console` — tests: none — verify: `composer cs-fix -- --dry-run --diff` exits 0, and temporarily deleting `declare(strict_types=1);` from one file makes it exit non-zero (criterion 9; restore the file afterwards) — commit: `build: enforce PSR-12 and strict types with php-cs-fixer`
   — routing: `sonnet` because the config is supplied verbatim, including `setRiskyAllowed(true)` (R5) and the explicit prohibition on weakening it.
9. **Encode the dependency rule in deptrac** — files: `deptrac.yaml` — tests: none — verify: `composer deptrac` exits 0, and `grep -c 'Framework' deptrac.yaml` confirms the vendor layer is present — commit: `build: encode the bounded-context dependency rule in deptrac`
   — routing: `opus` because this file *is* the cross-context dependency contract (five context layers, the deliberate absence of `Api -> Engine`, the D4 vendor layer, P3's exclusion of `Shared`); it is the single highest-leverage file in the feature.
10. **Add the aggregate `qa` script** — files: `composer.json` (`cs-check`, `qa`, two `scripts-descriptions`) — tests: none — verify: `composer qa` exits 0 and its output shows the four checks in order; then temporarily break one file's formatting and confirm `composer qa` exits non-zero *and stops* before PHPUnit (criterion 4); confirm `git status --porcelain` is empty after a `composer qa` run (criterion 11) — commit: `build: add aggregate qa composer script`
    — routing: `sonnet` because the script array and its ordering are fully specified.
11. **Add the Docker dev environment** — files: `Dockerfile`, `compose.yaml`, `docker/php/php.ini`, `docker/nginx/default.conf` — tests: none — verify: `HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose build` exits 0; `docker compose up -d` brings php, nginx and postgres up with postgres healthy; `docker compose exec -T php php -v` reports 8.5.x; `docker compose exec -T php php -m | grep -q pdo_pgsql`; `test "$(docker compose exec -T php id -u)" != "0"`; a file created by the container in the bind mount is writable from the host without sudo — commit: `build: add Docker dev environment (php-fpm 8.5, nginx, postgres 16)`
    — routing: `sonnet` because R8/R9/R10 are already resolved into exact Dockerfile and Compose content; nothing architectural is decided here.
12. **Add the Makefile targets** — files: `Makefile` — tests: none — verify: `make up` then `make qa` exits 0 inside the container; `make test` exits 0; `make sh` opens a shell; `make down` exits 0 and `docker volume ls | grep -q storyteller_pgdata` still finds the volume; with containers down, `make qa` prints the guard error and exits non-zero — commit: `build: add Makefile dev targets`
    — routing: `sonnet` because the recipes are supplied verbatim, including the `HOST_UID` naming workaround.
13. **Document setup** — files: `README.md` — tests: none — verify: `make qa` still exits 0, and the README contains a runnable clone → `make up` → `make qa` sequence plus the `HOST_UID`/`HOST_GID` override note (R9) and the four `composer` gate commands — commit: `docs: document setup and the quality gates`
    — routing: `sonnet` because it is prose over already-decided commands.
14. **Correct the platform line in `CLAUDE.md`** — files: `CLAUDE.md` (line 3 only) — tests: none — verify: `sed -n '3p' CLAUDE.md` equals the exact target string above, and `git show --stat HEAD` reports 1 file changed, 1 insertion, 1 deletion — commit: `docs: correct platform line to PHP 8.5 / Symfony 7.4 LTS`
    — routing: `sonnet` because the before/after text is given literally and the diff size is asserted.

### Steps that touch a context boundary or `deptrac.yaml`

Steps **2**, **6** and **9**. Step 2 creates the layer directories (including `src/Engine/**`), step 6 asserts the layout and the Engine-purity invariant in a test, step 9 writes the dependency contract itself. Step 5 is not a boundary step but is the highest-risk step in the feature (R1/R2). These four are the strongest-tier steps.

## Routing hints for the orchestrator

The orchestrator picks the implementer's model per step. Flag here anything that forces
the `opus` capability floor, so it does not have to infer it:

| Trigger | Steps affected |
|---------|----------------|
| touches `src/Engine/**` | **2** (creates `src/Engine/.gitkeep`), **6** (test scans `src/Engine` — read-only) |
| Doctrine migration | none — this feature writes no migration |
| public interface crossing two contexts | **9** (`deptrac.yaml` is the cross-context contract itself); no PHP interface crosses any boundary |
| auth / input validation / security surface | none — `src/Api/Security/` is an empty directory; `symfony/security-bundle` is out of scope |
| open question from `01-analysis.md` still unanswered | none — D1–D9 are all resolved |
| plan-specified high risk (R1 container compile with zero entities) | **5** |

Steps with no trigger above and a single bounded context may run at `sonnet`.

**Summary:** run steps **2, 5, 6, 9** at `opus`; run steps **1, 3, 4, 7, 8, 10, 11, 12, 13, 14** at `sonnet`. No step may run at `haiku` or `fable`. Every `sonnet` step has its file contents written out verbatim in this document, which is what makes the lower tier safe; if an implementer finds itself needing to invent a config key, a version constraint or a layer definition, that is a plan defect — stop and report rather than improvise.

## Rollback

Nothing reaches production in this feature: there is no deployment target, no database schema, no migration, no data and no API surface. Rollback is purely local.

- **Per step:** `git revert <sha>` — every step is one commit with one logical artifact, and no step depends on uncommitted state from another.
- **Whole feature:** `git reset --hard <sha-before-step-1>` on a branch, or simply delete the branch. Step 1 (`git init` + `.gitignore`) is what makes this possible — before it there is no rollback at all (R12).
- **Environment:** `docker compose down -v` removes the containers and the `pgdata` volume; `rm -rf vendor var .phpunit.cache .php-cs-fixer.cache` returns the working tree to its pre-install state. `composer install` from the committed `composer.lock` reproduces the exact dependency set (R7).
- **Dependency rollback:** if a resolved version proves wrong (R6 — a Doctrine major drift), revert the step-3 commit; `composer.lock` is committed precisely so this is a one-command, exact restoration.
- **`CLAUDE.md`:** step 14 is isolated and one line, so reverting it cannot disturb any standing rule.
