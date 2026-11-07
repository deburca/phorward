---
type: task
tags: [phorward/task]
status: review        # backlog | todo | in-progress | review | done | blocked | dropped
priority: medium       # high | medium | low
project: "[[first-public-release]]"
area: security
created: 2026-11-05
branch: feature/0003-restricted-ssh-access-guide
release: 0.1.0
depends-on: ["[[0002-phorward-server-wrapper]]"]
blocked-by:
---
# Task 0003: Restricted SSH Access Guide (Forced Command, Unprivileged User)

## Context
Origin: domus task 0146 (the generic half; the first server's own key, user, VPN ACL and credential-inventory entry stay in that repo). The client reaches the server over SSH to run `phorward-run`. The code it runs is model-generated, so the credential must be able to do exactly one thing ([[0001-offload-validation-via-ssh-and-sandboxed-containers]]).

## Acceptance criteria
- [x] A security guide in `docs/` covers: a dedicated key pair (private key never in git), an `authorized_keys` entry with `command="…/phorward-run"` plus `restrict` (no pty, forwarding or other commands) and optional `from=`, a dedicated unprivileged user with no sudo, and why host data is never mounted into runs (`docs/ssh-access.md`)
- [x] The guide states the container-engine trade-off plainly: Docker group membership is root-equivalent; rootless Podman (or a dedicated rootless Docker daemon) is preferred; what the sandbox does and does not guarantee (rootless Podman recommended; decision recorded in ADR-0002, which already preferred it, now with test evidence)
- [ ] `phorward server:install` ([[0008-config-loader-doctor-and-server-install]]) can print the exact `authorized_keys` line for a given public key and install path (the logic exists as `server/authorized-key`, with 9 tests; the `server:install` command that calls it is task 0008)
- [x] Verified with evidence on a real server: the key runs `phorward-run`, and an attempt to run any other command, open a shell or forward a port is refused (2026-11-07, first server: OpenSSH 10.2 as a throwaway `sshd` on loopback run by the existing user, rootless Podman 5.7; `server/access-test` passed 12 of 12 with `restrict,command=` in `authorized_keys`, and 12 of 12 with an unrestricted key and the restrictions only in `sshd_config`; with `command=` but no `restrict` it correctly failed 3 checks, terminal and both port forwards allowed; with an unrestricted key and no forced command it stopped, unable to reach `phorward-run`)

## Implementation notes
- Open question, decide here and record in [[0002-generic-configurable-packagist-tool]] if the design changes: recommend `docker` group, rootless Docker/Podman for the validation user, or a dedicated daemon. The recommendation to other users depends on the answer, and it must be tested rather than assumed.
- Do not put host names, VPN names or key material in the guide; use placeholders.

## Pending updates
- **Not tested, because it needs root and a second account:** a freshly created dedicated user with `loginctl enable-linger` (do the cgroup limits still apply? the guide says to run `hostile-test` to find out), and a `nologin` login shell (the guide says it breaks the forced command, from the sshd documentation). The real `sshd` of the first server was not touched; `AllowUsers` there lists a single user, so the dedicated user will need adding.
- **Not reproduced:** the Docker-group root-equivalence demonstration (declined by the environment's permission check, since it mounts the host's root directory into a container). The guide cites Docker's documentation and says so.
- Leftovers on the first server from this test (`~/pr-ssh` with its throwaway keys, the scratch directories `~/pr-test` and the subdirectories of `~/phorward-parity` created during tasks 0002 to 0011, and the logs and scripts in the home directory) were deleted on 2026-11-07 with the owner's approval. The owner's earlier `~/phorward-parity/mariadb`, `module` and `report.txt`, `~/phorward-build` and `~/validate-build` were left alone.
- The first deployment still has its own task in the domus repo (user, key, VPN rule, credential inventory, refusal tests): follow `docs/ssh-access.md` and finish with `server/access-test`.
- Found while testing: my first `access-test` ran `kill 0` in its exit trap when no forward had been started, which signals the whole process group, caller included. Fixed to signal only a PID it started.

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0001-offload-validation-via-ssh-and-sandboxed-containers]], [[0002-generic-configurable-packagist-tool]]
- Commits:: 
