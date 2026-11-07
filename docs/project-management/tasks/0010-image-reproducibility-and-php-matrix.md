---
type: task
tags: [phorward/task]
status: review        # backlog | todo | in-progress | review | done | blocked | dropped
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
- [x] Rebuilding the same inputs gives the same tool versions: `composer.lock` for `/opt/lint-tools` and for the Drupal project are produced once and reused (stored with the profile, or generated into a volume the build cache keeps), or a clear refresh command and policy replace them (lock files under `profiles/<name>/locks/` for the lint tools and, for `drupal-module`, the module-agnostic Drupal project; `build-image --refresh-locks` re-resolves them on purpose; resolved on PHP 8.3 so they install on 8.3 to 8.5. Verified 2026-11-07 on the first server: two builds with `--no-cache` recorded byte-identical `/opt/TOOL-VERSIONS`, and the locks resolve to exactly the versions the previous images carried. **Not locked:** the Debian packages installed with `apt` (MariaDB, the extension libraries), and a `drupal-module` project's own dependencies beyond the locked project, which are resolved when the image is built)
- [x] The base image is pinned (digest or documented tag policy) and its digest is recorded in `/opt/TOOL-VERSIONS` (digests in the manifest's `base.pins`, written by `build-image --pin-base`; recorded as `base-image=` and `composer-image=`; an unpinned base is resolved to its current digest with a warning)
- [x] The dated tag and the moving tag (`phorward:<module>-php<ver>`) have a documented meaning; a way to list and prune old images exists (`server/images list` and `prune`, dry run by default, keeps the newest 3 dated tags per name and PHP version and never touches a moving tag; tried for real on the first server, list and dry-run prune only; meanings in `docs/image.md`)
- [x] PHP 8.4 built and verified like 8.3 and 8.5 (HiveLog's CI matrix is 8.3/8.4/8.5; only 8.3 and 8.5 have been run) (built and smoke-tested on 8.3, 8.4 and 8.5 with identical tool versions; the full 1444-test HiveLog suite passes 1444 of 1444 on 8.4 and, on the smaller image, on 8.3)
- [x] Image size reviewed (about 2.5 GB): composer cache, unused extensions, drush; keep it only if the saving is real and the results stay identical (2.43 GB to 1.82 GB for the fixture module, 2.49 to 1.88 GB for HiveLog: one 452 MB layer held nothing but permission changes from a late `chmod -R`; results identical, see above; a contract test now forbids a recursive chmod outside the step that creates the files. The rest is essential; purging the build-time `-dev` libraries was not tried)

## Implementation notes
- 8.3 and 8.5 gave identical results on 1444 tests, so 8.4 is expected to pass, but it is unverified.
- Drupal's own version moves with `drupal/recommended-project:^11`; decide whether the image should follow ^11 or pin a minor. **Decided:** follow ^11, locked: the lock fixes the exact 11.x (now 11.4.12) and a deliberate `--refresh-locks` moves it to the newest 11.x, so the choice is reviewed in a diff rather than made by the day of the build.

## Pending updates
- Profile manifests carry the version policy: done (`versions.php`, `versions.policy`, `base.pins`, `locks`).
- Reading `docker history` found one layer problem, the late `chmod -R` (above); no other layer was larger than the content it adds.
- Bugs found by the new tests before they cost anything: a helper that forwarded one argument instead of all (so `--lines` was lost and `--pin-base` would have tried to pull a tag named `["8.3",`), and a heredoc that replaced a pipe (ShellCheck caught it).
- Open question for the owner: how often to refresh. Suggested: when a new Drupal 11.x or a tool release matters, or at least before each release; `phorward doctor` already shows a stale image per project, but nothing yet compares an image's locks with the profile's current ones.
- Left on the first server, for the owner to delete: `~/verify-0010/`, `~/verify-0010.sh` and its log and pid files (`~/verify-0010.log`, `~/verify-0010.pid`, `~/v83.pid`, `~/locks.log`, `~/locks.pid`), and the images built for the checks, which are tagged `phorward:smoke_module-php8.4`, `-php8.5`, `phorward:hivelog-php8.4` (the 8.3 images were rebuilt in place). `server/images prune --keep 1 --apply` would remove the old 5 November dated tags.

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0002-generic-configurable-packagist-tool]]
- Commits:: 
