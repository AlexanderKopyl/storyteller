---
name: context-boundaries
description: "Policy: the bounded-context dependency rule enforced by deptrac. Use when adding a class, moving code between src/Engine, src/Content, src/Play, src/Api or src/Shared, adding a use statement across contexts, adding a composer dependency, editing deptrac.yaml, or when deptrac reports a violation or an uncovered token."
---

# Policy — bounded contexts and the dependency rule

**Owner:** architecture owner. Changes to `deptrac.yaml` need their sign-off.

```
Engine  -> (nothing but Shared)
Content -> Engine
Play    -> Engine, Content
Api     -> Play, Content
```

Everything may depend on `Shared`. **No context may depend upward or sideways.**

| Context | Holds |
|---------|-------|
| `src/Engine` | Pure rules: `GameState`, `ConditionEvaluator`, `EffectApplier`, `NextSceneResolver`, `Transition`, the AST. Framework-free, deterministic, no I/O. |
| `src/Content` | Story definitions, authoring, versioning, YAML → compiled JSONB snapshot. |
| `src/Play` | Play sessions, event log, progress. |
| `src/Api` | HTTP controllers, DTOs, serialization, auth. |
| `src/Shared` | Cross-context primitives: value objects, ids, clock, errors. |

Note the deliberate absence of **`Api -> Engine`**. The API reaches the Engine *through*
Play. If a controller wants to call the Engine directly, the design is wrong — put the
orchestration in `Play`.

## Where a new class goes

Ask what it depends on, not what it is about:

- Needs no framework and no I/O, and is a rule? → `Engine`.
- Needs Doctrine or the filesystem to load or compile a story? → `Content`.
- Coordinates a session, writes the event log, calls the Engine? → `Play`.
- Touches HTTP, a request, a response, serialization or auth? → `Api`.
- A primitive that two or more contexts need and that depends on nothing? → `Shared`.

If it seems to belong in two contexts, it is two classes.

## Things that fail the build

- A Symfony, Doctrine or HTTP type inside `src/Engine` — the `Engine` layer is granted
  only `Shared`.
- A class under `src/` outside the five context directories. The `Unlayered` layer is
  granted nothing and granted to nobody, so it fails loudly. `src/Kernel.php` is the one
  carve-out, by name.
- An import of a `require-dev` package from `src/`. The `Dev` layer covers the full
  transitive dev-only closure from `composer.lock`;
  `tests/Unit/Shared/DevPackageIsolationTest.php` fails when that list goes stale.
- Any vendor class reaching a context that was not granted `Framework`.

## Changing the rule

A new edge is a **design decision**, not a build fix.

1. It must be stated in the committed `spec.md`, with the reason.
2. The `deptrac.yaml` change is its own step in `plan.md`.
3. The architecture owner signs off.

**Never** widen a ruleset, delete a layer or add a `must_not` carve-out to make a red
build green. That is a review blocker in its own right. The same goes for lowering the
PHPStan level or adding a baseline entry.

Granting one vendor package narrowly (say, `symfony/uid` to `Shared`) needs its **own**
layer with a `composer` collector *plus* a matching `must_not` in `Framework`, so the two
layers stay disjoint — read the comments in `deptrac.yaml` before trying it.

## Check

```bash
composer deptrac    # --report-uncovered --fail-on-uncovered; Violations: 0, Uncovered: 0
```

An **Uncovered** token is not a pass. It means deptrac had no opinion, which is the
failure mode `deptrac.yaml` was rebuilt to close.
