# The AI-native SDLC — Storyteller

Adapted from Anthropic's [AI-native SDLC playbook][playbook]. Six stages, each committing
one artifact the next stage reads. The chain — intent, spec, plan, diff, review findings —
*is* the audit trail.

[playbook]: https://claude.com/resources/articles/the-ai-native-sdlc-playbook

```
  ┌──────────────────────────────────────────────────────────────────┐
  │                                                                  │
  ▼                                                                  │
Plan ──▶ Design ──▶ Build ──▶ Test ──▶ Deploy ──▶ Maintain ──────────┘
intent.md  spec.md   plan.md   green    merged     breach writes a
                               gates    PR         new intent.md
```

## The artifact chain

| Stage | Artifact | Lives at | Owner | Triggers |
|-------|----------|----------|-------|----------|
| 1 Plan | `intent.md` | `docs/sdlc/<feature>/intent.md` | originator → product owner | Stage 2 |
| 2 Design | `spec.md` | `docs/sdlc/<feature>/spec.md` | product owner | Stage 3 |
| 3 Build | `plan.md` | `docs/sdlc/<feature>/plan.md` | engineer | implementation |
| 4 Test | green gates + evidence | PR body / session transcript | engineer | Stage 5 |
| 5 Deploy | review findings, approved PR | GitHub PR | code owner | deploy |
| 6 Maintain | new `intent.md` | `docs/sdlc/<feature>/intent.md` | service owner | Stage 1 |

A stage does not start until the previous stage's artifact is committed. Committing is the
trigger — not a meeting, not a ticket transition.

`<feature>` is a kebab-case slug, e.g. `condition-ast-has-flag`. One folder per feature.

## Running it

```
/sdlc <what you want built>          # the whole chain from one task description
/sdlc <task> --auto                  # no gates; every decision presented at the end
```

The `sdlc` orchestrator sizes the work first — **trivial** (just do it and verify),
**small** (plan, build, verify, review) or **full** (all six stages) — then sequences the
stages and stops at three gates: approve the intent, approve the spec, approve the plan.
Everything between those, it decides and records.

Work touching `src/Engine`, `migrations/`, a context boundary or anything security-relevant
is sized **full**.

### Model routing

The orchestrator chooses a model per **step** and names it with a one-line reason, so the
choice is reviewable. It decides by asking how expensive the step is to undo, whether the
gates would even catch it being wrong, and whether it is judgement or mechanics — and when
torn, it goes up a tier.

| | |
|---|---|
| Never below `opus` | `src/Engine`, migrations and published snapshots, context boundaries, auth and validation, the spec, the plan, the review |
| `sonnet` | re-running gates, collecting output, docs, straightforward single-context code |
| `haiku` | mechanical sweeps with a verifiable answer |
| `fable` | narrative content only — story YAML, scene text. Never code, review or tests. |

A failure caused by missing judgement is retried **one tier up**, never at the tier that
produced it; two failures at `opus` stop and come to you rather than escalating to `fable`.

Each stage also has its own command, for when you want only that stage: `/intent`, `/spec`,
`/plan`, `/verify`, `/review`.

> **Historical note.** `docs/sdlc/project-skeleton/` and `docs/sdlc/engine-core/` use an
> older five-document format (`01-analysis.md` … `05-test-report.md`). They are kept as a
> record. New work uses the three-artifact chain above.

## Stage 1 — Plan: capture as `intent.md`

Anyone with an idea brainstorms it with Claude and lands a committed artifact. No committee
in between.

```
/intent <feature-slug>
```

Claude interrogates the idea, then drafts `docs/sdlc/<feature>/intent.md` from
[`templates/intent.md`](templates/intent.md): problem, proposed outcome, affected systems,
constraints, open questions. The product owner reviews, corrects, and commits it.

- **Governance** — author, timestamp and revision history come from git. Product-owner
  approval is recorded as the merge of the commit that adds the file.
- **Measures** — time from idea to committed `intent.md`; share of committed intents that
  survive to a merged change.

## Stage 2 — Design: `intent.md` → `spec.md`

Requirements and design happen in one prompted session, with the project's policy skills
loaded as *constraints while writing*, not as findings in review.

```
/spec <feature-slug>
```

Claude reads the committed `intent.md`, applies the policy skills
([`engine-purity`](../../.claude/skills/engine-purity/SKILL.md),
[`context-boundaries`](../../.claude/skills/context-boundaries/SKILL.md),
[`api-input-validation`](../../.claude/skills/api-input-validation/SKILL.md),
[`doctrine-migrations`](../../.claude/skills/doctrine-migrations/SKILL.md)) and writes
`spec.md` from [`templates/spec.md`](templates/spec.md), flagging anything it cannot
resolve under **Concerns for policy owners**.

Flagged concerns are resolved with the policy owner *before* the spec is committed. The
committed spec is the sign-off.

- **Measures** — elapsed time `intent.md` → `spec.md`; count of requirements reworked after
  build starts.

## Stage 3 — Build: plan mode, then one pass

Work starts from a written plan that was reviewed before any file changed.

```
/plan <feature-slug>     # then press shift+tab twice to enter plan mode
```

In plan mode Claude cannot edit files. It reads `intent.md` and `spec.md` and produces the
plan: files that change, work order, risks, proof. The engineer interrogates and iterates;
the approved plan is committed as `plan.md` from [`templates/plan.md`](templates/plan.md);
implementation then usually runs in one pass.

Three things carry institutional knowledge into every session:

- **`CLAUDE.md`** — the codebase memory, read at session start. When Claude makes the same
  mistake twice, the correction goes into its *Things Claude gets wrong* section.
- **Skills** (`.claude/skills/`) — one policy, one named owner, one versioned folder. The
  policy applies while the code is written rather than being discovered in review.
- **Hooks** (`.claude/hooks/`, wired in `.claude/settings.json`) — deterministic
  enforcement for the policies that must hold without exception. See [Hooks](#hooks) below.

For independent tasks, run parallel sessions on separate worktrees
(`claude --worktree <name>`); two or three is a sensible ceiling. The repo's hooks and
permissions apply to every session.

- **Measures** — share of first-pass implementations that merge; plan approval → merged PR;
  how closely the merged diff matches the committed plan.

## Stage 4 — Test: give Claude a feedback loop

The session verifies its own work before a human sees it.

One command is the loop: **`composer qa`** — `cs-check`, `phpstan`, `deptrac`, `test`, in
that order, non-zero on any failure. State a quantifiable target, let Claude iterate until
it passes, and paste the output.

```
/verify
```

For a bug, the failing test is written **first** and must pre-exist the fix. While a fix
loop is running, freeze the test files so the fix cannot be made by editing the test:

```bash
touch var/freeze-tests     # the freeze-tests hook now blocks every edit under tests/
rm -f var/freeze-tests     # thaw, once the fix is in and the gates are green
```

Evidence — raw command output — goes in the task summary and the PR body. "Done" without
pasted output is not done. See [`evals.md`](evals.md) for the regression suite that keeps
`CLAUDE.md`, the skills and the hooks themselves honest when they change.

- **Measures** — first-pass CI success rate for agent-written changes; review time per PR;
  change failure rate.

## Stage 5 — Deploy: review, gates, pipeline

Every PR gets the same review passes, ranked by severity, before a human reads it.
[`REVIEW.md`](../../REVIEW.md) at the repo root is the review policy: the passes, the
severity definitions, the nit cap, the exclusions.

- **In CI** — `.github/workflows/claude-review.yml` runs the review on every PR. It is a
  no-op until the `ANTHROPIC_API_KEY` secret is set.
- **Locally, before pushing** — `/review` runs the same policy against your diff.
- **Quality gates** — `.github/workflows/qa.yml` runs `composer qa` on every push and PR.

Review findings feed back: when a review flags the same mistake twice, the correction goes
into `CLAUDE.md`, not into another review comment.

Claude never approves its own code. Code-owner approval is required to merge; branch
protection turns agent writes into PRs.

- **Measures** — time to first review (target: minutes); defects caught pre-merge vs.
  post-merge.

## Stage 6 — Maintain: closing the loop

**Not installed yet — it has nothing to attach to.** Storyteller has no deployed
environment and no production metrics, so there is no baseline to detect a breach against.

When there is, install it in this order:

1. Pick one production metric with a stable baseline.
2. Write a **deterministic** detection script — mean and standard deviation over a rolling
   window, Western Electric rules to catch slow drift. No model involved in detection.
3. Commit `monitoring/bands.yaml` with the response tiers: 1σ log only, 2σ Claude diagnoses
   read-only, 3σ Claude proposes a PR or triggers a pre-approved runbook.
4. On a breach, Claude runs non-interactively and writes its diagnosis as an `intent.md` in
   the Stage 1 format. The service owner triages the queue. The loop closes.
5. Every incident gets an eval in [`evals.md`](evals.md), permanently.

- **Measures** — breach → `intent.md` in the triage queue; share of findings that become
  merged fixes; repeat incident rate by class.

## Hooks

Wired in `.claude/settings.json`, scripts in `.claude/hooks/`.

| Hook | Event | What it does |
|------|-------|--------------|
| `format-php.sh` | PostToolUse `Edit\|Write` | Runs php-cs-fixer on the one file just written. Keeps `cs-check` green without a trip through the gates. |
| `protect-paths.sh` | PreToolUse `Edit\|Write` | Denies writes to `vendor/`, `var/`, `.env*`, and to any migration already committed (CLAUDE.md rule 6). |
| `freeze-tests.sh` | PreToolUse `Edit\|Write` | While `var/freeze-tests` exists, denies edits under `tests/` so a fix loop cannot "pass" by rewriting the test. |

Build-stage hooks are fast, scoped to the changed file, and never ask a human — an approval
that pauses the build belongs at the Stage 5 gate, not on the critical path.

## Roles

| Role | Owns |
|------|------|
| Originator | `intent.md` — anyone with an idea |
| Product owner | `spec.md`, intent approval |
| Engineer | `plan.md`, the diff, the verification evidence |
| Policy owner | one skill each; signs off changes to it |
| Code owner | PR approval, merge |
| Service owner | Stage 6 triage queue (when Stage 6 exists) |

## What to run by hand first

The playbook's own sequencing: run a play by hand, codify it as a command, make artifact
acceptance the trigger, then move it into CI. The two plays with no prerequisites are
**Stage 1 (`intent.md`)** and **Stage 4 (the feedback loop)** — start there.
