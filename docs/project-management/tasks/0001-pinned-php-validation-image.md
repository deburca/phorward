---
type: task
tags: [phorward/task]
status: done        # backlog | todo | in-progress | review | done | blocked | dropped
priority: high       # high | medium | low
project: "[[first-public-release]]"
area: image
created: 2026-11-05
branch: feature/0001-pinned-php-validation-image
release: 0.1.0
depends-on: []
blocked-by:
---
# Task 0001: Pinned PHP Validation Image (PHP, Composer, phpcs, phpstan, phpunit)

## Context
Origin: prototype task 0144 (the prototype is a private server repository where this was first built). Implements [[0001-offload-validation-via-ssh-and-sandboxed-containers]]. Results must match the local setup and not drift, so the PHP version and tools are pinned in one image built from this repo. This task is the record of the image's design and measurements; further work on it lives in [[0007-image-profiles-and-module-parameters]], [[0010-image-reproducibility-and-php-matrix]] and [[0011-ci-and-automated-tests-for-the-server-tools]].

## Acceptance criteria
- [x] Image with PHP (build arg, default 8.3), Composer, phpcs/coder ^9, phpstan 2, phpunit 11 via drupal/core-dev, Drupal 11 core; resolved versions recorded in `/opt/TOOL-VERSIONS`
- [x] Runs as non-root (uid 10001) with a read-only root filesystem, read-only `vendor`/`core`/module mount, tmpfs for `/tmp` and `web/sites/simpletest`
- [x] Dependencies baked in, so runs work with `--network none`
- [x] `build-image` builds and tags the image and prints the recorded versions
- [x] `smoke-test`: good tree passes php -l, phpcs, Unit + Kernel phpunit; a deliberately broken tree is caught by each; sandbox checks hold (11 checks)
- [x] Built and smoke-tested on arm64 (a Mac) and on x86_64 (the first server, 8C/16T, 96 GB)
- [x] Database parity measured (1444 Kernel/Unit tests of the HiveLog module, 4 shards x 2 CPUs): in-container MariaDB 11.8 matches MySQL 8.0 on every test (4.1 min vs 4.7 min); SQLite differs on one test (`HiveMoveTest::testTransferPurchasesAreFrozen`, a boolean filter on `transfer`; cause not diagnosed), 1443/1444 in 3.8 min. Outcome: MariaDB via `with-mariadb` is the default Kernel-test database
- [x] PHP 8.5 variant: PHP 8.5.12, same tool versions, 1444/1444 on MariaDB, identical to 8.3
- [x] Module scope decided: module-agnostic base plus a thin per-module layer (multi-stage Dockerfile); generalised into a `drupal-module` profile, verified from this repo on the first server (smoke 11/11, MariaDB run 1444/1444)

## Implementation notes
- Key files: `profiles/drupal-module/Dockerfile`, `profiles/drupal-module/with-mariadb`, `server/build-image`, `server/smoke-test`, `server/parity-check`
- Base is Debian **trixie**, not bookworm: Drupal 11.4 needs SQLite >= 3.45 for Kernel tests and bookworm ships 3.40.
- `with-mariadb <cmd>` starts a throwaway MariaDB 11.8 on 127.0.0.1 with its datadir under `/tmp` (tmpfs), exports `SIMPLETEST_DB`, runs the command, shuts down. Works under `--network none`, as non-root, on a read-only root. Use `--memory 6g`: tmpfs and the server both count against the limit. Adds ~200 MB.
- PHP 8.5 compiles opcache into core, so the Dockerfile installs the `opcache` extension only below 8.5.
- The final stage must not be named `module`: `COPY --from=module` has to resolve to the build context, and a stage with the same name shadows it.
- phpstan runs from the project (`vendor/bin/phpstan`) as module CI does. On the HiveLog tree at `9fb160d` it reports 7 findings in `ApiaryForm.php`, `HivelogCalendarCopier.php` and `CalendarCopierTest.php` that the baseline does not absorb. The same image reports none on an older HiveLog copy, so these come from HiveLog's own recent code, not the image. That is a HiveLog-side fix, not a phorward one.

## Pending updates
- Reproducibility and the PHP 8.4 check: [[0010-image-reproducibility-and-php-matrix]]
- `smoke-test` does not yet exercise `with-mariadb`: [[0011-ci-and-automated-tests-for-the-server-tools]]
- Diagnose the SQLite-only test failure if SQLite is ever to be offered as a fast mode (not needed while MariaDB is the default)

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0001-offload-validation-via-ssh-and-sandboxed-containers]]
- Commits:: 61d099a (migration into this repo)
