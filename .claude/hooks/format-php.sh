#!/usr/bin/env bash
# PostToolUse | Edit, Write
#
# Runs php-cs-fixer on the one PHP file just written, so `composer cs-check` stays green
# without a round trip through the gates. Scoped to the changed file and best-effort: a
# formatting failure never blocks the work.
set -uo pipefail

input=$(cat)

file=$(printf '%s' "$input" | jq -r '.tool_input.file_path // empty')
[ -n "$file" ] || exit 0
case "$file" in *.php) ;; *) exit 0 ;; esac
[ -f "$file" ] || exit 0

root="${CLAUDE_PROJECT_DIR:-}"
[ -n "$root" ] || root=$(printf '%s' "$input" | jq -r '.cwd // empty')
[ -n "$root" ] || exit 0

fixer="$root/vendor/bin/php-cs-fixer"
[ -x "$fixer" ] || exit 0

case "$file" in
  "$root"/src/*|"$root"/tests/*|"$root"/migrations/*|"$root"/config/*|"$root"/public/*) ;;
  *) exit 0 ;;
esac

out=$("$fixer" fix --quiet --using-cache=yes "$file" 2>&1)
status=$?

if [ "$status" -ne 0 ]; then
  jq -n --arg o "php-cs-fixer could not format this file (exit $status): $out" '{
    hookSpecificOutput: {
      hookEventName: "PostToolUse",
      additionalContext: $o
    }
  }'
fi

exit 0
