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
| Server tools | `server/` | `build-image`, `smoke-test`, `parity-check`, `shard-tests.py` |
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

## Try the server tools

```bash
server/build-image --module ~/path/to/module --php 8.3 --dev-package drupal/key
server/smoke-test --module ~/path/to/module
server/parity-check --module ~/path/to/module --phases mariadb
```

`phorward-run` is the SSH forced command that runs the checks (protocol in [docs/protocol.md](docs/protocol.md)).
For a local trial, with a policy file or the defaults:

```bash
tar -C ~/path/to/module -cf - . | server/phorward-run run --protocol 1 --module mymodule --checks lint,phpcs
server/phorward-run info --protocol 1
```

`build-image` reads the package name from the module's `composer.json` and the machine name from its
single `*.info.yml`. The image records the mount path and name as labels (`phorward.module-path`,
`phorward.module-name`), which the other tools read.

See [docs/image.md](docs/image.md) for the design and measurements.

## Tests

```bash
python3 -I -m unittest discover -s tests -v   # unit tests for server/shard-tests.py
shellcheck server/build-image server/smoke-test server/parity-check profiles/*/with-mariadb
```

GitHub Actions (`.github/workflows/`) runs these and `composer validate` on every push. A heavier workflow
builds the image from the fixture module in `tests/fixtures/smoke_module` and smoke-tests it weekly and on
demand, on x86_64 and arm64. Neither workflow has run yet, because the repository has no remote.

## Licence

MIT. See [LICENSE](LICENSE).
