# Plan: <feature>

- **Slug:** `<feature>`
- **Based on:** [`spec.md`](spec.md) @ `<commit sha>`
- **Engineer:** <name>
- **Date:** <YYYY-MM-DD>
- **Approved by:** <name> on <YYYY-MM-DD>

## Approach

<Three to six sentences: the shape of the solution and why this shape rather than the
obvious alternative.>

## Files that change

| Action | Path | Purpose |
|--------|------|---------|
| create | `src/Engine/...` | <…> |
| change | `src/Play/...` | <…> |
| create | `tests/Unit/Engine/...` | <…> |

## Work order

Bottom-up along the dependency rule: `Shared` → `Engine` → `Content` → `Play` → `Api`.
Each step is independently committable and leaves `composer qa` green.

| # | Step | Context | Commit subject |
|---|------|---------|----------------|
| 1 | <failing test for …> | `Engine` | `test(engine): …` |
| 2 | <implement …> | `Engine` | `feat(engine): …` |

**Engine steps are TDD**: the failing test is its own step, written and seen to fail before
the implementation step begins.

## Public interfaces

```php
// Exact signatures being added or changed.
```

AST node shapes / DTO shapes being added:

```json
{}
```

## Risks

| # | Risk | Likelihood | Mitigation |
|---|------|-----------|------------|
| R1 | <…> | low/med/high | <…> |

## Proof

What makes this demonstrably done:

| Claim | Proof |
|-------|-------|
| <F1 from the spec> | `<test name>` in `<file>` |
| Engine stays deterministic | same state + input run twice ⇒ identical result |
| No new context edge | `composer deptrac` clean, `deptrac.yaml` unchanged |
| Gates green | `composer qa` output pasted in the PR |

## Migration

- Needed: yes / no
- Name: `<VersionYYYYMMDDHHMMSS>`
- Reversible: yes / no — <why>
- Never edit a committed migration; a correction is a new migration.

## Deviations

<Filled in during implementation. A step that turns out wrong is recorded here, not
silently improvised around.>

| Step | Planned | Actually done | Why |
|------|---------|---------------|-----|
