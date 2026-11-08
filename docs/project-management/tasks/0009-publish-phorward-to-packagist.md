---
type: task
tags: [phorward/task]
status: done        # backlog | todo | in-progress | review | done | blocked | dropped
priority: low       # high | medium | low
project: "[[first-public-release]]"
area: release
created: 2026-11-05
branch: feature/0009-publish-phorward-to-packagist
release: 0.1.0
depends-on: ["[[0004-phorward-composer-client]]", "[[0008-config-loader-doctor-and-server-install]]", "[[0003-restricted-ssh-access-guide]]", "[[0011-ci-and-automated-tests-for-the-server-tools]]"]
blocked-by:
---
# Task 0009: Publish phorward to Packagist

## Context
Origin: prototype task 0152. Last step of [[0002-generic-configurable-packagist-tool]]. Publishing makes the tool a compatibility promise and a support burden, so it follows a working private use on the first server and HiveLog.

## Acceptance criteria
- [x] Composer vendor/package name chosen and checked for clashes on Packagist (`deburca/phorward`: free on 2026-11-08, and the `deburca` vendor already holds `deburca/beeswax`)
- [x] Licence chosen (MIT, 2026-11-05) and `LICENSE` added
- [x] `composer.json` metadata complete (description, keywords, support links, `bin`, PHP constraint)
- [x] Public documentation: install, quick start for the `drupal-module` and `php-library` profiles, configuration reference, security guide, requirements (Linux, `sshd`, `rsync`, Docker or Podman, 8 GB RAM for Kernel tests with MariaDB), troubleshooting with `doctor`
- [x] Public repository and docs contain no private-server specifics, VPN names, personal hostnames or key material, **checked with a search, not by eye**. Known places that currently mention the first server or its owner's setup: `docs/project-management/` (this vault, ADRs and task origins), `CLAUDE.md`, `profiles/drupal-module/` comments, `docs/image.md`. Decide whether the vault ships in the public tree (scrub it) or is removed from it
- [x] Protocol version and configuration keys documented as the compatibility surface ([compatibility.md](../../compatibility.md)); first tag `0.1.0` marked experimental (the tag itself is the owner's call, see below)
- [x] GitHub repository created and pushed, Packagist package created, webhook for auto-update configured
- [x] `composer require --dev` of the published package works in a clean project and a `scripts` alias runs `phorward doctor`
- [x] CI passes on the supported PHP versions

Progress 2026-11-08: added [quickstart.md](../../quickstart.md), [troubleshooting.md](../../troubleshooting.md) and [compatibility.md](../../compatibility.md), a documentation table in the README, a CI badge, `homepage` and `support` in `composer.json`, and `actions/checkout` v5 in both workflows. The repository is already public on GitHub (`deburca/phorward`), so "created and pushed" is true except for tags.

The search (`git grep -i` over everything tracked, for the first server's and user's names, the words for a home lab and the services run on it, VPN names, private address ranges, home paths and key material) found nothing outside the vault, apart from the documentation range `203.0.113.0/24` and a placeholder key in `docs/ssh-access.md`. In the vault it found no hostnames, addresses or VPN names, only the name of the owner's other repository and the word for a home lab in the origin notes. Decision 2026-11-08: keep the vault public and scrub those mentions. Done: they now say "prototype repository" and "private server", and the handover note is `2026-11-05-handover-from-prototype.md`. The same search now finds nothing. `CLAUDE.local.md` and `examples/harness-pilot/results/` are git-ignored and untracked; `examples/` is untracked entirely and `export-ignore`d.

Decisions 2026-11-08: tag `0.1.0` now, with a GitHub pre-release carrying notes; checks before calling it done: a clean-project install, a full Linux CI dry run and the image workflow on both architectures. Still open: the tag and release themselves, and `composer require --dev` from the published package in a clean project.

Done 2026-11-08: the scrub is on `main`; the image workflow passed on x86_64 and arm64 for both profiles; from an empty Composer project `composer require --dev deburca/phorward:dev-main` installed the published package straight from Packagist (so the GitHub webhook updates it), and a `scripts` alias running `phorward doctor` ran and reported the missing server setting as it should. `Application::VERSION` is `0.1.0`; the tag `0.1.0` and a GitHub pre-release carry the release.

## Implementation notes
- Check `git tag` conventions before the first tag (this repo has none; `0.1.0` without a `v` prefix is the plan).
- Explicit permission from the owner is required before anything is pushed or published; this task does not authorise it by itself.

## Pending updates
- `.gitattributes` already marks `/tests` and `/docs` as `export-ignore`, which keeps the Composer dist archive clean, but the GitHub repository itself would still show them.

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0002-generic-configurable-packagist-tool]]
- Commits:: 
