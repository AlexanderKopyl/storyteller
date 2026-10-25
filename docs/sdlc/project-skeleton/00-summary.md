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
| `composer test` | exit **0** — `OK (6 tests, 44 assertions)` |
| `composer phpstan` | exit **0** — `[OK] No errors`, 7 files, level 8 |
| `composer deptrac` | exit **0** — Violations 0 / Uncovered 0 / Allowed 0 |
| `composer cs-fix` | exit **0** via `cs-check` — 0 of 8 files would change |
| `make qa` | exit **0** — the acceptance criterion |

Tests added: **6** (unit: 6, functional: 0) across two files — a kernel-boot smoke test and
`tests/Unit/Shared/BoundedContextLayoutTest.php`.

**Proven non-vacuous.** The architecture test was demonstrated red-first by the tester and
again by the orchestrator: planting `use function Symfony\Component\String\u;` in `src/Engine`
and `Symfony\Component\Uid\Uuid` in `src/Shared` produces **2 failures, exit 1**; deleting them
returns exit 0 with a clean tree. The deptrac rule was likewise probed in the container —
Engine→Routing **1**, Engine→Doctrine **1**, Shared→Uid **1**, Api→Engine **1**, Play→Engine+Content
**0 with `Allowed 2`** (positive control), Engine→`Random\Randomizer`/`DateTimeImmutable`/`BcMath\Number` **0**.

**Gate vacancy, stated plainly.** On an empty tree `deptrac` reports `Allowed 0` — no layered
token exists yet — so it is vacuous in *coverage* while proven in *mechanism*. `phpstan` is
genuinely ceremonial: 7 files, one of them generated vendor output, and its extensions are inert
without `containerXmlPath`. `cs-check` and `test` are protective today. One of four is ceremonial.

## Review findings not fixed

| Severity | Finding | Why deferred |
|----------|---------|--------------|
| major | **N1** — `require-dev` packages fall inside the `Framework` layer, so a production class may `use PHPUnit\Framework\Assert` with every gate green. Fatal under `composer install --no-dev`. Verified by probe: `Allowed 1`, exit 0. | Found by the Gate C re-review *after* the fix loop closed. Fixing it edits the dependency graph, which is implementer work under the capability floor, and Gate C had already consumed fix loop 1 of 2. Fix is known: a `Dev` layer over the six dev packages, granted to nobody, plus a matching `must_not` in `Framework`. |
| major | **M1** — `migrations/` sits outside phpstan, cs-fixer and deptrac. | No migration exists yet. Closing it means deciding how generated migration code is held to level 8, which is a decision for the first migration. |
| major | **M2** — PHPStan's input set depends on whether a kernel has booted, because it analyses the generated, gitignored `config/reference.php`. | Cosmetic while the tree is empty; interacts with C-2's untracking decision and deserves its own change. |
| major | **M3** — a class in an unlayered namespace escapes the dependency rule entirely. Verified: `Storyteller\Support` importing Doctrine *and* Symfony is reported **nowhere** — not a violation, not `Uncovered`. | Structural: deptrac only evaluates dependencies whose *depender* is layered, so `--fail-on-uncovered` cannot reach it. Needs a deliberate choice (catch-all layer, or a test asserting `src/` has no unlayered top-level dirs). |
| major | **M4** — commit `9bcc496` changed `.claude/**` mid-feature and is recorded in no phase document. | Process finding, not a code defect. Recorded here, which is the remedy. |
| minor | **N2** — deptrac cannot see vendor *functions*; confirmed a tool limitation, not a config error. | **Mitigated** test-side in `b4ba08d`: the scan now covers `use function` / `use const` across Symfony, Doctrine, Psr, Monolog and PHPUnit roots. |
| minor | **N3** — the built-ins exemption is pinned to `jetbrains/phpstorm-stubs`; a built-in the map misses becomes a hard violation in Engine/Shared. | 33 built-ins probed against the locked v2026.2 map, all present, including `Random\Randomizer` and `BcMath\Number`. Recorded for diagnosis speed only. |
| minor | **N4, m1** — doc inaccuracies: a token in two layers is a *violation*, not a warning; the `analyser.types` comment is incomplete; batch 4's notes say `guard-running` exits 2 when it exits 1. | Documentation drift, no behavioural effect. |
| minor | **m2–m10** | Carried from `04-review.md` unchanged. |

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

Escalations (fix loops): **Gate C loop 1 — `opus`** for both the implementer and the re-review.
Per the escalation rule a fix loop never reuses the tier that produced the blocker, and both
agents were already at the ceiling, so the loop was run at `opus` with the explicit instruction
that a new blocker would mean stopping and reporting rather than routing again. It closed with
**0 blockers**, so loop 2 was never needed. Gate D required no loop.

Capability floor triggered by: **the dependency graph and `deptrac.yaml`** (batch 3, both Gate C
reviews, the fix loop, and Gate D's probe work). Not triggered by Engine code, migrations,
cross-context interfaces or security, because none of those exist yet.

## Open items

- **N1 is the one to schedule first.** It is the only open finding that can produce a green
  build which dies in production. Fix it before real code lands on top of the contract.
- **M3 next.** `"Storyteller\\": "src/"` leaves a one-file route to unconstrained code; the
  longer the tree stays empty, the cheaper the decision is.
- M1 and M2 attach naturally to the first migration and the first real PHPStan surface.
- `.env` is tracked alongside `.env.example`. The reviewer judged the tracked file clean —
  placeholder `APP_SECRET`, dev-only credentials, Postgres bound to `127.0.0.1` since C-3 — and
  the `.env`/`.env.local` split correct. **Raised with the user, not yet answered.** Before real
  secrets exist in this tree, move them out of it rather than relying on deny patterns.
- `.claude/skills/sdlc/SKILL.md` line 102 still reads "PHP 8.3 / Symfony 7". Out of scope by
  decision D2; needs separate approval.
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
- php-fpm 8.5 / nginx / postgres 16 compose stack; `make qa` is the gate
- pins 13 transitive packages to keep Symfony 8.1 out of a 7.4 LTS tree

Refs: docs/sdlc/project-skeleton/00-summary.md

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
```
