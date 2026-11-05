---
type: task
tags: [phorward/task]
status: backlog        # backlog | todo | in-progress | review | done | blocked | dropped
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
- [ ] A profile is a directory with a Dockerfile and a manifest (name, what it provides, build arguments, per-check command lines, default database, default memory); the wrapper and `build-image` discover profiles from there
- [ ] `drupal-module` profile: no HiveLog strings anywhere; HiveLog still builds with the same result (smoke 11/11, identical tool versions)
- [ ] `php-library` profile: PHP, Composer, the project's own `composer.lock` installed at build time, phpunit, phpcs and phpstan; a small non-Drupal sample project passes and fails correctly (add it as a fixture in `tests/`)
- [ ] `phorward image:build [--profile …] [--php …]` builds the per-project layer on the server with network access, only on request; the client may send a hash of `composer.lock` so `doctor` can report a stale image
- [ ] Runs remain `--network none` for every profile
- [ ] Documentation explains how to add a custom profile, and the image label contract (`phorward.profile`, `phorward.module-name`, `phorward.module-path`) is written down

## Implementation notes
- Extra dev packages (HiveLog needs `drupal/key` and `drupal/simple_oauth`, as its CI installs them) are a `--dev-package` flag on `server/build-image` today. They belong in the project config (`phorward.json` / `extra.phorward`, [[0008-config-loader-doctor-and-server-install]]); keep the flag as an override.
- Known specifics still in the tools: `server/smoke-test` and `server/parity-check` default the image tag to `php8.3` (read the PHP version from a label instead); `server/shard-tests.py` only understands the Drupal layout `*/tests/src/{Kernel,Unit}` and needs a generic layout option for `php-library`; the Dockerfile removes the composer-installed copy by `web/modules/contrib/<package name part>` which assumes the Drupal installer path.
- Reuse the `base`/`final` multi-stage layout; do not name the final stage `module` (shadows the build context).
- Do not add further ecosystems until these two profiles work.

## Pending updates
- `smoke-test` picked a submodule's Unit and Kernel tests by `find | sort | head -1`; that works but is arbitrary. Let a profile manifest name its smoke tests.
- Image size is about 2.5 GB; see [[0010-image-reproducibility-and-php-matrix]].

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0002-generic-configurable-packagist-tool]]
- Commits:: 
