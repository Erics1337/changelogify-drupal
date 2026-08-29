# Changelogify 1.8.1 release-readiness record

Changelogify 1.8.1 contains navigation, discoverability, and drafting-copy
changes only. It does not change event capture, release storage, public output,
AI provider requests, synthesis contracts, or provenance formats.

## Local verification

- Date: 2026-08-29
- Drupal: 11.4.5
- PHP: 8.3.31
- Drupal AI: 1.4.7
- Complete local PHPUnit suite: 232 tests and 2,391 assertions passed.
- PHPStan, targeted PHPCS, Composer validation, database update status, and
  whitespace validation passed.
- Browser verification covered Content-page discovery, the Dashboard,
  Releases, and Settings primary tabs, General and AI settings secondary tabs,
  drafting-method copy, removal of the top-level administration-menu entry,
  and responsive behavior.

## Release gate

The full commit SHA and required Drupal.org pipeline results must be recorded
after the release commit is pushed. A historical green pipeline does not
certify this patch commit.
