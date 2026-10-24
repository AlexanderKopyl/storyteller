---
description: Run the full Analyze -> Plan -> Implement -> Review -> Test workflow for a feature
argument-hint: <feature-slug> <description>
---

Run the `sdlc` skill for feature: $ARGUMENTS

Instructions:

- If `$ARGUMENTS` is empty or contains no usable feature name, **ask the user for a
  kebab-case feature name and a one-paragraph description before doing anything else.**
  Do not invent a slug and proceed.
- If a description was given but no slug, propose a kebab-case slug derived from it and
  confirm it with the user.
- The skill chooses a model per phase and per step automatically; do not pin one
  model for the whole run, and do not route coding, review or test authoring to
  `fable`.
- Then invoke the `sdlc` skill and follow it exactly: all five phases in order, the
  approval gates after Analyze and Plan, and the fix loops after Review and Test.
