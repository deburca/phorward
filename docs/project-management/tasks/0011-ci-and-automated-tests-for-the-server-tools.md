---
type: task
tags: [phorward/task]
status: backlog        # backlog | todo | in-progress | review | done | blocked | dropped
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
- [ ] A CI workflow runs on every push: `shellcheck` on the shell scripts, `php -l` on PHP files, `composer validate`, and Python tests for `server/shard-tests.py`
- [ ] Unit tests for `shard-tests.py` against a small fixture tree: every test lands in exactly one shard for 1 to 8 shards, stray test files are rejected, results are deterministic
- [ ] `smoke-test` also exercises `with-mariadb`: starts, sets `SIMPLETEST_DB`, runs a command and shuts down, with no network, as non-root, on a read-only root
- [ ] `smoke-test` also runs phpstan (it currently checks `php -l`, phpcs and phpunit only)
- [ ] The parity SQLite and MySQL phases are re-run from this repo and recorded (only the MariaDB phase has been re-run since the migration; the earlier numbers are in [[0001-pinned-php-validation-image]]); the leftover `mysql:8.0` image on the first server is either used for that or removed
- [ ] Rootless Podman verified with the same smoke test, or the README's Podman claim is removed
- [ ] The image is built and smoke-tested in CI on at least one architecture (heavy: about 2.5 GB, minutes to build; a scheduled or manual job is acceptable), and both x86_64 and arm64 are covered somewhere

## Implementation notes
- Mind the repo's own test directory: `tests/` is `export-ignore`d from the Composer archive, which is the right place for fixtures.
- macOS `sed -i` needs an empty suffix argument; scripts must work with GNU and BSD tools if they run on both.

## Pending updates
- Add the status to the README once CI exists (badge or a plain line).

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0002-generic-configurable-packagist-tool]]
- Commits:: 
