# Conventions for a coding agent working on {{project}}

This file is yours. `phorward harness:install` wrote it once as a starting point and does not overwrite it
after you edit it. The agent reads it at the start of every task, so keep it short: the rules a change most
often breaks, not a copy of your coding standard. Replace every line marked TODO with your own, and delete
what does not apply.

If you keep a longer guide (an `AGENTS.md`, a wiki page), say here which section covers which area, so the
agent reads that section and not the whole guide.

## Where things are

- TODO: the source directory, the test directory, and where public API versus internals live.

## Code rules

- The gates run syntax, coding standards and static analysis on the oldest PHP the project supports and the
  tests on the newest, so write code valid on every version in between.
- Follow the standard the project's phpcs ruleset sets; the gate runs it as CI does.
- Do not read request data (`$_GET`, `$_POST`) outside the entry point; do not build SQL or shell commands by
  string interpolation.
- TODO: your rules for dependency injection, error handling, public API stability.

## Tests

- A new public method or branch gets a test that would fail without it. A bug fix gets a test that fails before
  the fix and passes after.
- Test the failure paths and edge cases (empty input, unknown values), not only the happy path.
- TODO: the test layout, base classes and fixtures your tests use.
- Do not run the whole suite when one test file shows the change works; CI runs the rest.

## Never

- Add `@phpstan-ignore` or `phpcs:ignore`, or edit the phpstan baseline, to make a gate pass. If a finding is a
  false positive, say so.
- Commit, push or change CI configuration. A person does that.
- Weaken or delete an existing test to get green.
