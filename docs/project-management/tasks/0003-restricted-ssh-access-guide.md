---
type: task
tags: [phorward/task]
status: backlog        # backlog | todo | in-progress | review | done | blocked | dropped
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
- [ ] A security guide in `docs/` covers: a dedicated key pair (private key never in git), an `authorized_keys` entry with `command="…/phorward-run"` plus `restrict` (no pty, forwarding or other commands) and optional `from=`, a dedicated unprivileged user with no sudo, and why host data is never mounted into runs
- [ ] The guide states the container-engine trade-off plainly: Docker group membership is root-equivalent; rootless Podman (or a dedicated rootless Docker daemon) is preferred; what the sandbox does and does not guarantee
- [ ] `phorward server:install` ([[0008-config-loader-doctor-and-server-install]]) can print the exact `authorized_keys` line for a given public key and install path
- [ ] Verified with evidence on a real server: the key runs `phorward-run`, and an attempt to run any other command, open a shell or forward a port is refused

## Implementation notes
- Open question, decide here and record in [[0002-generic-configurable-packagist-tool]] if the design changes: recommend `docker` group, rootless Docker/Podman for the validation user, or a dedicated daemon. The recommendation to other users depends on the answer, and it must be tested rather than assumed.
- Do not put host names, VPN names or key material in the guide; use placeholders.

## Pending updates
- The first deployment (a homelab server) has its own matching task in the domus repo: unprivileged user, key, VPN access rule, credential-inventory entry, and the same refusal tests. Do that deployment after this guide exists, so the guide is the thing being followed.

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0001-offload-validation-via-ssh-and-sandboxed-containers]], [[0002-generic-configurable-packagist-tool]]
- Commits:: 
