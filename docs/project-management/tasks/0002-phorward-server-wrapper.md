---
type: task
tags: [phorward/task]
status: done          # backlog | todo | in-progress | review | done | blocked | dropped
priority: medium       # high | medium | low
project: "[[first-public-release]]"
area: server
created: 2026-11-05
branch: feature/0002-phorward-server-wrapper
release: 0.1.0
depends-on: ["[[0001-pinned-php-validation-image]]"]
blocked-by:
---
# Task 0002: phorward Server Wrapper (`phorward-run`: Sandboxed Run, Structured JSON Result)

## Context
Origin: prototype task 0145. The wrapper is part of the server kit and is the single entry point the SSH forced command invokes ([[0001-offload-validation-via-ssh-and-sandboxed-containers]], [[0002-generic-configurable-packagist-tool]]). It is generic: nothing in it names a particular host or module. It does not exist yet; today only the pieces it will be built from exist (`server/smoke-test`, `server/parity-check`).

## Acceptance criteria
- [x] `phorward-run` reads a tar archive on stdin into a fresh temp dir (tmpfs where possible); nothing from the host is mounted into the container
- [x] Runs the container for the requested profile/image with `--network none`, `--read-only`, non-root, `--cap-drop ALL`, `--security-opt no-new-privileges`, `--pids-limit`, `--memory`, `--cpus` and a hard wall-clock timeout; works with Docker and with rootless Podman
- [x] Requested limits and image are clamped to the server policy file ([[0005-server-policy-resource-limits-and-concurrency]]); a request above policy is clamped or refused, never honoured
- [x] Arguments from the client are validated against an allowlist (checks, profile, limits); no client string reaches a shell
- [x] Database for Kernel tests defaults to `with-mariadb`; `sqlite` and `none` selectable
- [x] Emits one JSON document: protocol version, per-check (lint, phpcs, phpstan, phpunit) status, exit code, duration and captured output (size-capped), an overall verdict, requested versus applied limits, and the image's `/opt/TOOL-VERSIONS`
- [x] Distinguishes passed, failed and infrastructure error (timeout, OOM-kill, wrapper/container failure) in the JSON and the exit code
- [x] Each result is also written to a results directory for history
- [x] Container and temp dir are always cleaned up, including on timeout and on SSH disconnect (disconnect is detected by polling stdout, because sshd sends no SIGHUP without a tty)
- [x] Verified with evidence on a real server: pass, fail, timeout (infinite loop) and OOM (memory hog) samples each produce the right outcome, and the other services on that host stay healthy (2026-11-07, first server, Docker 29.8.2 and rootless Podman 5.7.0: pass 0, fail 1, timeout 70 after 30.4 s, OOM kill 70 after 1.4 s, clamping reported; the eight other containers unchanged, load stayed under 1; SSH disconnect mid-run left no container, process or temp dir and stored a `cancelled` result)

## Implementation notes
- Key files: `server/phorward-run` (one Python 3 file, standard library only; no `server/lib/` was needed), `profiles/drupal-module/manifest.json` (per-check commands, tmpfs, database wrappers, defaults), `docs/protocol.md` (protocol v1: request arguments, result JSON schema, exit codes). Write the protocol document first; the client ([[0004-phorward-composer-client]]) depends on it.
- Starting point: the `docker run` flags in `server/smoke-test` (`sandbox()`) and `server/parity-check` already hold the working sandbox invocation, including the tmpfs mounts for `/tmp` and `web/sites/simpletest`. The image labels `phorward.module-path` and `phorward.module-name` tell the wrapper where to mount the tree; this label contract is shared with [[0007-image-profiles-and-module-parameters]].
- Proposed exit codes (decide and document): 0 passed, 1 failed, 2 configuration error, 70 infrastructure error.
- MariaDB runs need `--memory 6g`; make that a profile default, clamped by policy.
- The smoke test runs `php -l`, `phpcs --warning-severity=0` and phpunit; phpstan is not in it yet. Decide the per-check command lines per profile (phpstan runs from the project's `vendor/bin/phpstan`) and keep them in the profile manifest, not in the wrapper.
- Installed copy versus repo: `phorward server:install` ([[0008-config-loader-doctor-and-server-install]]) places the files; never copy by hand.

## Pending updates
- Not verified through a real `authorized_keys` forced command: the `SSH_ORIGINAL_COMMAND` path is tested with a simulated variable and the SSH-disconnect case over a plain `ssh host command`. Belongs to [[0003-restricted-ssh-access-guide]].
- `--db none` has no test, real or fake. Per-check exit codes for `lint` and `phpunit` are `xargs`'s (123 on any failure), noted in the protocol.
- Protocol additions made while building, both additive and documented: `--module` (a server with several modules of one profile has several matching images; the first server hit this at once), and stricter symlink rules (any `..` in a target is refused).
- phpstan runs with the module's own `phpstan.neon` when present, else on `.`; revisit with [[0007-image-profiles-and-module-parameters]].
- The client ([[0004-phorward-composer-client]]) must send `--module` and treat 255 and non-JSON stdout as infrastructure errors.

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0001-offload-validation-via-ssh-and-sandboxed-containers]], [[0002-generic-configurable-packagist-tool]]
- Commits:: 
