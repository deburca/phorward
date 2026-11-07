---
type: task
tags: [phorward/task]
status: in-progress        # backlog | todo | in-progress | review | done | blocked | dropped
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
- [x] Configuration resolves in this order: CLI flags, `PHORWARD_*` environment variables, project file (`phorward.json` or `extra.phorward` in `composer.json`), user file (`~/.config/phorward/config.json`), defaults; `phorward config` prints the resolved values and where each came from
- [x] The connection is an SSH alias or `user@host` (`host`); port, user, key, `ProxyJump` and the like come from the user's `~/.ssh/config`, never from phorward options; no secret is read from `composer.json` or the project file (`docs/configuration.md`: `host` is a user setting the project file cannot set, is validated and passed to `ssh` after `--`; a key that looks like a secret is refused)
- [x] Project facts documented and validated: `profile`, `php`, `extensions`, `dev_packages`, `checks` and their arguments, `database`, `sync.exclude`, `sync.include_vendor`, output format, retry and wait settings; unknown keys produce a clear error (done, with the nearest valid key suggested; `extensions` and per-check `arguments` are deliberately not keys: the image profile owns the PHP extensions and each check's command line, and the protocol lets a client choose only checks, tests and limits)
- [ ] `phorward server:install <host>` pushes the server kit over SSH, installs `phorward-run` and a default policy, builds the requested profile image, and prints the `authorized_keys` line for a given public key ([[0003-restricted-ssh-access-guide]])
- [x] `phorward doctor` checks SSH reachability, forced command present, container engine (Docker/Podman, rootless or not), image present and its tool versions, protocol-version match, effective policy versus host CPU/RAM, free disk; exits non-zero with a fix hint for each failure (`src/Command/DoctorCommand.php`; also needed two additive `info` fields, `images[].lock_sha256` and `host.disk_free_bytes`. Verified 2026-11-07 from this Mac with the real `ssh`: against a throwaway restricted `sshd` on the first server with the real wrapper and rootless Podman, all ten checks passed and the freshness check matched the real `composer.lock` hash; and four real failure modes were each classified correctly with a fix hint: a project changed since the image was built, a module the server has no image for, a login with no forced command, and a host that does not exist)
- [ ] Verified end to end from a clean state on the first server, and against a second SSH host (a throwaway Linux VM or container) to prove it is not specific to either

## Implementation notes
- Key files (to create): `src/Config/`, `src/Command/{Config,Doctor,ServerInstall}.php`
- `server:install` needs network on the server for the image build; the runs afterwards do not.
- Decide the CLI framework (plain PHP versus `symfony/console`) with the dependency-minimal goal in mind ([[0004-phorward-composer-client]]).

## Pending updates
- Design agreed 2026-11-07 (`docs/configuration.md`): no CLI framework (hand-written dispatcher plus `symfony/process`), the project/user scope split enforced, `server:install` as a plan by default with `--apply` using `sudo -n`, key names as tabled.
- Done so far: `src/Config/` (schema, layered loader, project detection), `src/Cli/`, `phorward config`, `phorward doctor` with `src/Ssh/` (the transport, `symfony/process`), `src/Server/Protocol`, `src/Archive/TarWriter`; 144 PHPUnit tests passing on PHP 8.5 and 8.3.36 (the latter in the `php-library` image on the first server, `--network none`), including an end-to-end test where a stand-in `ssh` drives the real `phorward-run` with the fake engine. Next: `server:install`, then the end-to-end verification on two hosts (the second host is not yet available).
- Found while testing doctor: `sshd` refuses a key file under `/tmp` while `StrictModes` is on (the sticky `/tmp` parent), which doctor reported correctly as `Permission denied (publickey)`; the guide's file-mode advice already covers it.
- Left on the first server for the owner to delete: `/tmp/phorward-doctor.kIrLBS` (a throwaway host key, a throwaway client key, a policy and an `sshd_config`; its `sshd` is stopped) and `~/phorward-client-test` (a scratch copy used to run the PHP tests on 8.3).

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0002-generic-configurable-packagist-tool]]
- Commits:: 
