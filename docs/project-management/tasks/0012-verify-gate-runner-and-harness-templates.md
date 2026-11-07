---
type: task
tags: [phorward/task]
status: in-progress        # backlog | todo | in-progress | review | done | blocked | dropped
priority: medium       # high | medium | low
project: "[[first-public-release]]"
area: client
created: 2026-11-07
branch: feature/0012-verify-gate-runner-and-harness-templates
release: 0.1.0
depends-on: ["[[0004-phorward-composer-client]]", "[[0008-config-loader-doctor-and-server-install]]"]
blocked-by:
---
# Task 0012: `phorward verify` Gate Runner and Harness Templates

## Context
A pilot LLM harness for the HiveLog module was built and benchmarked outside this repo (13 real tasks, plain opencode against the harness, 2026-11-02 to 2026-11-07). The agent and command snapshots are in `templates/`; its scripts, benchmark and results are a local reference in `examples/harness-pilot/` (not distributed). Findings that shape this task:

- The harness is useful as a **lint, coding-standards and CI-failure filter**: it brought phpcs, phpstan and syntax gates to clean far more often than plain runs. It did **not** make the model's logic or tests more correct (hidden-test pass 5/13 against 4/13 plain; a test-first variant passed 1/6). Review of logic and tests stays with the human. Do not oversell the harness as a quality guarantee in any documentation shipped from here.
- It split into two parts that belong in different places:
  - **Gates** (lint, phpcs, phpstan, PHPUnit, security and test-presence checks) run model-generated code, so they belong on the sandboxed server. Locally they needed a DDEV project, a host phpcs install and a docker-based phpstan project, and still disagreed with CI at times. This is what phorward is for ([[0001-offload-validation-via-ssh-and-sandboxed-containers]]).
  - **Agents and commands** (opencode agents `hivelog-dev` and `hivelog-tests`, the `/fix-bug`, `/feature` and `/verify` commands, the retry loop) run on the developer machine, next to the model client and the files being edited. They are never sent to the server; the server only receives the tree and runs the gates.

[[0004-phorward-composer-client]] defines `phorward run` (raw checks). This task adds the harness-shaped layer on top of it.

## Acceptance criteria
### 1. `phorward verify` (first piece)
- [x] `phorward verify [--base <ref>] [--only gate,gate] [--format human|json]` runs the gates through `phorward run` against the changed files since `--base` (default: the merge base with the project's default branch) and exits 0 only when every selected gate passes
- [x] Gates: `phpcs` (the project's own ruleset and tool versions, as CI runs them), `syntax` (`php -l` on the oldest supported PHP), `phpstan` (the project's config and baseline; fails on any new `phpstan-ignore` comment or baseline entry), `phpunit` (tests mapped to the change, capped; runs on the newest supported PHP), `tests` (source changed but no test added or changed: fail), `security` (gitleaks if present, plus a scan of added lines for `unserialize`, SQL string interpolation, open routes and similar)
- [x] Zero changed files is a **failure** ("nothing was implemented"), not a pass. A past run passed on an empty diff
- [x] Test selection by file name and class name matching, ported from the pilot (`examples/harness-pilot/select-tests.py`), with its known limit documented (it can miss an indirectly affected test)
- [x] The report is short, machine-readable (`--format json`) and names the first failing gate with the log excerpt a model needs; the pilot's fresh-session-per-attempt design relies on it
- [x] Infrastructure failures (SSH error, timeout, wrapper error) are reported separately from gate failures, so a caller never counts them as the model's fault ([[0004-phorward-composer-client]])
- [x] Per-project gate settings live in `phorward.json` (`gates`, `phpunit.max_files`, which PHP versions are "oldest" and "newest"); nothing is hard-coded for HiveLog or Drupal beyond the `drupal-module` profile defaults ([[0007-image-profiles-and-module-parameters]])
- [x] Tests: unit tests for test selection, the diff scoping and the suppression check; a fixture-module integration run on the first server
- [ ] Deferred, each needing an additive protocol option ([protocol.md](../../protocol.md)): `phpcbf` (auto-fix in place, needs a `patch` field in a check entry) and limiting `syntax` and `phpcs` to the changed files (`--paths`). Without them those gates cover the whole project, as CI does

Progress 2026-11-07: slice 1 implemented (`src/Verify`, `VerifyCommand`, `VerifyReport`, `gates.*` settings, `verify` sections in both profile manifests, [verify.md](../../verify.md)). Unit tests for git scoping, selection, scanners and gitleaks; command tests with the sequence transport; mutation checks on the exit status, the empty-diff failure, the suppression check, the comment stripping and the PHP split. Run against the first server with the `smoke_module` fixture: a clean change passed all seven gates in 9 s; a change with a style error, `eval`, a `phpstan-ignore` and a failing assertion failed `phpcs`, `phpstan`, `phpunit` and `security` with excerpts, and the later gates still ran.

### 2. Port the harness onto `phorward verify`
- [ ] The retry loop (`harness.sh`) calls `phorward verify` in place of the local `verify.sh`; the local DDEV, host-phpcs and local-phpstan setup scripts are retired
- [ ] Safety fixes found in the pilot are kept: a time limit on every model session (a session once hung 20 hours), one retry when opencode reports "Agent not found", and a deny list in the agents for `git stash`, `git restore`, `git rm`, `git reset`, `git checkout`, `git clean` and `git push`/`commit` (a model once ran `git stash --include-untracked` and removed the harness itself)
- [ ] The benchmark runner (`bench.sh`) scores through `phorward verify`; scoring stays repeatable (throwaway worktree, hidden tests overlaid, one row per run)
- [ ] Parity check: the same sample set gives the same gate verdicts through `phorward verify` and through the pilot's local gates

### 3. Generalise the agents and commands as templates, and `harness:install`
- [ ] The Hivelog-specific text (module name, entity and test conventions, DDEV paths) moves to project configuration or to a conventions file the project owns; the agent prompts stay Drupal-aware by default
- [ ] `phorward harness:install` copies the agent and command templates and a conventions starter into a project's `.opencode/` and fills in the project facts from `phorward.json`; it never overwrites a file the user edited without asking
- [ ] The installed commands call `vendor/bin/phorward verify`, so the project needs no other tooling
- [ ] The README states plainly what the harness does and does not do (see Context) and how to review the model's logic and tests

### 4. Adoption in HiveLog (work outside this repo, tracked here)
- [ ] HiveLog adds phorward as a dev requirement (needs [[0009-publish-phorward-to-packagist]] or a documented VCS repository entry) and runs `phorward harness:install`
- [ ] The harness files currently kept out of HiveLog's commits through `.git/info/exclude` are then removed from HiveLog
- [ ] Only HiveLog code is committed to HiveLog, on `feat/apiary-calendar-copy`; harness code lives here

## Implementation notes
- Reorganised 2026-11-07: `templates/opencode/` (agents and commands, committed), `templates/harness/` (harness.sh, CONVENTIONS.md) and `examples/harness-pilot/` (pilot scripts, `bench/`, git-ignored `results/`), both local-only until reviewed. `/examples` is export-ignored. Results hold run logs naming machines and models; review them before any public push ([[0009-publish-phorward-to-packagist]]). Design: [verify.md](../../verify.md).
- The pilot's phpstan gate needed a CI-shaped Drupal project and PHP 8.3 because the dev site's extra contrib changed the findings; the server image already provides a CI-shaped environment ([[0001-pinned-php-validation-image]], [[0010-image-reproducibility-and-php-matrix]]).
- Model findings relevant to docs: `coding/pro` is the only local model tried that edits files reliably through opencode; the two gpt-oss models made no tool calls through opencode. Treat the model list as a project-local note, not as shipped guidance.

## Pending updates
- [[0004-phorward-composer-client]] "Adoption" items overlap with section 4 here; when this task starts, move them here or link them so each is tracked once.
- The open question "where the harness-side integration code lives" in [[first-public-release]] is answered by this task: it lives here.

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0001-offload-validation-via-ssh-and-sandboxed-containers]], [[0002-generic-configurable-packagist-tool]]
- Commits:: 
