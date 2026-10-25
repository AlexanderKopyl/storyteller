# 00 — Summary: project-skeleton

- **Feature slug:** `project-skeleton`
- **Date:** 2026-10-25
- **Documents:** [01](01-analysis.md) · [02](02-plan.md) ·
  [03](03-implementation-notes.md) · [04](04-review.md) · [05](05-test-report.md)

A foundation, not a feature. No product logic was written by design: the deliverable is the
set of constraints that the first real code will land inside.

## What changed

| Context | Change |
|---------|--------|
| `Shared`  | Directory created with `.gitkeep`. No code. Newly protected: it is withheld from the `Framework` layer in deptrac, and the architecture test now scans it. |
| `Engine`  | Directory created with `.gitkeep`. No code. Framework-free by two independent mechanisms — deptrac's `Framework` layer is not granted to it, and a unit test scans its sources for framework imports. |
| `Content` | `Domain`, `Application`, `Infrastructure` created with `.gitkeep`. No code. |
| `Play`    | `Domain`, `Application`, `Infrastructure` created with `.gitkeep`. No code. |
| `Api`     | `Controller`, `Dto`, `Security` created with `.gitkeep`. No code. |

Outside the contexts: `src/Kernel.php` (deliberately in no deptrac layer, R13), `composer.json`
with the five quality scripts, `phpunit.xml` with unit + integration suites, `phpstan.neon`
(level 8, symfony + doctrine extensions, `phpVersion: 80500`), `.php-cs-fixer.dist.php`
(PSR-12 + enforced `declare(strict_types=1)`), `deptrac.yaml`, `compose.yaml` (php-fpm 8.5,
nginx, postgres 16), `Makefile`, `.env.example`, `.gitignore`, `README.md`, and the
`migrations/` directory with Doctrine configured for PostgreSQL and no entities.

Migration: **no** — Doctrine is configured and `doctrine:migrations:status` runs clean
(`Storyteller\Migrations | /app/migrations`), but no migration exists, because no entity does.

Deptrac configuration changed: **yes** — it is the central deliverable, and it was rewritten
once during the Gate C fix loop (see B1 below).

## Test results

All four verified **inside the container** (PHP 8.5.12) by the tester, each gate invoked
individually before `make qa` so a green aggregate could not hide a vacuous member, and then
re-verified independently by the orchestrator.

| Command | Result |
|---------|--------|
| `composer test` | exit **0** — `OK (24 tests, 2020 assertions)` |
| `composer phpstan` | exit **0** — `[OK] No errors`, level 8 |
| `composer deptrac` | exit **0** — Violations 0 / Uncovered 0 / Allowed 0 |
| `composer cs-fix` | exit **0** via `cs-check` — 0 of 10 files would change |
| `make qa` | exit **0** — the acceptance criterion |

Tests added: **24** (unit: 24, functional: 0) across four files — a kernel-boot smoke test,
`tests/Unit/Shared/BoundedContextLayoutTest.php`, and the two guard tests added when N1 and M3
were closed after Gate D: `DevPackageIsolationTest.php` and `UnlayeredSourceTest.php`.

**Proven non-vacuous.** The architecture test was demonstrated red-first by the tester and
again by the orchestrator: planting `use function Symfony\Component\String\u;` in `src/Engine`
and `Symfony\Component\Uid\Uuid` in `src/Shared` produces **2 failures, exit 1**; deleting them
returns exit 0 with a clean tree. The deptrac rule was likewise probed in the container —
Engine→Routing **1**, Engine→Doctrine **1**, Shared→Uid **1**, Api→Engine **1**, Play→Engine+Content
**0 with `Allowed 2`** (positive control), Engine→`Random\Randomizer`/`DateTimeImmutable`/`BcMath\Number` **0**.

**Gate vacancy, stated plainly.** On an empty tree `deptrac` reports `Allowed 0` — no layered
token exists yet — so it is vacuous in *coverage* while proven in *mechanism*. That remains true
after the N1/M3 closures: the two new layers are deny-only, so on an empty tree they can only ever
report zero. What changed is that `test` grew teeth — `DevPackageIsolationTest` asserts against
`composer.lock` and the generated autoload maps, so it has real input to fail on today, and did.
`phpstan` is
genuinely ceremonial: 7 files, one of them generated vendor output, and its extensions are inert
without `containerXmlPath`. `cs-check` and `test` are protective today. One of four is ceremonial.

## Review findings not fixed

| Severity | Finding | Why deferred |
|----------|---------|--------------|
| ~~major~~ **CLOSED** | **N1** — `require-dev` packages fell inside the `Framework` layer, so a production class could `use PHPUnit\Framework\Assert` with every gate green and fatal under `composer install --no-dev`. | **Closed after Gate D in `ff3afca`.** See "Post-Gate-D closures" below — the prescribed fix did not work and was replaced. |
| major | **M1** — `migrations/` sits outside phpstan, cs-fixer and deptrac. | No migration exists yet. Closing it means deciding how generated migration code is held to level 8, which is a decision for the first migration. |
| major | **M2** — PHPStan's input set depends on whether a kernel has booted, because it analyses the generated, gitignored `config/reference.php`. | Cosmetic while the tree is empty; interacts with C-2's untracking decision and deserves its own change. |
| ~~major~~ **CLOSED** | **M3** — a class in an unlayered namespace escaped the dependency rule entirely. `Storyteller\Support` importing Doctrine *and* Symfony was reported **nowhere** — not a violation, not `Uncovered`. | **Closed after Gate D in `4badf1c`** by a catch-all `Unlayered` layer. See below; the layer-free *zone* still exists, only the escape from the rule is gone. |
| major | **M4** — commit `9bcc496` changed `.claude/**` mid-feature and is recorded in no phase document. | Process finding, not a code defect. Recorded here, which is the remedy. |
| minor | **N2** — deptrac cannot see vendor *functions*; confirmed a tool limitation, not a config error. | **Mitigated** test-side in `b4ba08d`: the scan now covers `use function` / `use const` across Symfony, Doctrine, Psr, Monolog and PHPUnit roots. |
| minor | **N3** — the built-ins exemption is pinned to `jetbrains/phpstorm-stubs`; a built-in the map misses becomes a hard violation in Engine/Shared. | 33 built-ins probed against the locked v2026.2 map, all present, including `Random\Randomizer` and `BcMath\Number`. Recorded for diagnosis speed only. |
| minor | **N4, m1** — doc inaccuracies: a token in two layers is a *violation*, not a warning; the `analyser.types` comment is incomplete; batch 4's notes say `guard-running` exits 2 when it exits 1. | Documentation drift, no behavioural effect. |
| minor | **m2–m10** | Carried from `04-review.md` unchanged. |

## Post-Gate-D closures: N1 and M3

Both were closed after the pipeline finished, on `implementer` at `opus` (capability floor:
both edit `deptrac.yaml`). Commits `ff3afca`, `4badf1c`, `e83c056`.

**N1 — the fix the review prescribed does not work.** The review called for a `Dev` layer built
from a `composer` collector over the six `require-dev` packages. Deptrac 4.7.2's `composer`
collector resolves a package only through its `autoload.psr-0` / `psr-4` keys. Confirmed against
`composer.lock`: `phpunit/phpunit` declares `classmap` + `files`, `phpstan/phpstan` is `files`-only,
and every `sebastian/*` is `classmap`. That collector would have resolved **zero** namespaces for
exactly the packages N1 is about — the prescribed fix would have shipped green and caught nothing.
Implemented with `classLike` namespace-prefix collectors instead, over the **full transitive
dev-only closure** (all 51 `packages-dev` entries → 20 prefixes), not the six direct packages.
`Framework` gained `must_not: {type: layer, value: Dev}` so the two cannot drift apart.

The scope decision is load-bearing and was verified both ways: a probe in `src/Api/Dto` importing
`SebastianBergmann\Diff\Differ` — a *transitive* dev symbol — is **allowed** under a six-package
layer and a **violation** under the closure.

`tests/Unit/Shared/DevPackageIsolationTest.php` pins the prefix list by recomputing the dev-only
namespace set from `composer.lock` plus the generated autoload maps, failing three ways: a dev
namespace missed, a **production** namespace captured, or a dead prefix. It earned itself on the
first run — `^Symfony\Polyfill\Php8` also matched `symfony/polyfill-php86`, a *production* package,
which would have turned legitimate polyfill use into a false violation. Dev carries `php80`/`php81`;
production carries `php83`–`php86`. Narrowed to `^Symfony\Polyfill\Php8[01]\`.

**M3 — closed with a catch-all `Unlayered` layer** (`src/.*` minus the five contexts minus
`src/Kernel\.php$`), granted nothing and granted to nobody, pinned by
`tests/Unit/Shared/UnlayeredSourceTest.php`. A directory-name test was rejected: it would hold the
layout but leave any file it allowed unconstrained, and would duplicate the context list.

Verified by the orchestrator in the container (PHP 8.5.12), not taken from the agent's report:
three probes planted in one run → **4 violations, exit 1**, each naming the right file and the
right layer (`Dev` for both N1 probes, `Framework` for the `Storyteller\Support` probe). Probes
replaced with allowed dependencies → `Violations 0 / Allowed 3`, exit 0, with the Engine's
`DateTimeImmutable` / `Random\Randomizer` / `BcMath\Number` producing no entry at all. Probes
removed → `make qa` exit 0, tree clean.

**Not covered, stated plainly** — all four are written into `deptrac.yaml` as well:

- Vendor **functions and constants** stay invisible to deptrac (N2 unchanged). `use function
  PhpCsFixer\…;` in `src/` is still not a violation; the `BoundedContextLayoutTest` scan is what
  covers that, and only for Engine and Shared.
- An unlayered class that **depends on nothing** stays silent. Deptrac reports dependencies, not
  file existence.
- The layer-free **zone** still exists. `"Storyteller\\": "src/"` is still in `composer.json`; the
  narrow cure is to drop it and classmap `src/Kernel.php`. Left open deliberately.
- `Dev` is a prefix list, so it is **not** exhaustive by construction the way `Framework` is. It can
  go stale — but staleness is loud, not silent, because the guard test recomputes it.
- `migrations/` is still outside `paths` (M1), so a migration importing PHPUnit is not covered.

## Model routing

| Phase | Agent | Model | Why this tier |
|-------|-------|-------|----------------|
| Analyze   | `analyst`     | `opus`   | Greenfield foundation with open questions; ambiguity and consequence-finding is the floor's own criterion. |
| Plan      | `planner`     | `opus`   | The plan defines the dependency graph and `deptrac.yaml` — an explicit capability-floor trigger. |
| Implement | `implementer` | mixed    | Routed per batch, not per run — see below. |
| Review    | `reviewer`    | `opus`   | Adversarial reading of a diff that *is* the dependency contract; `reviewer` never runs below `sonnet` regardless. |
| Test      | `tester`      | `opus`   | Test authoring has a `sonnet` floor; this step touched two contexts (Engine and Shared) plus the dependency-rule mechanism, which is one tier up. |

Implement, batch by batch:

| Batch | Steps | Model | Why |
|-------|-------|-------|-----|
| 1 | 1–6 | `sonnet` | Scaffolding and directory tree; mechanical and command-verifiable. *The one batch whose model the implementation notes do not state — reconstructed from the orchestrator's record, not from the document. That omission is itself a process gap.* |
| 2 | 7–8 | `sonnet` | PHPStan and cs-fixer config shipped verbatim from the plan; no design judgement open. |
| 3 | 9 | `opus` | `deptrac.yaml` — capability floor, explicitly. |
| 4 | 10–12 | `opus` | Docker, Makefile and the container toolchain; crosses the host/container boundary where the acceptance criterion lives. |
| 5 | 13–14 | `sonnet` | README and the `CLAUDE.md` line-3 edit; docs-only, every fact already established. |

Post-Gate-D: **`implementer` on `opus`** for the N1/M3 closures — capability floor, both steps edit
`deptrac.yaml` and the dependency graph. Not a gate fix loop; the pipeline had already closed.

Escalations (fix loops): **Gate C loop 1 — `opus`** for both the implementer and the re-review.
Per the escalation rule a fix loop never reuses the tier that produced the blocker, and both
agents were already at the ceiling, so the loop was run at `opus` with the explicit instruction
that a new blocker would mean stopping and reporting rather than routing again. It closed with
**0 blockers**, so loop 2 was never needed. Gate D required no loop.

Capability floor triggered by: **the dependency graph and `deptrac.yaml`** (batch 3, both Gate C
reviews, the fix loop, and Gate D's probe work). Not triggered by Engine code, migrations,
cross-context interfaces or security, because none of those exist yet.

## Open items

- **N1 and M3 are closed** (see above). Both guards are proven red-first, not merely green.
- **M1 and M2 remain open by design.** They attach naturally to the first migration and the first
  real PHPStan surface; closing them now would be deciding in the abstract how generated migration
  code is held to level 8.
- **One deliberate loose end:** `"Storyteller\\": "src/"` stays in `composer.json`. The `Unlayered`
  layer means code there can no longer import anything, but the zone itself still exists. Removing
  it is a `composer.json` change worth making on its own.
- `.env` is tracked alongside `.env.example`. **Closed as correct:** `.gitignore` carries
  `/.env.local`, `/.env.local.php` and `/.env.*.local`, which is Symfony's intended split —
  committed defaults in `.env`, real secrets in the ignored `.env.local`. The reviewer's read was
  right. The standing advice is unchanged: when real secrets exist, keep them out of the tree
  rather than behind deny patterns.
- `.claude/skills/sdlc/SKILL.md` **fixed in `e59e00f`** — it justified the `fable` exclusion against
  "PHP 8.3 / Symfony 7", a stack this project never used. Now 8.5 / 7.4 LTS, matching CLAUDE.md.
- Five security reviews fired on `.claude/settings.json` during this feature. All were
  acknowledged, none applied: the permission breadth and `defaultMode: dontAsk` were requested
  by the user with the tradeoffs surfaced first. The deny rules are speed bumps, not a boundary —
  with interpreters allowlisted, nothing path-based can hold that line.

## Suggested squash commit message

```
feat(skeleton): Symfony 7.4 / PHP 8.5 foundation with enforced context rules

- composer project: framework-bundle, orm-pack, serializer, validator,
  uid, yaml, monolog; dev: phpunit, phpstan level 8, php-cs-fixer, deptrac
- five bounded-context directories under src/, empty by design
- deptrac encodes the CLAUDE.md dependency rule and fails on any vendor
  package, not a list that goes stale when one is added
- unit test scans src/Engine and src/Shared for framework imports,
  including `use function`, which deptrac structurally cannot see
- a Dev layer denies all 51 require-dev packages transitively, so a
  production class cannot import PHPUnit and pass; a guard test
  recomputes the list from composer.lock and fails if it drifts
- an Unlayered layer closes the gap where a class outside the five
  contexts was reported nowhere at all, not even as uncovered
- php-fpm 8.5 / nginx / postgres 16 compose stack; `make qa` is the gate
- pins 13 transitive packages to keep Symfony 8.1 out of a 7.4 LTS tree

Refs: docs/sdlc/project-skeleton/00-summary.md

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
```
