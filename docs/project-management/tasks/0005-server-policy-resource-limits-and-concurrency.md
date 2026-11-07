---
type: task
tags: [phorward/task]
status: review        # backlog | todo | in-progress | review | done | blocked | dropped
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
- [x] Policy file (`/etc/phorward/policy.json` by default) defines maximum memory, CPUs, pids, wall-clock timeout, concurrency slots, allowed images/profiles and results retention; documented with safe defaults for a small box (`docs/policy.md`)
- [x] `phorward-run` clamps every request to policy and records requested versus applied limits in the result
- [x] Concurrency capped by `flock` slots; excess requests wait briefly, then fail cleanly as an infrastructure error the client retries
- [x] Containers run at low priority (`--cpu-shares` or a parent cgroup) so other services win under contention
- [x] Disk use bounded: temp dirs on tmpfs or cleaned, results directory pruned by age or count
- [ ] `phorward doctor` reports the effective policy and warns when it exceeds the host's resources (server half done: `phorward-run info` returns the effective policy, the host's CPUs, memory and load, and a `warnings` list; `doctor` itself is [[0008-config-loader-doctor-and-server-install]] and only has to print it)
- [x] Verified with evidence on a real server: with the cap saturated by hostile samples (fork bomb, memory hog, CPU spin), the other services on the host stay healthy and responsive (2026-11-07, first server, `server/hostile-test`, 2 slots, 3 runs of 30 s: Docker twice and rootless Podman once, all checks held; samples measurably bit, peaks 200% CPU, the full 1 GiB memory limit and the 512-process cap; Home Assistant and Nextcloud's proxy answered within 5 ms of their quiet-host baseline, worst 24 ms; load peaked at 1.7; no container, temp directory or process left behind. Also shown: excess runs refused as busy with exit 75, and the load guard cancelling all runs cleanly)

## Implementation notes
- Measured data to inform defaults ([[0001-pinned-php-validation-image]]): the 1444-test suite as 4 shards x 2 CPUs finished in about 4 minutes on 8 cores with MariaDB at `--memory 6g`. Per-run starting point: 2 CPUs and 4 GB; 6 GB for MariaDB profiles; a pids cap of 512 to 1024; overall budget decided per host.
- `server/parity-check` already runs shards concurrently, which is a useful load generator for the verification.

## Pending updates
- The first deployment still needs its own policy values chosen (the test above used a scratch policy: 2 slots, 6g, 2 CPUs, `cpu_shares` 256, 120 s). Tracked in the domus repo.
- Decide whether the results directory belongs in a host's backups or is disposable (host-side decision).
- The 5 ms agreement above is on an idle host. The test measures the effect of saturation, not of a busy afternoon; rerun it when the host is under normal load before relying on the numbers.
- Found by the test, fixed in `phorward-run`: the engine records an OOM kill late under Podman when runs compete, so a 137 exit is re-checked for about 1.5 s before it is reported as `killed` rather than `oom_killed`. Either way the verdict is `error`, never `failed`.

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0001-offload-validation-via-ssh-and-sandboxed-containers]], [[0002-generic-configurable-packagist-tool]]
- Commits:: 
