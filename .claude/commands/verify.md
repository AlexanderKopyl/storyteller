---
description: "Stage 4 — run the quality gates and report the real output as evidence"
argument-hint: "[what passing means]"
---

Run the `verification` skill. Target, if the user gave one: $ARGUMENTS

Run the loop:

```bash
composer qa      # cs-check -> phpstan -> deptrac -> test
```

If it fails, isolate with the individual commands, fix the cause, and run it again. Iterate
until it passes or until you are genuinely stuck — then report the failure with its output
rather than a description of it.

Then check the rules the gates do not cover:

- a diff touching `src/Engine/**` must also touch `tests/Unit/Engine/**`;
- nothing in the Engine reads the environment — no `rand()`, `time()`, `getenv()`,
  superglobals, statics or I/O;
- no committed migration was edited;
- `phpstan.neon`, `deptrac.yaml` and `.php-cs-fixer.dist.php` were not weakened;
- no test was edited to make a fix pass.

**Paste the real output.** Report any command you did not run as not run. Do not claim
passing, fixed or done without output in this session that shows it.

For an independent read, delegate to the `verifier` subagent instead — it runs the gates
and reports without being able to fix anything.
