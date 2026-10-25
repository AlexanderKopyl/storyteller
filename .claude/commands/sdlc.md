---
description: "Build a task end-to-end: intent -> spec -> plan -> implement -> verify -> review"
argument-hint: "<what you want built>  [--auto]"
---

Run the `sdlc` skill for: $ARGUMENTS

- If `$ARGUMENTS` is empty, ask what to build. That is the one thing you cannot assume.
- Otherwise derive a kebab-case slug, state it, and start. Do not stop to have the slug
  approved.
- If `--auto` is present, run every stage without stopping at the gates, then present all
  the gate decisions together at the end, before anything is pushed. The Stage 5 review
  still runs.

Size the work first and say which size you chose and why — trivial, small or full. Then
follow the skill: the stages in order, the three gates, and the subagents for verification
and review.

Choose a model per **step**, not once per run, and name it with its one-line reason as you
go. Engine, migration, cross-context and security work never drops below `opus`; re-running
gates does not need it. When torn between two tiers, take the higher one.

Stop at each gate with the artifact path, a short summary and the decision you need.
Never push and never open a PR — report, and let the user decide.
