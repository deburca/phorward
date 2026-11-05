---
type: project
tags: [phorward/project]
status: active         # planning | active | paused | done | dropped
target: 0.1.0
created: 2026-11-05
---
# Project: First Public Release (0.1.0, experimental)

## Goal
Take phorward from a working prototype (server-side image and test tools, verified on one server) to an installable, documented, experimental Composer package: a project can `composer require --dev` it, point it at its own SSH server, and run lint, phpcs, phpstan and phpunit there in a sandbox. The first users are the test-first LLM harness and the HiveLog Drupal module; the second audience is anyone with an SSH-reachable Linux box.

## Scope
- In scope: the client (`bin/phorward`), configuration, `doctor`, `server:install`, the `phorward-run` wrapper, server policy, the `drupal-module` and `php-library` profiles, security and usage documentation, CI for phorward itself, Packagist publication.
- Out of scope for 0.1.0: ecosystems other than PHP, multi-host scheduling, a web UI, run history beyond a JSON results directory. See the revisit triggers in [[0001-offload-validation-via-ssh-and-sandboxed-containers]] and [[0002-generic-configurable-packagist-tool]].

## Tasks
```dataview
TABLE status, priority
FROM #phorward/task
WHERE contains(string(project), this.file.name)
SORT status asc, priority asc
```

## Open questions
- Composer vendor name (`deburca` is provisional).
- Rootless Podman versus the `docker` group as the recommended engine ([[0003-restricted-ssh-access-guide]]).
- Whether the public repository keeps `docs/project-management/` ([[0009-publish-phorward-to-packagist]]).
- Where the harness-side integration code lives (it is outside this repo).

## Related decisions
- [[0001-offload-validation-via-ssh-and-sandboxed-containers]]
- [[0002-generic-configurable-packagist-tool]]
