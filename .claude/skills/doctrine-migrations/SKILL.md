---
name: doctrine-migrations
description: "Policy: every schema change goes through a Doctrine migration, and a committed migration is never edited. Use when adding or changing a Doctrine entity, mapping or repository, when creating a table, column or index, when touching story_version or the compiled JSONB snapshot, or when anything under migrations/ is involved."
---

# Policy — schema changes and migrations

**Owner:** data owner. Changes to this skill need their sign-off.

## Two rules

1. **Every schema change goes through a Doctrine migration.** No `schema:update`, no
   hand-run DDL, no "I'll add the column on the server".
2. **Never edit a committed migration.** Once it is in `main`, it has run somewhere. A
   correction is a *new* migration.

Editing a committed migration is a review blocker, every time.

## Making one

```bash
php bin/console doctrine:migrations:diff      # generate from the mapping delta
php bin/console doctrine:migrations:migrate   # apply locally
```

Then read the generated file before committing it. `diff` is a starting point, not an
answer — it routinely produces drops you did not intend and misses data backfill entirely.

Each migration:

- has a `getDescription()` saying what it does and why, in one line;
- implements `down()`, or states in the description why it is irreversible;
- does **one** logical schema change;
- separates DDL from data backfill. A backfill over a large table belongs in its own
  migration, written to be re-runnable.

## Published versions are immutable

A published `story_version` row — its compiled JSONB snapshot — is **immutable**. A
migration must never change the meaning of an already-published snapshot.

This is the constraint that makes old sessions replayable. If a change would reinterpret
existing snapshot data:

- add a new column or a version marker instead;
- compile new content into the new shape;
- leave published rows exactly as they are;
- make the reader handle both shapes, and test that an old snapshot still plays.

A migration that rewrites published snapshot JSONB is a blocker unless the data owner has
signed off in the spec.

## JSONB

- Index what you query. A GIN index on a JSONB column that is filtered on is not optional
  at play-time volumes.
- Never interpolate user input into a JSONB path expression — parameterize it.
- Loading a whole snapshot where a projection suffices is a review finding (`REVIEW.md`,
  pass 8).

## In the plan

A feature that changes the schema states in `plan.md`:

- the migration name;
- whether it is reversible, and why not if not;
- its effect, explicitly, on already-published `story_version` rows;
- the step order — the migration lands before the code that depends on it.

## Checks

```bash
php bin/console doctrine:migrations:status
php bin/console doctrine:schema:validate    # mapping and database agree
composer test
```

`doctrine:database:drop` and `doctrine:schema:drop` are denied in `.claude/settings.json`.
That is deliberate.
