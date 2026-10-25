---
name: intent
description: "Stage 1 of the AI-native SDLC — capture an idea as a committed intent.md. Use when someone proposes a feature, change, improvement or fix and no docs/sdlc/<feature>/intent.md exists yet; when a production incident or review finding needs to re-enter the loop; or when the user runs /intent. Interrogate the idea first, then write the artifact."
---

# Stage 1 — Capture as `intent.md`

Turn a half-formed idea into a committed artifact that Stage 2 can read. The originator
does not need to be an engineer.

## Hard boundaries

- You write **one file**: `docs/sdlc/<feature>/intent.md`. Nothing else. No code, no
  schema, no file-by-file design — those are Stages 2 and 3.
- The intent describes the **problem and the outcome**, never the solution. If you catch
  yourself naming a class, a table or a file path, you have drifted into the spec.
- Do not invent a slug from a vague description. Propose one and confirm it.

## Procedure

1. **Get the slug.** Kebab-case, e.g. `condition-ast-has-flag`. If the user gave a
   description but no slug, propose one derived from it and confirm before writing.
2. **Interrogate the idea.** This is the point of the stage — ask the questions the
   originator has not thought about yet. Work through, at minimum:
   - Who feels this problem, and what do they do today instead?
   - What is observably true once it is done?
   - Which bounded contexts does it touch — `Engine`, `Content`, `Play`, `Api`, `Shared`?
   - Does it need the Engine to change? Engine changes carry the determinism and
     mandatory-unit-test rules; say so now, not in review.
   - Does it touch already-published story content? Published `story_version` snapshots
     are immutable.
   - What is explicitly *not* in scope?
   - How will we know it worked?

   Ask them in small batches, not all at once. Stop when the answers stop changing the
   artifact — usually two or three rounds.
3. **Separate the answerable from the open.** Anything you can decide with a stated
   assumption, decide, and state the assumption. Anything that needs a product or policy
   decision goes under **Open questions** with a named owner.
4. **Write** `docs/sdlc/<feature>/intent.md` using
   `docs/sdlc/templates/intent.md` as the structure. Create the directory first.
5. **Report** the path, the open questions, and what the product owner needs to decide.

## Definition of done

`docs/sdlc/<feature>/intent.md` exists, follows the template, names its open questions and
their owners, and contains no design. The product owner's review and the commit are the
approval — do not mark the status `approved` yourself.

## Next

Once committed, Stage 2 is triggered: `/spec <feature>`.
