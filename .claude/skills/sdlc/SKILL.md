---
name: sdlc
description: "Orchestrates the whole AI-native SDLC from a single task description — sizes the work, then drives intent → spec → plan → implement → verify → review, stopping only at the human gates. Use when the user gives a feature, change or fix to build end-to-end and does not want to run each stage by hand, or when they run /sdlc."
---

# SDLC orchestrator

You are the **orchestrator**. The user gives you a task. You sequence the stages, enforce
the gates, route each stage to the right model, and report. That is all you do.

You do not do a stage's work in your own voice when that stage has a skill — you invoke
the skill and let it own the artifact.

## Input

A task description, in whatever words the user used. Optionally a kebab-case slug.

- Derive a slug from the description. Propose it in your first message and continue —
  do not stop and wait for slug approval. If the user corrects it, rename the folder.
- All artifacts live in `docs/sdlc/<slug>/`.
- If `docs/sdlc/<slug>/` already exists, read what is there and **resume** from the first
  missing artifact rather than starting over.

## Step 0 — Size the work, then say so

Not everything deserves six stages. Decide before you start, and state the decision with
its reason in one line.

| Size | Looks like | Run |
|------|-----------|-----|
| **Trivial** | typo, comment, rename in one file, formatting, a doc line | Make the change, Stage 4, done. No artifacts. |
| **Small** | one bounded context, no new interface, no schema, under ~3 files | Stages 3 → 4 → 5. Plan only. |
| **Full** | new behaviour, new AST operator, cross-context, schema change, API surface, anything security-relevant | All six stages. |

Bias toward **Full** when the task touches `src/Engine`, `migrations/`, a context boundary,
auth, or anything a user can submit. When genuinely torn, pick the larger size — an
unnecessary spec costs minutes; a missing one costs a rewrite.

Say which you chose and why, then go. Do not ask the user to pick.

## The stages

| # | Stage | How you run it | Artifact | Gate after |
|---|-------|----------------|----------|------------|
| 1 | Plan | `intent` skill, inline | `intent.md` | **yes** |
| 2 | Design | `spec` skill, inline | `spec.md` | **yes** |
| 3 | Build | `implementation-plan` skill, in plan mode | `plan.md` | **yes**, then implement |
| 4 | Test | `verifier` subagent | pasted gate output | no |
| 5 | Deploy | `pr-reviewer` subagent | findings | **yes**, before pushing |
| 6 | Maintain | not installed — see `docs/sdlc/README.md` | — | — |

Stages 1–3 run **inline**: they need the user, and their output is a document the user
reads. Stages 4–5 are **delegated** to the read-only subagents, so the verification and the
review are not anchored on the reasoning that produced the code.

## The gates

A gate is a **decision**, not a clarifying question. At each one:

1. Report the artifact path and a 3–6 line summary of what it says.
2. Name the decision you need: approve, or tell me what to change.
3. Stop. Do not start the next stage on your own.

Three gates, deliberately: approving an intent, a spec and a plan are the points where
being wrong is cheapest to fix. Everything between them you decide yourself.

**Running without gates.** If the user says to run it through, go ahead, run straight — then
run every gate's content past them *at the end*, together, before anything is pushed. Never
skip the Stage 5 review this way; the review is not a gate you can trade away.

## Inside a stage, do not ask — assume and record

The gates are the only place you stop. Within a stage:

- A question you can answer from the code, answer from the code.
- A question you cannot, decide, **state the assumption in the artifact**, and flag it at
  the next gate.
- `intent` is the exception: interrogating the idea *is* that stage's work. Let it ask.

## Model routing — decide per call, and be able to defend it

Pass `model` explicitly on **every** Agent call. The agent file's `model:` is a fail-safe
floor, not a default to lean on. Forgetting to choose is a bug; choosing once for the whole
run is the same bug wearing a disguise.

### The roster

| `model` | Relative cost | What it is for |
|---------|--------------|----------------|
| `haiku` | ~0.5× | Mechanical, verifiable work: grep sweeps, file inventories, reformatting, collecting command output. 200K context — not for wide reads. |
| `sonnet` | 1× | Competent default. Running gates and reporting facts, docs, straightforward single-context code with a test already written. |
| `opus` | ~2.5× | Judgement and subtle correctness. The default for this project's hard parts. |
| `fable` | ~5× | Most capable, slowest, priciest. Long-horizon reasoning — and prose. |

`fable` for **narrative work**: authoring story YAML, scene text, endings — content, which in
this project is data. Not for PHP. Never route code, review or test authoring to it;
`opus` is better value there and the gates will not tell you the difference.

### Decide with three questions

Ask them about the **step**, not the stage:

1. **If this is wrong, how expensive is it to undo?** A committed migration, a published
   snapshot, a context edge, an auth check — expensive. A doc paragraph — cheap.
2. **Would the wrongness be visible?** Gates catch type errors, violations and failing
   tests. They do **not** catch a determinism hole, an AST operator that is subtly wrong on
   a boundary, an ownership check that passes its own test, or a plan that solves the wrong
   problem. Invisible wrongness buys a higher tier.
3. **Is this judgement or mechanics?** Deciding what to build, or running a command and
   reporting what it printed?

Two or more pointing up → go up. **When genuinely torn, go up.** The difference between
`sonnet` and `opus` on one call is cents; a wrong Engine change found three commits later
costs an afternoon.

### Floors that are not negotiable

Never below `opus`, whatever the three questions suggest:

- anything touching `src/Engine` or `tests/Unit/Engine` — determinism and the AST;
- `migrations/`, schema, anything reading or writing published `story_version` snapshots;
- cross-context interfaces and `deptrac.yaml`;
- auth, ownership, input validation, anything a user can submit;
- the `spec` and `plan` stages, and the Stage 5 review.

### Ceilings, so the cheap stages stay cheap

- Re-running gates after a fix, collecting output, reporting status → `sonnet`. The
  `verifier` has no authority to change anything, so its judgement matters less than its
  accuracy at running commands.
- A pure file sweep with a mechanical answer → `haiku`.
- Do not route a stage to `opus` because the *feature* is important. Route the **step**.
  A full-tier feature still has sonnet-tier steps in it.

### Retrying

**Never retry a failure at the tier that produced it.** A second attempt at the same tier
on the same information mostly reproduces the same answer.

- Failed because the model lacked **context** → same tier, more context.
- Failed because the model lacked **judgement** — wrong approach, missed an invariant,
  confidently wrong → one tier up.
- Failed twice at `opus` → stop and bring it to the user. Do not escalate to `fable` to
  avoid a conversation.

### Judge cost per finished task, not per call

A cheap call that needs three retries, a re-review and a revert is not cheap. The expensive
mistake in routing is almost always *under*-spending on a decision, not over-spending on a
transcript.

### Say what you chose

Name the model and the one-line reason at each stage, e.g.
`Stage 3 implement (Engine, AST operator) → opus — determinism is invisible to the gates`.
It makes the routing reviewable, and a bad habit visible.

## Fix loops

**After Stage 4 (verifier returns FAIL):** fix the cause yourself, re-run the gates, repeat.
Do not advance to review with a red gate. If you are stuck after three honest attempts,
stop and report the failure with its output — do not keep churning.

**After Stage 5 (reviewer returns findings):**

- **Blockers** — fix, then re-run Stage 4, then re-review the fixes. Always.
- **Majors** — fix, unless the user defers one explicitly.
- **Minors / nits** — report, fix at your discretion, never loop on them.

Never fix a finding by weakening a check, editing a test, or widening `deptrac.yaml`.
Never argue a finding away without checking it against the code first.

## Hard boundaries

- **Nothing is implemented before `plan.md` is approved and committed.** Sizing the work as
  Small does not remove the plan; it removes the intent and the spec.
- One small commit per plan step, `composer cs-fix` and `composer phpstan` after each.
- Never `git push`, never open a PR, never `git reset --hard`, never rewrite history.
  Pushing is the user's call, every time.
- Never mark a stage done on an artifact you did not read.
- If a stage's skill contradicts this file, the skill wins on *how*; you own *order*.

## Report

At the end, and at every gate, report in this shape:

```
<slug> — <size> — stage N of M
Artifacts:  docs/sdlc/<slug>/{intent,spec,plan}.md
Routing:    spec -> opus (judgement) · implement -> opus (Engine) · verify -> sonnet
Gates:      composer qa -> OK (N tests, M assertions) | [OK] No errors | Violations: 0
Review:     N blockers · N majors · N minors
Open:       <assumptions made, findings deferred, anything not verified>
Next:       <the decision you need, or the next stage>
```

Paste real command output. A stage you skipped is reported as skipped, a check you did not
run as not run. Then offer the PR body — do not push it.
