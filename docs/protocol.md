# phorward protocol, version 1

**Status: agreed 2026-11-07, not yet implemented.** The client ([task 0004]) and the server wrapper
`phorward-run` ([task 0002]) are both written against this document, so change it before changing either.

[task 0002]: project-management/tasks/0002-phorward-server-wrapper.md
[task 0004]: project-management/tasks/0004-phorward-composer-client.md

## Overview

The client calls one command over SSH. On the server that command is `phorward-run`, installed as the SSH
forced command, so the client never chooses what runs; it only supplies a request.

```
ssh <host> phorward-run run --protocol 1 --checks lint,phpcs,phpunit ... < tree.tar > result.json
```

| Channel | Carries |
|---|---|
| Command line (`SSH_ORIGINAL_COMMAND`) | The request: a subcommand and `--option value` pairs |
| stdin | For `run`: the tree under test, as a tar archive. Nothing else |
| stdout | Exactly one JSON document, the result. Nothing else |
| stderr | Human-readable progress and diagnostics. Never parsed |
| Exit status | The verdict class, see [Exit codes](#exit-codes) |

Two properties follow and are worth keeping:

- **stdout is machine-only.** A caller can always `json_decode` it. If stdout is empty or not valid JSON,
  that is an infrastructure error, whatever the exit status says.
- **No client string reaches a shell.** The wrapper parses the request itself, validates every value against
  a fixed pattern, and passes values to the container engine as separate arguments.

## Subcommands

| Subcommand | stdin | Purpose |
|---|---|---|
| `run` | tar archive | Run checks on the tree in a throwaway sandbox |
| `info` | none | Report protocol version, kit version, container engine, installed images and the effective policy. Used by `phorward doctor` |

Any other subcommand is a request error (exit 2).

## Request

### Syntax

A request is the subcommand followed by `--option value` pairs, separated by single spaces. The whole string
must match `^[A-Za-z0-9_./:,=@+-]+( [A-Za-z0-9_./:,=@+-]+)*$`; anything else is refused before it is parsed.
Options may appear in any order and at most once. Unknown options are refused, never ignored.

### Options for `run`

| Option | Values | Default | Notes |
|---|---|---|---|
| `--protocol` | `1` | required | A different number is refused with `protocol_mismatch` |
| `--profile` | profile name, e.g. `drupal-module` | server default | Must be allowed by policy |
| `--module` | module machine name, e.g. `mymodule` | none | Picks among images of the same profile and PHP version when a server holds several modules. Refused with `ambiguous_image` if more than one image matches and this is absent |
| `--php` | `8.3`, `8.5`, ... | profile default (`8.3` for `drupal-module`) | Must be an installed image allowed by policy |
| `--checks` | comma list from `lint`, `phpcs`, `phpstan`, `phpunit` | all four | Run in that order, whatever order is given |
| `--db` | `mariadb`, `sqlite`, `none` | `mariadb` | Applies to `phpunit` |
| `--tests` | comma list of paths relative to the module root | all | Only for `phpunit`. Each path must match `^[A-Za-z0-9_./-]+$`, contain no `..` and exist in the tree |
| `--memory` | size, e.g. `4g`, `6g` | profile default | Request; clamped to policy |
| `--cpus` | number, e.g. `2` | profile default | Request; clamped to policy |
| `--pids` | integer | profile default | Request; clamped to policy |
| `--timeout` | seconds | policy default | Whole-run wall clock. Request; clamped to policy |

The client chooses an image only by `--profile`, `--php` and `--module`. It cannot name an arbitrary image tag. The
wrapper maps the pair to a tag it found installed, so a request can never reach an image the operator did
not build and allow.

### The tree on stdin

An uncompressed tar archive (ustar or pax) of the project root, as produced by the client after applying its
sync filters. The wrapper extracts it into a fresh private directory, preferably on tmpfs, and mounts that
directory read-only into the container. Nothing else from the host is ever mounted.

The wrapper refuses the whole archive (exit 2, `bad_archive`) if any member is any of:

- an absolute path, or a path containing `..`;
- a symlink whose target is absolute or has a `..` component (stricter than "leaves the root", because a `..` can
  escape through another symlink), or a hard link to anything but an already-extracted regular file;
- a path that passes through a symlink, or a duplicate path;
- a device, FIFO or other non-regular, non-directory member;
- over the policy's per-file or total size limit, or the policy's file count limit.

### Where the module lives

The image labels `phorward.module-path` and `phorward.module-name` say where in the container the tree is
mounted and what the module is called. The client sends the project root and does not need to know either.

## Result

One JSON object on stdout, always, for every request the wrapper understood far enough to answer. Fields
marked *optional* are omitted rather than `null`.

```json
{
  "protocol": 1,
  "kit_version": "0.1.0",
  "run_id": "20261107T091500Z-4f9c2a",
  "verdict": "failed",
  "exit_code": 1,
  "started_at": "2026-11-07T09:15:00Z",
  "duration_ms": 187342,
  "profile": "drupal-module",
  "image": "phorward:mymodule-php8.3",
  "tool_versions": {"php": "8.3.36", "phpcs": "4.0.4", "phpstan": "2.3.5", "phpunit": "11.5.57"},
  "limits": {
    "requested": {"memory": "8g", "cpus": 2, "pids": 512, "timeout": 1800},
    "applied":   {"memory": "6g", "cpus": 2, "pids": 512, "timeout": 900},
    "clamped": ["memory", "timeout"]
  },
  "db": "mariadb",
  "checks": [
    {"name": "lint",    "status": "passed", "exit_code": 0, "duration_ms": 2100,
     "output": "", "output_truncated": false, "output_bytes": 0},
    {"name": "phpcs",   "status": "passed", "exit_code": 0, "duration_ms": 8400,
     "output": "", "output_truncated": false, "output_bytes": 0},
    {"name": "phpstan", "status": "passed", "exit_code": 0, "duration_ms": 14200,
     "output": "", "output_truncated": false, "output_bytes": 0},
    {"name": "phpunit", "status": "failed", "exit_code": 1, "duration_ms": 176000,
     "output": "...last bytes of output...", "output_truncated": true, "output_bytes": 912340}
  ]
}
```

### Top-level fields

| Field | Type | Meaning |
|---|---|---|
| `protocol` | integer | The protocol version the server speaks. Always present, even in errors |
| `kit_version` | string | Version of the installed server kit |
| `run_id` | string | Unique id; also the file name of the stored result |
| `verdict` | `passed`, `failed`, `error` | See below |
| `exit_code` | integer | The wrapper's exit status, repeated here so a result file is self-describing |
| `started_at`, `duration_ms` | string (UTC, ISO 8601), integer | Whole run |
| `profile`, `image` | string | What actually ran |
| `tool_versions` | object | Parsed from the image's `/opt/TOOL-VERSIONS` |
| `limits` | object | `requested`, `applied`, and `clamped`: the names of limits that were lowered |
| `db` | string | The database actually used |
| `checks` | array | One entry per requested check, in run order |
| `error` | object, *optional* | Present when `verdict` is `error`: `{"code": "...", "message": "..."}` |

### Verdict

| Verdict | Meaning | Trust |
|---|---|---|
| `passed` | Every requested check ran and passed | The code is fine for these checks |
| `failed` | Every requested check ran to completion and at least one reported a problem | The code has a problem; `output` says what |
| `error` | The run could not give a verdict: refused request, timeout, out-of-memory kill, container or wrapper failure, or the server was busy | Says nothing about the code. Retry or investigate the server |

A check is `failed` only when its command ran to completion and exited non-zero. A check killed by the
timeout or the OOM killer is `error`, never `failed`, because the model must not be told its code is wrong
when the machine ran out of resources.

### Check entries

| Field | Meaning |
|---|---|
| `name` | `lint`, `phpcs`, `phpstan` or `phpunit` |
| `status` | `passed`, `failed`, `error` or `skipped` |
| `exit_code` | The check command's exit status. Checks that fan out with `xargs` (`lint`, `phpunit`) report `xargs`'s status, 123 when any invocation failed. *Optional*: absent when `skipped` or killed |
| `duration_ms` | *Optional*: absent when `skipped` |
| `output`, `output_truncated`, `output_bytes` | Combined stdout and stderr of the check. When it exceeds the policy's cap, the **last** bytes are kept, because the failure summary is at the end; `output_bytes` is the full size. *Optional*: absent when `skipped` |
| `reason` | *Optional*: why the check is `error` or `skipped`, e.g. `timeout`, `oom_killed`, `earlier_error` |

Checks run sequentially in the fixed order lint, phpcs, phpstan, phpunit. A `failed` check does not stop the
later ones, so one run reports every kind of problem. An `error` stops the run, and the remaining checks are
`skipped` with reason `earlier_error`.

The command line for each check comes from the profile's manifest, never from the request ([task 0007]).

[task 0007]: project-management/tasks/0007-image-profiles-and-module-parameters.md

### Limits

A request above policy is **clamped** to the policy maximum and the name is listed in `limits.clamped`; the
run proceeds. A request the policy cannot satisfy at all (a profile or PHP version that is not allowed, a
test path that is not in the tree) is **refused** with exit 2. The client may only ask for less than policy allows; a
clamp is a notice, not an error, and a client should log it.

## Exit codes

The wrapper's exit status, carried back by `ssh`:

| Code | Verdict | Meaning | Client action |
|---|---|---|---|
| 0 | `passed` | All requested checks passed | Succeed |
| 1 | `failed` | At least one check failed | Report `output`; fail |
| 2 | `error` | Request refused (bad option, unknown profile, `protocol_mismatch`, `bad_archive`, policy refusal) | Fix the request or config; do not retry unchanged |
| 70 | `error` | Infrastructure error (timeout, OOM kill, container or wrapper failure) | Report as an infrastructure problem; do not report as a code failure |
| 75 | `error` | Server busy: no concurrency slot became free within the wait | Retry after a delay |

`ssh` itself exits 255 when it cannot connect or authenticate. That is not a wrapper status: the client
must treat 255, or any result where stdout is not a valid JSON document, as an infrastructure error and
report the connection failure rather than a code failure. The values 70 and 75 are `sysexits.h`
`EX_SOFTWARE` and `EX_TEMPFAIL`.

## `info`

`phorward-run info --protocol 1` prints one JSON object and exits 0:

```json
{
  "protocol": 1,
  "kit_version": "0.1.0",
  "engine": {"name": "podman", "version": "5.7.0", "rootless": true},
  "policy": {"max_memory": "6g", "max_cpus": 2, "max_pids": 1024, "max_timeout": 900, "slots": 2},
  "images": [{"profile": "drupal-module", "php": "8.3", "tag": "phorward:mymodule-php8.3",
              "module_name": "mymodule", "lock_sha256": "3b1f…", "built": "2026-11-05"}],
  "host": {"cpus": 16, "memory_bytes": 98784247808, "load_1m": 0.3, "disk_free_bytes": 812345678901}
}
```

`policy` is the effective policy after defaults are applied. `warnings` (a list of `{code, message}`, empty
when the policy looks safe) is the server's own comparison of the policy with `host`: `memory_exceeds_host`,
`memory_heavy`, `cpu_exceeds_host`, `cpu_heavy`, `no_priority` and `tmpfs_heavy`. `phorward doctor` prints them
([task 0005]); clients ignore codes they do not know. `info` takes no stdin and starts no
container.

`lock_sha256` is the hash recorded in the image's `phorward.lock-sha256` label (empty for an image built before the
label existed), which a client compares with the hash of the project's dependency file to see that an image is
stale. `host.disk_free_bytes` is the free space on the filesystem holding the results directory. Both were added
without a version bump; a client must treat them as optional when talking to an older server.

[task 0005]: project-management/tasks/0005-server-policy-resource-limits-and-concurrency.md

## Versioning

- `--protocol N` is required on every request. If the server does not speak `N`, it answers with
  `verdict: error`, `error.code: protocol_mismatch`, its own `protocol` field, and exit 2, so the client can
  tell the user which side to upgrade.
- Within one protocol version, fields may be **added** to the result and to `info`; clients must ignore
  fields they do not know. Removing or renaming a field, changing a meaning, or adding a required option
  needs a new protocol version.
- The client and the kit ship from one repository and release together, so a mismatch normally means an
  out-of-date server install (`phorward server:install` fixes it).

## Lifecycle guarantees

- The temp directory and the container are removed on every path out: normal exit, timeout, signal, and SSH
  disconnect (the wrapper treats `SIGHUP`, `SIGPIPE` and `SIGTERM` as a cancelled run, kills the container
  by its run-id label, cleans up and exits 70).
- Every result is also written to the results directory as `<run_id>.json`, pruned by policy.
- A run never writes to the host outside its temp directory and the results directory.

## Decisions

Settled when the draft was reviewed (2026-11-07):

1. **Exit 75 for "busy"** is a separate code, so a client can retry on the exit status alone.
2. **No `--shard` in v1.** Adding an optional option later needs no version bump. Until then a client that
   wants parallel runs sends disjoint `--tests` lists.
3. **No fail-fast.** Every requested check runs after a `failed` one; only an `error` stops the run.
4. **Plain tar on stdin.** The wrapper does not decompress client data. `ssh -C` covers slow links.
5. **`--tests` is pattern-checked only.** The wrapper checks that a path is safe and exists in the tree;
   PHPUnit decides what counts as a test, so the wrapper carries no PHP naming rules.
6. **`phorward verify` (task 0012) runs on protocol 1 as it is**: its first slice sends ordinary `run` requests, one per
   PHP version. Two further additive changes are planned for what it cannot do yet, without a version bump:
   - an optional `--paths` option to limit checks to changed files, validated like `--tests`;
   - an optional `patch` field in a check entry, for `phpcbf`, because the tree is mounted read-only and the
     result otherwise carries no files;
   - different PHP versions per gate are separate `run` calls, since `--php` applies to the whole run.
