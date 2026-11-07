# Server policy

The policy is the operator's cap on what a client may ask for. A client can request less than the policy
allows, never more ([protocol](protocol.md#limits)): a request above a limit is **clamped** and the result
lists what was lowered. The policy is a plain JSON file on the server; the client never sees or changes it.

## Where it lives

`/etc/phorward/policy.json`, or the file named by the `PHORWARD_POLICY` environment variable. The file is
optional: without it, every key has the default below, which suits a small box. A key you leave out keeps its
default.

The file is checked on every run. An unknown key, a wrong type or an impossible value (for example `"slots": 0`
or `default_timeout` above `max_timeout`) makes the run fail with exit 70 and error `policy_invalid`, naming the
problem. A broken policy never silently falls back to looser limits.

`phorward-run info --protocol 1` prints the effective policy (file plus defaults) next to the host's CPU count,
memory and load, so a mistake in the numbers is visible without starting a run.

## Keys

### Per-run limits (a request is clamped to these)

| Key | Default | Meaning |
|---|---|---|
| `max_memory` | `"6g"` | Memory limit of a run's container (`512m`, `4g`). The in-container MariaDB needs about 6g for the Kernel suite; SQLite and lint-only runs need far less |
| `max_cpus` | `2` | CPUs a run may use |
| `max_pids` | `1024` | Processes and threads in the container; stops fork bombs |
| `max_timeout` | `900` | Wall-clock seconds for the whole run, including reading the archive |
| `default_timeout` | `900` | Used when the client asks for none. Not above `max_timeout` |

### Concurrency and priority

| Key | Default | Meaning |
|---|---|---|
| `slots` | `2` | Runs at once. Each slot is a lock file; the kernel releases it if the wrapper dies |
| `slot_wait` | `30` | Seconds a run waits for a free slot before it fails with exit 75 (`busy`), which the client retries |
| `cpu_shares` | `256` | Relative CPU weight of a run's container. The engine default is 1024, so under contention other services get four times the share |

### What the server accepts

| Key | Default | Meaning |
|---|---|---|
| `allowed_profiles` | `["drupal-module"]` | Image profiles a client may name. The first is used when the client names none |
| `allowed_php` | `null` | PHP versions a client may name, e.g. `["8.3"]`; `null` allows any installed image |
| `max_archive_files` | `100000` | Files in the uploaded tree |
| `max_archive_file_bytes` | 64 MiB | Size of any one file |
| `max_archive_bytes` | 512 MiB | Total size of the tree |
| `max_output_bytes` | `200000` | Output kept per check (the last bytes) |

### Disk

| Key | Default | Meaning |
|---|---|---|
| `tmp_dir` | `null` | Where each run's tree is unpacked. `null` means `/dev/shm` when writable, else the system temp directory. **On tmpfs the tree counts against RAM**, so `slots × max_archive_bytes` is a worst case to budget for |
| `results_dir` | `/var/lib/phorward/results` | One `<run_id>.json` per run, including refused ones |
| `results_keep` | `200` | Newest results kept (`0` for no count limit) |
| `results_max_age_days` | `30` | Older results are removed (`0` for no age limit) |
| `lock_dir` | `null` | Slot lock files. `null` means `$XDG_RUNTIME_DIR` or the temp directory |

Pruning runs after every result is stored, so the directory stays bounded without a cron job. Whether results are
worth backing up is the operator's call; they are a history, not state the tool depends on.

## Choosing numbers

Budget for the worst case, because that is what a hostile or broken sample produces:

- **Memory:** `slots × max_memory` must fit in what the other services leave free, with room to spare. The tree
  on tmpfs and the engine itself come on top.
- **CPU:** `slots × max_cpus` is the most the runs can take. With `cpu_shares` low, the other services win under
  contention, but a run still takes idle CPU. Pick a total you would accept at 100% for the length of
  `max_timeout`.
- **Timeout:** a full Kernel suite split in four shards of 2 CPUs finished in about four minutes in our
  measurements ([image notes](image.md)); `max_timeout` should allow a few times what a normal run needs.

| Box | Suggestion |
|---|---|
| 4 cores, 8 GB, shared | `slots: 1`, `max_memory: "4g"`, `max_cpus: 2`, `max_timeout: 600`, SQLite or lint-only runs |
| 8 cores, 16 GB, shared | `slots: 2`, `max_memory: "6g"`, `max_cpus: 2`, `cpu_shares: 128` |
| 16 threads, 32 GB+, shared | the defaults, or `slots: 3` if little else runs there |

These are starting points. Measure with the saturation test below before trusting them.

## Checking a policy

`server/hostile-test` fills every slot, plus one, with hostile samples (a CPU spin, a memory hog and a shell
fork bomb), while it times probe commands you give it against the other services on the host:

```bash
server/hostile-test --module ~/path/to/module --profile drupal-module \
  --probe 'curl -fsS -o /dev/null http://127.0.0.1:8123' --timeout 30
```

It passes only if every run ends on its own with a clean verdict (timeout, OOM kill, or refused as busy), the
load stays under a ceiling it enforces by cancelling the runs, each probe stays within a margin of its quiet
baseline, and no container, temp directory or wrapper process is left behind. Run it after changing the
policy, and on any new host.

## What the policy is not

It bounds resources, not trust. The sandbox (no network, read-only root, non-root user, no capabilities, and
no host mounts except the tree under test) is fixed by the wrapper and is not configurable. A policy that is too
generous can still starve a host; a policy cannot make an untrusted workload safe on an engine that is itself
root-equivalent (see the SSH access guide, task 0003).
