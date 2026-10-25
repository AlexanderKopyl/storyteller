# Spec: <feature>

- **Slug:** `<feature>`
- **Based on:** [`intent.md`](intent.md) @ `<commit sha>`
- **Author:** <product owner>
- **Date:** <YYYY-MM-DD>
- **Status:** draft / concerns open / approved
- **Skills applied:** `engine-purity`, `context-boundaries`, `api-input-validation`,
  `doctrine-migrations`

## Summary

<Three to five sentences: what is being built and the shape of it.>

## Requirements

### Functional

| # | Requirement | Acceptance |
|---|-------------|------------|
| F1 | <…> | <observable check> |

### Non-functional

| # | Requirement | Acceptance |
|---|-------------|------------|
| N1 | <determinism / latency / immutability / validation> | <…> |

## Design

### Bounded contexts

| Context | Change | Allowed by the dependency rule? |
|---------|--------|----------------------------------|
| `Shared` | <…> | — |
| `Engine` | <…> | `Engine -> nothing` |
| `Content` | <…> | `Content -> Engine` |
| `Play` | <…> | `Play -> Engine, Content` |
| `Api` | <…> | `Api -> Play, Content` |

<State explicitly if no new edge is required. A new edge needs a note here and a
`deptrac.yaml` change in the plan.>

### Domain model

- **JSON AST** — new or changed condition/effect operators, with their node shape.
  Every new operator needs: AST spec entry, evaluator/applier arm, unit tests.
- **Story content** — YAML authoring shape, and the compiled JSONB snapshot shape.
- **State** — what `GameState` gains or loses.

### Data & schema

- New tables / columns / indexes: <…>
- Migration needed: yes / no — <what it does, and whether it is reversible>
- Effect on already-published `story_version` snapshots: <must be none, or explain>

### API surface

| Method | Path | Request DTO | Response | Auth |
|--------|------|-------------|----------|------|
| <…> | <…> | <…> | <…> | <…> |

Validation: every field, its constraint, and what the API returns when it fails.

### Determinism

<How the Engine stays deterministic: what is injected (seed, clock) rather than read from
the environment. If this feature needs randomness or time, say exactly how it is injected.>

## Concerns for policy owners

| # | Concern | Policy / skill | Owner | Resolution |
|---|---------|----------------|-------|------------|
| C1 | <…> | `<skill>` | <name> | open / resolved: <…> |

**The spec is not committed while a concern is open.**

## Open questions carried from the intent

| # | Question | Answer |
|---|----------|--------|
| 1 | <…> | <…> |

## Out of scope

- <…>
