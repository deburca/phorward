---
type: notes
tags: [phorward/notes, session]
created: 2026-11-05
---
# 2026-11-05: Handover from the domus repo

This project began as a design question in the domus repo (a homelab server repo): could part of a test-first LLM harness be offloaded from an 8 GB PC to a home server? The design, prototype and migration all happened in one long session. This note is the hand-over for a new session started in this repo.

## What exists
- Image profile `drupal-module` (two stages: module-independent base, thin per-module layer). PHP 8.3 and 8.5 verified, identical results on 1444 Kernel/Unit tests of the HiveLog Drupal module.
- `server/build-image`, `smoke-test` (11 checks), `parity-check` (SQLite / MariaDB / MySQL comparison), `shard-tests.py`.
- Initial commit `61d099a`, MIT licence `45a6558`, then this vault. Everything is on `main`; no remote exists yet.

## Decisions already made (do not reopen without a reason)
- SSH + throwaway container beats GitLab and Forgejo for this use ([[0001-offload-validation-via-ssh-and-sandboxed-containers]]).
- Generic tool on Packagist, client + server kit in one repo, config in layers with SSH delegated to `~/.ssh/config`, limits as server policy, image profiles ([[0002-generic-configurable-packagist-tool]]).
- Kernel tests use an in-container MariaDB: identical to MySQL 8.0 on all 1444 tests, no network needed. SQLite differs on one test ([[0001-pinned-php-validation-image]]).
- Debian trixie base (Drupal 11.4 needs SQLite >= 3.45; bookworm has 3.40).
- MIT licence.

## Gotchas learned
- Do not name the Dockerfile's final stage `module`: `COPY --from=module` would resolve to the stage instead of the build context.
- PHP 8.5 has opcache built in; installing the extension fails there.
- MariaDB runs need `--memory 6g` (tmpfs and server share the limit).
- macOS `sed -i` needs an empty suffix; an earlier script bug came from GNU/BSD differences.
- A smoke test whose must-fail checks pass when the image is missing proves nothing; `smoke-test` now exits early if the image does not exist. Keep that property in any new test.
- Waiting for a background job with `pgrep -f` on its own name matches the waiting shell; wait on a PID file.
- The permission checker can block a combined rsync-plus-background-ssh command; split it and ask the owner.

## Everything pending (also in the tasks)
Tracked in this repo:
- [[0002-phorward-server-wrapper]]: write `docs/protocol.md` first, then `phorward-run`.
- [[0003-restricted-ssh-access-guide]]: decide Docker group versus rootless Podman, and test it.
- [[0004-phorward-composer-client]]: replace the `bin/phorward` placeholder; adoption in HiveLog and the harness.
- [[0005-server-policy-resource-limits-and-concurrency]]: policy file, clamping, concurrency, hostile-sample test.
- [[0006-bootstrap-repo-and-migrate-scripts]]: GitHub remote and name, branch-per-task workflow, CI.
- [[0007-image-profiles-and-module-parameters]]: manifests, `php-library` profile, dev packages into project config, remove remaining `php8.3` and Drupal-layout assumptions in the tools.
- [[0008-config-loader-doctor-and-server-install]]: settle config keys, `doctor`, `server:install`.
- [[0009-publish-phorward-to-packagist]]: vendor name, scrub homelab specifics (search), docs, publish only with the owner's approval.
- [[0010-image-reproducibility-and-php-matrix]]: lock files, base digest, PHP 8.4, image size.
- [[0011-ci-and-automated-tests-for-the-server-tools]]: shellcheck, shard tests, smoke-test for `with-mariadb` and phpstan, Podman, SQLite/MySQL parity re-run, CI on both architectures.

Outside this repo (do not act on these from here without the owner):
- domus repo: its ADR-0029/0030 and tasks 0144-0152 carry pointers to this vault; the old `scripts/validate/` copy is still there and must stay until a long-running harness test on that server has finished; the domus README should then link here; the domus-side deployment tasks (unprivileged user and key, VPN rule, credential inventory, policy values, hostile-sample test) remain there.
- HiveLog repo: its phpstan run reports 7 findings its baseline does not absorb (`ApiaryForm.php`, `HivelogCalendarCopier.php`, `CalendarCopierTest.php`, at `9fb160d`); a remote phpstan check fails on HiveLog until they are fixed or baselined. The module should later add phorward as a dev requirement with `scripts` aliases.
- The harness repo: its path is unknown here; it should call `phorward run` once the client exists.
- First server: old build scratch (`~/validate-build`, `~/phorward-build`), the pre-migration `domus-validate:*` images and a leftover `mysql:8.0` image; clean up after the harness test finishes.

## Suggested order
1. [[0011-ci-and-automated-tests-for-the-server-tools]] (small, protects everything else), then settle the protocol (start of [[0002-phorward-server-wrapper]]).
2. [[0002-phorward-server-wrapper]] and [[0005-server-policy-resource-limits-and-concurrency]] together.
3. [[0007-image-profiles-and-module-parameters]], [[0008-config-loader-doctor-and-server-install]], [[0004-phorward-composer-client]].
4. [[0003-restricted-ssh-access-guide]], [[0010-image-reproducibility-and-php-matrix]], then [[0009-publish-phorward-to-packagist]].

## Follow-ups
- Ask the owner before the first push; the repository has no remote.
