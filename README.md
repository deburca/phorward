# phorward

Offload PHP lint, phpcs, phpstan and phpunit runs to an SSH-reachable Linux server. Each run happens
in a throwaway, network-less, read-only, non-root container, so the machine you work on stays light and
code you do not trust (for example model-generated code) never runs on it.

**Status: early.** The server-side image and test tools work. The Composer client (`bin/phorward`),
configuration, `doctor` and `server:install` are not written yet, and the package is not published.

## How it fits together

| Part | Where | State |
|---|---|---|
| Image profiles | `profiles/<name>/` | `drupal-module` works (PHP 8.3 and 8.5) |
| Server tools | `server/` | `phorward-run`, `build-image`, `smoke-test`, `hostile-test`, `access-test`, `authorized-key`, `parity-check`, `shard-tests.py` |
| Client | `bin/phorward`, `src/` | placeholder |

A validation image has two stages: a **base** (PHP, Composer, lint tools, Drupal core with core-dev, a
throwaway in-container MariaDB) that knows no module, and a thin **module layer** with that module's
composer dependencies and patches. Everything is baked in, so runs work with `--network none`. The tree
under test is mounted read-only at run time and never baked in.

## Requirements (server)

- Linux with Docker, or rootless Podman with its `docker` command available (the `podman-docker` package or a
  `docker` symlink to `podman`; the scripts call `docker`), plus `rsync` and `python3`
- Network access while building images; none while running them
- About 8 GB RAM for Kernel tests with MariaDB (`--memory 6g`)
- x86_64 or arm64

## The client

`bin/phorward` is being built. So far `phorward config` prints the resolved configuration and where each value
came from; the settings, their scopes and the layering rules are in [docs/configuration.md](docs/configuration.md).
`phorward doctor` checks the connection, the server and the image end to end and says how to fix what it finds.
`run` and `server:install` follow.

## Try the server tools

```bash
server/build-image --module ~/path/to/module --php 8.3 --dev-package drupal/key
server/smoke-test --module ~/path/to/module
server/build-image --profile php-library --module ~/path/to/library   # a plain PHP project
server/parity-check --module ~/path/to/module --phases mariadb
```

`phorward-run` is the SSH forced command that runs the checks (protocol in [docs/protocol.md](docs/protocol.md)).
For a local trial, with a policy file or the defaults:

```bash
tar -C ~/path/to/module -cf - . | server/phorward-run run --protocol 1 --module mymodule --checks lint,phpcs
server/phorward-run info --protocol 1
```

Access is by one restricted SSH key per client ([docs/ssh-access.md](docs/ssh-access.md)); `server/access-test` proves it
can run `phorward-run` and nothing else. Limits are the server's, set in a policy file ([docs/policy.md](docs/policy.md)). After changing it, check it with
`server/hostile-test`, which fills the run slots with hostile samples while timing the host's other services.

`build-image` builds an image from a profile ([docs/profiles.md](docs/profiles.md)): `drupal-module` for a Drupal
module, `php-library` for a plain PHP project. It reads the package name from `composer.json` and the project name
from where the profile says (a Drupal module's `*.info.yml`, or the package name). The image records the mount path and name as labels (`phorward.module-path`,
`phorward.module-name`), which the other tools read.

See [docs/image.md](docs/image.md) for the design and measurements.

## Tests

```bash
python3 -I -m unittest discover -s tests -v   # the server tools: shard-tests, phorward-run, profiles, authorized-key
composer install && vendor/bin/phpunit         # the PHP client: configuration loader and commands
shellcheck server/build-image server/smoke-test server/parity-check server/hostile-test server/access-test profiles/*/with-* profiles/*/phorward-check
```

GitHub Actions (`.github/workflows/`) runs these and `composer validate` on every push. A heavier workflow
builds the image from the fixture module in `tests/fixtures/smoke_module` and smoke-tests it weekly and on
demand, on x86_64 and arm64. Neither workflow has run yet, because the repository has no remote.

## Licence

MIT. See [LICENSE](LICENSE).
