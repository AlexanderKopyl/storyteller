---
name: implementation-plan
description: "Stage 3 of the AI-native SDLC — turn a committed spec.md into a reviewed plan.md (files that change, work order, risks, proof) before any file is edited, then implement it in one pass. Use when starting to build a feature that has a spec, when the user runs /plan, or whenever implementation is about to begin without a written plan."
---

# Stage 3 — Build: plan first, then one pass

Design review happens before code generation, not after.

## Write the plan in plan mode

Enter plan mode (shift+tab twice) before reading anything. In plan mode you cannot edit
files, which is the point: the plan is argued over while it is still cheap to change.

## Hard boundaries

- **Nothing is implemented until the plan is approved and committed** as
  `docs/sdlc/<feature>/plan.md`.
- You implement the plan. You do not re-scope it, redesign it or add unplanned features.
  A step that turns out to be wrong is recorded under **Deviations** and raised — never
  silently improvised around.
- Never weaken `phpstan.neon`, `deptrac.yaml` or `.php-cs-fixer.dist.php` to make a step
  pass. Never add a PHPStan baseline entry without a note saying why.
- Never `git push`, never `git reset --hard`, never rewrite history, never touch `.env*`.

## Writing the plan

1. Read `docs/sdlc/<feature>/spec.md` and `intent.md`, and `CLAUDE.md`. Record the spec's
   commit sha.
2. Read the files the spec names — exact existing signatures and namespaces, by symbol
   navigation, not by reading whole files.
3. Produce an ordered sequence of **small steps**. Each one is independently committable
   and leaves `composer qa` green.
4. Order bottom-up along the dependency rule: `Shared` → `Engine` → `Content` → `Play` →
   `Api`.
5. **Engine steps are TDD.** The failing test is its own numbered step, before the
   implementation step. A plan that implements Engine code before its test is a broken
   plan.
6. State the **risks** and the **proof** — what demonstrates each spec requirement is met.
7. Write it from `docs/sdlc/templates/plan.md`. Hand it to the engineer to interrogate.

## Implementing the plan — per step

1. **Engine steps:** write the failing unit test under `tests/Unit/Engine/`, run
   `composer test`, *see it fail*, then implement until it passes. Editing `src/Engine`
   without a corresponding test change is a rule violation and a review blocker.
2. **Other contexts:** implement, with tests accompanying the change in the same step.
3. Run `composer cs-fix`, then `composer phpstan`. Fix what they report.
4. Run `composer test`, and `composer deptrac` if the step touched context boundaries.
5. Commit that step alone. One logical change per commit:
   `<type>(<context>): <imperative summary>` — e.g.
   `feat(engine): add has_flag condition operator`.
6. Do not batch steps. Do not mix a refactor and a behaviour change in one commit.

Run `composer cs-fix` and `composer phpstan` **after each step**, not once at the end.

## Parallel work

Independent tasks can run as parallel sessions on separate worktrees
(`claude --worktree <name>`). Two or three is a sensible ceiling. Tasks must not share
files. The repo's hooks and permissions apply in every worktree.

## Definition of done

Every step in the plan is committed, `composer qa` is green with the output pasted, and any
deviation is recorded in `plan.md`.

## Next

Stage 4: `/verify`. Then Stage 5: `/review`, then open the PR.
