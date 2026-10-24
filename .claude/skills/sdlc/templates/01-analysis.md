# 01 — Analysis: <feature>

- **Feature slug:** `<feature>`
- **Date:** <YYYY-MM-DD>
- **Request:** <one paragraph, in the user's terms>

## Scope

**In scope**
- <…>

**Out of scope (explicitly not doing)**
- <…>

## Affected bounded contexts

| Context   | Affected? | What changes                      |
|-----------|-----------|-----------------------------------|
| `Shared`  | yes/no    | <…>                               |
| `Engine`  | yes/no    | <…>                               |
| `Content` | yes/no    | <…>                               |
| `Play`    | yes/no    | <…>                               |
| `Api`     | yes/no    | <…>                               |

## Existing code touched

| File | Why it is relevant |
|------|--------------------|
| `src/...` | <…> |

## Data & schema impact

- Story content / JSON AST changes: <…>
- `story_version` snapshot shape: <…>
- New tables / columns / indexes: <…>
- Migration needed: yes / no

## Dependency-rule impact

Required edges: <e.g. `Play -> Engine` (already allowed)>
New or questionable edges: <none / describe>

## Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| <…>  | low/med/high | low/med/high | <…> |

## Open questions

1. **<question>** — recommended default: <…>
2. …

## Acceptance criteria

1. <testable statement>
2. …

## Assumptions made

- <…>
