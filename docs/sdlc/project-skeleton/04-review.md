# 04 — Review: project-skeleton

- **Feature slug:** `project-skeleton`
- **Reviewed against:** `docs/sdlc/project-skeleton/02-plan.md`, `docs/sdlc/project-skeleton/01-analysis.md`, `docs/sdlc/project-skeleton/03-implementation-notes.md` and `CLAUDE.md`
- **Diff reviewed:** the entire tracked tree — `b31155c..c01a311`, 26 commits (the repository is created by this feature)
- **Date:** 2026-10-24
- **Reviewer model:** `opus` (mandatory tier: the diff defines the cross-context dependency contract, the container surface and the project's security posture)

## Gate results observed by the reviewer (host, PHP 8.5.3)

| Command | Exit code | Observed |
|---|---|---|
| `composer qa` | **0** | cs-check 0/8 · phpstan `[OK] No errors` (7 files) · deptrac `Violations 0 / Uncovered 0 / Allowed 0` (1 file) · phpunit `OK (4 tests, 19 assertions)` |
| `composer cs-check` | **0** | |
| `composer phpstan` | **0** | |
| `composer deptrac` | **0** | |
| `composer test` | **0** | |
| `composer validate --strict` | **0** | |
| `git status --porcelain` before and after `composer qa` | **empty** | criterion 11 holds |

Docker was reachable but the container cycle was not re-run; the orchestrator's `make qa` → 0
and `doctrine:migrations:status` → 0 are taken as given.

## Verdict

**blockers: 2 · majors: 4 · minors: 10**

The skeleton is well built, unusually well evidenced, and all four gates genuinely exit 0 with a
clean tree. It is mergeable once the two blockers are fixed; neither is large (one is a list in
`deptrac.yaml` plus a CLI flag, the other is a single line in `compose.yaml`). Both blockers are
the same species of problem and the reason they are blockers rather than majors: this is the
foundation every later context inherits, and each one is a *silent* failure — a rule that reads
as enforced in the documents but does not fire in the tooling. B1 means `use
Symfony\Component\Routing\…` inside `src/Engine` passes `composer deptrac`; B2 means the
`.env.local` secret-override mechanism that `.env` and the README both advertise is inert in the
container, which is the only supported way to run the application. Everything else can be
scheduled.

On the question the brief weighted most heavily — which gates are decorative — the honest answer
is **deptrac and PHPStan are currently decorative, cs-fixer and PHPUnit are not**. See
§"Are the gates green by vacancy?".

## Findings

### Blockers

#### B1 — The `Framework` layer covers 26 of the 59 installed framework packages, so Engine/Shared purity is not actually enforced for the other 33
- **File:** `deptrac.yaml:43-69` (the `packages:` list); `composer.json:58-70` (the `deptrac` script); `composer.json:13-31` (the packages added by C-1)
- **Category:** dependency rule
- **Problem:** `ComposerCollector` resolves **only** the namespaces of the packages named in the
  list — it does not follow requirements
  (`vendor/deptrac/deptrac/src/DefaultBehavior/Layer/Helpers/ComposerFilesParser.php::autoloadableNamespacesForRequirements()`
  loops over `$requirements` and nothing else). I enumerated `composer.lock` against the list:
  **33 installed `symfony/*`, `doctrine/*`, `psr/*` packages are outside the `Framework` layer**,
  including `Symfony\Component\Routing\`, `Symfony\Component\String\`, `Symfony\Component\Cache\`,
  `Symfony\Component\VarExporter\`, `Symfony\Component\ErrorHandler\`,
  `Symfony\Component\OptionsResolver\`, `Symfony\Component\Process\`, `Symfony\Bridge\Doctrine\`,
  `Symfony\Bridge\Monolog\`, all four `Symfony\Contracts\*` namespaces (Service, EventDispatcher,
  Translation, Cache), `Doctrine\Common\` (doctrine/event-manager), `Doctrine\Common\Lexer\`,
  `Doctrine\Inflector\`, `Doctrine\Deprecations\`, `Psr\Cache\` and `Psr\EventDispatcher\`.
  Nine of those were promoted to explicit `require` entries by correction **C-1** — `error-handler`,
  `monolog-bridge`, `options-resolver`, `process`, `routing`, `stopwatch`, `string`, `var-dumper`,
  `var-exporter` — and `deptrac.yaml`, written afterwards at step 9 from the plan's pre-C-1 verbatim
  list, was never reconciled with them. A token in no layer is reported as *Uncovered*, not as a
  violation, and the `deptrac` composer script does not pass `--fail-on-uncovered` (the flag exists
  in deptrac 4.7.2; I confirmed it in `vendor/bin/deptrac analyse --help`), so `composer deptrac`
  exits **0** on all of them.
- **Why it matters:** `deptrac.yaml` is the single artifact this feature exists to produce, and
  CLAUDE.md states the rule absolutely: "Symfony, Doctrine and HTTP types must never appear inside
  `src/Engine`". For `src/Engine` there is a partial backstop —
  `tests/Unit/Shared/BoundedContextLayoutTest.php:49-53` greps for `^use Symfony\`, `^use Doctrine\`,
  `^use Psr\` — so an Engine breach via a `use` statement is still caught. **`src/Shared` has no
  backstop at all.** P3's whole purpose is to keep the framework out of `Shared` as well (because
  `Engine -> Shared`, a contaminated `Shared` re-contaminates `Engine`), and a
  `use Symfony\Component\String\UnicodeString;` in `src/Shared` today passes deptrac, passes the
  unit test, and passes `composer qa`. The regex backstop also misses inline fully-qualified
  references (`new \Symfony\Component\Uid\Uuid()`) in both contexts. The cost of a wrong boundary
  here compounds across every later feature.
- **Suggested fix:** two changes, both small. (1) Stop hand-maintaining the allowlist: add a second
  collector to the `Framework` layer that is exhaustive by construction, e.g.
  `- type: className` with `value: ^(Symfony|Doctrine|Psr|Monolog)\\\\` alongside the existing
  `composer` collector, or regenerate the `packages:` list from `composer.lock` and add a check that
  keeps the two in sync. (2) Change the `deptrac` script to
  `vendor/bin/deptrac analyse --config-file=deptrac.yaml --fail-on-uncovered` so an unlayered vendor
  token in a layered file fails the gate instead of being counted and ignored. Neither weakens a
  rule; both strengthen it, so standing rule 4 is not in tension. Re-run the step-9 probe table
  afterwards with one of the newly-covered namespaces (`Symfony\Component\Routing`) to prove the
  gap closed.

#### B2 — `env_file: .env` makes `.env.local` incapable of overriding anything inside the container, so the documented secret-override mechanism is inert
- **File:** `compose.yaml:16-17`; `.env:1` and `.env.example:1` (the instruction it contradicts); `phpunit.xml:30-35` (the symptom patch)
- **Category:** security
- **Problem:** `env_file: - .env` injects `APP_ENV`, `APP_SECRET` and `DATABASE_URL` into the `php`
  container's **real process environment**. Symfony's `Dotenv::populate()`
  (`vendor/symfony/dotenv/Dotenv.php`) contains
  `if (!isset($loadedVars[$name]) && !$overrideExistingVars && isset($_ENV[$name])) { continue; }` —
  a pre-existing real environment variable wins over every `.env*` file, `.env.local` included.
  PHP's default `variables_order` (`EGPCS`) puts env-file values into `$_ENV`, which the
  implementer's own step-11 deviation D-4 observed directly ("`KernelTestCase` reads
  `$_ENV['APP_ENV']` … the suite booted the **dev** kernel in the container"). So inside the
  container the committed `.env` is authoritative and `.env.local` is dead, while `.env:1` says
  verbatim: "Never put a real secret here — override in `.env.local` (git-ignored)."
- **Why it matters:** this is the project's entire secret-handling story, and it silently does not
  work in the only supported runtime. Nothing holds a real secret today, which is why this is a
  foundation defect rather than an incident — but the first feature that needs one (a JWT signing
  key, an API token, a non-default `APP_SECRET`) will put it in `.env.local`, observe that the
  application ignores it, and the path of least resistance from there is to edit the **tracked**
  `.env`. It also means `DATABASE_URL` cannot be pointed at a different database per developer.
  Separately, it is the root cause the step-11b `phpunit.xml` `<env>` entries patch over: the test
  suite was fixed, the environment was not.
- **Suggested fix:** delete the `env_file:` block from the `php` service in `compose.yaml`. Dotenv
  already reads `/app/.env` (and then `.env.local`) through the bind mount at runtime — that is what
  `tests/bootstrap.php:9` and `vendor/autoload_runtime.php` do on the host today, with no env_file
  involved. Nothing in the `php` service needs `APP_ENV` in its ambient environment. **Keep the
  `phpunit.xml` `<env>` lines** regardless: they make the suite hermetic against any ambient
  `APP_ENV` (a developer's exported var, CI) and are correct on their own merits. After the change,
  verify `docker compose exec -T php printenv APP_ENV` prints nothing and `bin/console about` still
  reports `dev`.

### Majors

#### M1 — `migrations/` is covered by zero quality gates
- **File:** `phpstan.neon:10-14`; `.php-cs-fixer.dist.php:5-19`; `deptrac.yaml:4-5`
- **Problem / Why / Suggested fix:** `phpstan.neon` analyses `src`, `tests`, `config`, `public`;
  the cs-fixer finder covers `src`, `tests`, `config`, `public` plus `bin/console`; deptrac's
  `paths` is `./src` only. `migrations/` is in none of them, yet it is PSR-4 mapped
  (`composer.json:50`), registered with DoctrineMigrationsBundle
  (`config/packages/doctrine_migrations.yaml:3`), and CLAUDE.md standing rule 6 routes *every*
  schema change through it. The first migration class will therefore ship without
  `declare(strict_types=1)` enforcement, without level-8 analysis of its `$schema`/`addSql` code,
  and without any dependency check — in a project whose core data structure is a JSONB snapshot
  that migrations will manipulate. Add `migrations` to `phpstan.neon` `paths` and to the cs-fixer
  finder's `->in([...])`. Decide deliberately whether `migrations/` joins deptrac's `paths` (it is
  outside the five contexts, so it would need its own layer or would show up as uncovered under
  B1's `--fail-on-uncovered`), and record the decision.

#### M2 — PHPStan analyses the generated, gitignored `config/reference.php`, so the gate's input set depends on whether a kernel booted
- **File:** `phpstan.neon:10-14`; `.gitignore:3`; `.php-cs-fixer.dist.php:12-15`
- **Problem / Why / Suggested fix:** I ran `vendor/bin/phpstan analyse --debug` and the file list
  is: `config/bundles.php`, **`config/reference.php`**, `public/index.php`, `src/Kernel.php`,
  `tests/Integration/KernelBootTest.php`, `tests/Unit/Shared/BoundedContextLayoutTest.php`,
  `tests/bootstrap.php` — 7 files, one of which is Symfony's 78 KB auto-generated config-builder
  dump, rewritten by `PhpConfigReferenceDumpPass` on every debug-mode container compile and
  gitignored since C-2. Correction C-2 removed this file from git's index and from the cs-fixer
  finder but **not** from PHPStan's paths, so it fixed one half of a two-sided problem. Today the
  generated file happens to be level-8 clean; the consequence is that `composer phpstan` analyses 6
  files on a fresh clone and 7 after any `composer test`, and the day a bundle emits a config
  builder PHPStan dislikes, the gate goes red on a file nobody wrote and nobody can edit. A
  non-reproducible gate is a weakened gate. Add `excludePaths: [config/reference.php]` (or
  `analyseAndScan`) to `phpstan.neon`, mirroring the `->notPath('reference.php')` already in
  `.php-cs-fixer.dist.php:15`.

#### M3 — Deviation D-1 (`"Storyteller\\": "src/"`) opens a layer-free zone at the `src/` root
- **File:** `composer.json:44`; `deptrac.yaml:10-34` (five `directory` collectors, nothing else)
- **Problem / Why / Suggested fix:** D-1 was *necessary* — the plan's PSR-4 map genuinely has no
  rule that autoloads `Storyteller\Kernel`, and `php bin/console about` fails without one, so the
  implementer was right not to improvise a different kernel location (R13). But the chosen fix is
  broader than the problem. `Storyteller\ => src/` makes every future top-level directory under
  `src/` autoloadable, and deptrac's collectors only match `src/{Shared,Engine,Content,Play,Api}/.*`.
  A future `src/Support/Clock.php` in namespace `Storyteller\Support` would autoload, would be used
  by any context, would belong to **no layer**, and — because the `deptrac` script does not pass
  `--fail-on-uncovered` (see B1) — would be reported as `Uncovered` and exit 0. That is a one-file
  route around the dependency contract, created by a deviation introduced to solve a one-class
  problem. `src/Kernel.php` sitting in no layer is itself **sound** (it is a single, framework-by-
  definition, never-imported-by-a-context file, and R13 reasoned it correctly); what is not sound is
  that the escape hatch is now *generic*. Prefer the narrow fix: drop the `Storyteller\ => src/`
  psr-4 rule and autoload the kernel with `"autoload": {"classmap": ["src/Kernel.php"]}`, which
  loads exactly one class and leaves the `src/` root unmappable. If the psr-4 rule is kept, pair it
  with `--fail-on-uncovered` so the hole is at least noisy. Either way, record the decision — the
  notes correctly flag D-1 as still needing one.

#### M4 — An unplanned commit widens the agent permission surface inside this feature's history
- **File:** `.claude/settings.json` and `.claude/agents/{analyst,implementer,planner,reviewer,tester}.md` (commit `9bcc496`, between step 8 and step 9)
- **Problem / Why / Suggested fix:** The plan authorises exactly one edit outside the new files —
  `CLAUDE.md` line 3 (D1) — and D2 explicitly declines to touch anything under `.claude/` on the
  grounds that it "is the user's call, not the pipeline's". Commit `9bcc496` nevertheless changes
  `.claude/settings.json` and all five agent definitions mid-feature (+216/-7), and
  `03-implementation-notes.md` does not mention it anywhere: it is the only commit in the 26 that no
  phase document accounts for. The content broadens the allowlist substantially — bare `Write`,
  `Edit`, `Bash(php *)`, `Bash(cp *)`, `Bash(mv *)`, `Bash(npm|npx|yarn|pnpm *)` — which is a change
  to the project's safety posture that every future SDLC run inherits. The deny side is a sensible
  counterweight, but one of its rules already has a measurable cost: `Read(**/.env*)` blocks
  `.env.example`, which forced step 13 to write the README's `DATABASE_URL` and credential claims
  from the plan document rather than from the file (recorded as a batch-5 deviation). I am not
  asserting the change is wrong — it may well have been an explicit, separate user decision — but it
  must not sit unrecorded in a feature's history. Either record it in
  `03-implementation-notes.md` as an out-of-band change with its rationale, or split it onto its own
  commit outside the feature range. Separately, narrow the `.env*` deny rules so `.env.example` (a
  deliberately secret-free, committed file) is readable.

### Minors

#### m1 — `03-implementation-notes.md` records the wrong `guard-running` exit code
- **File:** `docs/sdlc/project-skeleton/03-implementation-notes.md:638` and `:676`; `Makefile:27`
- **Problem / Suggested fix:** Batch 4's step-12 log says the guard "exit **2** (non-zero)" and its
  §"Final check state" repeats `make qa (containers down) -> guard error, exit 2`. The committed
  `Makefile:27` is `{ echo "php container is not running - run 'make up' first"; exit 1; }`, so the
  real code is **1**. Batch 5 caught the discrepancy, correctly declined to touch the Makefile
  (out of scope) and wrote `README.md:41-43` to assert only "exit non-zero" — which is accurate
  either way and is the right call — but the batch-4 text was never reconciled. **Severity: minor,
  deliberately.** It is a transcription error in a record, not a behavioural defect: the Makefile is
  correct, the README is correct, and no gate or acceptance criterion depends on the value. It is
  recorded because the notes are the audit trail a later agent will trust. Correct the two batch-4
  mentions to `1`.

#### m2 — `Shared: []` forbids the `symfony/uid` that was required specifically for ids
- **File:** `deptrac.yaml:74`; `composer.json:28`; `02-plan.md` P3 and the rule-9 justification table
- **Problem / Suggested fix:** CLAUDE.md puts "value objects, **ids**, clock, errors" in
  `src/Shared`; the rule-9 justification for `symfony/uid` is "Story / session / version identifiers
  (UUIDv7)"; `symfony/uid` **is** in the `Framework` layer; and `Shared` has no `Framework` edge. The
  first `StoryId` value object in `src/Shared` wrapping `Symfony\Component\Uid\Uuid` therefore fails
  `composer deptrac` on day one. P3 anticipated this ("a reviewed, deliberate ruleset change"), so
  this is a scheduled decision rather than a defect — but it is currently a required dependency with
  no legal home, which is in tension with standing rule 9. Settle it before the first `Shared` class:
  either keep ids framework-free (hand-rolled UUIDv7 in `Shared`, `symfony/uid` used only in
  `Content`/`Play`/`Api` at the persistence/serialisation edge), or grant `Shared` a narrow
  `FrameworkValueObjects` layer containing `symfony/uid` alone. Do not grant `Shared` the whole
  `Framework` layer — that defeats P3.

#### m3 — `.env` is tracked and byte-identical to `.env.example`
- **File:** `.env:1-4`, `.env.example:1-4`
- **Problem / Suggested fix:** I inspected the tracked `.env`: it contains `APP_ENV=dev`,
  `APP_SECRET=change_me_in_env_local` and a `DATABASE_URL` with the `storyteller/storyteller` dev
  credentials pointing at the Compose-internal host `postgres`. **No real secret is committed** and
  acceptance criterion 18 holds. Committing `.env` while gitignoring `.env.local` /
  `.env.*.local` (`.gitignore:4-6`) is correct Symfony practice, and the split is set up right —
  except that B2 makes the `.local` half non-functional in the container. Two residual notes:
  (a) the two files are byte-identical, so `.env.example` carries zero additional information and
  will silently drift the first time `.env` gains a key — consider dropping it or reducing it to
  keys-with-empty-values; (b) `APP_SECRET` is a fixed, world-readable constant with nothing that
  forces a non-dev context to override it. Nothing in the skeleton uses it (no sessions, no CSRF, no
  `security-bundle`), so the impact today is nil — but the first feature that adds signed cookies or
  CSRF inherits a published secret. Add a `when@prod` guard or a documented deployment check before
  that happens.

#### m4 — Version banners are not suppressed in nginx or PHP
- **File:** `docker/php/php.ini:1-8`; `docker/nginx/default.conf:2-4`
- **Problem / Suggested fix:** `expose_php` is not set to `Off`, so every FPM response carries
  `X-Powered-By: PHP/8.5.12`; nginx has no `server_tokens off;`, so it advertises `nginx/1.27.x`.
  Dev-only today and low impact, but these are the two one-line hardening defaults that never get
  added later if they are not added now. `display_errors = Off` is already correctly set.

#### m5 — No `when@prod` configuration anywhere, and `auto_generate_proxy_classes` is unconditional
- **File:** `config/packages/doctrine.yaml:10`; `config/packages/monolog.yaml` (only `when@dev` / `when@test`)
- **Problem / Suggested fix:** In `prod`, Monolog would have no handlers at all and Doctrine would
  regenerate proxies on every request. Production hardening is explicitly out of scope per the
  analysis, so this is correctly deferred — recorded only so the first deployment feature does not
  assume the config is production-shaped. Wrap `auto_generate_proxy_classes` in `when@dev`/`when@test`
  when a prod target lands.

#### m6 — `composer qa` omits `composer validate --strict`
- **File:** `composer.json:64-69`
- **Problem / Suggested fix:** Every batch ran `composer validate --strict` by hand and every batch
  reported it, which is a good signal that it belongs in the aggregate. Add a `validate` script and
  `@validate` as the first entry of `qa` (it is the cheapest check of all). The plan specified the
  four-entry array verbatim, so this is a follow-up, not a deviation.

#### m7 — `deptrac.yaml`'s collector paths are CWD-relative and `composerPath` is unused
- **File:** `deptrac.yaml:41-42`
- **Problem / Suggested fix:** `ComposerCollector::satisfy()` reads only `composerLockPath`;
  `composerPath` is validated as a string and then never used. Both values, and `paths: ./src`, are
  resolved against the working directory, so `composer deptrac` works only from the project root —
  fine today (the composer script and the Makefile both run there), worth knowing before anyone
  wires deptrac into a hook or a CI step with a different CWD. Consider `%currentWorkingDirectory%`
  or dropping the unused key.

#### m8 — `tests/` is outside deptrac's `paths`
- **File:** `deptrac.yaml:2-5`
- **Problem / Suggested fix:** Deliberate and documented in the file's own comment, and correct for
  now (tests legitimately depend on the framework). Note it as a thing to revisit once integration
  tests start crossing contexts: today nothing stops `tests/Unit/Engine/**` from importing
  `Storyteller\Api\…`, which would make an Engine unit test non-pure without any gate noticing.

#### m9 — `apt-get purge -y --auto-remove` with no package names is a no-op
- **File:** `Dockerfile:23`
- **Problem / Suggested fix:** Inherited verbatim from the plan. With no arguments it purges nothing
  and only autoremoves orphans, so `libpq-dev`, `libicu-dev` and `libzip-dev` stay in the image.
  Harmless for a dev-only image (and arguably desirable, since rebuilding an extension needs them),
  but the line reads as cleanup that is not happening. Either name the `-dev` packages or drop the
  `purge` and keep only the `rm -rf /var/lib/apt/lists/*`.

#### m10 — Known-accepted residuals, recorded so they are not rediscovered
- **Files:** `config/packages/doctrine.yaml` (C-1); `phpstan.neon:16-18` (P5); `bin/console` (no `.php` extension)
- **Problem / Suggested fix:** Three items already reasoned about in the notes and correctly left
  alone, listed here so the next reviewer does not re-litigate them. (a) C-1's removal of
  `enable_native_lazy_objects` reintroduces the `Doctrine\ORM\Proxy\Autoloader` deprecation notice on
  stderr in dev; `about` still exits 0, no gate is affected, and it becomes moot on ORM 4 —
  accepting it rather than re-adding an unauthorised config key was the right call. (b)
  `phpstan-symfony` is loaded without `containerXmlPath`, so its container/service-map rules are
  inert; P5's reasoning (not making `composer phpstan` depend on a warmed dev cache) is sound and the
  commented line is already in place — enable it with the first real services. (c) `bin/console` is
  reached by the cs-fixer finder via `->append()` but not by PHPStan (no `.php` extension); correct
  and documented, just invisible.

## Specific questions from the brief

### Does `deptrac.yaml` encode CLAUDE.md's dependency rule?

**The five context edges: yes, exactly.** `Shared: []`, `Engine: [Shared]`,
`Content: [Engine, Shared]`, `Play: [Engine, Content, Shared]`, `Api: [Play, Content, Shared]` —
which is CLAUDE.md's graph plus the expansion of "Everything may depend on `Shared`". No forbidden
edge is present. In particular **`Api -> Engine` is absent**, as the analysis and plan both
insisted, and no `className` collector exists that could accidentally swallow `src/Kernel.php` into
`Api` (R13 respected). `Framework` appears on the `Content`, `Play` and `Api` lines only, which is a
vendor edge, not a context edge, so acceptance criterion 10 is met literally.

**P3 (withholding `Framework` from `Engine` and `Shared`) is indeed the mechanism** that turns
"Symfony, Doctrine and HTTP types must never appear inside `src/Engine`" into a machine check — the
step-9 probe table proves it fires, with the exact `DependsOnDisallowedLayer … (Framework)` output
recorded. That mechanism is correct in design. It is the **membership of the layer** that is wrong:
see B1. So the answer is "yes for the 26 named packages, no for the other 33".

**Is the 26-package list the right set, or arbitrary?** It is a reasonable hand-picked set that has
since gone stale — it is the plan's pre-C-1 list, written verbatim at step 9 after C-1 had already
added nine more Symfony components to `require`. The implementer verified all 26 exist in
`composer.lock` (which is what the collector throws on) but not the converse, that every framework
package in the lock is in the list. That converse is the property that matters.

**Is `src/Kernel.php` in no layer sound, or a hole?** **Sound, as applied to the Kernel itself.** It
is one file, it is framework-by-definition (it extends `Symfony\…\Kernel`), no context imports it,
and giving it a layer would either force a `Framework` edge onto a context or require a sixth layer
for a single class. Deptrac confirms it: `Uncovered 0`, no warning, because an "uncovered"
dependency is one *from* a layered token, and `Kernel` is not layered. **The hole is not the Kernel —
it is that the no-layer zone is now generically reachable** via the `Storyteller\ => src/` psr-4 rule
added by D-1, with no `--fail-on-uncovered` to make it noisy. That is M3, not a problem with R13's
reasoning.

### Are the gates green by vacancy?

| Gate | Verdict | Evidence |
|---|---|---|
| `composer cs-check` (php-cs-fixer) | **Protective.** | 8 files, and proven to bite twice — step 8 and step 10 each removed `declare(strict_types=1);` from `src/Kernel.php` and observed exit **8**, with the dry run confirmed not to rewrite the file. `setRiskyAllowed(true)` is present and is load-bearing. Gap: `migrations/` (M1). |
| `composer test` (PHPUnit) | **Protective, modestly.** | 4 tests, 19 assertions, and they assert real invariants rather than implementation: the 12-leaf context layout, and the Engine-purity import scan that is *today the only live check* on `src/Engine`. `failOnRisky`/`failOnWarning`/`failOnSkipped` make criterion 6 machine-checked instead of eyeballed, and the deliberate extra `assertDirectoryExists` in the vacuous test is the right way to keep it non-risky. Gaps: the scan matches `use` statements only (not inline FQNs) and covers `src/Engine` but not `src/Shared`. |
| `composer deptrac` | **Decorative today.** | One file analysed (`src/Kernel.php`, itself unlayered); `Violations 0 / Uncovered 0 / **Allowed 0**` — zero *allowed* dependencies is the tell: not one layered token exists, so not one rule was evaluated. D4/R3 predicted and accepted this, and step 9's throwaway probes are the right compensating evidence that it will bite on the first real class. Tomorrow it will be **partially** protective: the five context edges and the Engine→Framework edge fire for the 26 listed packages, and silently do not for the other 33 (B1). |
| `composer phpstan` | **Decorative today, and thinner than it looks.** | 7 files analysed, of which `config/reference.php` is generated vendor output (M2), `config/bundles.php` is an array literal, `tests/bootstrap.php` is three lines, and `src/Kernel.php` is an empty class with a trait. There is essentially no code at level 8 to be wrong. Compounding it, `phpstan-symfony`'s container rules are inert without `containerXmlPath` (P5) and `phpstan-doctrine`'s rules have no entity to see. This is not a defect — it is the honest state of a skeleton — but `composer phpstan` exiting 0 today certifies that the config parses and the extensions load, nothing more. Gap: `migrations/` (M1). |

Two of four gates are currently ceremonial. That is defensible for a skeleton and the plan said so
in advance; what is **not** defensible is B1, where a gate will stay partly ceremonial *after* real
code lands, which is exactly the failure mode D4 was written to prevent.

### Deviation-by-deviation judgement

| Deviation | Justified? | Correctly executed? | Verdict |
|---|---|---|---|
| **D-1** — `"Storyteller\\": "src/"` psr-4 | **Yes.** The plan's map genuinely cannot autoload `Storyteller\Kernel`; `bin/console about` — the step's own verification — fails without a rule. Reporting rather than relocating the Kernel was correct (R13). | **Partly.** It is minimal *in diff size* but not *in blast radius*: it maps the whole `src/` root, where a classmap entry for one file would have done. Longer psr-4 prefixes still win, so the five context mappings are genuinely unaffected, and `composer.lock` is genuinely untouched (autoload is outside the content hash) — both claims check out. | Accept the fix, **raise M3**; the open decision the notes ask for is: narrow it to a classmap. |
| **C-1** — 13 transitives pinned to `7.4.*`, `enable_native_lazy_objects` removed | **Yes, clearly.** A "Symfony 7.4 LTS" skeleton whose lock is 7.4 only at the edges is not one, and D-2's `ORMInvalidArgumentException::lazyGhostUnavailable()` was the concrete proof. Replacing a workaround with its root cause is the right direction, and it was a user decision, correctly escalated. | **Yes.** Scoped `composer update` (no unscoped update, no `--with-all-dependencies`, no widened constraint, no `--ignore-platform-reqs`), 13 downgrades, no Symfony 8.x package surviving. The rule-9 justification is correct: these are promotions of already-installed transitives, not new dependencies. | Accept. The reintroduced `Doctrine\ORM\Proxy\Autoloader` deprecation notice is the right trade (reporting beats silencing with an unauthorised key) — recorded as m10. **But C-1 is the direct cause of B1**: nine of the thirteen newly-pinned packages are outside the `Framework` layer, and nothing in the process reconciled `deptrac.yaml` with the expanded `require` block. |
| **C-2** — `config/reference.php` untracked, gitignored, `->notPath()` | **Yes.** A generated file that a container compile rewrites cannot live in git or in a formatter's scope; the flap was reproduced twice before the fix. No fixer rule, no `setRiskyAllowed`, no finder root was weakened — the exclusion is of one generated file, which is not what standing rule 4 forbids. | **Half.** The file was removed from git, from `.gitignore`'s blind spot and from the cs-fixer finder, but **not** from `phpstan.neon`'s paths, where it is still analysed. | Accept, **raise M2** to finish it. |
| **C-3** — Postgres on `127.0.0.1:5432` | **Yes, and it is an improvement on the plan.** `"5432:5432"` publishes `storyteller/storyteller/storyteller` on every host interface. Keeping the port published (so host-side `psql` still works) while binding it to loopback is the right minimal scope, and leaving `nginx`'s `8080:80` alone is correct — it is meant to be reachable and carries no data. | **Yes**, including re-verifying `make qa` and `doctrine:migrations:status` after the change, fixing forward rather than amending, and documenting it in `README.md:73-81` with an explicit warning not to revert it. | Accept, with credit. This is the one deviation that made the artifact better than the approved plan. |
| **Step 11a** — `opcache` dropped from `docker-php-ext-install` | **Yes, forced.** `php:8.5.12-fpm` compiles Zend OPcache into the binary, so the shared-module build dies on `cp: cannot stat 'modules/*'`. The plan's Dockerfile does not build as written. | **Yes**, and well: the failure was isolated empirically (`pgsql`, `intl`, `zip` each build; only `opcache` fails), the capability was confirmed retained (`php -m` lists `Zend OPcache`, still tuned by `docker/php/php.ini:6-8`), and `Dockerfile:11-13` carries a comment explaining why the line looks short. | Accept. No approval round needed — this is a plan defect with exactly one correct resolution. |
| **Step 11b** — `<env>` forms of `APP_ENV`/`APP_DEBUG` in `phpunit.xml` | **Yes as a change, but it is a patch over a misconfiguration, not a hermetic fix on its own.** The brief asks exactly the right question. The `<env>` lines *are* independently correct — `KernelTestCase` reads `$_ENV` before `$_SERVER`, and forcing both makes the suite immune to any ambient `APP_ENV`, which is a property worth having in CI. | **Yes** mechanically: nothing was relaxed, skipped or deleted, and the same 4 tests / 19 assertions pass on host and in container. | **Accept the `<env>` lines and keep them — but they are a symptom.** The implementer's own reasoning names the alternative ("rather than by deleting `env_file`") and chose to preserve the plan's verbatim `compose.yaml`. That was the wrong call on the merits, and it is **B2**: `env_file: .env` is a genuine container misconfiguration with consequences well beyond the test suite. This is the one deviation that should have come back for approval, because the choice between "patch the suite" and "fix the environment" is a design decision, not an implementation detail. |

### Security surface

- **Tracked `.env` — acceptable, verified clean.** `APP_SECRET=change_me_in_env_local`, dev-only
  Postgres credentials, host `postgres` which resolves only inside Compose. Nothing that should not
  be committed. Committing `.env` and gitignoring `.env.local` / `.env.local.php` / `.env.*.local`
  is the standard Symfony split and is set up correctly in `.gitignore:4-6` — **but B2 makes the
  `.local` half inert in the container**, which is the finding that matters here. Residual notes in
  m3 (identical `.env.example`, unguarded `APP_SECRET`).
- **Dockerfile user handling — good.** Non-root `app` user, `HOST_UID`/`HOST_GID` build args
  (correctly *not* named `UID`, which is read-only in bash), the tolerant `groupadd … || true` for
  Debian's pre-existing GID 20, `USER app` at the end, and `compose.yaml:12` repeating the ids at
  runtime. Verified in the notes by an R9 probe: a container-written file came out owned by
  `501:20` and was appended to and deleted from the host without `sudo`. One residual hazard worth
  knowing: a stale image built with `501` plus a bare `docker compose up` (which falls back to
  `:-1000`) yields a container running as a UID that does not exist in the image. `README.md:45-65`
  documents the prefix prominently and both Makefile recipes set it, which is the right mitigation.
- **nginx — correct and tight.** `root /app/public` with `try_files $uri /index.php…`, the
  `^/index\.php(/|$)` location marked `internal` so it is reachable only by internal redirect, and a
  catch-all `location ~ \.php$ { return 404; }`. The `./:/app:ro` mount exposes the whole repo
  (including `.env` and `.git`) to the nginx container, but nothing outside `/app/public` is
  servable and the mount is read-only, so this is fine. `client_max_body_size 8m` is a sensible
  default. Only `server_tokens` is missing (m4).
- **Can dev credentials leak into a non-dev context?** Not today, and the obvious paths are closed:
  no production target exists, no deployment, `postgres` is loopback-bound (C-3), and the DSN host
  does not resolve outside Compose. The one open channel is the fixed `APP_SECRET` plus the broken
  `.env.local` override (B2 + m3) — fix B2 and the mechanism that is supposed to prevent the leak
  starts working.
- No `security-bundle`, no routes, no DTOs, no controllers — so standing rule 5 (validate all
  external input in `src/Api`) has nothing to bite on, correctly per scope. `src/Api/Dto/` and
  `src/Api/Security/` exist as empty, tracked directories ready for it.

## Checklist

| Area | Result | Note |
|------|--------|------|
| Dependency rule (deptrac) | **fail** | Five context edges encoded exactly right, `Api -> Engine` correctly absent, P3 mechanism correct and proven by probes — but the `Framework` layer omits 33 of 59 installed framework packages and the gate does not `--fail-on-uncovered` (B1). M3 adds a second route around it. |
| Engine purity & determinism | **pass** (with a caveat) | No PHP file under `src/Engine` at all, which is the correct choice (a placeholder class would trigger standing rule 1 for a class with no behaviour). `src/Engine` is deliberately excluded from `config/services.yaml:8-9` per P4 — good. No `rand()`/`time()`/statics anywhere. Caveat: the only live purity check is the `use`-statement regex in `BoundedContextLayoutTest`, and `src/Shared` has no equivalent (B1). |
| Plan conformance | **pass with recorded deviations** | 14 steps plus C-1/C-2/C-3, every deviation logged with its reasoning and verification. D-1, C-1, C-2, C-3 and 11a judged justified; 11b justified as written but should have escalated the root cause (B2). One unrecorded commit (M4). |
| Engine changes have unit tests | **n/a — correctly** | No `src/Engine/**` change beyond `.gitkeep`, so standing rule 1 is satisfied by construction. `tests/Unit/Engine/.gitkeep` exists and is ready. |
| Input validation & auth | **n/a** | No API surface in scope. `symfony/validator` is installed and `framework.validation` enabled, so standing rule 5 has its tooling in place for the first DTO. |
| Error handling | **pass** | `handle_all_throwables: true`, `php_errors.log: true`, `display_errors = Off` in the container ini. Monolog has no `when@prod` handler (m5). Nothing swallows an exception; `composer qa` is a JSON array with no `|| true`, and fail-fast was proven empirically at exit 8. |
| Performance (N+1, indexes, loads) | **n/a** | No entities, no queries, no migrations. `auto_generate_proxy_classes: true` is unconditional (m5). |
| Naming & PSR-12 | **pass** | `composer cs-check` exit 0 over 8 files; namespaces match paths; `Storyteller\Tests\Unit\*` / `Storyteller\Tests\Integration\*` match D3's layout; ubiquitous language (`Engine`, `Content`, `Play`, `Api`, `Shared`, `Storyteller\Migrations`) consistent with CLAUDE.md. `migrations/` is unformatted-by-omission (M1). |

## Good things worth keeping

- **The step-9 probe table.** Four throwaway classes — three negative, one positive control — run,
  their exact `DependsOnDisallowedLayer` output pasted into the notes, then deleted and never
  committed. That is the correct way to prove a green-by-vacancy check will bite, and it is why B1
  is a bounded list problem rather than an unknown. The same discipline shows in step 8 and step 10
  deliberately removing `declare(strict_types=1);` to watch cs-fixer and `qa` fail at exit 8.
- **C-1 replaced a workaround with its root cause.** Removing `enable_native_lazy_objects` and
  pinning the 13 drifting transitives, rather than keeping a config key that silenced the symptom,
  is the harder and better call — and the returned deprecation notice was reported rather than
  re-silenced.
- **C-3 improved on an approved plan.** Noticing that `"5432:5432"` plus
  `storyteller/storyteller/storyteller` is a real exposure, fixing it to loopback, keeping the port
  published so the tool is still usable, and then documenting in the README *why it must not be
  reverted*.
- **Refusing to manufacture a signal.** No placeholder class under `src/Engine` just to give deptrac
  something to analyse; no PHPStan baseline; no `skip_violations`; no finder root narrowed; no
  widened constraint; no `--ignore-platform-reqs`. Standing rule 4 was respected under genuine
  pressure, repeatedly.
- **The notes name their own uncertainty.** `.deptrac.cache`, the `config/reference.php` flap and
  the `guard-running` exit code were all surfaced by the implementer before anyone asked, with
  options and a recommendation rather than an improvised fix. The two counting slips in the plan
  (18 vs 20 `.gitkeep` files, 13 vs 12 directories) were caught and recorded instead of being
  silently followed.
- **`BoundedContextLayoutTest` is a real architectural test**, not a smoke test — it encodes the
  layout and the Engine-purity invariant as executable assertions, and it is currently carrying more
  of the Engine boundary than deptrac is.
