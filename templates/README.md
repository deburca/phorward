# Templates

Files that `phorward harness:install` (task 0012, section 3) will copy into a project, with the project's facts filled in.

**State: snapshots, not yet generalised.** `opencode/agent/` holds the two agent prompts and `opencode/command/` the
`/fix-bug`, `/feature` and `/verify` commands, from a pilot harness written for one Drupal module. They still carry its
names, paths and conventions, and name the pilot's `scripts/harness/verify.sh` where they will call
`vendor/bin/phorward verify`.

What the harness is and is not is in [docs/verify.md](../docs/verify.md): a filter for lint, coding-standard and CI failures,
not a guarantee that the model's logic or tests are right.
