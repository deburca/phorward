#!/usr/bin/env bash
# harness.sh: run a task through opencode with a bounded retry loop and a fresh session per attempt.
#
#   harness.sh [options] "task description"
#
#   -m, --model MODEL        model, provider/model (or set HARNESS_MODEL); required
#   -e, --escalate MODEL     model for one last attempt if the others fail (or HARNESS_ESCALATE; default none)
#   -n, --attempts N         attempts before escalating (default 3)
#       --base REF           what the changes are measured from (default: phorward's choice, the default branch)
#       --test-first         first a session that only writes failing tests from the task statement
#                            (the spec); the fix sessions then must make those tests pass and may not edit them
#       --dry-run            print the prompts instead of running opencode
#
# The gates are `phorward verify`: lint, standards, static analysis, the tests that match the change and a
# security scan, run on the phorward server, not here. A green run means CI's lint and test steps are likely
# to pass; it does not show the logic or the tests are right. Review those yourself.
#
# Why a loop outside opencode: a long chat of failed fixes anchors a small model on its own mistakes.
# Each attempt here is a NEW session that sees the original task, the verify report from the last
# attempt and nothing else. The working tree keeps the earlier attempts' edits, so progress is kept
# but the reasoning that produced a wrong fix is not.
#
# Exit 0: all gates passed. Exit 1: still failing after every attempt (report in .harness/last-report.txt).
# Exit 2: usage or setup error. Exit 3: the gates could not run (server, ssh or configuration): the attempt is
# not counted against the model, and the report says what to fix before running again.
#
# Environment: HARNESS_MODEL, HARNESS_ESCALATE, HARNESS_PHORWARD (the command, default vendor/bin/phorward),
# HARNESS_SESSION_TIMEOUT (seconds a model session may run, default 1500), HARNESS_AGENT_RETRY_WAIT (default 20), HARNESS_AGENT and HARNESS_TESTS_AGENT
# (opencode agent names, default phorward-dev and phorward-tests).
set -uo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || { echo "harness: not inside a git work tree" >&2; exit 2; }
MODEL="${HARNESS_MODEL:-}"
ESCALATE="${HARNESS_ESCALATE:-none}"
AGENT="${HARNESS_AGENT:-phorward-dev}"
TESTS_AGENT="${HARNESS_TESTS_AGENT:-phorward-tests}"
ATTEMPTS=3
BASE=""
DRY=0
TESTFIRST=0

while [ $# -gt 0 ]; do
  case "$1" in
    -m|--model) MODEL="$2"; shift 2 ;;
    -e|--escalate) ESCALATE="$2"; shift 2 ;;
    -n|--attempts) ATTEMPTS="$2"; shift 2 ;;
    --base) BASE="$2"; shift 2 ;;
    --test-first) TESTFIRST=1; shift ;;
    --dry-run) DRY=1; shift ;;
    -h|--help) sed -n '2,30p' "${BASH_SOURCE[0]}"; exit 0 ;;
    --) shift; break ;;
    -*) echo "unknown option: $1" >&2; exit 2 ;;
    *) break ;;
  esac
done
TASK="$*"
[ -n "$TASK" ] || { echo "usage: harness.sh [options] \"task\"" >&2; exit 2; }
[ -n "$MODEL" ] || { echo "harness: no model; give --model or set HARNESS_MODEL" >&2; exit 2; }

cd "$ROOT" || exit 2
if [ -n "${HARNESS_PHORWARD:-}" ]; then PHORWARD="$HARNESS_PHORWARD"
elif [ -x vendor/bin/phorward ]; then PHORWARD="vendor/bin/phorward"
else PHORWARD="phorward"; fi
# A model session must have a time limit: one once hung for 20 hours.
if command -v timeout >/dev/null; then LIMIT=timeout
elif command -v gtimeout >/dev/null; then LIMIT=gtimeout
else echo "harness: no timeout command (on macOS: brew install coreutils); a session without a time limit can hang" >&2; exit 2; fi
mkdir -p .harness
REPORT=".harness/last-report.txt"
: > "$REPORT"

SPEC_NOTE=""   # set after the test-first phase

attempt_prompt() { # n, model
  if [ "$1" -eq 1 ]; then
    printf '%s\n%s' "$TASK" "$SPEC_NOTE"
  else
    printf 'Task:\n%s\n\nA previous attempt left the working tree changed but the gates still fail. You start fresh: do not trust the existing edits, read them. The verify report from the last run:\n\n%s\n\nFind the real cause before changing anything.\n%s' \
      "$TASK" "$(cat "$REPORT")" "$SPEC_NOTE"
  fi
}

# opencode run with a time limit; retried once when opencode has not yet discovered the project agents
oc_run() {
  local out; out="$(mktemp)"
  "$LIMIT" "${HARNESS_SESSION_TIMEOUT:-1500}" opencode run "$@" 2>&1 | tee "$out"
  if grep -q 'Agent not found' "$out"; then
    echo "### agent not found; retrying once"; sleep "${HARNESS_AGENT_RETRY_WAIT:-20}"
    "$LIMIT" "${HARNESS_SESSION_TIMEOUT:-1500}" opencode run "$@"
  fi
  rm -f "$out"
}

run_attempt() { # n, model
  local prompt; prompt="$(attempt_prompt "$1")"
  if [ $DRY -eq 1 ]; then
    printf '\n===== attempt %s with %s =====\n%s\n' "$1" "$2" "$prompt"; return 0
  fi
  oc_run --agent "$AGENT" --model "$2" --auto --title "harness attempt $1: ${TASK:0:50}" "$prompt"
}

# gates [phorward verify options]: the report goes to $REPORT. Returns phorward's exit status:
# 0 passed, 1 a gate failed (the model's to fix), anything else means the gates could not run.
gates() {
  # shellcheck disable=SC2086
  "$PHORWARD" verify ${BASE:+--base "$BASE"} "$@" >"$REPORT" 2>&1
  GATES_RC=$?
  return $GATES_RC
}

# verify: 0 passed, 1 failed, 2 the gates could not run
verify() {
  [ $DRY -eq 1 ] && return 1
  restore_spec
  gates; local rc=$?
  tail -3 "$REPORT"
  case $rc in 0) return 0 ;; 1) return 1 ;; *) return 2 ;; esac
}

# The machinery failed, not the model: say so and stop without spending another attempt.
gates_unavailable() {
  echo "### the gates could not run (phorward verify exit ${GATES_RC:-?}); this is not counted as an attempt"
  sed -n '1,30p' "$REPORT"
  echo "### fix that, then run again; the working tree keeps the edits made so far"
  exit 3
}

# ---- test-first phase ----------------------------------------------------------------------------
is_test() { case "$1" in tests/*|*/tests/*) return 0 ;; *) return 1 ;; esac; }
changed_files() { { git diff --name-only --diff-filter=ACMR HEAD; git ls-files --others --exclude-standard; } | sort -u | grep -vE '^(\.opencode|\.harness)(/|$)' || true; }

# red_check: 0 = tests fail as a spec should, 10 = tests already pass (warn), other = rejected (reason in $RED_MSG)
RED_MSG=".harness/red-msg.txt"
red_check() {
  : > "$RED_MSG"
  red_check_inner >>"$RED_MSG"
}
red_check_inner() {
  local f src="" tests=""
  for f in $(changed_files); do if is_test "$f"; then tests="$tests $f"; else src="$src $f"; fi; done
  if [ -n "$src" ]; then
    for f in $src; do if git ls-files --error-unmatch "$f" >/dev/null 2>&1; then git checkout HEAD -- "$f"; else rm -f "$f"; fi; done
    echo "You edited non-test files, which were reverted:$src. Write tests only."; return 2
  fi
  [ -n "$tests" ] || { echo "No test file was added or changed. Write the tests."; return 3; }
  gates --only syntax,phpcs; local rc=$?
  [ $rc -le 1 ] || return 6
  if [ $rc -eq 1 ]; then
    echo "The test files fail lint:"; sed -n '1,40p' "$REPORT"; return 4
  fi
  # shellcheck disable=SC2086
  gates --only phpunit --tests "$(echo $tests | tr ' ' ',')"; rc=$?
  [ $rc -eq 0 ] && return 10
  [ $rc -eq 1 ] || return 6
  # PHPUnit prints its totals line when it got as far as running the tests; without it the file did not load.
  if grep -qE 'Parse error|syntax error, unexpected|Cannot declare class|PHP Fatal error' "$REPORT" && ! grep -qE '^Tests: [0-9]+, Assertions' "$REPORT"; then
    echo "The tests do not run (fatal or parse error):"; sed -n '1,40p' "$REPORT"; return 5
  fi
  return 0
}

RED_DIR=".harness/red"
restore_spec() { # put the spec tests back if a fix session edited them
  [ -d "$RED_DIR" ] || return 0
  local f
  while IFS= read -r f; do
    [ -f "$RED_DIR/$f" ] || continue
    cmp -s "$RED_DIR/$f" "$f" || { cp "$RED_DIR/$f" "$f"; echo "### restored spec test $f (spec tests must not be edited)"; }
  done < <(cd "$RED_DIR" && find . -type f | sed 's|^\./||')
}

if [ $TESTFIRST -eq 1 ] && [ $DRY -eq 0 ]; then
  rm -rf "$RED_DIR"; feedback=""; state=bad
  for t in 1 2; do
    echo "### spec phase, attempt $t of 2 ($MODEL)"
    sp="Task:
$TASK"
    [ -z "$feedback" ] || sp="$sp

Your last attempt was rejected: $feedback"
    oc_run --agent "$TESTS_AGENT" --model "$MODEL" --auto --title "harness spec $t: ${TASK:0:50}" "$sp"
    red_check; rc=$?
    [ $rc -eq 6 ] && gates_unavailable
    if [ $rc -eq 0 ] || [ $rc -eq 10 ]; then state=$([ $rc -eq 0 ] && echo red || echo green); break; fi
    feedback="$(cat "$RED_MSG")"; echo "### spec rejected: $(head -c 300 "$RED_MSG")"
  done
  specfiles="$(changed_files | while read -r f; do is_test "$f" && echo "$f"; done)"
  if [ -n "$specfiles" ]; then
    for f in $specfiles; do mkdir -p "$RED_DIR/$(dirname "$f")"; cp "$f" "$RED_DIR/$f"; done
    echo "### spec: $state ($(echo "$specfiles" | wc -w | tr -d ' ') test file(s))"
    SPEC_NOTE="
The specification tests below were written from the task statement before any fix. They are the contract:
make them pass by changing the SOURCE. Do not edit, weaken or delete them (edits are reverted). You may add
more tests. Spec test files: $(echo "$specfiles" | tr '\n' ' ')
State when written: $state$([ "$state" = green ] && echo " (they already pass, so they may not capture the problem: check the task statement and extend them first)").
Before you finish: grep the module for every route, link template, permission, field, label or method you
remove, rename or change, and update or test each caller you find."
  else
    echo "### spec: no test files produced; continuing without a spec"
  fi
fi

n=0
while [ "$n" -lt "$ATTEMPTS" ]; do
  n=$((n + 1))
  echo "### attempt $n of $ATTEMPTS ($MODEL)"
  run_attempt "$n" "$MODEL"
  verify; rc=$?
  [ $rc -eq 0 ] && { echo "### gates passed on attempt $n"; exit 0; }
  [ $rc -eq 2 ] && gates_unavailable
done

if [ "$ESCALATE" != "none" ]; then
  n=$((n + 1))
  echo "### escalating to $ESCALATE (attempt $n)"
  run_attempt "$n" "$ESCALATE"
  verify; rc=$?
  [ $rc -eq 0 ] && { echo "### gates passed after escalation"; exit 0; }
  [ $rc -eq 2 ] && gates_unavailable
fi

echo "### gates still failing after $n attempt(s); last report: $REPORT"
exit 1
