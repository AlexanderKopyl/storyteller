# Review policy

The policy every pull request is reviewed against — by Claude in CI
(`.github/workflows/claude-review.yml`), by `/review` locally before pushing, and by the
human code owner who approves the merge.

Every PR gets the same passes in the same order. Findings are posted ranked by severity.

## Passes

### 1. Architecture and the dependency rule

```
Engine  -> (nothing)
Content -> Engine
Play    -> Engine, Content
Api     -> Play, Content
```

Everything may depend on `Shared`. Nothing depends upward or sideways.

- A Symfony, Doctrine or HTTP type inside `src/Engine` is a **blocker**.
- A new edge between contexts that is not in the committed `spec.md` is a **blocker**.
- Weakening `deptrac.yaml` to make the check pass is a **blocker**.

### 2. Engine purity and determinism

The Engine is pure PHP and deterministic: same `GameState` + same input ⇒ same result.

- `rand()`, `mt_rand()`, `random_int()`, `shuffle()`, `time()`, `date()`, `microtime()`,
  `new \DateTime()` without injection, `$_ENV`, `$_SERVER`, `getenv()`, superglobals,
  static mutable state, filesystem or network I/O inside `src/Engine` — **blocker**.
- Randomness and time must be injected (a seed, a clock). An injected source used
  non-deterministically — iteration order over an unordered structure, reliance on object
  identity or `spl_object_id` — is a **blocker**.

### 3. Tests

- A diff touching `src/Engine/**` with no change under `tests/Unit/Engine/**` is a
  **blocker**. No exceptions.
- A new AST condition or effect operator without unit tests covering happy path, missing
  variable, wrong type, boundary values and malformed node shape is a **blocker**.
- A test that asserts implementation detail rather than behaviour is a **major**.
- A test changed in the same commit as the bug it was supposed to catch is a **major** —
  the failing test must pre-exist the fix.

### 4. Security

- Unvalidated external input reaching the Engine is a **blocker**. All input is validated
  in `src/Api` by a DTO with constraints; the Engine trusts its inputs, so the API must
  earn that trust.
- Missing auth or ownership check on a session or story endpoint — **blocker**.
- Secrets, credentials or PII in code, logs, fixtures or responses — **blocker**.
- Injection risk in a JSONB query, or user input interpolated into SQL or YAML —
  **blocker**.
- Internal details (stack traces, class names, SQL) leaked in an error response —
  **major**.

### 5. Data and migrations

- An edit to an already-committed migration is a **blocker**; a correction is a new
  migration.
- A schema change made outside a Doctrine migration is a **blocker**.
- A change that alters the meaning of an already-published `story_version` snapshot is a
  **blocker** — published versions are immutable.
- An irreversible migration without a note saying why is a **major**.

### 6. Spec and plan conformance

- Compare the diff against the committed `docs/sdlc/<feature>/spec.md` and `plan.md`.
- Scope in the diff that is in neither — **major**, unless recorded under *Deviations* in
  the plan.
- A requirement in the spec with nothing in the diff that satisfies it — **major**.
- Files changed that the plan does not list — **minor**, and expected to be noted.

### 7. Correctness and error handling

- Swallowed exceptions, empty `catch`, errors degraded to a silent default — **major**.
- Error paths with no test — **major**.
- Off-by-one, null handling, unhandled enum case, unreachable or contradictory condition —
  severity by impact.

### 8. Performance

- N+1 queries — **major**.
- Loading a whole compiled story snapshot where a projection suffices — **major**.
- Unbounded loop or recursion over user-supplied AST depth without a limit — **blocker**
  (it is a denial-of-service path).

## Severity

| Severity | Definition | Effect |
|----------|------------|--------|
| **Blocker** | Breaks an invariant this project is built on: the dependency rule, Engine purity, the Engine-test rule, a security boundary, migration immutability. | Must be fixed before merge. |
| **Major** | Real defect or real risk; would be a bug report or a follow-up ticket. | Fixed before merge, or deferred with an explicit note from the code owner. |
| **Minor** | Clarity, naming, duplication, a missing but non-essential test. | Author's discretion. |
| **Nit** | Style and taste where the tooling has no opinion. | Optional. |

**Nit cap: 5 per PR.** Beyond that, the reviewer reports the pattern once instead of each
instance. php-cs-fixer owns formatting — a formatting comment is never a finding.

## Exclusions

Not reviewed, because something deterministic already owns them:

- `vendor/`, `var/`, `public/bundles/` — not ours.
- Formatting and PSR-12 — owned by `composer cs-check`.
- Type errors reachable by static analysis — owned by `composer phpstan` at level 8.
- The dependency rule as a mechanical check — owned by `composer deptrac`. The reviewer
  still reviews *intent*: an edge that passes deptrac but contradicts the spec is a
  finding.
- Generated files and lock files, unless a dependency was added — a new dependency with no
  justification in the committed `plan.md` is a **major** (CLAUDE.md rule 9).

## Rules of the loop

- **Claude never approves its own code.** Findings and fixes are advisory; a human code
  owner approves the merge.
- On a PR, tag `@claude` on a finding to have the fix pushed as a commit. The loop repeats
  until the gates are green.
- **When a review flags the same mistake twice, the correction goes into `CLAUDE.md`**
  under *Things Claude gets wrong* — not into a third review comment.
- Every finding, fix and dismissal stays in the PR history. A dismissal records a reason.

## The gates are not this document

`composer qa` must be green before review is useful: `cs-check`, `phpstan` (level 8),
`deptrac`, `test`. Never weaken the PHPStan level, the deptrac rules or the cs-fix config
to make a check pass — that is a blocker in its own right.
