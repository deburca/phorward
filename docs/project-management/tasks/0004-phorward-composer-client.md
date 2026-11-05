---
type: task
tags: [phorward/task]
status: backlog        # backlog | todo | in-progress | review | done | blocked | dropped
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
- [ ] `phorward run [--checks …] [--profile …] [--format json|junit|human]` tars the working tree (default excludes `.git`, `vendor`, `node_modules`, overridable by config), pipes it over SSH to the forced command and parses the JSON result
- [ ] Exit codes distinguish passed, failed, infrastructure error and configuration error (see [[0002-phorward-server-wrapper]])
- [ ] Infrastructure failures (SSH error, timeout, OOM, wrapper error, protocol mismatch) are classified separately from test failures; the former are retried with bounded backoff and only then surface as infrastructure errors the caller can treat as "not the model's fault"
- [ ] Clear error when the server is unreachable or the wrapper is missing, pointing to `phorward doctor`
- [ ] Every result reports the server's tool versions and protocol version
- [ ] Minimal dependencies (for example `symfony/process`); supports PHP >= 8.3
- [ ] A documented example `composer.json` with `scripts` aliases (`"validate": "phorward run"`, `"validate:lint": …`) works end to end when phorward is a `require-dev`

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

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0001-offload-validation-via-ssh-and-sandboxed-containers]], [[0002-generic-configurable-packagist-tool]]
- Commits:: 
