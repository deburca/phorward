---
type: task
tags: [phorward/task]
status: review        # backlog | todo | in-progress | review | done | blocked | dropped
priority: medium       # high | medium | low
project: "[[first-public-release]]"
area: client
created: 2026-11-05
branch: feature/0004-phorward-composer-client
release: 0.1.0
depends-on: ["[[0002-phorward-server-wrapper]]", "[[0003-restricted-ssh-access-guide]]", "[[0008-config-loader-doctor-and-server-install]]"]
blocked-by:
---
# Task 0004: phorward Composer Client (`bin/phorward`, Composer Script Entry Points)

## Context
Origin: domus task 0147. The client is a Composer package a project adds with `composer require --dev`. It ships `bin/phorward`; the project defines aliases in its own `scripts` (a dependency's `scripts` section never runs). The test-first harness and the HiveLog Drupal module are the first consumers ([[0002-generic-configurable-packagist-tool]]). Today `bin/phorward` is a placeholder that prints its version.

## Acceptance criteria
- [x] `phorward run [--checks …] [--profile …] [--format json|junit|human]` tars the working tree (default excludes `.git`, `vendor`, `node_modules`, overridable by config), pipes it over SSH to the forced command and parses the JSON result (`src/Command/RunCommand.php`; reports in `src/Report/`; `docs/client.md`)
- [x] Exit codes distinguish passed, failed, infrastructure error and configuration error (see [[0002-phorward-server-wrapper]])
- [x] Infrastructure failures (SSH error, timeout, OOM, wrapper error, protocol mismatch) are classified separately from test failures; the former are retried with bounded backoff and only then surface as infrastructure errors the caller can treat as "not the model's fault" (done for the transient kinds: a dropped ssh connection, a garbled reply, a transient engine error, and a busy server within a `wait` budget. **A resource limit (the run's time or memory ran out) is deliberately not retried**: it would run out again, and three retries of a runaway test could cost an hour; it is reported at once as exit 70 with a hint. Protocol mismatches and refusals are configuration errors, exit 2, also not retried)
- [x] Clear error when the server is unreachable or the wrapper is missing, pointing to `phorward doctor`
- [x] Every result reports the server's tool versions and protocol version
- [x] Minimal dependencies (for example `symfony/process`); supports PHP >= 8.3
- [x] A documented example `composer.json` with `scripts` aliases (`"validate": "phorward run"`, `"validate:lint": …`) works end to end when phorward is a `require-dev` (`docs/client.md`; a test installs the package as a copy and runs the aliases with `composer run-script`, opt-in with `PHORWARD_TEST_COMPOSER=1`, and runs in CI)

### Adoption (work outside this repo, tracked here)
- [ ] HiveLog adds phorward as a dev requirement and defines its scripts; the harness uses `composer validate` (or `vendor/bin/phorward`) instead of local lint/phpcs/phpunit
- [ ] Local-versus-remote parity: the same sample set gives identical verdicts locally and on the server
- [ ] Every result is appended to the harness's own run log (attempt id, model, timings, verdict)
- [ ] Per-attempt latency and local RAM use measured, to confirm the offload goal (an 8 GB machine) was met

## Implementation notes
- Key files (to create): `bin/phorward` (replace the placeholder), `src/`
- Config loading, `doctor` and `server:install` are [[0008-config-loader-doctor-and-server-install]]; this task consumes them.
- For HiveLog the image needs `drupal/key` and `drupal/simple_oauth` as extra dev packages (CI installs them explicitly). Today they are passed to `server/build-image --dev-package`; they must come from the project config instead ([[0007-image-profiles-and-module-parameters]]).
- Harness-side integration code lives in the harness repository; record its path here once known (it was not known when this task was written).

## Pending updates
- HiveLog's phpstan run reports 7 findings not absorbed by its baseline (`ApiaryForm.php`, `HivelogCalendarCopier.php`, `CalendarCopierTest.php`, tree at `9fb160d`). Any remote phpstan check will fail on HiveLog until HiveLog fixes or baselines them; this is a HiveLog task, but it blocks the adoption criteria above.
- HiveLog's Kernel suite has one test that fails on SQLite only; the default is MariaDB, so no action unless SQLite mode is offered.
- Verified 2026-11-07 from this Mac with the real `ssh` against a throwaway restricted `sshd` on the first server, the real wrapper and rootless Podman: a full run of the `php-library` fixture passed in about 5 s including the jump host; a changed assertion gave exit 1 with PHPUnit's own output; a test that never finishes gave `ERROR`, exit 70, "no verdict", not retried, with a hint; JUnit and JSON were valid and the JSON `client` object correct; an unreachable host was retried and ended as exit 70 pointing to `phorward doctor`. 310 PHPUnit tests (on PHP 8.3.36 on Linux too); mutation checks broke the retry classification and the terminal sanitising and were caught.
- Server text is untrusted: the human report strips terminal escape sequences and control characters (a test drives the client with `ESC[2J`, title and hyperlink sequences, and a carriage-return overwrite), and the JUnit report removes characters XML cannot carry.
- **Adoption (HiveLog, the harness, parity, run log, latency and RAM measurements) is outside this repository and is not started**, so the Adoption criteria above stay open. The HiveLog phpstan baseline problem below still blocks them.
- Not built: streaming progress while a long run is in flight (the client waits for the one result), and a streaming tar for very large trees (it is built in memory; over 512 MiB is refused with a hint to use `sync.exclude`).

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0001-offload-validation-via-ssh-and-sandboxed-containers]], [[0002-generic-configurable-packagist-tool]]
- Commits:: 
