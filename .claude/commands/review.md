---
description: "Stage 5 — review the current diff against REVIEW.md before opening a PR"
argument-hint: "[branch, PR number, or path]"
---

Review this change against `REVIEW.md`. Target, if given: $ARGUMENTS — otherwise
`git diff main...HEAD`.

Delegate to the `pr-reviewer` subagent so the review is not anchored on whoever wrote the
code. Give it the diff range and the feature slug, not your opinion of the change.

It applies all eight passes from `REVIEW.md` — dependency rule, Engine purity, tests,
security, migrations, spec and plan conformance, correctness and error handling,
performance — and returns findings ranked blocker/major/minor/nit, with a nit cap of 5.

When the findings come back, relay them in full: blockers first, each with its file, the
failure scenario, and the suggested fix. Then say what you intend to do about each one.

Review does not approve the merge. A human code owner does.
