# 05 — Test report: <feature>

- **Feature slug:** `<feature>`
- **Date:** <YYYY-MM-DD>

## Verdict

**PASS / FAIL**

## Check results

| Command | Result | Detail |
|---------|--------|--------|
| `composer test` | pass / fail | <N tests, N assertions, N failures> |
| `composer phpstan` | pass / fail | <N errors> |
| `composer deptrac` | pass / fail | <N violations> |
| `composer cs-fix` | clean / fixed N files | <…> |

## Tests added

| File | Test | Covers |
|------|------|--------|
| `tests/Unit/Engine/...` | `test...` | determinism / operator / transition / concurrency / versioning |

## Edge cases exercised

- **Engine determinism:** <…>
- **Condition / effect operators:** <…>
- **Transition resolution:** <…>
- **Session concurrency:** <…>
- **Content versioning:** <…>

## Failures found

| Test | Failure | Suspected cause | Owner |
|------|---------|-----------------|-------|
| <…>  | <…>     | <…>             | implementer |

## Coverage gaps not closed

| Gap | Why not closed | Suggested follow-up |
|-----|----------------|---------------------|
| <…> | <…>            | <…>                 |
