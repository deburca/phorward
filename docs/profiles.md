# Image profiles

A **profile** is the recipe for one kind of sandbox image. It is a directory, `profiles/<name>/`, holding a
`Dockerfile` that builds the image and a `manifest.json` that tells the tools everything else they need to know
about it: what checks it offers, the command line of each, its databases, its defaults, and how to smoke-test
it. The tools carry no knowledge of any ecosystem; they read it from here.

| Profile | For | Checks | Database |
|---|---|---|---|
| `drupal-module` | A Drupal 11 module: Drupal core and `core-dev` are baked in, so Kernel and Unit tests run offline | lint, phpcs (Drupal standard), phpstan, phpunit | `mariadb` (in-container, default), `sqlite`, `none` |
| `php-library` | A plain PHP library or application: the project's own `composer.lock` is installed at build time | lint, phpcs (the project's ruleset, else PSR-12), phpstan (the project's config, else level 5), phpunit | `none` |

`server/build-image --list-profiles` prints the profiles found.

## How an image is built and used

```
server/build-image --profile php-library --module ~/src/my-lib      # network needed, once
server/smoke-test  --profile php-library --module ~/src/my-lib      # proves the image and the sandbox
tar -C ~/src/my-lib -cf - . | server/phorward-run run --protocol 1 --profile php-library --module my_lib
```

Every profile builds in two stages:

- **`base`** knows nothing about any project: PHP, Composer, the fallback tools, helper scripts. It is cached
  and shared by every project of the profile.
- **`final`** (the default target) adds one project: its Composer dependencies are installed from the checkout
  given as the `module` build context, then the image is locked down (non-root, read-only trees).

The tree under test is never baked in. It is mounted read-only at run time, and runs use `--network none`
whatever the profile.

The build installs only what the project declares, so an image goes **stale** when the project's dependencies
change. Each image records a hash of the file that defines them in the label `phorward.lock-sha256`
(`composer.lock` for `php-library`, `composer.json` for `drupal-module`), so a tool can compare it with the
checkout and say that a rebuild is due. Nothing rebuilds automatically; building needs network access and is
always a deliberate act.

## The image contract

What `phorward-run`, `smoke-test` and the other tools rely on. A profile must provide all of it.

**Labels** (set in the `final` stage):

| Label | Meaning |
|---|---|
| `phorward.profile` | The profile name, equal to its directory name |
| `phorward.module-name` | The project's short name: lowercase letters, digits and underscores, starting with a letter. It selects the image (`--module`) and appears in the tag |
| `phorward.module-path` | Where the tree under test is mounted, read-only |
| `phorward.lock-sha256` | Hash of the dependency definition the image was built from, or `unknown` |

**Tags:** `phorward:<name>-php<version>` is the moving tag `phorward-run` finds; `…-<yyyymmdd>` is a dated copy
for humans. **User:** non-root, uid 10001. **`/opt/TOOL-VERSIONS`:** `key=value` lines of the resolved versions,
returned in every result.

**Dockerfile:** stages `php-base` (PHP, Composer, extensions), `locks` (resolves the tools afresh; only
`--refresh-locks` builds it), `base` (installs from the committed locks) and `final` (`FROM base AS final`); never a
stage named `module`, which would shadow the build context of that name. `COPY --from=module` reads the project
checkout. Build arguments the tools pass: `PHP_VERSION`, `PHP_IMAGE` and `COMPOSER_IMAGE` (the base images, by digest
from the manifest's pins), `PROJECT_PACKAGE` (Composer package name), `PROJECT_NAME`, `PROJECT_REV`, `LOCK_SHA256`, and
`DEV_PACKAGES` only when `build.dev_packages` is true. Nothing is resolved outside the `locks` stage: tools install
from `locks/`. A recursive `chmod` belongs in the same step that creates the files; in a step of its own it copies every
file into a new layer (a contract test enforces this).

## The manifest

`manifest.json`, with every key the tools read:

| Key | Meaning |
|---|---|
| `profile`, `description` | Name (equal to the directory) and a one-line description |
| `provides` | The checks offered; equal to the keys of `checks` |
| `build.name_from` | How `build-image` derives the project name: `info.yml` (the single `*.info.yml`) or `composer-package` (the package name, `vendor/my-lib` becoming `my_lib`) |
| `build.dev_packages` | Whether `--dev-package` is accepted |
| `build.needs_lock` | Whether the project must have a `composer.lock` |
| `build.stale_hash_file` | The file whose hash goes into `phorward.lock-sha256` |
| `base.php_image`, `base.composer_image` | The unpinned names of the base images; `{php}` stands for the PHP version |
| `base.pins` | The digests the build starts from, per PHP version and for Composer. Written by `build-image --pin-base` |
| `versions.php` | The PHP versions this profile is built and verified for |
| `versions.policy` | The version policy in words: what is locked, what floats, how it moves |
| `locks` | The lock files under `profiles/<name>/locks/` that a build needs; `build-image` refuses to build without them and says how to make them |
| `defaults.php` | The PHP version used when none is asked for |
| `defaults.cpus`, `defaults.pids` | Per-run defaults (a policy can only lower them) |
| `defaults.memory` | Default memory per database, e.g. `{"mariadb": "6g", "sqlite": "4g"}` |
| `defaults.db` | The database used when the request names none; a key of `databases` |
| `databases` | The databases a request may name. Each may have `env` (variables set for checks that use it) and `wrap` (a command put before the check's, e.g. `with-mariadb`) |
| `tmpfs` | Writable in-memory mounts, as `path:options` |
| `env` | Variables set in every check's container |
| `checks.<name>` | `lint`, `phpcs`, `phpstan` or `phpunit`: `argv` (the command, never run through a shell with client text in it), `workdir`, and for phpunit `argv_tests` (used when tests are selected, where the word `{tests}` expands to the paths) and `tests_prefix` (what goes before each path; defaults to the mount path) |
| `test_layout` | `drupal` or `flat`; which test directories `shard-tests.py` looks for |
| `verify` | What `phorward verify` needs: `source_extensions` (files that count as source), `scan_extensions` (files the security scan reads), and `security.fail` / `security.warn`, lists of `{pattern, message}` regular expressions (PCRE, no delimiters) matched against added lines |
| `smoke` | How `smoke-test` proves the image: `pick_tests` (patterns for the tests to run), `good_checks`, `bad` (a broken file to plant per check, from a template in the profile), `scratch` (tiny synthetic trees for checks that must hold whatever state the project is in), `readonly_paths` |

`{module_path}` in a command or `workdir` expands to the label's mount path. Strings from the client reach a
container only as separate arguments, so nothing in a manifest needs quoting against them.

## Adding a profile

1. Create `profiles/<name>/` with a `Dockerfile` following the contract above and a `manifest.json`.
2. Put the requirement files for the tools in `locks/<tool>/composer.json`, list the lock files in the manifest's `locks`,
   generate them with `build-image --refresh-locks` and pin the base images with `--pin-base`; commit the results.
   Put any helper scripts the Dockerfile installs and the manifest's commands call in the same directory
   (`php-library` has `with-project`, which makes a writable copy of the tree with the baked `vendor/`, and
   `phorward-check`, which picks the project's configuration or a default). A helper that sets something up
   should exit **125** if the setup fails: the wrapper reports that as an infrastructure error, where any other
   non-zero status would pass for "the project's check failed".
3. Add smoke templates under `profiles/<name>/smoke/`: a file that breaks each check, and for `phpstan` a good
   and a bad scratch file. `smoke-test` insists that every check has a must-fail case.
4. Run the contract test, which checks every profile directory the same way:
   `python3 -I -m unittest discover -s tests -v` (see `tests/test_profiles.py`).
5. Build and smoke-test an image on a real host (`build-image`, `smoke-test`). If the profile has hostile
   samples (`tests/fixtures/hostile/<name>/`), run `hostile-test` too; see [policy.md](policy.md).
6. Add the profile name to `allowed_profiles` in the server policy, or the wrapper refuses it.

Keep new ecosystems out until a profile needs one; the contract above is meant to hold for PHP projects.

## Known limits

- Dev packages for a project (for example the modules a Drupal module's tests need) are still passed to
  `build-image` with `--dev-package`; they will move into the project's configuration with the client.
- A server holding two projects of the same profile and PHP version distinguishes them by `--module`.
- Building from a project's `composer.lock` runs Composer with scripts and the autoloader disabled, so a
  project that needs one of its own scripts to run at install time is not supported yet.
