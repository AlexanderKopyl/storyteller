#!/usr/bin/env bash
# PreToolUse | Edit, Write, NotebookEdit
#
# Denies writes to paths that must not be hand-edited:
#   - vendor/, var/, public/bundles/  - generated or not ours
#   - .env*                           - secrets
#   - migrations/<file> already in HEAD - a committed migration is immutable
#                                        (CLAUDE.md rule 6)
#
# Deterministic enforcement for policies that must hold without exception. Fast, scoped to
# the one file being written, and never asks a human - an approval that pauses the build
# belongs at the Stage 5 gate, not here.
set -uo pipefail

input=$(cat)

deny() {
  jq -n --arg r "$1" '{
    hookSpecificOutput: {
      hookEventName: "PreToolUse",
      permissionDecision: "deny",
      permissionDecisionReason: $r
    }
  }'
  exit 0
}

file=$(printf '%s' "$input" | jq -r '.tool_input.file_path // .tool_input.notebook_path // empty')
[ -n "$file" ] || exit 0

root="${CLAUDE_PROJECT_DIR:-}"
[ -n "$root" ] || root=$(printf '%s' "$input" | jq -r '.cwd // empty')
[ -n "$root" ] || exit 0

# Repo-relative path. A file outside the project is not ours to police.
case "$file" in
  "$root"/*) rel="${file#"$root"/}" ;;
  /*)        exit 0 ;;
  *)         rel="$file" ;;
esac

case "$rel" in
  vendor/*|var/*|public/bundles/*)
    deny "$rel is generated or vendored. Do not hand-edit it - change the source that produces it." ;;
  .env|.env.*|*/.env|*/.env.*)
    deny "Refusing to write $rel. Environment files hold secrets; edit them outside Claude." ;;
esac

# A migration that already exists in HEAD has run somewhere. Correct it with a NEW
# migration - never by editing it.
case "$rel" in
  migrations/*)
    if git -C "$root" cat-file -e "HEAD:$rel" 2>/dev/null; then
      deny "$rel is already committed, so it has run somewhere. Never edit a committed migration (CLAUDE.md rule 6) - generate a new one that corrects it."
    fi
    ;;
esac

exit 0
