---
description: "Stage 3 — write the implementation plan in plan mode, before any file changes"
argument-hint: "<feature-slug>"
---

Run the `implementation-plan` skill for feature: $ARGUMENTS

**Enter plan mode first** (shift+tab twice) if you are not already in it. The point of this
stage is that the plan is argued over while nothing has been written yet.

- If no slug was given, list the folders under `docs/sdlc/` that have a `spec.md` but no
  `plan.md`, and ask which one.
- If `docs/sdlc/<slug>/spec.md` does not exist, stop and run `/spec` first.

Produce the plan from `docs/sdlc/templates/plan.md`: files that change, work order, risks,
proof. Order the steps bottom-up — `Shared` → `Engine` → `Content` → `Play` → `Api`. Every
Engine step is TDD, with the failing test as its own numbered step.

Present the plan for interrogation. Do not edit a single file until it is approved and
committed as `docs/sdlc/<slug>/plan.md`.

Then implement it step by step: `composer cs-fix` and `composer phpstan` after each step,
one small commit per step, deviations recorded in the plan rather than improvised around.
