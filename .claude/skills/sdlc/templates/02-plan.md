# 02 — Implementation plan: <feature>

- **Feature slug:** `<feature>`
- **Based on:** `docs/sdlc/<feature>/01-analysis.md`
- **Date:** <YYYY-MM-DD>

## Approach

<3–6 sentences: the shape of the solution and why this shape.>

## Files per bounded context

### Shared
| Action | Path | Purpose |
|--------|------|---------|
| create/change | `src/Shared/...` | <…> |

### Engine
| Action | Path | Purpose |
|--------|------|---------|

### Content
| Action | Path | Purpose |
|--------|------|---------|

### Play
| Action | Path | Purpose |
|--------|------|---------|

### Api
| Action | Path | Purpose |
|--------|------|---------|

## Public interfaces

```php
// Exact signatures to be added or changed.
```

JSON AST node shapes / DTO shapes:

```json
{ }
```

## Tests to write first

| Test file | Test name | Asserts |
|-----------|-----------|---------|
| `tests/Unit/Engine/...` | `test...` | <…> |

Engine steps are TDD: the failing test is its own sub-step before the implementation.

## Migrations

- Needed: yes / no
- Up: <…>
- Down / rollback: <…>
- Backfill: <…>

## Deptrac impact

- New cross-context edges: <none / list>
- `deptrac.yaml` change required: yes / no — <why>

## Ordered steps

Each step is independently committable and must leave `test`, `phpstan`, `cs-fix` and
`deptrac` green.

1. **<step>** — files: `<…>` — tests: `<…>` — commit: `<Context: subject>`
   — routing: `<opus|sonnet>` because `<Engine / migration / cross-context interface / security / single-context>`
2. …

## Routing hints for the orchestrator

The orchestrator picks the implementer's model per step. Flag here anything that forces
the `opus` capability floor, so it does not have to infer it:

| Trigger | Steps affected |
|---------|----------------|
| touches `src/Engine/**` | <…/none> |
| Doctrine migration | <…/none> |
| public interface crossing two contexts | <…/none> |
| auth / input validation / security surface | <…/none> |
| open question from `01-analysis.md` still unanswered | <…/none> |

Steps with no trigger above and a single bounded context may run at `sonnet`.

## Rollback

<How to undo this if it goes wrong in production.>
