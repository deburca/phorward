# Configuration, `doctor` and `server:install`

**Status: agreed 2026-11-07.** The key names below are a compatibility promise from 0.1.0, so they are settled
here and written down once. Implemented: the loader, `phorward config`, `phorward doctor` and `phorward server:install`.

## The model

Configuration is resolved from five layers, highest first:

1. command-line flags
2. `PHORWARD_*` environment variables
3. the **project file**, committed with the project
4. the **user file**, `~/.config/phorward/config.json` (or `$XDG_CONFIG_HOME/phorward/config.json`)
5. built-in defaults

`phorward config` prints every resolved value and where it came from (`default`, the file path, the variable
name, or the flag), as text or with `--format json`.

### Two scopes, enforced

The project file is committed and shared; the user file is private to one person on one machine. Keeping them
apart is what keeps homelab hostnames and secrets out of repositories:

- **Project keys** describe the project, and live in the project file: what to run and how.
- **User keys** describe the person's setup, and live in the user file: which server, how patient to be.
- Environment variables and flags may set either kind.
- A key in the wrong file is an error that says where it belongs (`"host" is a user setting; put it in
  ~/.config/phorward/config.json or PHORWARD_HOST, not in the project file`). So a repository can never choose
  which server your code is sent to, and a project file can never override your `host`.

### Where the project file is

`phorward.json` at the project root, or the `extra.phorward` object in `composer.json`. The project root is the
nearest directory, from the working directory upwards, that has either. If **both** exist it is an error rather
than a silent choice. Nothing is read from a project file but the keys below; there is no way to include other
files or run code from it.

## Keys

### Project keys

| Key | Type, default | Meaning |
|---|---|---|
| `profile` | string; detected | Image profile. Detected when absent: `drupal-module` if the root has a `*.info.yml`, else `php-library` |
| `php` | string, e.g. `"8.3"`; the profile's default | PHP version of the image to run on |
| `module` | string; derived | The project's short name, which selects the image (`--module`). Derived as `build-image` does: the `*.info.yml` name, or the Composer package name made safe (`vendor/my-lib` becomes `my_lib`) |
| `checks` | list of `lint`, `phpcs`, `phpstan`, `phpunit`; all the profile offers | Checks `phorward run` performs by default |
| `database` | `mariadb`, `sqlite` or `none`; the profile's default | Database for `phpunit` |
| `dev_packages` | list of Composer packages; empty | Extra dev dependencies baked into a `drupal-module` image (`drupal/key`). Used by `server:install` and image builds, not by runs |
| `sync.exclude` | list of globs; empty | Paths left out of the uploaded tree, **in addition to** the built-in ones |
| `sync.include_vendor` | bool; `false` | Send `vendor/`. Normally not needed: the image carries the project's dependencies |
| `limits.memory`, `limits.cpus`, `limits.pids`, `limits.timeout` | as the protocol's options; unset | What to ask the server for. The server's policy can lower these, never raise them |

The tree is uploaded without `.git`, `vendor` and `node_modules` unless a key above says otherwise, and never with
`.env`, `.env.*`, `*.pem`, `*.key` or the SSH private key names `id_rsa`, `id_dsa`, `id_ecdsa` and `id_ed25519`. The
secret patterns cannot be switched off.

A run's per-check arguments are **not** a key: the command line of each check belongs to the profile
([profiles.md](profiles.md)), and the protocol lets a client choose only which checks, which tests and which
limits. Tests are chosen per run with `--tests`.

### User keys

| Key | Type, default | Meaning |
|---|---|---|
| `host` | string; none | The server: an SSH alias or `user@host`. Required for any command that connects |
| `format` | `human`, `json`, `junit`; `human` | How results are printed |
| `retries` | integer; `3` | Attempts after an infrastructure failure or a busy server |
| `backoff` | seconds; `2` | First wait between attempts; doubles each time, at most 30 |
| `wait` | seconds; `120` | Longest total time to keep retrying a busy server |
| `connect_timeout` | seconds; `10` | SSH connection timeout |

**Connection settings are not phorward's.** Port, user, identity file, `ProxyJump`, `ControlMaster` and the
rest come from the user's own `~/.ssh/config`; there are no phorward keys for them and none will be added.
`host` is validated (letters, digits, `.`, `_`, `@`, `-`, not starting with `-`) and always passed to `ssh` after
`--`, so a value can never become an `ssh` option. No key holds a secret, and any key whose name suggests one
(`password`, `token`, `key`, `secret`) is refused with an explanation.

### Environment variables

The name is `PHORWARD_` plus the key in upper case, with `.` written `_`: `PHORWARD_HOST`,
`PHORWARD_PROFILE`, `PHORWARD_SYNC_INCLUDE_VENDOR`, `PHORWARD_LIMITS_MEMORY`. Lists are comma-separated;
booleans are `1`/`0`/`true`/`false`. `PHORWARD_CONFIG` names an alternative user file. An unknown `PHORWARD_*`
variable is reported by `phorward config` but not fatal, since the server tools use the same prefix.

### Validation

Unknown keys in a file are an error naming the key and the nearest valid one. Types and values are checked
(`php` must look like `8.3`, `checks` must be known checks, `database` must be one the profile offers). Further
subsystems add their own keys the same way (the `phorward verify` task adds `gates`); each registers its keys
and scope, so there is one place that knows the whole set.

## Commands

| Command | Does |
|---|---|
| `phorward config` | Print the resolved configuration and its sources |
| `phorward doctor` | Check the connection and the server end to end (below) |
| `phorward server:install <admin-host>` | Install or update the server side (below) |
| `phorward run` | Send the project to the server and run its checks ([client.md](client.md)) |
| later `phorward verify` | The harness gate runner (task 0012) |

Exit codes match the wrapper's, so a script sees one set whether the failure was local or remote: `0` passed,
`1` a check failed, `2` configuration or usage error (nothing was sent), `70` infrastructure error (ssh failed,
timeout, bad reply, server error), `75` server busy after the retries ran out. `doctor` exits non-zero when any
check fails.

## `phorward doctor`

Each check prints `ok`, `WARN` or `FAIL`, and every `WARN` and `FAIL` has a `fix:` line saying what to do. Warnings
(a rootful engine, a stale image, a heavy policy, little disk) do not change the exit status; a `FAIL` makes it `1`.
Checks that cannot run because an earlier one failed are not reported. `--output json` prints the same as data.
It changes nothing on the server: the only thing it runs there is a lint of a one-line file it sends itself.

| Check | How |
|---|---|
| Configuration valid, `host` set | the config loader |
| SSH reachable, key accepted | a connection that runs the forced command |
| Forced command present | `info` returns a document, not "command not found" |
| Protocol match | `info.protocol` equals the client's |
| Container engine | `info.engine`: Docker or Podman, and whether rootless (warns on rootful Docker) |
| Image present for this project | `info.images` has the project's profile, PHP and module |
| Image up to date | the image's `lock_sha256` against the hash of the project's `composer.lock` or `composer.json` (new, see below) |
| End to end, and tool versions | a one-file `lint` run; its result carries the image's tool versions |
| Policy against the host | `info.warnings`, plus the effective policy printed |
| Free disk | `info.host.disk_free_bytes` against a floor |

It needs two small **additive** changes to `phorward-run info` (no protocol version bump, per the versioning
rules): each image entry gains `lock_sha256`, and `host` gains `disk_free_bytes` for the results directory's
filesystem.

## `phorward server:install <admin-host>`

Installing is **not** done with the restricted runtime key; that key can only run `phorward-run`
([ssh-access.md](ssh-access.md)). `<admin-host>` is a normal SSH login of someone who may administer the server,
and is separate from the `host` that runs use. The client ships the server kit (`server/` and `profiles/`), so
the two are always the same release.

Because the kit and the policy are root-owned and rootless images belong to the dedicated user, installing needs
`sudo` on the server. So the command works as a **plan**:

1. **Default: print the plan and change nothing.** It does not even connect. It lists, in order, the exact scripts
   it would run over `ssh <admin-host>`, what each is sent on standard input, the `authorized_keys` line for
   `--public-key FILE`, and the `sshd_config` block from [ssh-access.md](ssh-access.md), so you can read them or run
   them yourself.
2. **`--apply`: run the same steps over ssh** with `sudo -n`. If sudo would need a password, the first step
   (a check that changes nothing) fails and the command stops with "Nothing was changed", rather than prompting
   through a pipe. A later failure stops there; earlier steps are safe to repeat.

The steps:

| Step | What it does |
|---|---|
| Check the server | `sudo -n` works, the dedicated user exists, `tar`, `python3` (and `rsync` unless the smoke test is skipped) and an engine are present, and the prefix is not a real directory |
| Install the kit | Unpacks into `<prefix>.d/<release>` (the release id is a hash of the kit's content, so a repeat is a no-op) and switches the link `<prefix>` to it with one atomic rename. Previous releases are left in place |
| Policy and results | Writes a minimal `policy.json` (`allowed_profiles` and `results_dir`, so every other limit follows the kit's documented defaults) **only if there is none**; an existing policy is never touched, and a note says if it does not list the profile. Creates the results directory for the dedicated user |
| Build the image | Sends the project (without `.git`, `vendor`, `node_modules` or secret-looking files, honouring `sync.exclude`) to a temporary directory owned by the dedicated user, runs `build-image` and then `smoke-test` there as that user, and removes that temporary directory. Needs the network on the server |

It never creates the user, edits `sshd_config` or `authorized_keys`, or touches the Docker group; those stay the
operator's decisions, with the guide to follow.

| Option | Default | Meaning |
|---|---|---|
| `--apply` | off | Run the plan instead of printing it |
| `--public-key FILE` | none | The runtime client's `.pub` file; its `authorized_keys` line is printed (validated like `server/authorized-key`) |
| `--from PATTERN` | none | Added to that line as `from="..."` |
| `--prefix PATH` | `/opt/phorward` | Where the kit is linked |
| `--user NAME` | `phorward` | The dedicated user (runs the build, owns the results directory) |
| `--policy-file PATH` | `/etc/phorward/policy.json` | Where the policy lives; a non-default path is also put in the forced command |
| `--results-dir PATH` | `/var/lib/phorward/results` | Where results are kept |
| `--no-sudo` | sudo is used | The login is the dedicated user and owns the paths; for a personal server |
| `--skip-build` | off | Install the kit and policy only |
| `--skip-smoke` | off | Build the image but do not smoke-test it |

The settings `profile`, `php`, `module` and `dev_packages` come from the project's configuration or the usual flags.
Every path and name is validated and shell-quoted before it reaches a script. Exit status: `0` done (or planned),
`2` a usage or configuration error, `70` a step failed.

Needs: `ssh` and `tar` on the client; on the server `sudo`, `tar`, `python3`, a container engine and, for the
smoke test, `rsync`.

## What is built, in order

1. The config loader, its validation and `phorward config` (no network, fully testable offline).
2. `doctor`, with the two additive `info` fields.
3. `server:install` (done).
4. Verified from a clean state on the first server and on a second SSH host (a throwaway Linux container or VM),
   so nothing is specific to either.

## Decisions

Settled when this was reviewed (2026-11-07):

1. **No CLI framework.** A small hand-written dispatcher and argument parser, and one dependency,
   `symfony/process` (`^6.4 || ^7.0`, which a Drupal project already has), for safe two-way piping to `ssh`.
2. **The scope split above,** with the project file unable to set `host` at all.
3. **`server:install` is a plan by default and `--apply` runs it with `sudo -n`,** instead of installing outright.
4. **Key names and defaults as tabled.**
