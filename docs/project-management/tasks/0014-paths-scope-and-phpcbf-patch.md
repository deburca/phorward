---
type: task
tags: [phorward/task]
status: in-progress        # backlog | todo | in-progress | review | done | blocked | dropped
priority: medium       # high | medium | low
project: "[[first-public-release]]"
area: protocol
created: 2026-11-08
branch: feature/0014-paths-and-phpcbf
release: 0.2.0
depends-on: ["[[0012-verify-gate-runner-and-harness-templates]]"]
blocked-by:
---
# Task 0014: `--paths` Scope and a `phpcbf` Patch

## Context
The two protocol additions deferred from [[0012-verify-gate-runner-and-harness-templates]]. Both are additive to protocol version 1 ([compatibility.md](../../compatibility.md)): an old client keeps working against a new server, and a new client against an old server must fall back, not fail.

- **Scope.** `syntax` and `phpcs` cover the whole project, as CI does, so a model is told about style problems in files it never touched, and every `verify` pays for them. The pilot's gates looked only at the changed files.
- **Auto-fix.** The pilot ran `phpcbf` on the changed files before `phpcs`. Four of the nine differences found by the parity run came from that. The tree on the server is read-only and the model's files are on the client, so the server cannot fix them in place: it returns a patch and the client applies it.

## Design
**`--paths` (request option).** A comma list of file paths relative to the module root, same character rules as `--tests`, each must exist in the tree, at most 500. It limits the checks whose manifest entry has `argv_paths` (`lint`, `phpcs`); other checks ignore it, and an entry that used it says `"scope": "paths"`. Absent, nothing changes.

**`phpcbf` (a new, advisory check).** A manifest check with `"default": false` (not run unless named in `--checks`, so existing clients see no change), `"needs_paths": true` and `"patch": true`. The server runs it on a copy of the named files in the container's `/tmp`, writes a unified diff against the read-only originals to **standard output** and the tool's own chatter to standard error. The check entry gets `patch` (text, up to policy `max_patch_bytes`, 1 MiB) and `patch_bytes`. An empty patch means nothing to fix. The check passes whenever the tool ran; it is never a failed gate.

**Client.** `phorward verify` sends `--paths` with the changed files that have a source extension of the profile (skipping the gate with a note when there are none, and falling back to the whole project when a name cannot be sent). `gates.scope` (`changed`, default, or `project`) chooses. With `--fix` (`gates.fix`, default off, because it rewrites the user's files) it first asks for `phpcbf`, checks that the patch touches only the requested files and nothing else (no renames, modes, symlinks, `..` or absolute paths), applies it with `git apply`, and then archives the fixed tree for the real gates. The report names how many files were fixed. A server that does not know `--paths` or `phpcbf` answers `bad_request`; the client then falls back to the whole project and no fix.

## Acceptance criteria
- [ ] Server: `--paths` validated and applied to `lint` and `phpcs` in both profiles; the entry says `scope`
- [ ] Server: the `phpcbf` check in both profiles, patch captured from stdout, size cap in policy, never a failed check
- [ ] Protocol, policy and compatibility docs updated; tests with the fake engine for options, limits, scope and patch
- [ ] Client: `run --paths`; `verify` scopes by default and falls back against an older server
- [ ] Client: `verify --fix` validates and applies the patch safely; a hostile patch is refused
- [ ] Real server: scoped `verify` and `--fix` on the fixture and on HiveLog; parity differences from the pilot re-checked
- [ ] The harness uses `--fix`; docs ([verify.md](../../verify.md), [configuration.md](../../configuration.md)) updated

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0001-offload-validation-via-ssh-and-sandboxed-containers]]
- Commits::
