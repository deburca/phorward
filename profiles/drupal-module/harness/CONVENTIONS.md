# Conventions for a coding agent working on {{project}}

This file is yours. `phorward harness:install` wrote it once as a starting point and does not overwrite it
after you edit it. The agent reads it at the start of every task, so keep it short: the rules a change most
often breaks, not a copy of your coding standard. Replace every line marked TODO with your own, and delete
what does not apply.

If you keep a longer guide (an `AGENTS.md`, a wiki page), say here which section covers which area, so the
agent reads that section and not the whole guide.

## Where things are

- TODO: the module root, the submodules and where their `src/` and `tests/` live.
- TODO: where services are declared, where entity fields are defined, where config schema lives.

## Code rules

- Drupal 11. The gates run syntax, coding standards and static analysis on the oldest PHP the project supports
  and the tests on the newest, so write code valid on every version in between: no implicit nullable
  parameter types (`Foo $x = NULL` is deprecated since PHP 8.4; write `?Foo $x = NULL`).
- Coding standard is `Drupal` and `DrupalPractice`: a doc comment on every class, method and property that
  PHPCS names, 2-space indent, `[]` and not `array()`.
- Inject services. Do not call `\Drupal::database()` or `\Drupal::entityTypeManager()` inside a class that can
  take them in its constructor.
- Use the entity query or the database API with placeholders. Never build SQL by string interpolation.
- Render arrays and Twig escape output. Never `#markup` a variable, `Markup::create()` or `|raw` text a user
  controls.
- `->accessCheck(FALSE)` needs a one-line reason in a comment beside it.
- Cache metadata: a controller or builder that depends on a user, an entity or config bubbles its cacheability.
- TODO: your rules for entity schema changes (update hooks), validation constraints, patches, permissions.

## Tests

- Kernel tests list every module they need in `$modules` (a missing one gives "Unavailable module" or "no such
  table") and install entity schemas and config explicitly.
- Pure logic with no Drupal bootstrap is a Unit test (fast); entities, services, access and cache metadata are a
  Kernel test.
- A new public method or branch gets a test that would fail without it. A bug fix gets a test that fails before
  the fix and passes after.
- Test the access rules, not only the happy path: another user's content must be refused.
- TODO: the test group attribute, base classes and fixtures your tests use.
- Do not run Functional (browser) tests or the whole suite; CI does that.

## Never

- Add `@phpstan-ignore` or `phpcs:ignore`, or edit the phpstan baseline, to make a gate pass. If a finding is a
  false positive, say so.
- Commit, push or change CI configuration. A person does that.
- Weaken or delete an existing test to get green.
