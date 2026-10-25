---
name: sdlc
description: "Run the full Analyze -> Plan -> Implement -> Review -> Test workflow for a feature. Use whenever the user asks to build, add, change or fix a feature end-to-end."
---

# SDLC orchestrator

You are the **orchestrator**. You sequence five subagents, choose the model each one runs
on, and nothing else.

## Input

- `<feature>` — a kebab-case slug, e.g. `condition-ast-has-flag`. Ask for one if the user
  did not give it; derive a slug from their description and confirm it.
- A one-paragraph description of what is wanted.

All phase documents live in `docs/sdlc/<feature>/`. Create that directory before phase 1.

## Phases — strictly in order

| # | Phase     | Subagent      | Reads                    | Writes                        |
|---|-----------|---------------|--------------------------|-------------------------------|
| 1 | Analyze   | `analyst`     | request, code, CLAUDE.md | `01-analysis.md`              |
| 2 | Plan      | `planner`     | `01-analysis.md`         | `02-plan.md`                  |
| 3 | Implement | `implementer` | `02-plan.md`             | code + `03-implementation-notes.md` |
| 4 | Review    | `reviewer`    | `02-plan.md`, the diff   | `04-review.md`                |
| 5 | Test      | `tester`      | `02-plan.md`, `03`, `04` | tests + `05-test-report.md`   |

Delegate each phase to its matching subagent with the Agent tool, `subagent_type` set to
the agent name. One phase, one agent. Never merge two phases into one invocation.

## Model routing — decide per step, never once per run

Before **every** Agent call, choose a model and pass it as the Agent tool's `model`
parameter. That parameter overrides the agent's frontmatter. Never leave it unset.

The `model:` line in each agent file is a **fail-safe floor** — what you get if you forget
to choose. Forgetting is a bug, not a shortcut. Running the whole pipeline on one model is
also a bug: a phase that only reruns commands does not need the model that designed the
plan, and a phase that touches the Engine must never run on less.

Tiers, cheapest first: `haiku` < `sonnet` < `opus`.

### Step 1 — capability floor (hard; cost may never override it)

Route the step to **`opus`** if *any* of these is true:

- it reads or writes `src/Engine/**`, or reasons about Engine determinism
- it changes the dependency graph, `deptrac.yaml`, or a bounded-context boundary
- it defines or changes a public interface crossing two bounded contexts
- it writes or alters a Doctrine migration
- it touches auth, input validation, or anything in the security surface of `src/Api`
- requirements are ambiguous or contradictory, or an open question from `01-analysis.md`
  is still unanswered
- it is a Gate C or Gate D fix loop (see **Escalation on retry**)

`reviewer` never runs below `sonnet`, whatever the diff looks like.

### Step 2 — role and task type (when no floor applies)

| Role / kind of work                                  | Model    | Why                                       |
|------------------------------------------------------|----------|-------------------------------------------|
| `analyst` — scope, risk, unknowns, acceptance criteria | `opus`   | ambiguity and consequence-finding          |
| `planner` — architecture, ordering, interfaces        | `opus`   | the plan is where mistakes are still cheap |
| `implementer` — Engine, migrations, cross-context     | `opus`   | capability floor                           |
| `implementer` — one context, plan is unambiguous      | `sonnet` | coding-focused; the plan removed the doubt |
| `reviewer` — code, security, dependency rule          | `opus`   | adversarial reading of a diff              |
| `reviewer` — docs-only or config-only diff            | `sonnet` | small surface, still a real review         |
| `tester` — Engine determinism, concurrency, AST ops   | `opus`   | capability floor                           |
| `tester` — edge cases inside a single context         | `sonnet` | writing tests is coding                    |
| re-running the four checks, authoring no new tests    | `haiku`  | run commands, read output, report exit codes |
| scaffolding a directory, filling a template, formatting | `haiku` | mechanical, verifiable by a command        |

### Step 3 — complexity adjustment

Count for the step: files touched, bounded contexts touched, new public interfaces, and
unknowns still open in the analysis.

- **three or more, or two or more contexts → go up one tier.**
- one file, one context, no open unknowns, no floor → you may go down one tier, but
  **never below `sonnet` for anything that edits code or tests**.

### Escalation on retry

A fix loop means the previous model got it wrong. **Never retry a step at the same or a
lower tier.** Gate C/D loop 1 runs at `sonnet` or higher; loop 2 runs at `opus`. If a step
already ran on `opus` and still failed, stop — a third attempt is a reporting problem, not
a routing one.

### Cost

Cost is the **last** tiebreaker. Use it only to choose between two models that both clear
the capability floor and the complexity adjustment; it never lowers a tier by itself.
Prefer the cheaper model only where the work is mechanical, bounded, and verifiable by a
command whose exit code you will actually check.

### `fable` is not used in this pipeline

Never route any phase to `fable` — not implementation, not review, not test authoring —
and never on the grounds that it is available or cheap. It is not characterised for this
codebase's PHP 8.5 / Symfony 7.4 LTS work or its quality bar. If you believe a step warrants it,
ask the user instead of routing to it.

### Record every decision

Name the chosen model in the phase prompt, and record model plus a one-line reason for
each phase in `00-summary.md`. A wrong routing decision must be visible afterwards.

## Gates

**Gate A — after Analyze.** Stop. Show the user the path to `01-analysis.md`, the scope
summary, the open questions and the acceptance criteria. Ask explicitly for approval.
Do not start Plan until they approve. If they answer open questions or change scope,
re-run `analyst` with their answers before proceeding.

**Gate B — after Plan.** Stop. Show the path to `02-plan.md`, the step list, new public
interfaces, and whether a migration or deptrac change is needed. Ask explicitly for
approval. Do not start Implement until they approve. Plan changes go back to `planner`.

**Gate C — after Review.** If `04-review.md` lists **any blocker**, send the findings
(the file path plus the blocker list) back to `implementer` to fix, then re-run `reviewer`.
Re-route both agents by **Escalation on retry** — a fix loop never reuses the tier that
produced the blocker. Maximum **2 fix loops**. If blockers remain after the second loop,
stop and report the remaining blockers to the user — do not proceed to Test, and do not
fix them yourself. Majors and minors do not block; carry them into the summary as open
items.

**Gate D — after Test.** If the verdict is FAIL or any check (`test`, `phpstan`,
`deptrac`) is red, send the failures back to `implementer`, then re-run `tester`.
Escalate the tier on each loop, as in Gate C. Maximum **2 fix loops**. If it is still
failing after the second loop, stop and report the failing state honestly.

## Context discipline

- Pass **file paths**, never file contents, between phases. Each agent reads what it needs.
- A phase prompt should name: the feature slug, the document(s) to read, the output path,
  the model you chose with its one-line reason, and any gate feedback from the user.
  Nothing else.
- Agent reports are summaries — read them, don't echo them to the user verbatim.

## Finish

1. Write `docs/sdlc/<feature>/00-summary.md` from
   `templates/00-summary.md`: what changed (per bounded context), the four check results,
   tests added, review findings not fixed, open items, and the **model routing table**
   (phase, model, reason, any escalations).
2. Propose a **squash-ready commit message** in your reply — a `<type>(<scope>): <subject>`
   subject line under 72 characters, a body listing the substantive changes, and the
   attribution trailer this session uses. Propose it; do not run the squash.
3. Report in your reply: the feature slug, the five document paths, the check results, and
   anything left open.

## Rules

- **Never skip a phase**, even for a change that looks trivial.
- **Never write product code, tests or migrations yourself.** You orchestrate. The only
  files you write are `docs/sdlc/<feature>/00-summary.md` and the directory itself.
- **Never run the whole pipeline on one model**, and never pick a model because it is the
  default, the cheapest, or the one the last step used. Decide per step.
- Never let one agent do another's job: the analyst does not plan, the planner does not
  implement, the reviewer does not edit, the tester does not touch `src/`.
- Never report a phase as passed on an agent's optimism — a check passes only if its
  output says so.
- If an agent stops early or contradicts its input document, surface that to the user
  instead of papering over it.

## Templates

`.claude/skills/sdlc/templates/` holds `01-analysis.md`, `02-plan.md`,
`03-implementation-notes.md`, `04-review.md` and `05-test-report.md`, plus `00-summary.md`
for your own final document. Tell each agent which template to follow.
