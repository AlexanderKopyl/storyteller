---
description: "Stage 2 — turn a committed intent.md into a requirements and design spec.md"
argument-hint: "<feature-slug>"
---

Run the `spec` skill for feature: $ARGUMENTS

- If no slug was given, list the folders under `docs/sdlc/` that have an `intent.md` but no
  `spec.md`, and ask which one.
- If `docs/sdlc/<slug>/intent.md` does not exist, stop and run `/intent` first.

Before writing a single requirement, load the policy skills and apply them as constraints
on what you may specify — not as a checklist appended at the end:

- `engine-purity`
- `context-boundaries`
- `api-input-validation`
- `doctrine-migrations`

Then read the actual code the intent points at — real signatures, namespaces and AST node
shapes, by symbol navigation — and write `docs/sdlc/<slug>/spec.md` from
`docs/sdlc/templates/spec.md`.

Anything you cannot resolve under those policies goes under **Concerns for policy owners**
with a named owner and a decidable question. Say clearly in your report that the spec is
not committed while a concern is open.
