---
type: project
tags: [phorward/project]
status: planning       # planning | active | paused | done | dropped
target:                # target release, e.g. 1.2.0
created:                # ISO date
---
# Project: <title>

## Goal
One paragraph: the outcome and who benefits.

## Scope
- In scope:
- Out of scope:

## Tasks
```dataview
TABLE status, priority
FROM #phorward/task
WHERE contains(string(project), this.file.name)
SORT status asc, priority asc
```
_(Set each task's `project:` frontmatter to the real wikilink for this note, e.g.
`"[[this-project-name]]"`, to populate.)_

## Open questions
-

## Related decisions
-
