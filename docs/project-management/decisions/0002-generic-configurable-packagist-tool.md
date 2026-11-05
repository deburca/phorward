---
type: decision
tags: [phorward/decision]
status: proposed       # proposed | accepted | superseded | deprecated
date: 2026-11-05
supersedes:
---
# ADR-0002: A Generic, Configurable Tool Published on Packagist

## Status
proposed (2026-11-05). Carried over from domus ADR-0030, which extracted this project from the domus repo; it amends [[0001-offload-validation-via-ssh-and-sandboxed-containers]] on scope and packaging only. Execution is tracked in the tasks listed under Consequences.

## Context
[[0001-offload-validation-via-ssh-and-sandboxed-containers]] chose "SSH + throwaway container". By the end of [[0001-pinned-php-validation-image]] the result is useful beyond one person and one server: the image builds on x86_64 and arm64, runs PHP 8.3 and 8.5 with identical results, and uses an in-container MariaDB so no network is needed at run time. Two things no longer fit the first prototype:
- **The first host is one host, not the feature.** The prototype was tied to one homelab and to one Drupal module.
- **The caller wants to `composer require` the capability** and call it from the project's `scripts`, and other people with their own SSH-reachable Linux box should be able to do the same.

## Decision
Make phorward a generic tool published on Packagist.

### 1. Three parts, one versioned repository
| Part | Contents |
|---|---|
| **Client** | Composer package (`type: library`) shipping `bin/phorward`. Syncs the tree, calls the forced command over SSH, classifies the result, returns exit codes. PHP, minimal dependencies |
| **Server kit** | `server/`: the `phorward-run` wrapper (the SSH forced command), image profiles, `with-mariadb`, a policy file reader, smoke and parity tools |
| **Installer** | `phorward server:install <host>` pushes the kit over SSH and builds images; `phorward doctor` checks SSH, container engine, image, protocol version and headroom |

Client and kit ship together so they stay version-matched. They exchange a **protocol version**; a mismatch fails loudly.

### 2. Entry points via `bin`, not the package's `scripts`
A dependency's own `scripts` section never runs; Composer runs only the root project's. The package therefore ships `bin/phorward`, and the consuming project defines thin aliases (`"validate": "phorward run"`, `"validate:lint": "phorward run --checks lint"`, and so on).

### 3. Configuration in layers, SSH delegated to `ssh_config`
Precedence, highest first: CLI flags, `PHORWARD_*` environment variables, the project file (`phorward.json` or `extra.phorward` in `composer.json`), the user file (`~/.config/phorward/config.json`), defaults.
- **Project facts** (profile, PHP version, checks, database, sync filters, extra dev packages) are committed with the project.
- **Host identity** is `host: <ssh alias or user@host>` in the user file or environment. Port, user, key, `ProxyJump`, VPN hostnames and `ControlMaster` live in the user's own `~/.ssh/config`; phorward never reimplements them. No secrets in `composer.json` or the project file.
- **Limits are server policy** (`/etc/phorward/policy.json`: maximum memory, CPUs, timeout, concurrency, allowed images). The client may only request less. A client can never widen its own sandbox.

### 4. Image profiles, not one universal image
A profile is a directory with a Dockerfile and a manifest. Two ship at first: `php-library` (PHP, Composer, the project's own lockfile, phpunit, phpcs, phpstan) and `drupal-module` (module name, package and mount path are build arguments). Users can add profiles. Runs stay `--network none`; the per-project layer is built with network access once, on request (`phorward image:build`), not automatically per run.

### 5. Security posture for other users
Documented, not assumed: a forced-command `authorized_keys` entry with `restrict`, a dedicated unprivileged user, rootless Podman preferred over the `docker` group (root-equivalent), and a plain statement that the sandbox is designed for untrusted model output but is not a formal guarantee. Minimum host: Linux with `sshd`, `rsync` and Docker or Podman, 8 GB RAM for Kernel tests with MariaDB.

### 6. Naming, licensing, publication
Name `phorward`; MIT licence (chosen 2026-11-05); Composer vendor name still to be chosen (`deburca` is provisional). Semver from `0.x`, marked experimental. Public documentation and examples contain no homelab specifics. Publication is the last step ([[0009-publish-phorward-to-packagist]]).

### Revisit if
- profiles multiply beyond PHP (then consider separating the server kit from the client package), or
- users need multi-host scheduling or run history (then the Forgejo trigger in [[0001-offload-validation-via-ssh-and-sandboxed-containers]] applies), or
- the forced-command model cannot be made safe on a common setup (for example only rootful Docker is available).

## Consequences
- Positive: one tool for the test harness, for a module's own `composer validate`, and for other people's servers; configuration and limits are explicit and testable.
- Negative / trade-offs: a public package carries a support burden and a compatibility promise (protocol version, config keys); profile maintenance grows with each ecosystem.
- Follow-up tasks: [[0006-bootstrap-repo-and-migrate-scripts]], [[0007-image-profiles-and-module-parameters]], [[0008-config-loader-doctor-and-server-install]], [[0009-publish-phorward-to-packagist]], [[0002-phorward-server-wrapper]], [[0003-restricted-ssh-access-guide]], [[0004-phorward-composer-client]], [[0005-server-policy-resource-limits-and-concurrency]], [[0010-image-reproducibility-and-php-matrix]], [[0011-ci-and-automated-tests-for-the-server-tools]]
