---
type: task
tags: [phorward/task]
status: backlog        # backlog | todo | in-progress | review | done | blocked | dropped
priority: medium       # high | medium | low
project: "[[first-public-release]]"
area: server
created: 2026-11-05
branch: feature/0005-server-policy-resource-limits-and-concurrency
release: 0.1.0
depends-on: ["[[0002-phorward-server-wrapper]]"]
blocked-by:
---
# Task 0005: Server Policy, Resource Limits and Concurrency Cap

## Context
Origin: domus task 0148 (the generic half; the first server's own values and its health checks stay in that repo). Limits are server policy, never client config ([[0002-generic-configurable-packagist-tool]]): a client may request less than the policy allows, never more. A shared host must not be starved by a misbehaving test ([[0001-offload-validation-via-ssh-and-sandboxed-containers]]).

## Acceptance criteria
- [ ] Policy file (`/etc/phorward/policy.json` by default) defines maximum memory, CPUs, pids, wall-clock timeout, concurrency slots, allowed images/profiles and results retention; documented with safe defaults for a small box
- [ ] `phorward-run` clamps every request to policy and records requested versus applied limits in the result
- [ ] Concurrency capped by `flock` slots; excess requests wait briefly, then fail cleanly as an infrastructure error the client retries
- [ ] Containers run at low priority (`--cpu-shares` or a parent cgroup) so other services win under contention
- [ ] Disk use bounded: temp dirs on tmpfs or cleaned, results directory pruned by age or count
- [ ] `phorward doctor` reports the effective policy and warns when it exceeds the host's resources
- [ ] Verified with evidence on a real server: with the cap saturated by hostile samples (fork bomb, memory hog, CPU spin), the other services on the host stay healthy and responsive

## Implementation notes
- Measured data to inform defaults ([[0001-pinned-php-validation-image]]): the 1444-test suite as 4 shards x 2 CPUs finished in about 4 minutes on 8 cores with MariaDB at `--memory 6g`. Per-run starting point: 2 CPUs and 4 GB; 6 GB for MariaDB profiles; a pids cap of 512 to 1024; overall budget decided per host.
- `server/parity-check` already runs shards concurrently, which is a useful load generator for the verification.

## Pending updates
- Already in `phorward-run` (task 0002): the policy file with defaults (`POLICY_DEFAULTS`; unknown keys and invalid values are refused), clamping with requested versus applied recorded, `flock` slots with a wait then exit 75, `--cpu-shares`, tmpfs temp dirs, results pruned by count. Still open here: documenting the policy, results pruning by age, `phorward doctor`, and the hostile-sample test (fork bomb, CPU spin) with the cap saturated.
- The first deployment (a homelab server running Home Assistant and Nextcloud) needs its own policy values chosen and the hostile-sample test run there; tracked in the domus repo.
- Decide whether the results directory belongs in a host's backups or is disposable (host-side decision).

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0001-offload-validation-via-ssh-and-sandboxed-containers]], [[0002-generic-configurable-packagist-tool]]
- Commits:: 
