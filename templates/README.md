# Templates

Files that `phorward harness:install` copies into a project, with the project's facts filled in. Run it inside the
project; it uses `phorward.json` (or what it detects) for the `profile` and the project name.

```bash
vendor/bin/phorward harness:install            # --dry-run shows what would change; --force replaces edited files
```

| Template | Installed as | What it is |
|---|---|---|
| `opencode/agent/phorward-dev.md` | `.opencode/agent/` | The developer agent: reproduce, implement, test, then pass `phorward verify` before claiming done |
| `opencode/agent/phorward-tests.md` | `.opencode/agent/` | The spec writer for the optional test-first phase: writes failing tests, changes no source |
| `opencode/command/{feature,fix-bug,verify}.md` | `.opencode/command/` | The `/feature`, `/fix-bug` and `/verify` commands |
| `harness/harness.sh` | `.harness/harness.sh` | The retry loop around opencode (below) |
| `../profiles/<profile>/harness/CONVENTIONS.md` | `.harness/CONVENTIONS.md` | A starter for the project's conventions, which the agents read first. **The project owns it**: edit it |

`{{project}}`, `{{kind}}` (for example "a Drupal module") and `{{phorward}}` (the command, from `harness.command`, default
`vendor/bin/phorward`) are the only placeholders. Everything that is specific
to a project (where tests go, the rules a change most often breaks, which guide covers which area) belongs in
`.harness/CONVENTIONS.md`, which is written once and never replaced without `--force`. The agent prompts themselves
carry the generic discipline: reproduce a bug first, stop after three failed fixes, never suppress a gate.

**Re-running is safe.** The installer records a hash of every file it writes in `.harness/installed.json`. A file
that still matches its record is updated to the current template; a file you edited is reported as "kept" and left
alone. Commit `.opencode/` and `.harness/`. The loop's own scratch files in `.harness/` (`last-report.txt`, `red-msg.txt`,
`red/`) can go in `.gitignore`.

The agents may not run `git stash`, `restore`, `rm`, `reset`, `checkout`, `clean`, `commit` or `push` (a model once
stashed the harness itself away). The gates call `phorward verify` (by the command in `harness.command`), so the project needs no other tooling.

`harness/harness.sh` is the retry loop around opencode: a fresh session per attempt that sees only the task and the last
`phorward verify` report, bounded attempts, an optional escalation model, phpcbf auto-fix of style findings before each gate run (`HARNESS_FIX=0` turns it off), a time limit on every session, one retry when
opencode reports "Agent not found", and an optional test-first phase. It treats a gate that could not run (the server,
not the code) as exit 3 rather than a failed attempt. It needs a model (`--model` or `HARNESS_MODEL`); nothing about
a particular model or machine is built in. The agent names default to `phorward-dev` and `phorward-tests`
(`HARNESS_AGENT`, `HARNESS_TESTS_AGENT`). Its behaviour is tested in `tests/test_harness.py` with stand-ins for
opencode and phorward.

## What the harness is, and is not

It is a filter for **lint, coding-standard and CI failures**. In the pilot it brought phpcs, phpstan and syntax gates
to clean far more often than plain opencode did. It is **not** a check that the model's logic or tests are right: on
13 real tasks the hidden-test pass rate was 5 against 4 for plain runs, and a test-first variant passed 1 of 6. A green
run means "CI's lint and test steps are likely to pass". Review the change as you would a colleague's: read the diff for
what it does and does not do, and read the new tests for what they actually assert (a test the model wrote to pass its
own code proves little). The full account is in [docs/verify.md](../docs/verify.md).
