---
type: task
tags: [phorward/task]
status: done               # backlog | todo | in-progress | review | done | blocked | dropped
priority: medium       # high | medium | low
project: "[[first-public-release]]"
area: tests
created: 2026-11-05
branch: feature/0011-ci-and-automated-tests-for-the-server-tools
release: 0.1.0
depends-on: ["[[0006-bootstrap-repo-and-migrate-scripts]]"]
blocked-by:
---
# Task 0011: CI and Automated Tests for the Server Tools

## Context
New in this repo. The scripts in `server/` and `profiles/` have been verified by hand on one x86_64 server and one arm64 laptop. Nothing runs automatically, and several claims in the README (Podman, other hosts) are untested.

## Acceptance criteria
- [x] A CI workflow runs on every push (`.github/workflows/ci.yml`; green on GitHub on 2026-11-07, 1m17s, including the PHP client tests with the Composer test enabled): `shellcheck` on the shell scripts, `php -l` on PHP files, `composer validate`, and Python tests for `server/shard-tests.py`
- [x] Unit tests for `shard-tests.py` (`tests/test_shard_tests.py`, 13 tests) against a small fixture tree: every test lands in exactly one shard for 1 to 8 shards, stray test files are rejected, results are deterministic
- [x] `smoke-test` also exercises `with-mariadb` (verified 2026-11-05 on the first server; checks start, `SIMPLETEST_DB`, shutdown, exit status under the sandbox flags): starts, sets `SIMPLETEST_DB`, runs a command and shuts down, with no network, as non-root, on a read-only root
- [x] `smoke-test` also runs phpstan (clean file passes, undefined function fails; verified on the first server, 16/16 checks) (it currently checks `php -l`, phpcs and phpunit only)
- [x] The parity SQLite and MySQL phases are re-run from this repo and recorded (2026-11-05, first server, PHP 8.3, 4 shards x 2 cpus, HiveLog 9fb160d): SQLite 1443/1444 (3.6 min), MySQL 8.0 1444/1444 (4.4 min); the one difference is `HiveMoveTest::testTransferPurchasesAreFrozen` (passes on MySQL and MariaDB, fails on SQLite), as before. The `mysql:8.0` image on the first server was used and is kept for future runs. Output is in `~/phorward-parity/sqlite-mysql` there
- [x] Rootless Podman verified with the same smoke test (2026-11-05, first server, Podman 5.7.0 on Ubuntu 26.04, `docker` symlinked to `podman`): build and `smoke-test` 16/16. It needed fully qualified base image names in the Dockerfile and `parity-check` (`docker.io/library/...`); Podman does not resolve short names. `parity-check` also ran under Podman (SQLite, MariaDB and MySQL phases, 4 shards x 2 cpus): SQLite 1443/1444, MariaDB 1444/1444, MySQL 1444/1444, the same single SQLite difference as under Docker, MariaDB identical to MySQL (output in `~/phorward-parity/podman` on the first server). The scripts still call `docker` by name (a `docker` symlink or `podman-docker` is required)
- [x] The image is built and smoke-tested in CI on at least one architecture (heavy: about 2.5 GB, minutes to build; a scheduled or manual job is acceptable), and both x86_64 and arm64 are covered somewhere (`image.yml`, weekly and manual: run by hand on 2026-11-07, all four jobs green in 1m37s to 2m18s each: both profiles on `ubuntu-latest` (x86_64) and `ubuntu-24.04-arm`; 16 checks for `drupal-module` and 13 for `php-library`, none failing. The runners used their preinstalled rootless Podman and pulled the base image by the manifest's pinned digest, which therefore resolves on both architectures; composer installed the locks on arm64. This is the first verification on arm64)

## Implementation notes
- Mind the repo's own test directory: `tests/` is `export-ignore`d from the Composer archive, which is the right place for fixtures.
- macOS `sed -i` needs an empty suffix argument; scripts must work with GNU and BSD tools if they run on both.

## Pending updates
- README has a plain Tests section; a CI badge is optional now that the remote exists.
- **First CI run on GitHub, found 2026-11-07.** The repository is `deburca/phordward` (public; note the spelling), `main` at the "harness build and templates" commit. One workflow run, on that push, passed (the `static` job: composer validate, php -l, shellcheck, python syntax, the Python unit tests, shard checks). It covered the Python side as it stood after task 0003; the PHP client (tasks 0008, 0004) and tasks 0005 to 0010 had not run on GitHub. The `Image smoke test` workflow is scheduled (Mondays 04:17 UTC) and manual; it has not run.
- **Linux dry run of the whole CI job before pushing the rest**, in a throwaway Debian container with a non-root user, Python 3.13 and ShellCheck 0.10, with every step of `ci.yml`. It found four problems that passing on the Mac had hidden, all fixed on `feature/0011-linux-ci-portability`: ShellCheck 0.10 (and the older one on GitHub's Ubuntu) flags `A && B || C` (SC2015) and functions called indirectly (SC2317) in `build-image`, `smoke-test`, `parity-check` and `hostile-test`, which the Homebrew ShellCheck on the Mac does not; `build-image --print-name` demanded a container engine though it only reads files; a Python test closed `stdin` and then called `communicate()`, which raises on Python 3.13; and the name-derivation snippet, once indented inside a function, raised `IndentationError` on Python 3.13 (Python 3.15 dedents `-c` code, so it passed locally). After the fixes every step passes there: 310 PHP tests (none skipped) and 112 Python tests.
- Lesson kept: run the CI steps on the oldest Python and ShellCheck CI will use, not only on the development machine.

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0002-generic-configurable-packagist-tool]]
- Commits:: 
