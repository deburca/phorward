---
type: task
tags: [phorward/task]
status: backlog        # backlog | todo | in-progress | review | done | blocked | dropped
priority: medium       # high | medium | low
project: "[[first-public-release]]"
area: image
created: 2026-11-05
branch: feature/0010-image-reproducibility-and-php-matrix
release: 0.1.0
depends-on: ["[[0001-pinned-php-validation-image]]"]
blocked-by:
---
# Task 0010: Image Reproducibility and PHP Version Matrix

## Context
New in this repo (found while closing [[0001-pinned-php-validation-image]]). The image records the versions it resolved, but a rebuild resolves them again, so two builds on different days can differ. "Pinned" currently means "recorded", not "locked". A validation result is only comparable across time if the toolchain is.

## Acceptance criteria
- [ ] Rebuilding the same inputs gives the same tool versions: `composer.lock` for `/opt/lint-tools` and for the Drupal project are produced once and reused (stored with the profile, or generated into a volume the build cache keeps), or a clear refresh command and policy replace them
- [ ] The base image is pinned (digest or documented tag policy) and its digest is recorded in `/opt/TOOL-VERSIONS`
- [ ] The dated tag and the moving tag (`phorward:<module>-php<ver>`) have a documented meaning; a way to list and prune old images exists
- [ ] PHP 8.4 built and verified like 8.3 and 8.5 (HiveLog's CI matrix is 8.3/8.4/8.5; only 8.3 and 8.5 have been run)
- [ ] Image size reviewed (about 2.5 GB): composer cache, unused extensions, drush; keep it only if the saving is real and the results stay identical

## Implementation notes
- 8.3 and 8.5 gave identical results on 1444 tests, so 8.4 is expected to pass, but it is unverified.
- Drupal's own version moves with `drupal/recommended-project:^11`; decide whether the image should follow ^11 or pin a minor.

## Pending updates
- Profile manifests ([[0007-image-profiles-and-module-parameters]]) should carry the version policy.

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0002-generic-configurable-packagist-tool]]
- Commits:: 
