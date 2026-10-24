# Storyteller — Project Rules

Interactive choice-driven story platform. PHP 8.5 · Symfony 7.4 LTS · PostgreSQL · REST API ·
Vue 3 (CDN) frontend for the MVP.

## Architecture — modular monolith, bounded contexts

| Context       | Responsibility                                                              |
|---------------|-----------------------------------------------------------------------------|
| `src/Engine`  | Pure PHP rules: `GameState`, `ConditionEvaluator`, `EffectApplier`, `TransitionResolver`. Framework-free, deterministic, no I/O. |
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

```bash
composer test       # PHPUnit — unit tests for Engine are mandatory
composer phpstan    # PHPStan level 8, no baseline additions without a note
composer cs-fix     # php-cs-fixer, PSR-12
composer deptrac    # dependency rule check
```

Other commands: `php bin/console ...` (Symfony CLI), `docker compose up -d` (Postgres).

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
8. Follow the SDLC workflow (`/sdlc`) for any non-trivial feature: Analyze → Plan →
   Implement → Review → Test. Phase documents live in `docs/sdlc/<feature>/`.
   The orchestrator picks each phase's model per step — Engine, migrations,
   cross-context interfaces and security always run on the strongest tier.
9. Don't add dependencies without saying why in the plan document first.
