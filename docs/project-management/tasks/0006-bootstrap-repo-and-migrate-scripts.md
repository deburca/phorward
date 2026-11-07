---
type: task
tags: [phorward/task]
status: done               # backlog | todo | in-progress | review | done | blocked | dropped
priority: high       # high | medium | low
project: "[[first-public-release]]"
area: repo
created: 2026-11-05
branch: feature/0006-bootstrap-repo-and-migrate-scripts
release: 0.1.0
depends-on: ["[[0001-pinned-php-validation-image]]"]
blocked-by:
---
# Task 0006: Bootstrap the Repository and Migrate the Validation Scripts

## Context
Origin: domus task 0149. [[0002-generic-configurable-packagist-tool]] moved the validation offload out of the domus repo into this one. This task covers the skeleton, the migrated and generalised scripts, and the project hygiene around them.

## Acceptance criteria
- [x] Initial commit `61d099a` (2026-11-05): `composer.json`, `bin/phorward` placeholder, `README.md`, `.gitattributes` (`export-ignore` for tests and docs), `.gitignore`, `docs/image.md`, `src/`, `tests/`, `server/`, `profiles/drupal-module/`
- [x] MIT licence chosen and `LICENSE` added (`45a6558`)
- [x] Scripts migrated and de-specialised: module path, name and package come from `--module`, `composer.json`, `*.info.yml` and image labels; sharding script ported to `server/shard-tests.py`; `--dev-package` replaces hardcoded extras
- [x] Verified from this repo on the first server: `build-image`, `smoke-test` 11/11, `parity-check --phases mariadb` 1444/1444
- [x] Project-management vault for this repo, with the migrated ADRs and tasks; `CLAUDE.md` for a fresh session
- [x] A remote is configured (GitHub), default branch `main`, and the repository name is settled (2026-11-07: `github.com/deburca/phorward`, public; it was first created as `phordward`, a typo, and renamed by the owner before the first full push, `792cb9b..fb87166`)
- [x] CI for phorward itself exists: see [[0011-ci-and-automated-tests-for-the-server-tools]] (green on GitHub on 2026-11-07)
- [x] Branch-per-task workflow adopted (`feature/NNNN-slug`; `main` is fast-forwarded to a finished branch; small corrections to notes have been committed straight to `main`); every task from 0002 onward was done on its own branch

## Implementation notes
- Names fixed by [[0002-generic-configurable-packagist-tool]]: client `bin/phorward`, server wrapper `phorward-run`, project file `phorward.json`, user file `~/.config/phorward/config.json`, env prefix `PHORWARD_`, policy `/etc/phorward/policy.json`.
- Image tags: `phorward:<module>-php<ver>` (moving) and `phorward:<module>-php<ver>-<date>` (dated); base `phorward-base:php<ver>`.
- Composer package name `deburca/phorward` is provisional ([[0009-publish-phorward-to-packagist]]).

## Pending updates
- In the domus repo: the old `scripts/validate/` copy is still there and is removed only after a long-running harness test on that server has finished (removing it earlier could break that run); then point its README at phorward. Not a task in this repo.
- On the first server: `~/validate-build/` (old build scratch) and `~/phorward-build/` (this repo's synced copy) exist; a `mysql:8.0` image from the parity runs is still pulled; `domus-validate:php8.3` and `php8.5` are the pre-migration images. Clean up when convenient and when the harness run is done.

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0002-generic-configurable-packagist-tool]]
- Commits:: 61d099a, 45a6558
