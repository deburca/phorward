---
type: decision
tags: [phorward/decision]
status: accepted       # proposed | accepted | superseded | deprecated
date: 2026-11-05
supersedes:
---
# ADR-0001: Offload Validation (lint, phpcs, phpstan, phpunit) over SSH into Throwaway Sandboxed Containers

## Status
accepted (2026-11-05). Carried over from prototype ADR-0029 (status there: proposed), where the decision was made while prototyping on the first deployment host. It is accepted here because the prototype now works end to end (see [[0001-pinned-php-validation-image]]). The scope and packaging of the tool are decided in [[0002-generic-configurable-packagist-tool]].

## Context
A test-first model-evaluation harness (tests are written before a model proposes a solution; the solution is then checked with lint, phpcs and the pre-written phpunit tests) ran on a local PC with 8 GB RAM. Those checks are the heavy part. A home server with 8 cores / 16 threads and 96 GB RAM was available, running only a few light services.

Constraints that shape the choice:
- **The code under test is model-generated and phpunit executes it**, i.e. arbitrary code execution. The server also holds personal data, so isolation matters more than convenience.
- A runaway test must not starve the other services on the host.
- The caller already knows what pass/fail means (exit codes plus parsed output); it does not need a CI product to decide that.

### Options considered
**A: Self-hosted GitLab CE + runner.** Rejected: roughly 4-8 GB RAM at idle on a box that runs home automation, large upgrade and backup burden, far more than needed.

**B: Forgejo (or Gitea) + Actions runner.** GitHub-Actions-style workflows, ~300-600 MB RAM, REST API for polling, run history and queueing built in. Costs: a push/clone/queue round trip (~5-20 s) per attempt, a web UI and API tokens to secure, a runner that executes pushed code, polling states to handle (queued/running/cancelled), and one more service to patch and back up. Kept as the considered alternative.

**C: SSH + `docker run` per attempt (chosen).** The caller streams the working tree over SSH; a wrapper runs a throwaway container and returns structured results and an exit code synchronously.
- Nothing running when idle
- Tightest isolation: per-run container with `--network none`, `--memory`, `--cpus`, `--pids-limit`, `--read-only`, non-root, tmpfs workdir, hard timeout
- Synchronous: no poll loop, no queued/cancelled states, lowest per-attempt latency
- Smallest attack surface: one SSH key restricted to one forced command
- No built-in run history (mitigated: the wrapper writes each result as JSON to a directory and the caller keeps its own log)
- Concurrency is ours to build (mitigated: `flock`/slot cap, see [[0005-server-policy-resource-limits-and-concurrency]])
- Not portable to GitHub Actions as-is

## Decision
Use **option C**. The caller ships the tree to the server over SSH and runs a wrapper that executes lint, phpcs, phpstan and phpunit in a pinned, throwaway, network-less container, then returns JSON results.

- Tool versions are pinned in an image built from this repo ([[0001-pinned-php-validation-image]]).
- The wrapper and its limits live in this repo and are installed on the server ([[0002-phorward-server-wrapper]]).
- Access is a dedicated SSH key with a forced command ([[0003-restricted-ssh-access-guide]]).
- The client distinguishes infrastructure failures (SSH down, container killed by timeout/OOM, wrapper error) from genuine test failures so a model is not penalised for infrastructure problems ([[0004-phorward-composer-client]]).
- Runs are capped in concurrency and resources so other services on the host are protected ([[0005-server-policy-resource-limits-and-concurrency]]).
- The runner never mounts host data volumes into a run.

### Revisit (move to option B) if
- parallel runs outgrow a simple lock/slot cap, or
- runs must come from more than one machine at once, or
- the CI should double as a real pipeline for other repositories, or
- run history and comparison across model versions becomes painful to keep in the caller's own log.

## Consequences
- Positive: idle cost is zero; strongest sandbox; fast and simple control flow; faster than the 8 GB PC.
- Negative / trade-offs: history and concurrency are hand-rolled; the wrapper and image are a small piece of software to maintain; the host is shared, so limits must be right.
- Follow-up tasks: [[0001-pinned-php-validation-image]], [[0002-phorward-server-wrapper]], [[0003-restricted-ssh-access-guide]], [[0004-phorward-composer-client]], [[0005-server-policy-resource-limits-and-concurrency]]
