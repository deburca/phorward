# Using phorward in a project

`bin/phorward` sends your project to a phorward server, runs lint, phpcs, phpstan and phpunit there in a throwaway
sandbox, and reports. This page is about the client; the server side is in [ssh-access.md](ssh-access.md),
[policy.md](policy.md) and [profiles.md](profiles.md), and every setting is in [configuration.md](configuration.md).

## Setting up

```bash
composer require --dev deburca/phorward
```

Tell it which server (this is yours, not the project's, so it goes in your own configuration, never in the project):

```bash
mkdir -p ~/.config/phorward
echo '{"host": "my-phorward-box"}' > ~/.config/phorward/config.json    # an alias from ~/.ssh/config
vendor/bin/phorward doctor                                               # checks the whole path
```

Port, user, key and any jump host come from your `~/.ssh/config`; phorward has no settings for them. The key must be
the restricted one set up as in [ssh-access.md](ssh-access.md).

## Aliases in your composer.json

A dependency's own `scripts` never run, so the project defines the aliases:

```json
{
    "require-dev": {
        "deburca/phorward": "^0.1"
    },
    "scripts": {
        "validate": "phorward run",
        "validate:lint": "phorward run --checks lint",
        "validate:style": "phorward run --checks lint,phpcs",
        "validate:tests": "phorward run --checks phpunit",
        "validate:json": "phorward run --format json",
        "validate:doctor": "phorward doctor"
    },
    "extra": {
        "phorward": {
            "profile": "drupal-module",
            "php": "8.3",
            "dev_packages": ["drupal/key", "drupal/simple_oauth"]
        }
    }
}
```

`composer validate` is taken by Composer itself, so the aliases above use another name; a script named like a Composer
command is shadowed by the command. Project settings can live in `extra.phorward` (as above) or in a `phorward.json`
next to `composer.json`, but not both.

Then:

```bash
composer run-script validate:lint
composer run-script validate -- --tests tests/src/Unit/FooTest.php
```

This is exercised by a test that installs the package as a copy, as Composer does from a registry, and runs the
aliases (`PHORWARD_TEST_COMPOSER=1 vendor/bin/phpunit --filter ComposerScripts`).

## What `phorward run` does

1. Reads the configuration and works out the project (profile, module).
2. Packs the project into a tar archive **without** `.git`, `vendor` (unless `sync.include_vendor`), `node_modules`,
   `.env`, `.env.*`, `*.pem`, `*.key` and SSH private key names, and anything in `sync.exclude`. In a git work tree
   it packs only the files git tracks and the untracked files it does not ignore (`sync.respect_gitignore`), and
   refuses a tree over `sync.max_size` (256m) before sending it, naming the largest directories.
3. Sends one request to the server's forced command, with the archive on standard input, and reads one JSON result.
4. Prints a report and exits with a status that says what happened.

Options: `--checks lint,phpcs,phpstan,phpunit`, `--tests path,path` (project-relative), `--paths path,path` (limit `lint` and `phpcs` to these files), `--format human|json|junit`, and
any setting as a flag (`--php 8.4`, `--database sqlite`, `--limits-timeout 300`, ...). Flags beat environment variables,
which beat the project file, which beats your user file.

## Exit status

The point of the status is to keep "the code is wrong" apart from "the machinery is wrong". A script, or a model's
harness, that treats `70` and `75` as "try later" and `1` as "fix the code" never blames code for a server that fell
over.

| Status | Meaning | What to do |
|---|---|---|
| `0` | Every requested check passed | |
| `1` | At least one check failed | Fix the code; the report shows each failed check's output |
| `2` | Nothing was run: a bad option, a bad setup, or the server refused the request | Fix the configuration; `phorward doctor` says what |
| `70` | Infrastructure error: ssh failed, the reply was not a phorward result, or the run hit a time or memory limit | Not a verdict on the code; try again, or see `phorward doctor` |
| `75` | The server stayed busy (every run slot taken) for longer than `wait` | Try again later |

Retries: a dropped connection, a garbled reply or a transient engine error is retried up to `retries` times (3), waiting
`backoff` seconds (2) and doubling each time up to 30. A busy server is retried for at most `wait` seconds (120) in
all. A **failed** check, a configuration error and a **resource limit** (the run's time or memory ran out) are never
retried: the first two would not change, and the third would run out again. Retry progress goes to standard error, so
standard output is always just the report.

## Reports

- **human** (default): one line per check, the output of any check that failed or errored (the last 60 lines), the
  verdict, the server's protocol, kit and tool versions, and any limits the server lowered.
- **json**: the server's result document unchanged, plus a `client` object (`outcome`, `exit_code`, `reason`, `hint`,
  `attempts`, `waited_seconds`, `host`). With no result at all (ssh failed) the document is just `client`.
- **junit**: one test suite, one test case per check. A failed check is a `<failure>`; a check that could not give a
  verdict, or a run that never happened, is an `<error>`, so a CI dashboard can tell the two apart.

Everything the server sends is the output of code a model wrote, so the human report strips terminal escape sequences
and control characters before printing, and the JUnit report removes characters XML cannot carry.

## From CI

The same command works in CI, with `PHORWARD_HOST` set from a secret and an SSH key available to the job. Prefer
`--format junit` for test reports and keep the restricted key's `from=` pattern in step with the runner's addresses.
