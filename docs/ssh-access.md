# Restricted SSH access

The client reaches a phorward server over SSH, and what it runs there is code a model wrote. So the credential
it holds must be able to do exactly one thing: run `phorward-run`. This guide sets that up and shows how to
prove it. Hostnames, addresses and users below are placeholders (`203.0.113.0/24` is a documentation range).

If you read nothing else:

1. A **dedicated unprivileged user**, no sudo, with its own **key pair**.
2. An `authorized_keys` line that forces `phorward-run` **and says `restrict`**. `command=` alone is not enough.
3. Preferably also the same restrictions in `sshd_config`, and the key file and the kit **not writable by that user**.
4. A **rootless container engine** (Podman). Membership of the `docker` group is root on that host.
5. Run `server/access-test` from the client. It must pass.

## What the credential must not be able to do

Assume the key leaks, or the client machine is compromised, or the model finds a way to use the key. Then it
must not give: a shell, any other command, file transfer, a terminal, port forwarding, agent or X11 forwarding,
or a way to read or change anything on the host except by running `phorward-run`. What `phorward-run` itself does
is bounded by the server policy ([policy.md](policy.md)) and the sandbox (below).

## 1. A dedicated user

```bash
sudo useradd --create-home --shell /bin/sh phorward
sudo passwd --lock phorward
```

- **No sudo, no extra groups.** Do not add it to `docker` (see [the engine](#4-the-container-engine)).
- **A real login shell, not `nologin`.** sshd runs a forced command through the user's login shell
  (`sshd(8)`: "executed by the user's login shell"), so `/usr/sbin/nologin` makes every request fail. `/bin/sh`
  is fine because nothing the client sends reaches it: the forced command ignores what was asked for, and
  `phorward-run` validates the request itself. This was not tested here with a second account (it needs root);
  if requests fail with "This account is currently not available", this is why.
- **Password locked,** key only. If your `sshd_config` has `AllowUsers` or `AllowGroups`, add the user.
- **One user per trust domain.** Results and the run slots are shared by everything that connects as this user;
  do not give two mutually distrusting clients the same user.

## 2. A key pair, on the client

```bash
ssh-keygen -t ed25519 -f ~/.ssh/phorward_ed25519 -C "phorward client on my-laptop"
```

- The **private key stays on the client** and never goes in git, a project file or the server. Add it to
  `.gitignore` if it sits near a repository.
- **One key per client.** Revoking a client is then removing one line.
- A passphrase is good where a person starts the client. For an unattended client there is no one to type it, so
  the restrictions below are what protect you; keep the file mode at `600`.
- Prefer ed25519; ECDSA and RSA of at least 3072 bits are also accepted by the helper below.

## 3. Install the kit so the user cannot change it

Put the kit where the dedicated user can read and run it but **not write it**:

```
/opt/phorward/            root:root   755   the repository's server/ and profiles/
/etc/phorward/policy.json root:root   644   the policy
```

If the user could edit `phorward-run`, `policy.json` or its own `authorized_keys`, then anything that escaped a
container as that user could widen its own access. Only the results directory, the lock directory and the
container engine's storage belong to the user.

## 4. The container engine

This is the one real design choice. A user who can talk to the Docker daemon can ask it to run a container with
the host's root directory mounted and be root on the host. Docker's own documentation says so; it is how Docker
works, not a flaw in phorward. (We did not reproduce it on the test server: it would have meant mounting the
host's root directory into a container just to show it.)
Membership of the `docker` group is therefore **root-equivalent**, and a restricted SSH key does not change what
the account behind it can do once something runs as that account.

| Engine for the dedicated user | Verdict | Status |
|---|---|---|
| **Rootless Podman** | **Recommended.** Containers run in a user namespace as the unprivileged user; a container escape lands on an unprivileged account | Tested: the whole kit (build, smoke test, hostile samples, runs over SSH) on Podman 5.7, with limits enforced |
| Rootless Docker (a daemon the user owns) | Equivalent in spirit; more moving parts | Not tested |
| `docker` group | Only on a host you are prepared to treat as compromised if that account is: a throwaway VM or a machine that runs nothing else | Tested as working, not recommended |
| A separate, dedicated Docker daemon | Reasonable if rootless is unavailable | Not tested |

Setting up rootless Podman for the user:

```bash
sudo apt install podman uidmap          # newuidmap/newgidmap are needed
grep phorward /etc/subuid /etc/subgid   # needs a range; useradd normally adds one
sudo loginctl enable-linger phorward    # a user manager, so cpu, memory and pids limits can be delegated
```

Then, as that user, build and prove the images (`build-image`, `smoke-test`) and run `server/hostile-test` with
probes for your other services. Hostile samples are what show whether the limits really bite on your host: on a
host where cgroup delegation is missing, a rootless engine can silently ignore `--memory` or `--cpus`. The
Podman used for the tests applied them (a 256 MiB limit killed a memory hog, a process limit stopped a fork
bomb), but that was in an existing login session; **a freshly created user with `enable-linger` was not tested**,
so run the hostile test on yours.

Without systemd, or for any engine other than Podman or Docker, you are outside what has been tested.

## 5. Restrict the key

Generate the line instead of typing it, so options cannot be mistyped or smuggled in:

```bash
server/authorized-key --public-key phorward_ed25519.pub \
    --run /opt/phorward/server/phorward-run \
    --from 203.0.113.0/24 \
    --env PHORWARD_POLICY=/etc/phorward/policy.json
```

It prints one line and refuses private keys, keys that already carry options, weak RSA keys, and values that
could break out of the quoting:

```
restrict,command="env PHORWARD_POLICY=/etc/phorward/policy.json /opt/phorward/server/phorward-run",from="203.0.113.0/24" ssh-ed25519 AAAA… phorward client on my-laptop
```

| Part | What it does |
|---|---|
| `restrict` | Turns off every optional capability at once: pty, port, agent and X11 forwarding, `~/.ssh/rc`. **Without it the key still allows a terminal and port forwarding**, even with `command=` set; `access-test` fails when it is missing |
| `command="…"` | Whatever the client asks for, sshd runs this instead, and passes the request to it in `SSH_ORIGINAL_COMMAND`. `phorward-run` reads it, validates it against a fixed pattern, and refuses anything that is not a known request |
| `from="…"` | Optional: only accept the key from these addresses. Cheap, and worth it when the client has a stable address or VPN range |
| `env NAME=VALUE` | The forced command is a shell command line, so this is how to point the wrapper at a policy or an engine without a wrapper script |

`phorward server:install <admin-host>` prints this line (give it `--public-key`) together with the `sshd_config` block
below, after installing the kit.

Install it **outside the user's reach** and enforce the same limits in `sshd_config`, so one mistake in either
place is not enough:

```
# /etc/ssh/sshd_config.d/50-phorward.conf
Match User phorward
    AuthorizedKeysFile /etc/ssh/authorized_keys.d/%u        # root-owned: the user cannot add keys
    ForceCommand env PHORWARD_POLICY=/etc/phorward/policy.json /opt/phorward/server/phorward-run
    PermitTTY no
    DisableForwarding yes                                   # OpenSSH 7.4 or newer
    PermitTunnel no
    PermitUserRC no
    PasswordAuthentication no
```

Check the configuration with `sudo sshd -t`, then reload sshd. With `ForceCommand` in place the key line in
`/etc/ssh/authorized_keys.d/phorward` can be the plain output of `authorized-key` or even an unrestricted key:
the restrictions hold either way (see the evidence below). Keep the `authorized-key` line anyway; it is the
version that still protects you if the `Match` block is ever edited out.

## 6. Prove it

From the client, with the key in place:

```bash
server/access-test --host my-phorward-server \
    --tree ~/path/to/project --profile php-library --module my_lib
```

It changes nothing on the server. It checks that `phorward-run` answers and a real run gives a verdict, and that
each of these is refused: another command (`id`), a command appended with `;`, a `$(…)` substitution, a pipe, a
session with no command, a terminal, sftp, scp, remote forwarding (`-R`) and local forwarding (`-L`). Any
`FAIL` means the key can do more than it should; fix that before using the server.

The test only means something if it can fail. These are the cases it was run against (OpenSSH 10.2, a throwaway
`sshd` on the loopback interface, rootless Podman 5.7, one real run through SSH each time it applied):

| Authorization | Result |
|---|---|
| `restrict,command=…` in `authorized_keys` | every check held (12 of 12) |
| Unrestricted key, restrictions only in `Match User … ForceCommand … DisableForwarding yes` | every check held (12 of 12) |
| `command=…` **without** `restrict` | terminal, `-R` and `-L` forwarding **allowed**: 3 checks failed, as they should |
| Unrestricted key, no forced command | `phorward-run` unreachable by name; the test stops, saying the refusal checks would prove nothing |

## What the sandbox does and does not guarantee

Every run is a throwaway container with **no network, a read-only root, a non-root user, all capabilities
dropped, `no-new-privileges`, and limits on processes, memory, CPUs and time**. The only thing mounted is a
private copy of the tree under test, read-only. **No host directory, home directory, socket or credential is
ever mounted into a run**: the tree is unpacked from the upload into a fresh temporary directory, the archive is
refused if it has absolute paths, `..`, device files or links that leave the tree, and the directory is removed
afterwards. That is why a model's code cannot read your files or reach your network.

It is **not a formal guarantee**. In particular:

- Containers share the host's kernel. A kernel or runtime vulnerability could let a run escape. Rootless
  operation and the restricted user limit what an escape is worth; they do not prevent it. Keep the host patched.
- The limits bound a run; they do not make the host immune. A policy that is too generous can still starve it
  ([policy.md](policy.md)). Test with `server/hostile-test`.
- Whatever you upload is on the server for the length of the run. Do not put secrets in the tree you send
  (the client's sync filters should leave out `.env` files and keys).
- Output goes back to the client. A run cannot send data anywhere else, but it can print what it can read.
- This is built for a single trust domain. It is not a multi-tenant service.

## Revoking and troubleshooting

- **Revoke** a client by removing its line from `authorized_keys`: the key is the whole credential, since the
  password is locked. A run already in progress ends on its own; `sudo pkill -u phorward -f phorward-run` stops
  it now, and the wrapper cleans up its container and temporary directory.
- **`Permission denied (publickey)`:** check the user is allowed (`AllowUsers`), `from=` matches the client's
  address, and file modes (`sshd` refuses keys files that others can write).
- **`This account is currently not available`:** the login shell is `nologin` (see step 1).
- **`PTY allocation request failed`:** expected for `ssh -t`; a restricted key has no terminal.
- **Every request answers `bad_request`:** the forced command received a request it does not accept, for example
  from a client that is not phorward, or `SSH_ORIGINAL_COMMAND` was not set because the line has no `command=`.
- **Debug with `ssh -vvv`** from the client and `sudo journalctl -u ssh` on the server.
