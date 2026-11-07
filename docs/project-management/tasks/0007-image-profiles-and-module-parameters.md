---
type: task
tags: [phorward/task]
status: review        # backlog | todo | in-progress | review | done | blocked | dropped
priority: medium       # high | medium | low
project: "[[first-public-release]]"
area: image
created: 2026-11-05
branch: feature/0007-image-profiles-and-module-parameters
release: 0.1.0
depends-on: ["[[0006-bootstrap-repo-and-migrate-scripts]]"]
blocked-by:
---
# Task 0007: Image Profiles and Module Parameters

## Context
Origin: domus task 0150. The `drupal-module` profile is parameterised (package, machine name, extra dev packages are build args), but there is only one profile, no manifest, and some configuration still lives on the command line. [[0002-generic-configurable-packagist-tool]] replaces this with profiles.

## Acceptance criteria
- [x] A profile is a directory with a Dockerfile and a manifest (name, what it provides, build arguments, per-check command lines, default database, default memory); the wrapper and `build-image` discover profiles from there
- [x] `drupal-module` profile: no HiveLog strings anywhere; HiveLog still builds with the same result (smoke 11/11, identical tool versions) (2026-11-07, first server: rebuilt with the changed Dockerfile; the new `smoke-test` passes 22/22 on HiveLog and on the fixture; tool versions identical except `drupal-core` 11.4.11 to 11.4.12, an upstream patch release picked up because the base layer had been evicted from the build cache, which is the gap task 0010 covers; a repository search finds the string only in a test that asserts its absence)
- [x] `php-library` profile: PHP, Composer, the project's own `composer.lock` installed at build time, phpunit, phpcs and phpstan; a small non-Drupal sample project passes and fails correctly (add it as a fixture in `tests/`) (`tests/fixtures/php_library_sample`, with a real `composer.lock`; verified on the first server under Docker and rootless Podman: smoke test 18/18, a full four-check run takes 2.4 s, a deliberately wrong assertion fails with PHPUnit's own output, and `hostile-test` holds on it)
- [ ] `phorward image:build [--profile …] [--php …]` builds the per-project layer on the server with network access, only on request; the client may send a hash of `composer.lock` so `doctor` can report a stale image (server half done: `build-image` builds on request and records the hash in the label `phorward.lock-sha256`; the `phorward image:build` command and the `doctor` comparison belong to the client, tasks 0004 and 0008, and whether a client may trigger a networked build at all is a trust decision to make there)
- [x] Runs remain `--network none` for every profile
- [x] Documentation explains how to add a custom profile, and the image label contract (`phorward.profile`, `phorward.module-name`, `phorward.module-path`) is written down

## Implementation notes
- Extra dev packages (HiveLog needs `drupal/key` and `drupal/simple_oauth`, as its CI installs them) are a `--dev-package` flag on `server/build-image` today. They belong in the project config (`phorward.json` / `extra.phorward`, [[0008-config-loader-doctor-and-server-install]]); keep the flag as an override.
- Known specifics still in the tools: `server/smoke-test` and `server/parity-check` default the image tag to `php8.3` (read the PHP version from a label instead); `server/shard-tests.py` only understands the Drupal layout `*/tests/src/{Kernel,Unit}` and needs a generic layout option for `php-library`; the Dockerfile removes the composer-installed copy by `web/modules/contrib/<package name part>` which assumes the Drupal installer path.
- Reuse the `base`/`final` multi-stage layout; do not name the final stage `module` (shadows the build context).
- Do not add further ecosystems until these two profiles work.

## Pending updates
- Done as part of this task, beyond the criteria: `smoke-test` now runs the checks through `phorward-run` (so it tests the path a client uses) and takes its fixtures from the manifest; `build-image`, `smoke-test`, `parity-check` and `hostile-test` honour `PHORWARD_ENGINE` and no longer assume `docker` or PHP 8.3; `shard-tests.py` has a `flat` layout; the Dockerfile finds the Composer-installed copy with `composer show --path` instead of assuming Drupal's installer path; the build arguments are now `PROJECT_*`; `--dev-package` is refused for profiles that do not take it.
- Found by running it: a helper that fails during setup must exit 125, or a must-fail smoke case passes for the wrong reason (it did, until fixed); and `parity-check` could not see a test class dropped from every phase (PHPUnit ignores a docblock `@group` on a class that has attributes), so it now fails when a test file runs no tests.
- Dev packages still go through `--dev-package`; they move into project config with the client (task 0008).
- `smoke-test` picked a submodule's Unit and Kernel tests by `find | sort | head -1`; that works but is arbitrary. Let a profile manifest name its smoke tests.
- Image size is about 2.5 GB for `drupal-module`; see [[0010-image-reproducibility-and-php-matrix]]. Not measured for `php-library`.
- `smoke-test` is no longer a Drupal-only pick of `find | sort | head -1`: the manifest names its test patterns.

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0002-generic-configurable-packagist-tool]]
- Commits:: 
