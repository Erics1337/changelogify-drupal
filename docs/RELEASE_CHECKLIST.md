# Changelogify stable release gate

This document is the compatibility contract for stable releases. A commit
is releasable only when its Drupal.org GitLab pipeline completes successfully
with every job below green. Manual or skipped required jobs do not satisfy the
gate.

## Supported versions

Package metadata declares PHP 8.1 or newer and Drupal core `^10.3 || ^11` in
both `composer.json` and `changelogify.info.yml`. Drupal core may impose a
higher PHP minimum or a maximum for a particular core release; those core
constraints are part of the effective support contract.

| CI lane | Drupal core | PHP | Purpose |
| --- | --- | --- | --- |
| `phpunit (Drupal 10.3, PHP 8.1)` | Latest 10.3.x patch | 8.1 | Oldest declared Drupal/PHP support lines |
| `phpunit (previous major)` | Latest supported Drupal 10 | Core's minimum | Current Drupal 10 compatibility |
| `phpunit` | Latest stable Drupal 11 | Core's default | Current Drupal 11 compatibility |
| `phpunit (max PHP version)` | Latest stable Drupal 11 | Core's maximum | Newest supported dependency combination |

The rolling lanes intentionally take their core and PHP versions from the
Drupal.org shared templates. This keeps the gate current when Drupal publishes
supported minor or PHP releases, while the pinned lane protects the package's
declared lower bound.

The shared PHPUnit job discovers and runs every test class under `tests/`, so
the same job includes the Unit, Kernel, and Functional suites. There is no
FunctionalJavascript test suite at present.

## Required pipeline jobs

Before tagging a stable release, verify the pipeline contains and passes:

- [ ] `composer` and `composer-lint`
- [ ] `composer (previous major)`
- [ ] `composer (max PHP version)`
- [ ] `composer (Drupal 10.3, PHP 8.1)`
- [ ] `phpunit`
- [ ] `phpunit (previous major)`
- [ ] `phpunit (max PHP version)`
- [ ] `phpunit (Drupal 10.3, PHP 8.1)`
- [ ] `phpunit (Drupal AI)` with a compatible real `drupal/ai` dependency
- [ ] `phpstan` and all generated PHPStan matrix variants
- [ ] `phpcs`
- [ ] `stylelint` for the module's CSS
- [ ] `eslint` for applicable JavaScript, JSON, and YAML files

PHPCS, PHPStan, ESLint, and Stylelint are explicitly configured as blocking
jobs. Drupal's ESLint configuration also checks formatting in applicable JSON
and YAML files. The core matrices exclude the optional AI functional group
because Drupal AI has a higher Drupal core floor; the dedicated blocking AI
lane installs the real dependency and runs that group.

## Maintainer release checklist

- [ ] Confirm `composer.json` and `changelogify.info.yml` still declare the same
      Drupal and PHP ranges documented above.
- [ ] Confirm `phpstan.neon` loads the committed baseline and analyzes `src`.
- [ ] Confirm all required jobs ran automatically and passed for the release
      commit; a warning, manual, canceled, or skipped required lane is not green.
- [ ] Review the PHPUnit log to confirm Unit, Kernel, and Functional tests were
      discovered. Treat a required suite with zero discovered tests as failure.
- [ ] Confirm `CleanInstallKernelTest`, `UpdatePathKernelTest`, and
      `LifecycleFunctionalTest` pass in every required PHPUnit lane. These are
      the install, supported-upgrade, interrupted-update, and uninstall gate.
- [ ] Confirm Composer resolved dependencies in every matrix `composer` job.
- [ ] Confirm there are no allowed failures among validation jobs.
- [ ] Review the [security and operations guide](SECURITY_AND_OPERATIONS.md)
      against settings defaults, captured metadata, permissions, routes, cron
      behavior, and uninstall behavior.
- [ ] For a release containing Enhanced AI Summaries, run the documented
      non-sensitive cloud and local provider checks in [AI_DRAFTING.md](AI_DRAFTING.md).
      Record provider/model versions and results without recording credentials
      or payload text. Automated fake-provider tests do not replace this check.
- [ ] Confirm one Generate action makes one synthesis provider request containing
      the reviewed evidence; test duplicate protection, stale-evidence rollback,
      exactly-once unpublished finalization, private coverage, and terminal cleanup.
- [ ] Confirm the release-specific notes contain current upgrade and rollback
      steps. Describe optional AI drafting only when the release includes the
      `changelogify_ai` submodule, and do not claim semantic deduplication.
- [ ] Update release notes, then tag only the exact commit whose pipeline
      passed. Drupal.org packaging injects the module version; do not add a
      `version` key to `changelogify.info.yml`.

When support metadata changes, update this document and `.gitlab-ci.yml` in the
same commit. Dropping the pinned Drupal 10.3 lane requires dropping `^10.3` from
both package metadata files.
