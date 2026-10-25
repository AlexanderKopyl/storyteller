#!/usr/bin/env bash
# PreToolUse | Edit, Write
#
# While a fix loop is running, denies edits under tests/ so the fix cannot be made by
# rewriting the test. The failing test must pre-exist the fix.
#
#   touch var/freeze-tests     # freeze - start of a fix loop
#   rm -f var/freeze-tests     # thaw   - fix is done, gates are green
#
# Also honours STORYTELLER_FREEZE_TESTS=1 for whoever exports it before launching Claude.
# (Exporting it from a Bash tool call does NOT work - that shell does not persist.)
set -uo pipefail

input=$(cat)

file=$(printf '%s' "$input" | jq -r '.tool_input.file_path // empty')
[ -n "$file" ] || exit 0

root="${CLAUDE_PROJECT_DIR:-}"
[ -n "$root" ] || root=$(printf '%s' "$input" | jq -r '.cwd // empty')
[ -n "$root" ] || exit 0

frozen=false
[ "${STORYTELLER_FREEZE_TESTS:-}" = "1" ] && frozen=true
[ -e "$root/var/freeze-tests" ] && frozen=true
[ "$frozen" = true ] || exit 0

case "$file" in
  "$root"/*) rel="${file#"$root"/}" ;;
  /*)        exit 0 ;;
  *)         rel="$file" ;;
esac

case "$rel" in
  tests/*)
    jq -n --arg r "Tests are frozen for this fix loop, so $rel cannot be edited. The failing test must pre-exist the fix - fix the production code instead. If the test itself is genuinely wrong, say why, thaw with 'rm -f var/freeze-tests', and change it as its own separate commit." '{
      hookSpecificOutput: {
        hookEventName: "PreToolUse",
        permissionDecision: "deny",
        permissionDecisionReason: $r
      }
    }'
    exit 0
    ;;
esac

exit 0
