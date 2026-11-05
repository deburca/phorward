---
type: task
tags: [phorward/task]
status: backlog        # backlog | todo | in-progress | review | done | blocked | dropped
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
Origin: domus task 0152. Last step of [[0002-generic-configurable-packagist-tool]]. Publishing makes the tool a compatibility promise and a support burden, so it follows a working private use on the first server and HiveLog.

## Acceptance criteria
- [ ] Composer vendor/package name chosen and checked for clashes on Packagist (`deburca/phorward` is provisional)
- [x] Licence chosen (MIT, 2026-11-05) and `LICENSE` added
- [ ] `composer.json` metadata complete (description, keywords, support links, `bin`, PHP constraint)
- [ ] Public documentation: install, quick start for the `drupal-module` and `php-library` profiles, configuration reference, security guide, requirements (Linux, `sshd`, `rsync`, Docker or Podman, 8 GB RAM for Kernel tests with MariaDB), troubleshooting with `doctor`
- [ ] Public repository and docs contain no homelab specifics, VPN names, personal hostnames or key material, **checked with a search, not by eye**. Known places that currently mention the first server or its owner's setup: `docs/project-management/` (this vault, ADRs and task origins), `CLAUDE.md`, `profiles/drupal-module/` comments, `docs/image.md`. Decide whether the vault ships in the public tree (scrub it) or is removed from it
- [ ] Protocol version and configuration keys documented as the compatibility surface; first tag `0.1.0` marked experimental
- [ ] GitHub repository created and pushed, Packagist package created, webhook for auto-update configured
- [ ] `composer require --dev` of the published package works in a clean project and a `scripts` alias runs `phorward doctor`
- [ ] CI passes on the supported PHP versions

## Implementation notes
- Check `git tag` conventions before the first tag (this repo has none; `0.1.0` without a `v` prefix is the plan).
- Explicit permission from the owner is required before anything is pushed or published; this task does not authorise it by itself.

## Pending updates
- `.gitattributes` already marks `/tests` and `/docs` as `export-ignore`, which keeps the Composer dist archive clean, but the GitHub repository itself would still show them.

## Related
- Project:: [[first-public-release]]
- Decisions:: [[0002-generic-configurable-packagist-tool]]
- Commits:: 
