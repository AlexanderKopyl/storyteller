# 00 — Summary: <feature>

- **Feature slug:** `<feature>`
- **Date:** <YYYY-MM-DD>
- **Documents:** [01](01-analysis.md) · [02](02-plan.md) ·
  [03](03-implementation-notes.md) · [04](04-review.md) · [05](05-test-report.md)

## What changed

| Context | Change |
|---------|--------|
| `Shared`  | <…> |
| `Engine`  | <…> |
| `Content` | <…> |
| `Play`    | <…> |
| `Api`     | <…> |

Migration: yes / no — <name>
Deptrac configuration changed: yes / no

## Test results

| Command | Result |
|---------|--------|
| `composer test` | <…> |
| `composer phpstan` | <…> |
| `composer deptrac` | <…> |
| `composer cs-fix` | <…> |

Tests added: N (unit: N, functional: N)

## Review findings not fixed

| Severity | Finding | Why deferred |
|----------|---------|--------------|
| major/minor | <…> | <…> |

## Model routing

| Phase | Agent | Model | Why this tier |
|-------|-------|-------|----------------|
| Analyze   | `analyst`     | <opus/sonnet/haiku> | <…> |
| Plan      | `planner`     | <…> | <…> |
| Implement | `implementer` | <…> | <…> |
| Review    | `reviewer`    | <…> | <…> |
| Test      | `tester`      | <…> | <…> |

Escalations (fix loops): <none / Gate C loop 1 sonnet -> loop 2 opus / …>
Capability floor triggered by: <Engine / migration / cross-context interface / security / none>

## Open items

- <…>

## Suggested squash commit message

```
<type>(<scope>): <subject under 72 chars>

- <substantive change>
- <substantive change>

Refs: docs/sdlc/<feature>/00-summary.md
```
