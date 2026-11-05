# The `drupal-module` image

## Contents

- PHP (build argument, default 8.3; 8.5 verified) on Debian **trixie**. Not bookworm: Drupal 11.4 needs
  SQLite >= 3.45 for Kernel tests and bookworm ships 3.40.
- Composer, and the lint tools (`drupal/coder`, `phpstan`, `phpstan-drupal`, `phpstan-deprecation-rules`)
  in `/opt/lint-tools`, kept apart from the project so they never constrain its dependencies.
- A Drupal 11 project in `/opt/drupal-project` with `drupal/core-dev` (phpunit 11), drush and the module's
  own dependencies and patches.
- `with-mariadb`, and the resolved versions in `/opt/TOOL-VERSIONS`.
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

## Known limits

- The image is about 2.5 GB.
- phpstan runs from the project (`vendor/bin/phpstan`), as module CI usually does, not from the lint-tools
  copy.
