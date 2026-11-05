# phorward: orientation for a new session

phorward offloads PHP lint, phpcs, phpstan and phpunit runs to an SSH-reachable Linux server. Each run
is a throwaway, network-less, read-only, non-root container. It will be a Composer dev requirement
(`bin/phorward`) with a server kit (`phorward-run`, image profiles, policy). Status: early. The server
tools and the `drupal-module` image profile work and are verified on one server; the client, configuration,
`doctor`, `server:install` and publication are not written.

## Read first
1. `docs/project-management/notes/2026-11-05-handover-from-domus.md`: state, decisions, gotchas, every pending update
2. `docs/project-management/index.md`, then the two ADRs in `decisions/` and the tasks in `tasks/`
3. `README.md` and `docs/image.md`

## Layout
- `profiles/<name>/`: image profiles (`drupal-module` today): Dockerfile, `with-mariadb`
- `server/`: `build-image`, `smoke-test`, `parity-check`, `shard-tests.py`
- `bin/phorward`, `src/`: the client (placeholder)
- `tests/`: fixtures and tests (empty so far)
- `docs/`: documentation; `docs/project-management/`: the vault

## Conventions
- Work is tracked in `docs/project-management/` (follow the project-management-vault skill if available):
  zero-padded sequential IDs per type, never reused or renumbered; inline-array frontmatter; status
  vocabulary fixed per type; `[[wikilinks]]` only to notes that exist; tag slug `phorward`.
- Branch per task: `feature/NNNN-slug`; flip `status: done` in the commit that lands the work.
- Names fixed by ADR-0002: client `bin/phorward`, wrapper `phorward-run`, project file `phorward.json`, user
  file `~/.config/phorward/config.json`, env prefix `PHORWARD_`, policy `/etc/phorward/policy.json`.
- Image labels `phorward.profile`, `phorward.module-name`, `phorward.module-path` are a contract the tools read.

## Do not, without asking the owner
- Push to a remote, create a GitHub repository, publish to Packagist, or tag a release.
- Delete anything on a server or in another repository.
- Put homelab specifics (hostnames, VPN names, key material) in files meant to be public; see task 0009.

## Verifying changes
Shell and PHP syntax can be checked locally (`bash -n`, `php -l`, `composer validate`,
`python3 -I server/shard-tests.py <module> --check`). Real verification needs a Linux server with Docker:
sync the repo there, run `server/build-image`, `server/smoke-test`, and `server/parity-check`. Machine-specific
access notes are in `CLAUDE.local.md` if it exists (it is git-ignored).
