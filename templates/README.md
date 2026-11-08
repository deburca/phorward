# Templates

Files that `phorward harness:install` (task 0012, section 3) will copy into a project, with the project's facts filled in.

**State: snapshots, not yet generalised.** `opencode/agent/` holds the two agent prompts and `opencode/command/` the
`/fix-bug`, `/feature` and `/verify` commands, from a pilot harness written for one Drupal module. They still carry its
names, paths and conventions (and the `ddev` deny rules of its dev environment). They call
`vendor/bin/phorward verify` for their gates and read `.harness/CONVENTIONS.md`, which the project supplies.

`harness/harness.sh` is the retry loop around opencode: a fresh session per attempt that sees only the task and the last
`phorward verify` report, bounded attempts, an optional escalation model, a time limit on every session, one retry when
opencode reports "Agent not found", and an optional test-first phase. It treats a gate that could not run (the server,
not the code) as exit 3 rather than a failed attempt. It needs a model (`--model` or `HARNESS_MODEL`); nothing about
a particular model or machine is built in. Its behaviour is tested in `tests/test_harness.py` with stand-ins for
opencode and phorward.

What the harness is and is not is in [docs/verify.md](../docs/verify.md): a filter for lint, coding-standard and CI failures,
not a guarantee that the model's logic or tests are right.
