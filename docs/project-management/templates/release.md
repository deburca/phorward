---
type: release
tags: [phorward/release]
version: X.Y.Z
status: planned        # planned | in-progress | released
date:                  # release date (ISO)
---
# Release X.Y.Z

## Highlights
-

## Included tasks
_Use the exact release value in task frontmatter (e.g. `release: 1.4.0`) so this Dataview
query resolves cleanly._
```dataview
LIST
FROM #phorward/task
WHERE status = "done" AND contains(string(release), "X.Y.Z")
```

## Schema / migration notes
- New migrations in this release? List them and what they do.

## Release checklist
- [ ] All target tasks `done`
- [ ] Test suite green
- [ ] Migrations clean on a copy of real data
- [ ] Version bumped in the package manifest
- [ ] Docs updated if behaviour changed
- [ ] Tag created: `git tag X.Y.Z`
