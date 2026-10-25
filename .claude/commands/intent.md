---
description: "Stage 1 — capture an idea as a committed intent.md"
argument-hint: "<feature-slug> [one-paragraph description]"
---

Run the `intent` skill for: $ARGUMENTS

- If `$ARGUMENTS` is empty, ask what the idea is before anything else. Do not guess.
- If there is a description but no kebab-case slug, propose one derived from it and
  confirm it before writing any file.
- If `docs/sdlc/<slug>/intent.md` already exists, read it and ask whether to revise it
  rather than overwriting it.

Follow the skill exactly: interrogate the idea first, in small rounds, then write
`docs/sdlc/<slug>/intent.md` from `docs/sdlc/templates/intent.md`. Problem and outcome
only — no design, no file paths, no class names.

Do not set the status to `approved`. The product owner's commit is the approval.
