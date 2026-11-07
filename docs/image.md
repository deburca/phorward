# The `drupal-module` image

## Contents

- PHP 8.3, 8.4 or 8.5 (build argument, default 8.3; all three built and smoke-tested, and the full HiveLog suite run on
  8.3, 8.4 and 8.5) on Debian **trixie**. Not bookworm: Drupal 11.4 needs SQLite >= 3.45 for Kernel tests and
  bookworm ships 3.40.
- Composer, and the lint tools (`drupal/coder`, `phpstan`, `phpstan-drupal`, `phpstan-deprecation-rules`)
  in `/opt/lint-tools`, kept apart from the project so they never constrain its dependencies.
- A Drupal 11 project in `/opt/drupal-project` with `drupal/core-dev` (phpunit 11), drush and the module's
  own dependencies and patches.
- `with-mariadb`, and the resolved versions in `/opt/TOOL-VERSIONS`, including the digests of the base images.
- A non-root user (uid 10001). `/opt/lint-tools`, `vendor` and `web/core` are read-only.

PHP 8.5 compiles opcache into core, so the Dockerfile installs the `opcache` extension only below 8.5.

## Running it

The sandbox flags every run gets (see `server/smoke-test`):

```
--network none --read-only --cap-drop ALL --security-opt no-new-privileges
--pids-limit 512 --memory 4g --cpus 2
--tmpfs /tmp:exec,size=1g --tmpfs <drupal>/web/sites/simpletest:mode=1777,size=1g
-v <tree>:<module-path>:ro
```

Kernel tests need a database. `with-mariadb <command>` starts a throwaway MariaDB on `127.0.0.1` with its
datadir under `/tmp` (tmpfs), exports `SIMPLETEST_DB`, runs the command and stops the server. It works
with no network, as non-root, on a read-only root filesystem. Use `--memory 6g` with it: the tmpfs and the
server both count against the container's limit.

## Why MariaDB, not SQLite

Measured on a 1444-test Kernel + Unit suite, 4 shards of 2 CPUs each:

| Database | Result | Time |
|---|---|---|
| MySQL 8.0 (separate container, internal network) | 1444 / 1444 pass | 4.7 min |
| In-container MariaDB 11.8 | 1444 / 1444 pass, identical to MySQL on every test | 4.1 min |
| SQLite | 1443 / 1444 pass; one test differs | 3.8 min |
| In-container MariaDB, PHP 8.5 | 1444 / 1444 pass, identical to PHP 8.3 | 4.1 min |

SQLite is quicker but its behaviour differs from the databases most Drupal sites run on, so a result from
it can mislead. MariaDB gives MySQL-equivalent results with no network.

`server/parity-check` reproduces the comparison for any module.

## Reproducibility

"Pinned" means locked, not just recorded. A rebuild of the same inputs gives the same tool versions:

- **The base images are named by digest.** The profile manifest (`base.pins`) holds the digest of the PHP image for each
  supported version and of the Composer image. `build-image` builds `FROM` that digest, and `/opt/TOOL-VERSIONS`
  records it (`base-image=`, `composer-image=`). A base that is not pinned is resolved to what its tag points at today,
  with a warning, and that digest is recorded too.
- **The tools and the Drupal project come from lock files.** `profiles/<name>/locks/` holds a `composer.json` and a
  `composer.lock` for the lint tools and, for `drupal-module`, for the module-agnostic Drupal project. The image installs
  from them with `composer install`; nothing is resolved at build time. They are resolved on PHP 8.3, the lowest
  supported version, so they install on 8.3, 8.4 and 8.5.
- **A project's own dependencies:** a `php-library` image installs the project's `composer.lock`. A `drupal-module`
  image adds the module's dependencies on top of the locked project at build time, so packages the locked project does
  not already have are resolved when the image is built.

Moving any of this is a deliberate, reviewable act, and each of these commands changes files you then commit:

```
server/build-image --profile drupal-module --pin-base        # pin the base images to today's digests
server/build-image --profile drupal-module --refresh-locks   # re-resolve the tools and Drupal (newest 11.x)
```

The policy is "locked, moved on purpose": refresh when you want newer tools or a newer Drupal, and read the diff. To
prove a rebuild gives the same versions, build twice with `--no-cache` and compare `/opt/TOOL-VERSIONS`.

What is not locked: the Debian packages the image installs with `apt` (the MariaDB server, the libraries PHP extensions
need) float with the Debian archive on the day of the build. Their versions are recorded where it matters
(`mariadb=` in `/opt/TOOL-VERSIONS`), and the base image digest fixes the PHP and the Debian release.

## Tags, listing and pruning

Each build puts two tags on the image it makes:

| Tag | Meaning |
|---|---|
| `phorward:<name>-php<ver>` | The **moving** tag: the newest build. This is what `phorward-run` runs. |
| `phorward:<name>-php<ver>-<yyyymmdd>` | A **dated** tag: the same image, named for the day it was built. Kept so a bad rebuild can be rolled back by retagging. |

`server/images list` shows the phorward images (one line per name and PHP version, with the build date, size, the
dependency hash recorded in the image, and the dated tags). `server/images prune` removes the dated tags beyond the
newest N per name and PHP version (default 3), which frees an image once nothing else names it; it only prints what it
would do until you add `--apply`. It never touches a moving tag or an image that is not a phorward image. Untagged
layers left behind by rebuilds are the engine's to clean (`podman image prune`, `docker image prune`).

## Size

The `drupal-module` image used to be about 2.5 GB. Reading `docker history` found 452 MB of it in a single layer that
changed nothing but file permissions: a `chmod -R a-w` run in a step of its own after `vendor/` and `web/core` were
written, which on an overlay filesystem copies every file into a new layer. The bit it removed (the owner's write
permission) was root's; the run user is not the owner and the root filesystem is read-only at run time, so it protected
nothing. Permissions are now set in the step that creates the files, where they cost nothing.

| Image (PHP 8.3) | Before | After |
|---|---|---|
| fixture module | 2.43 GB | 1.82 GB |
| HiveLog | 2.49 GB | 1.88 GB |
| `php-library` fixture | | 1.14 GB |

About 25% less. The results are identical: the full 1444-test HiveLog suite passes 1444 of 1444 on the smaller image
on PHP 8.3 and 8.4 (it did before the change on 8.3 and 8.5), and every smoke test passes.

What remains is what the image is for: PHP and Debian (about 0.6 GB), the MariaDB server that Kernel tests use (355 MB),
Drupal core with `core-dev` and drush (350 MB), and the lint tools (99 MB). The build-time `-dev` libraries in the
89 MB apt layer could be purged after the PHP extensions are compiled; that was not tried, because the saving is small
next to the risk of removing a library an extension needs at run time.

## Known limits

- phpstan runs from the project (`vendor/bin/phpstan`), as module CI usually does, not from the lint-tools
  copy.
