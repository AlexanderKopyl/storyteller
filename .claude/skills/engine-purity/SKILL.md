---
name: engine-purity
description: "Policy: src/Engine is framework-free, deterministic and always unit-tested. Use whenever reading, specifying, planning, writing or reviewing anything under src/Engine or tests/Unit/Engine — GameState, ConditionEvaluator, EffectApplier, NextSceneResolver, Transition, the AST nodes — or whenever a change involves randomness, time, the JSON condition/effect AST, or adding an AST operator."
---

# Policy — Engine purity and determinism

**Owner:** Engine maintainer. Changes to this skill need their sign-off.

The Engine is the one part of Storyteller that must be trivially reasonable about: pure
PHP rules, no I/O, no framework, and the same answer every time.

## The rule that is never bent

> **A diff touching `src/Engine/**` with no change under `tests/Unit/Engine/**` is
> incomplete.**

Not a guideline. In Stage 5 review it is a blocker. Write the test **first** — run
`composer test`, see it fail for the right reason, then implement.

## Framework-free

`deptrac.yaml` grants `Engine -> Shared` and nothing else. No `Framework` layer, no `Dev`
layer. Concretely, inside `src/Engine`:

- No Symfony, no Doctrine, no PSR packages, no HTTP types — not in a signature, not in an
  attribute, not in a docblock import.
- PHP built-ins **are** allowed (`DateTimeImmutable`, `JsonException`, `ArrayObject`…) —
  `ignore_uncovered_internal_classes` is on precisely so the Engine stays free to use them.
- A new `use` statement in the Engine that is not `Storyteller\Engine\`,
  `Storyteller\Shared\` or a PHP built-in is almost certainly a violation. Run
  `composer deptrac`.

## Deterministic

Same `GameState` + same input ⇒ same result. Byte-for-byte, every run, on every machine.

**Forbidden in `src/Engine`:**

| Banned | Use instead |
|--------|-------------|
| `rand()`, `mt_rand()`, `random_int()`, `shuffle()`, `array_rand()` | inject `Storyteller\Engine\Random\Rng` — in tests, `SeededRng` |
| `time()`, `microtime()`, `date()`, `new \DateTime()` | inject a clock; pass the instant in |
| `getenv()`, `$_ENV`, `$_SERVER`, `$_GET`, `php_uname()` | pass it as a parameter |
| `static` mutable properties, globals, singletons | per-call state, or a constructor dependency |
| `file_get_contents`, `fopen`, `curl_*`, any socket | the Engine does no I/O — the caller loads data |
| `spl_object_id`, `spl_object_hash`, identity-keyed maps | value equality on an explicit key |
| `uniqid()`, `random_bytes()`, `session_id()` | inject it |

Subtler ones that still break determinism:

- Iterating an unordered structure where the order reaches the output. Sort explicitly.
- `usort` with a comparator that returns `0` for distinct elements — PHP's sort is not
  stable across all shapes. Make the comparator total.
- Float accumulation whose order depends on hash order.
- Relying on `json_encode` key order of a dynamically built array.

## Adding an AST operator

An operator is a four-part change. All four, or it is not done:

1. **The node** — a class under `src/Engine/Ast/`, and the case in `ConditionOp` or
   `EffectOp`.
2. **The spec** — the node's shape documented in `docs/engine/ast.md`.
3. **The arm** — the branch in `ConditionEvaluator` or `EffectApplier`.
4. **The tests** — in `tests/Unit/Engine/`, covering: happy path, missing variable, wrong
   type, boundary values, malformed node shape (including a positional list where the
   format requires a map), unknown operator, and deep nesting.

Content is **data, not code**. An operator is interpreted, never `eval`'d, never compiled
to a closure that captures outside state.

## Errors

Throw the specific exception from `src/Engine/Exception/`:
`UnknownOperatorException`, `UnknownVariableException`, `TypeMismatchException`,
`MalformedNodeException`, `ChoiceUnavailableException`, `NoNextSceneException`,
`RedirectLoopException`, `UnknownSceneException`. All extend `EngineException`.

The Engine **trusts its inputs** — the API validates before calling in (see
`api-input-validation`). But malformed *story content* is an expected condition, not a
trusted one: throw `MalformedNodeException`, never let a `TypeError` escape.

Guard any recursion over user-authored AST depth with an explicit limit. Unbounded
recursion on authored content is a denial-of-service path, and a review blocker.

## Checks

```bash
composer test                 # unit tests for the Engine are mandatory
composer deptrac              # Engine -> Shared only
composer coverage             # line coverage scoped to src/Engine
```
