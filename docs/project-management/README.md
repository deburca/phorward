# phorward — Project Management Vault

A lightweight, Git-tracked [Obsidian](https://obsidian.md)-compatible vault for managing
work on phorward. Everything here is plain Markdown, so it version-controls
cleanly alongside the code it documents.

## How to open

Open either the vault root or `project-management/` as an Obsidian vault — the dashboard
queries are tag-based (`#phorward/...`), so they resolve either way.

> Obsidian → *Open folder as vault* → select the vault root (recommended, keeps Obsidian
> from indexing source/build directories)

## Structure

```
project-management/
├── README.md              ← you are here
├── index.md               ← dashboard / map-of-content (open this first)
├── templates/              ← copy these when creating new notes
├── projects/               ← multi-task initiatives
├── tasks/                  ← atomic units of work (NNNN-slug.md)
├── decisions/               ← Architecture Decision Records (ADRs)
├── releases/                ← per-version changelog + checklist notes
└── notes/                   ← daily / weekly review notes
```

## Conventions

- **IDs**: tasks and decisions are zero-padded (`0001`, `0002`, …) so they sort and link
  predictably. Never reuse or renumber an ID once committed.
- **Frontmatter**: every note carries YAML frontmatter (`status`, `created`, etc.) so
  [Dataview](https://github.com/blacksmithgu/obsidian-dataview) can build the dashboard
  automatically.
- **Tags**: every note declares a `phorward/<type>` tag in frontmatter
  (`phorward/task`, `phorward/project`, `phorward/decision`,
  `phorward/release`, `phorward/notes`, `phorward/review`).
- **Wikilinks**: cross-reference with real note links like `[[0001-some-task]]`. Avoid
  leaving placeholder wikilinks in filled-in notes — Obsidian treats them as unresolved
  notes and they clutter graph/backlink views.
- **Status vocabulary**: keep each type to its fixed set of values (see the vault's
  templates) so queries stay reliable.

### Frontmatter formatting

- Prefer inline YAML arrays for short lists: `tags: [phorward/task]`.
- Use exact field values (e.g. a release version exactly as it's tagged in git).
- Prefer blank values over placeholders when something is not known yet — never a fake
  wikilink.
- When editing an existing note, preserve the current field order.

## Recommended Obsidian plugins

- **Dataview** — powers the dashboard tables in `index.md`.
- **Tasks** — query `- [ ]` checkboxes across notes.
- **Templater** (or core *Templates*) — instantiate `templates/*.md`.

## Git workflow

1. Branch per task, named after the task ID: `git checkout -b feature/0001-slug`.
2. Reference notes in commits so history is self-documenting.
3. Commit the note with the code it describes — flip a task's `status` to `done` in the
   same commit that lands the feature.
4. Finalize the matching `releases/X.Y.Z.md` note in the release commit.

## phorward specifics

- **Origin.** The first decisions and tasks came from the prototype repository (a private server repository) on
  2026-11-05, where the validation offload was designed and prototyped. They were renumbered into this
  vault's own sequence; each note names its origin in plain text (no cross-repo wikilinks).
- **Public repository note.** This vault is plain text in the repo. Before the repository is made public
  (task 0009) its notes must be scrubbed of private-server specifics or the `docs/project-management/`
  directory removed from the public tree; see that task.
- **Tag format.** No release has been tagged yet. The first is planned as `0.1.0` (no `v` prefix);
  confirm with `git tag` before writing a `version:` value once tags exist.
- **Start here.** Read `notes/2026-11-05-handover-from-prototype.md`, then `index.md`.

