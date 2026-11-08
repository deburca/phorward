# `phorward verify`

**Status: agreed 2026-11-07.** Implemented as the first slice of task 0012 and run against the first server (see the task note).

`phorward verify` is the gate a change must pass before it is pushed. It was a shell script in a pilot harness for a
locally hosted model; here the same idea runs the heavy gates on the phorward server and keeps the cheap ones local.

## What it is, and what it is not

The pilot ran 13 real tasks against plain opencode. The gates were useful as a **lint, coding-standards and CI-failure
filter**: they brought phpcs, phpstan and syntax gates to clean far more often. They did **not** make the model's logic
or tests more correct (hidden-test pass 5 of 13 against 4 of 13 plain). A green `verify` means "CI's lint and test steps
are likely to pass", never "the change is right". Reviewing the logic and the tests stays with a person, and this page,
the README and the installed agent prompts all say so.

## Gates

A failed gate does not stop the later ones: one run gives one full report.

| Gate | Where | What it does |
|---|---|---|
| `changes` | local | Fails when nothing changed since the base. A run once passed on an empty diff; "nothing was implemented" is a failure |
| `syntax` | server | `lint` on the **oldest** supported PHP: catches code that only parses on a newer one |
| `phpcs` | server | The project's own ruleset and the image's tool versions, as CI runs them |
| `phpstan` | server | The project's own config and baseline, on the oldest PHP. Before it runs, a **local** check fails on any added `phpstan-ignore` or new baseline entry |
| `phpunit` | server | The tests mapped to the change, capped, on the **newest** PHP, which raises the most deprecations |
| `tests` | local | Source changed but no test file added or changed: fail |
| `security` | local | `gitleaks` on the diff if installed, plus a scan of the **added** lines for patterns that are almost never right |

Local gates read the working tree and the git diff; they never execute the model's code. Everything that executes it
goes to the server through the same request, retries and exit statuses as `phorward run`.

Not in the first slice, and why: `phpcbf` (auto-fix changed files in place) needs the server to return a patch, a
protocol addition; and limiting `syntax` and `phpcs` to the changed files needs a `--paths` option, another one. Both
are additive. Without them the server gates cover the whole project, which is what CI does.

## The changed files

Everything added, copied, modified or renamed since the merge base with the base ref, plus untracked files that git does
not ignore. The base is `--base`, else `gates.base`, else the repository's default branch (`origin/HEAD`, then `main`,
then `master`). Paths under `vendor/`, `node_modules/`, `.git/`, `.harness/`, `.opencode/` and anything in `gates.ignore`
are not judged: the harness does not verify itself.

## Test selection

Ported from the pilot, with its known limit. A changed test file selects itself. A changed source file selects every
Kernel or Unit test of the same extension (the project, or one submodule) that names its class, plus tests named after it
(`Hive.php` selects `Hive*Test.php`). A file with no match, such as a hook file, selects that extension's Unit tests, so a
change still gets some feedback. Results are ranked, then capped at `gates.phpunit.max_files` (default 8), so the cap drops
the weakest match. **Limit: this matches names, not coverage, so it can miss a test of something indirectly affected.** CI
remains the full check. The layout comes from the profile (`drupal`: `tests/src/{Kernel,Unit}` per extension; `flat`:
`tests/**/*Test.php`).

## Security scan

The scan reads only added lines, and skips test files. Each pattern is a failure or a warning; a warning prints and does not
fail. The patterns belong to the profile's manifest (`verify.security`), so a plain PHP library and a Drupal module have
their own: for example `eval`, `unserialize`, shell execution and SQL built by interpolation fail everywhere, while
`_access: TRUE` routes and `accessCheck(FALSE)` are Drupal's. A heuristic backstop next to gitleaks, not a replacement
for review.

## Settings (project scope, in `phorward.json`)

| Key | Default | Meaning |
|---|---|---|
| `gates.base` | detected | The git ref to compare with |
| `gates.ignore` | none | Extra path prefixes or globs that are not judged |
| `gates.php.oldest` | the first of the profile's supported versions | PHP for `syntax`, `phpcs` and `phpstan` |
| `gates.php.newest` | the last of them | PHP for `phpunit` |
| `gates.phpunit.max_files` | `8` | Cap on selected test files |
| `gates.skip` | none | Gates to leave out by default, for example `["security"]` |
| `gates.allow_no_tests` | `false` | Do not fail the `tests` gate (a change with nothing to test) |

Flags: `--base`, `--only gate,gate`, `--tests path,path` (run exactly these), `--format human|json`. Every setting is
also a flag, an environment variable or a key in the project file ([configuration.md](configuration.md)).

The gates that execute project code are sent as at most two ordinary `run` requests: one on the oldest PHP for `syntax`,
`phpcs` and `phpstan`, one on the newest for `phpunit` with the chosen tests, or a single request when the two versions
are the same. The tree is archived once. A profile that does not offer a check skips its gate, saying so.

## Report and exit status

The human report is short: one line per gate (verdict and time), then, for each failed gate, the first lines of its log
with colour codes removed, because the reader is a model that must not be confused. `--format json` has the same, with
`first_failure` naming the first failed gate and its excerpt.

Failures of the machinery (ssh, a timeout, a server error, a busy server) are reported **separately** from failed gates
and never count as the model's fault, as with `phorward run`. Exit status: `0` every selected gate passed; `1` a gate
failed (including "nothing changed"); `2` a configuration or usage error; `70` no gate failed but some could not run for
infrastructure reasons; `75` the server stayed busy. A real failure wins over an infrastructure one, since the model has
something to fix either way; among infrastructure problems a wrong setup (`2`, such as a missing image) comes before
a busy server (`75`), and that before anything else that broke (`70`).

## The harness loop

`templates/harness/harness.sh` calls `phorward verify` as its gate. Exit `0` ends the loop; exit `1` is the model's to
fix, and the report goes into the next attempt's fresh session; any other status means the gates could not run, so the
loop stops with its own exit `3` and does not spend an attempt (or escalate to a stronger model) on a server fault. The
loop's safety rules are kept from the pilot: a time limit on every model session (one once hung for 20 hours), one retry
when opencode has not yet found the project's agents, and agents that may not run `git stash`, `restore`, `rm`, `reset`,
`checkout`, `clean`, `commit` or `push` (a model once stashed the harness itself away).

Differences from the pilot's local gates, which a caller of the harness will see: there is no auto-fix (`phpcbf`), so a
style finding is fixed by hand; `syntax`, `phpcs` and `phpstan` cover the whole project, as CI does, not only the changed
files; and the project's own phpstan runs from the Drupal project in the image, as it does in CI.

## Decisions

Settled when this was reviewed (2026-11-07):

1. **First slice:** everything above except `phpcbf` and changed-file scoping, which wait for two additive protocol options.
2. **PHP versions:** oldest and newest default from the profile's supported list (`versions.php`) and can be set.
3. **Security patterns** live in the profile manifest (`verify.security`), not in project configuration.
4. **The pilot material** was reorganised on the spot: the agent and command snapshots are in `templates/`, and the
   pilot's scripts, benchmark and results are a local-only reference in `examples/harness-pilot/` (not distributed).
