---
name: spec
description: "Stage 2 of the AI-native SDLC — turn a committed intent.md into a requirements-and-design spec.md in one session, with the project's policy skills applied as constraints while writing. Use after an intent.md is committed, when the user runs /spec, or when asked to write requirements or a design for a feature that has an intent."
---

# Stage 2 — `intent.md` → `spec.md`

Requirements and design in one pass, with policy applied while writing rather than
discovered in review.

## Hard boundaries

- You write **one file**: `docs/sdlc/<feature>/spec.md`. No code, no tests, no migrations.
- You do **not** produce a file-by-file implementation order — that is the plan (Stage 3).
  The spec says *what* and *what shape*; the plan says *in what order*.
- **Do not start without a committed `intent.md`.** If it is missing, run the `intent`
  skill first. If it exists but is uncommitted, say so and continue — note the state.
- You may not resolve a policy question yourself. Flag it for its owner.

## Procedure

1. **Read the intent.** `docs/sdlc/<feature>/intent.md`, plus `CLAUDE.md`. Record the
   commit sha the spec is based on.
2. **Load the policy skills as constraints.** Before writing a single requirement, read:
   - `engine-purity` — determinism, injection, the mandatory-unit-test rule
   - `context-boundaries` — the dependency rule and what a new edge costs
   - `api-input-validation` — validation at the boundary
   - `doctrine-migrations` — schema change and migration immutability

   These are constraints on what you may specify, not a checklist to append.
3. **Read the code the intent points at.** Navigate by symbol — find the actual signatures,
   namespaces and AST node shapes you are extending. Do not design against a guess.
4. **Write the spec** from `docs/sdlc/templates/spec.md`:
   - Functional requirements, each with an observable acceptance check.
   - Non-functional requirements — determinism, validation, immutability, performance.
   - The design: contexts touched, domain model, AST node shapes, schema, API surface.
   - Every new AST operator gets its spec entry here; the evaluator/applier arm and its
     tests are planned in Stage 3.
5. **Flag what you could not resolve.** Anything that needs a policy owner's call goes in
   **Concerns for policy owners** with the skill it belongs to and a named owner. Be
   specific about what decision is needed — a concern with no decidable question is noise.
6. **Report** the path, the concerns, and who must resolve each.

## The dependency rule in the spec

Check every proposed interaction against:

```
Engine -> nothing · Content -> Engine · Play -> Engine, Content · Api -> Play, Content
```

A design that needs a new edge is not automatically wrong, but the spec must say so
explicitly and the plan must include the `deptrac.yaml` change. A design that needs
`Engine` to depend on anything is wrong — redesign it.

## Definition of done

`spec.md` exists, every functional requirement has an acceptance check, the design is
specific enough to plan from, and either there are no open concerns or the status is
`concerns open`.

**The spec is not committed while a concern is open.** Say this in your report.

## Next

Once committed, Stage 3 is triggered: `/plan <feature>` in plan mode.
