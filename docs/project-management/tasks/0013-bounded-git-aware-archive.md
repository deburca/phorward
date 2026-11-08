---
type: task
tags: [phorward/task]
status: done        # backlog | todo | in-progress | review | done | blocked | dropped
priority: high       # high | medium | low
project: "[[first-public-release]]"
area: client
created: 2026-11-08
branch: feature/0013-bounded-git-aware-archive
release: 0.1.0
depends-on: ["[[0012-verify-gate-runner-and-harness-templates]]"]
blocked-by:
---
# Task 0013: A Git-Aware, Bounded Archive of the Project

## Context
Found while adopting the harness in HiveLog ([[0012-verify-gate-runner-and-harness-templates]], section 4). `TreeArchiver` packs everything except a fixed exclude list and holds the whole tar in PHP memory. HiveLog's git-ignored `demo/web` (159 MB) and `demo/vendor` made a 200 MB archive, and `phorward verify` died with PHP's 128 MB memory limit: a bare fatal error with a stack trace, no hint of the cause. `sync.exclude` fixed that one project, but any Drupal module with a generated demo, a build directory or a `.pio` cache will hit the same wall, and the server refuses trees over its own limit (`max_archive_bytes`, 512 MiB by default) only after the upload.

## Acceptance criteria
- [x] In a git work tree the archive holds the files git tracks plus the untracked files it does not ignore (`git ls-files --cached --others --exclude-standard`), minus the built-in and `sync.exclude` patterns and the secret-looking names; outside git, or when `sync.respect_gitignore` is `false`, it behaves as before
- [x] Files deleted in the work tree but still tracked are not listed; submodules and nested repositories do not break the archive
- [x] The tar is written to a temporary file, not held by the process while it is built; a tree over the size limit (`sync.max_size`, default 256m) is refused **before** it is read into memory or sent, with a message that names the largest directories and says to add them to `sync.exclude`; exit status 2
- [x] The client no longer dies on PHP's default 128 MB `memory_limit` for a tree under the limit
- [x] Tests for the git-aware listing (ignored, untracked, deleted, nested repository, outside git), the size limit and its message
- [x] Documented in [configuration.md](../../configuration.md); HiveLog's `phorward.json` no longer needs its `sync.exclude` entries (checked by running `verify` there)

Progress 2026-11-08: `TreeArchiver::create` takes the file list from `git ls-files --cached --others --exclude-standard` (skipping tracked files deleted from disk) and feeds it to `tar` with `--null -T -`; the built-in, secret and `sync.exclude` patterns still apply. The archive is built in a temporary file (removed afterwards, also on refusal) and checked against `sync.max_size` before it is read; the refusal names the three largest two-level directories. `bin/phorward` raises PHP's memory limit to 512M when it is lower. Mutation check: making the git listing return nothing fails the ignore test. In HiveLog, with its `sync.exclude` entries removed and PHP at the default 128M, `verify` archived and ran syntax and phpcs on the first server. HiveLog's `phorward.json` therefore no longer needs the `sync.exclude` entries; removing them is a HiveLog commit, left for the owner.

## Implementation notes
- Key files: `src/Install/TreeArchiver.php`, `src/Config/Schema.php`, `src/Command/RunCommand.php`, `src/Verify/Verifier.php`, `tests/php/Install/TreeArchiverTest.php`.
- `InstallPlanner` archives fixed paths of the package itself (`server`, `profiles`); it keeps the plain behaviour.

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0002-generic-configurable-packagist-tool]]
- Commits::
