# Quick start

From nothing to a first green run. You need two machines: the **server** (where checks run) and the **client**
(where you work). They can be the same Linux box for a first try, but the point is to keep the sandbox off your
workstation.

## 1. The server

Requirements: Linux (x86_64 or arm64) with Docker, or rootless Podman with a `docker` command; `sshd`, `rsync`,
`python3` and `tar`; network access while images are built (none while they run); about 8 GB of RAM if you run
Kernel tests with the bundled MariaDB.

The server side is installed from the client, by someone who may use `sudo` there:

```bash
composer require --dev deburca/phorward
vendor/bin/phorward server:install admin@my-server             # prints the plan; changes nothing
vendor/bin/phorward server:install admin@my-server --apply     # runs it
```

It installs the kit, a minimal policy and the image for your project. It does not create the dedicated user, edit
`sshd_config` or write `authorized_keys`; those are yours, and [ssh-access.md](ssh-access.md) walks through them. Do
not skip that guide: the key your client holds must be able to run `phorward-run` and nothing else.

## 2. The client

Say which server runs your checks. This is yours, not the project's, so it goes in your own configuration:

```bash
mkdir -p ~/.config/phorward
echo '{"host": "my-phorward-box"}' > ~/.config/phorward/config.json    # an alias from ~/.ssh/config
vendor/bin/phorward doctor
```

`doctor` checks the connection, the server, the policy and the image end to end, and says how to fix what it finds.
Port, user, key and jump host come from `~/.ssh/config`.

## 3. Run it

A **Drupal module** (profile `drupal-module`, detected from `*.info.yml`):

```bash
vendor/bin/phorward run                      # lint, phpcs, phpstan, phpunit
vendor/bin/phorward run --checks lint,phpcs  # a subset
vendor/bin/phorward verify                   # the gate a change must pass: see verify.md
```

A **plain PHP library** (profile `php-library`, detected from `composer.json`):

```bash
vendor/bin/phorward run --checks lint,phpstan,phpunit
```

If detection guesses wrong, or you want to pin the PHP version, put the settings in a `phorward.json` next to
`composer.json`:

```json
{"profile": "drupal-module", "php": "8.3", "dev_packages": ["drupal/key"]}
```

The exit status tells "the code is wrong" (`1`) from "the machinery is wrong" (`70`, `75`) and "the setup is wrong"
(`2`); see [client.md](client.md). If something does not work, start with [troubleshooting.md](troubleshooting.md).

## Where next

| To | Read |
|---|---|
| Use composer `scripts` aliases, CI, report formats | [client.md](client.md) |
| Gate changes before a push (and for a coding agent) | [verify.md](verify.md) |
| Every setting | [configuration.md](configuration.md) |
| Limits and what the server accepts | [policy.md](policy.md) |
| How images are built, reproducibility, profiles | [image.md](image.md), [profiles.md](profiles.md) |
| What stays stable between versions | [compatibility.md](compatibility.md) |
