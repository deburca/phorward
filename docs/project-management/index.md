---
type: notes
tags: [phorward/notes]
---
# phorward — Dashboard

## Active tasks
```dataview
TABLE status, priority, project
FROM #phorward/task
WHERE status != "done" AND status != "dropped"
SORT priority asc, status asc
```

## Active projects
```dataview
TABLE status, target
FROM #phorward/project
WHERE status = "active" OR status = "planning"
```

## Open decisions
```dataview
TABLE status, date
FROM #phorward/decision
WHERE status = "proposed"
SORT date desc
```

## Upcoming release
```dataview
TABLE version, status, date
FROM #phorward/release
WHERE status != "released"
SORT date asc
```
