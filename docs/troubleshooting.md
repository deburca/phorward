# Troubleshooting

Start with `vendor/bin/phorward doctor`. It runs the whole path and names the step that failed. The exit status of
`run` and `verify` also says where to look: `2` is setup, `70` is the machinery, `75` is a busy server, `1` is your
code ([client.md](client.md)).

| Symptom | Cause and fix |
|---|---|
| `No server is configured` | Set `host` in `~/.config/phorward/config.json` or export `PHORWARD_HOST`. Never put it in the project file |
| `doctor` cannot connect | `ssh <host>` must work without a prompt. Check the alias in `~/.ssh/config`, the key and `from=` in the server's `authorized_keys` ([ssh-access.md](ssh-access.md)) |
| `protocol_mismatch` | Client and server speak different protocol versions. Update the older side; `phorward server:install --apply` brings the server kit to the client's release |
| "no image" / profile not allowed | The server has no image for this project, or its policy's `allowed_profiles` lacks the profile. `phorward server:install` builds it; check `allowed_profiles` in [policy.md](policy.md) |
| The image is stale | The project's `composer.json` or lock changed since the image was built. Rebuild with `server:install` (or `server/build-image` on the server) |
| `The project is N MB to send, over the limit` | Something generated is in the tree. In a git work tree only tracked and non-ignored files are sent, so git-ignore it, or add it to `sync.exclude`, or raise `sync.max_size`. The server has its own limit too (`max_archive_bytes`) |
| Exit `75` (busy) | Every run slot is taken. The client already waited `wait` seconds; try again, or raise `slots` in the policy |
| Exit `70` with a time or memory note | The run hit a limit. Raise `limits.timeout` or `limits.memory` (the server may lower them to its policy maximum) |
| phpstan reports hundreds of "unknown class" errors | It ran without your project's dependencies. For `drupal-module` the image runs the project's own phpstan; make sure the image was built from the current `composer.json` |
| A test passes locally and fails on the server | The server runs the image's PHP, Drupal core and MariaDB, not yours. `server/parity-check` compares the two; look at the versions in the report footer |
| `verify` says "NOT RUN" for a gate | The gate could not run for an infrastructure reason; it is not a failure of the change. Fix the cause above and run again |

The server keeps a result file for every run, including refused ones (`results_dir` in [policy.md](policy.md)).
