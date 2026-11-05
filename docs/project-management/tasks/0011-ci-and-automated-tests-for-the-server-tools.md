---
type: task
tags: [phorward/task]
status: in-progress        # backlog | todo | in-progress | review | done | blocked | dropped
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
- [x] A CI workflow runs on every push (`.github/workflows/ci.yml`; written, not yet run: no remote): `shellcheck` on the shell scripts, `php -l` on PHP files, `composer validate`, and Python tests for `server/shard-tests.py`
- [x] Unit tests for `shard-tests.py` (`tests/test_shard_tests.py`, 13 tests) against a small fixture tree: every test lands in exactly one shard for 1 to 8 shards, stray test files are rejected, results are deterministic
- [x] `smoke-test` also exercises `with-mariadb` (verified 2026-11-05 on the first server; checks start, `SIMPLETEST_DB`, shutdown, exit status under the sandbox flags): starts, sets `SIMPLETEST_DB`, runs a command and shuts down, with no network, as non-root, on a read-only root
- [x] `smoke-test` also runs phpstan (clean file passes, undefined function fails; verified on the first server, 16/16 checks) (it currently checks `php -l`, phpcs and phpunit only)
- [x] The parity SQLite and MySQL phases are re-run from this repo and recorded (2026-11-05, first server, PHP 8.3, 4 shards x 2 cpus, HiveLog 9fb160d): SQLite 1443/1444 (3.6 min), MySQL 8.0 1444/1444 (4.4 min); the one difference is `HiveMoveTest::testTransferPurchasesAreFrozen` (passes on MySQL and MariaDB, fails on SQLite), as before. The `mysql:8.0` image on the first server was used and is kept for future runs. Output is in `~/phorward-parity/sqlite-mysql` there
- [ ] Rootless Podman verified with the same smoke test, or the README's Podman claim is removed
- [ ] (workflow `image.yml` written: weekly and manual, x86_64 and arm64 matrix, fixture module `tests/fixtures/smoke_module`; the fixture build and smoke test passed 16/16 on the first server, x86_64, 2026-11-05; the first CI run and arm64 are unverified) The image is built and smoke-tested in CI on at least one architecture (heavy: about 2.5 GB, minutes to build; a scheduled or manual job is acceptable), and both x86_64 and arm64 are covered somewhere

## Implementation notes
- Mind the repo's own test directory: `tests/` is `export-ignore`d from the Composer archive, which is the right place for fixtures.
- macOS `sed -i` needs an empty suffix argument; scripts must work with GNU and BSD tools if they run on both.

## Pending updates
- README has a plain Tests section; add a badge once the remote exists.

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0002-generic-configurable-packagist-tool]]
- Commits:: 
