# Changelogify 1.3.0 release notes

Changelogify 1.3 hardens event capture, privacy controls, public access, release
data integrity, and the supported Drupal/PHP lifecycle. It remains a
deterministic release-drafting tool: this release does not add AI summarization
or semantic deduplication.

## User-visible changes

- Supports Drupal core `^10.3 || ^11` and PHP 8.1 or newer, subject to each
  Drupal release's own PHP requirements.
- Captures supported node, media, custom-block, and taxonomy-term lifecycle
  events with stable labels and paths when available.
- Adds an opt-in unpublished-content setting, disabled by default.
- Adds opt-in user creation and role-change tracking, disabled by default, with
  explicit privacy warnings.
- Makes the public changelog base path configurable and rebuilds routes when it
  changes.
- Prevents draft releases from appearing on public list or detail routes.
- Adds bounded cron retention, query indexes, event validation, normalized
  release sections, and stored event-ID provenance for generated items.
- Adds tested clean-install, 1.1/1.2/1.3-beta upgrade, interrupted-update, and
  uninstall behavior.

For clean installations, content and module tracking are enabled, user and
unpublished-content tracking are disabled, retention is 90 days, and the public
path is `/changelog`. Upgrades preserve existing values: a 1.2 site using
indefinite retention or disabled module tracking keeps those choices.

## Upgrade procedure

1. Review the [security and operations guide](SECURITY_AND_OPERATIONS.md),
   especially captured metadata and permission consequences.
2. Back up the database and active configuration and retain the prior module
   code artifact.
3. Deploy Changelogify 1.3.0.
4. Run:

   ```bash
   drush updb -y
   drush cr
   ```

5. Review `/admin/config/development/changelogify/settings`. Do not enable user
   or unpublished-content tracking until privacy and access requirements are
   approved.
6. Confirm Drupal cron runs regularly, verify the configured public path, and
   review Anonymous user permissions before publishing a release.
7. Check internal events, draft releases, published releases, and any external
   links or caches that depend on the changelog path.

Database updates preserve event metadata and release sections and can be rerun
after an interrupted attempt. If an update reports missing tables or an index
failure, follow the logged recovery guidance before rerunning it.

## Rollback

Changelogify does not provide a reverse update. Restore the pre-upgrade
database, active configuration, and previous module code as one matched set.
Do not roll back code alone after `drush updb`; that can leave the entity schema
and code incompatible.

## Release verification

The stable tag must satisfy the [release checklist](RELEASE_CHECKLIST.md),
including the complete Drupal/PHP compatibility pipeline and the clean-install,
supported-upgrade, interrupted-update, and uninstall lifecycle tests.
