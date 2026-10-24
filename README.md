# Storyteller

Interactive choice-driven story platform. PHP 8.5 · Symfony 7.4 LTS · PostgreSQL 16 ·
REST API · Vue 3 (CDN) frontend for the MVP.

See `CLAUDE.md` for the architecture, the bounded-context dependency rule and the
project's standing rules.

## Prerequisites

- Docker (tested with OrbStack and Docker Desktop) with the Compose plugin.
- `make`.

Nothing else needs to be installed on the host: PHP, Composer and all dependencies live
inside the `php` container.

## Setup

```sh
git clone <repo-url> storyteller
cd storyteller
make up
make qa
```

`make up` builds the `php` image (PHP 8.5-fpm with `intl`, `pdo_pgsql`, `pgsql`, `zip`
and OPcache), starts `php`, `nginx` and `postgres`, and waits for `postgres` to report
healthy. `make qa` then runs the full quality-gate suite inside the container.

### Available targets

| Target       | What it does |
|--------------|--------------|
| `make build` | Build the `php` image only. |
| `make up`    | Build (if needed) and start `php`, `nginx`, `postgres` in the background. |
| `make sh`    | Open an interactive shell in the `php` container. |
| `make test`  | Run `composer test` (PHPUnit) inside the container. |
| `make qa`    | Run `composer qa` (all four quality gates) inside the container. |
| `make down`  | Stop the containers. Does **not** pass `-v`, so the `pgdata` volume — and the dev database it holds — survives. |

`sh`, `test` and `qa` depend on a `guard-running` check: if the `php` container is not
running, they print `php container is not running - run 'make up' first` and exit
non-zero instead of failing with a confusing Docker error.

### The `HOST_UID` / `HOST_GID` build arguments

The `php` container runs as a non-root user whose UID/GID are passed in at build and run
time as `HOST_UID` / `HOST_GID`, so files the container writes into the bind-mounted
project directory (`vendor/`, `var/`, …) stay owned by *your* host user and remain
writable without `sudo` — this matters in particular on macOS, where the Docker Desktop /
OrbStack default UID (`1000`) does not usually match the host account.

**The `make build` and `make up` recipes already set these for you.** If you ever invoke
Compose directly instead of through `make`, you must supply the same values yourself:

```sh
HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose build
HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose up -d
```

A bare `docker compose build` / `up` falls back to the compose file's `:-1000` defaults.
On macOS that will not match your account, and any file the container then creates in
the bind mount ends up owned by a UID that is not yours — not world-writable, just
awkward to clean up without `sudo`. Prefer the `make` targets; if you do call `docker
compose` directly, always use the `HOST_UID=... HOST_GID=...` prefix above.

## Services and ports

| Service    | Image               | Published on         | Notes |
|------------|---------------------|-----------------------|-------|
| `php`      | built from `Dockerfile`, target `dev` | — (not published; reached via `nginx` or `make sh`/`make test`/`make qa`) | PHP 8.5.12-fpm. |
| `nginx`    | `nginx:1.27-alpine`  | `8080:80`             | Serves `public/index.php`. |
| `postgres` | `postgres:16`        | `127.0.0.1:5432:5432` | **Loopback-only.** |

Dev database credentials are `storyteller` / `storyteller` / `storyteller` (database,
user and password are all `storyteller`), set in `compose.yaml` and matched by
`DATABASE_URL` in `.env` / `.env.example`. These credentials are **dev-only** and are
deliberately safe only because the port is bound to `127.0.0.1`: the database is not
reachable from any other host on the network, including other machines on the same
Wi-Fi. Do not reuse them anywhere outside this local setup, and do not change the
`postgres` port mapping to a bare `"5432:5432"` without re-adding that restriction.

## Quality gates

Four independent checks, each runnable on its own:

```sh
composer test       # PHPUnit — unit and integration suites
composer phpstan    # PHPStan level 8, no baseline
composer cs-fix     # php-cs-fixer — reformats the tree (PSR-12 + strict types)
composer cs-check   # php-cs-fixer --dry-run --diff — verifies formatting without writing
composer deptrac    # Checks the bounded-context dependency rule
```

And one aggregate that runs all four, in the cheapest-first order, **failing fast** on
the first red gate (it never runs the next check once one fails, and never runs the
slower PHPUnit suite unless everything before it was clean):

```sh
composer qa   # cs-check -> phpstan -> deptrac -> test
```

Run any of these on the host if you have a matching local PHP/Composer toolchain, or
inside the container via `make qa` / `make test` / `make sh`. `make qa` is a thin
wrapper around `composer qa`, so the same four gates run identically on the host, inside
the container and in CI.

### The bounded-context dependency rule

The dependency rule described in `CLAUDE.md` (`Engine -> (nothing)`, `Content ->
Engine`, `Play -> Engine, Content`, `Api -> Play, Content`, everything may depend on
`Shared`) is not just documentation: it is machine-enforced by `deptrac.yaml` and checked
by `composer deptrac`. A `Framework` vendor layer (Symfony, Doctrine, Monolog, PSR
packages) is defined and granted to `Content`, `Play` and `Api`, but deliberately
withheld from `Engine` and `Shared` — that absence is what makes "Symfony, Doctrine and
HTTP types must never appear inside `src/Engine`" a check that actually fails the build,
rather than a rule that relies on code review.

## Generated files

A few files under version control are regenerated by tooling and are gitignored once
created or modified — you don't need to do anything about them, but seeing them appear
(and disappear) is expected:

- `config/reference.php` — Symfony's config-builder reference dump, rewritten on every
  container compile.
- `.deptrac.cache` — deptrac's analysis cache, rewritten on every `composer deptrac` run.

Neither is required for the quality gates to pass, and neither should be committed.
