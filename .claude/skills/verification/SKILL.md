---
name: verification
description: "Stage 4 of the AI-native SDLC — the feedback loop. Use before claiming any work is done, fixed, passing or ready to commit, review or push; when the user runs /verify; and when fixing a bug, to write the failing test first. Requires running composer qa and pasting the real output as evidence."
---

# Stage 4 — Give Claude a feedback loop

The session verifies its own work before a human sees it. Evidence before assertions,
always.

## The loop is one command

```bash
composer qa      # cs-check -> phpstan -> deptrac -> test; non-zero on any failure
```

Iterate against it until it passes. Do not report progress between iterations; report the
result.

Individually, when you need to isolate a failure:

| Command | Healthy output |
|---------|----------------|
| `composer test` | `OK (N tests, M assertions)` |
| `composer phpstan` | `[OK] No errors` |
| `composer cs-check` | no diff, exit 0 |
| `composer deptrac` | `Violations: 0`, `Uncovered: 0` |
| `composer coverage` | unit suite, coverage scoped to `src/Engine` |

Inside Docker: `make qa`, `make test`, `make coverage` (require `make up` first).

## Hard boundaries

- **Never claim done, fixed, passing or working without having run the command in this
  session and read its output.** Not "should pass". Not "tests look right".
- Paste the **real output**, not a summary of it. If it is long, paste the result lines.
- A command you did not run is reported as not run. A step you skipped is reported as
  skipped. If a test fails, say so and show it.
- **Never edit a test to make it pass.** If a test is genuinely wrong, say why, and change
  it as its own commit, separately from the fix.

## Fixing a bug

1. **Write the failing test first**, under `tests/Unit/<Context>/`. Run `composer test` and
   see it fail for the right reason.
2. Freeze the tests so the fix cannot cheat:
   ```bash
   touch var/freeze-tests
   ```
   The `freeze-tests` hook then denies every edit under `tests/` until it is removed.
3. Fix the production code until the test passes.
4. Thaw and re-run the full loop:
   ```bash
   rm -f var/freeze-tests && composer qa
   ```
   Paste the output.

The failing test must pre-exist the fix. A test changed in the same commit as the fix is a
review blocker.

## Set a quantifiable target

Before iterating, state what passing means, so the loop has an exit condition:

- "every test in `tests/Unit/Engine/ConditionEvaluatorTest.php` passes"
- "`composer deptrac` reports 0 violations and 0 uncovered"
- "`composer phpstan` reports no errors at level 8 with no new baseline entry"
- "`POST /sessions/{id}/choices` returns 200 with the new `transition` field"

Vague targets ("make it work") produce vague loops.

## Engine-specific checks

A change under `src/Engine/**` is not verified until:

- `tests/Unit/Engine/**` changed in the same diff — this is mandatory, not a preference;
- determinism is exercised: the same `GameState` and input run twice produce an identical
  result;
- every new or changed AST operator is tested for happy path, missing variable, wrong
  type, boundary values, malformed node shape and unknown operator;
- nothing in the diff reads the environment: no `rand()`, `time()`, `getenv()`,
  superglobals, statics, or I/O.

## Evidence goes in the report

The task summary and the PR body carry the pasted output. See `docs/sdlc/evals.md` for the
regression suite that keeps `CLAUDE.md`, the skills and the hooks honest when they change.
