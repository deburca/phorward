---
type: notes
tags: [phorward/notes, daily, journal]
---

# {{date}}

## Inbox
- [ ] Capture anything you want to process later

## Priorities
```dataview
TASK
FROM #phorward/notes
WHERE !completed
  AND meta(section).subpath = "Priorities"
  AND file.path != this.file.path
SORT file.day DESC
```
- [ ] Priority 1
- [ ] Priority 2
- [ ] Priority 3

## Recap
- Wins:
- Challenges:
- Lessons:

## Open loops
- [ ] Follow up on...

## Notes / log
-

## End of day review
- What moved forward today?
- What got stuck, and why?
- One thing to improve tomorrow:

## Tomorrow
- [ ] Top priority
- [ ] Secondary priority
- [ ] Nice-to-have

## Links
- Projects:
- People:
