---
type: task
tags: [phorward/task]
status: backlog        # backlog | todo | in-progress | review | done | blocked | dropped
priority: medium       # high | medium | low
project: "[[first-public-release]]"
area: client
created: 2026-11-05
branch: feature/0008-config-loader-doctor-and-server-install
release: 0.1.0
depends-on: ["[[0002-phorward-server-wrapper]]", "[[0007-image-profiles-and-module-parameters]]"]
blocked-by:
---
# Task 0008: Configuration Loader, `doctor` and `server:install`

## Context
Origin: domus task 0151. [[0002-generic-configurable-packagist-tool]] makes the tool usable on other people's servers. That needs one predictable configuration model and a way to set up and check a server without cloning anything onto it.

## Acceptance criteria
- [ ] Configuration resolves in this order: CLI flags, `PHORWARD_*` environment variables, project file (`phorward.json` or `extra.phorward` in `composer.json`), user file (`~/.config/phorward/config.json`), defaults; `phorward config` prints the resolved values and where each came from
- [ ] The connection is an SSH alias or `user@host` (`host`); port, user, key, `ProxyJump` and the like come from the user's `~/.ssh/config`, never from phorward options; no secret is read from `composer.json` or the project file
- [ ] Project facts documented and validated: `profile`, `php`, `extensions`, `dev_packages`, `checks` and their arguments, `database`, `sync.exclude`, `sync.include_vendor`, output format, retry and wait settings; unknown keys produce a clear error
- [ ] `phorward server:install <host>` pushes the server kit over SSH, installs `phorward-run` and a default policy, builds the requested profile image, and prints the `authorized_keys` line for a given public key ([[0003-restricted-ssh-access-guide]])
- [ ] `phorward doctor` checks SSH reachability, forced command present, container engine (Docker/Podman, rootless or not), image present and its tool versions, protocol-version match, effective policy versus host CPU/RAM, free disk; exits non-zero with a fix hint for each failure
- [ ] Verified end to end from a clean state on the first server, and against a second SSH host (a throwaway Linux VM or container) to prove it is not specific to either

## Implementation notes
- Key files (to create): `src/Config/`, `src/Command/{Config,Doctor,ServerInstall}.php`
- `server:install` needs network on the server for the image build; the runs afterwards do not.
- Decide the CLI framework (plain PHP versus `symfony/console`) with the dependency-minimal goal in mind ([[0004-phorward-composer-client]]).

## Pending updates
- Config key names listed above are a proposal; they become a compatibility promise at 0.1.0, so settle them here and document them once ([[0009-publish-phorward-to-packagist]]).

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0002-generic-configurable-packagist-tool]]
- Commits:: 
