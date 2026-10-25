# Storyteller — Project Rules

Interactive choice-driven story platform. PHP 8.5 · Symfony 7.4 LTS · PostgreSQL · REST API ·
Vue 3 (CDN) frontend for the MVP.

## Architecture — modular monolith, bounded contexts

| Context       | Responsibility                                                              |
|---------------|-----------------------------------------------------------------------------|
| `src/Engine`  | Pure PHP rules: `GameState`, `ConditionEvaluator`, `EffectApplier`, `NextSceneResolver`, `Transition`. Framework-free, deterministic, no I/O. |
| `src/Content` | Story definitions, authoring, versioning, YAML → compiled JSONB snapshot.   |
| `src/Play`    | Play sessions, event log, progress.                                         |
| `src/Api`     | HTTP controllers, DTOs, serialization, auth.                                |
| `src/Shared`  | Cross-context primitives (value objects, ids, clock, errors).               |

### Dependency rule (enforced by deptrac — must pass)

```
Engine  -> (nothing)
Content -> Engine
Play    -> Engine, Content
Api     -> Play, Content
```

Everything may depend on `Shared`. No context may depend upward or sideways. Symfony,
Doctrine and HTTP types must never appear inside `src/Engine`.

## Domain model

- Story content is **data**, not code: authored as YAML, compiled into a JSONB snapshot
  stored in `story_version`. A published version is immutable.
- Conditions and effects are a **JSON AST** interpreted by the Engine. Adding an operator
  means adding it to the AST spec, the evaluator/applier, and its unit tests.
- The Engine is **deterministic**: same `GameState` + same input ⇒ same result. No
  `rand()`, no `time()`, no globals, no static mutable state — inject a seed or clock.

## Quality bar (all four must pass before anything is considered done)

One command is the feedback loop:

```bash
composer qa         # cs-check -> phpstan -> deptrac -> test; non-zero on any failure
```

Individually, when isolating a failure — with the output a healthy run prints:

| Command | Healthy output |
|---------|----------------|
| `composer test` | `OK (N tests, M assertions)` — unit tests for Engine are mandatory |
| `composer phpstan` | `[OK] No errors` — level 8, no baseline additions without a note |
| `composer cs-check` | no diff, exit 0 (`composer cs-fix` writes) |
| `composer deptrac` | `Violations: 0`, `Uncovered: 0` |
| `composer coverage` | unit suite, coverage scoped to `src/Engine` |

**Never claim done, fixed or passing without running the command in this session and
pasting its real output.** A command you did not run is reported as not run.

Other commands: `php bin/console ...` (Symfony CLI), `make up` / `make qa` (Docker).

## Standing rules

1. **Never edit files under `src/Engine` without adding or updating unit tests.** A diff
   touching `src/Engine/**` with no change under `tests/Unit/Engine/**` is incomplete.
2. Write the test first for Engine work (TDD); for other contexts, tests accompany the change.
3. Run `composer cs-fix` and `composer phpstan` after each implementation step, not just at the end.
4. Never weaken PHPStan level, deptrac rules, or cs-fix config to make a check pass.
5. Validate all external input in `src/Api` (DTO + constraints). The Engine trusts its inputs;
   the API must therefore never pass unvalidated data into it.
6. Database changes go through Doctrine migrations — never edit a committed migration.
7. Small commits, one logical step each. Don't mix refactor and behaviour change.
8. Follow the AI-native SDLC (`docs/sdlc/README.md`) for any non-trivial feature — `/sdlc`
   runs the whole chain from one task description. Each stage commits one artifact the
   next stage reads, in `docs/sdlc/<feature>/`: `intent.md` → `spec.md` → `plan.md` →
   verified diff → review findings. Nothing is implemented before an approved, committed
   `plan.md`.
9. Don't add dependencies without saying why in the plan document first.

## The AI-native SDLC

**`/sdlc <what you want built>`** is the entry point: it sizes the work, then drives every
stage below, stopping only at the three gates. Run a stage by hand with its own command
when you want just that stage.

| Stage | Command | Artifact | Skill |
|-------|---------|----------|-------|
| 1 Plan | `/intent` | `docs/sdlc/<feature>/intent.md` | `intent` |
| 2 Design | `/spec` | `docs/sdlc/<feature>/spec.md` | `spec` |
| 3 Build | `/plan` | `docs/sdlc/<feature>/plan.md` | `implementation-plan` |
| 4 Test | `/verify` | pasted gate output | `verification` |
| 5 Deploy | `/review` | PR findings | `REVIEW.md`, `pr-reviewer` agent |
| 6 Maintain | — | a new `intent.md` | not installed yet — see the README |

Policy skills load themselves when the work touches them: `engine-purity`,
`context-boundaries`, `api-input-validation`, `doctrine-migrations`. They are constraints
applied while writing, not a checklist for review.

Hooks in `.claude/hooks/` enforce what must hold without exception: writes to `vendor/`,
`var/` and `.env*` are denied, a committed migration cannot be edited, `tests/` is frozen
while `var/freeze-tests` exists, and PHP files are formatted on write.

`REVIEW.md` is the PR review policy. `docs/sdlc/evals.md` regression-tests this
configuration — add a case whenever it is corrected.

## Things Claude gets wrong

When Claude makes the same mistake twice, the correction goes here — not into a third
review comment. Keep each entry to one or two lines, and delete entries that go stale.

- *(nothing recorded yet)*

## CODEBASE NAVIGATION POLICY

- Never read an entire source file unless necessary.
- Prefer symbol/LSP/Serena navigation over filesystem exploration.
- Search in this order:
    1. symbol / definition / references
    2. semantic/codebase search
    3. Grep/Glob
    4. targeted Read with minimal line range
- Do not use Bash ls/find/cat for code discovery when dedicated tools exist.
- Do not inspect vendor/, generated/, var/, cache/ unless explicitly relevant.
- For files >300 lines, read only the relevant symbol or line range first.
- Return discovered paths, symbols and conclusions; do not dump source code.
- Do not ask the user questions unless progress is genuinely blocked.
- Make reasonable implementation assumptions and continue.