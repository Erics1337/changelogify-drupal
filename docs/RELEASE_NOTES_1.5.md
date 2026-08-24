# Changelogify 1.5.0 release notes

Changelogify 1.5 adds an evidence-backed editorial workflow from event
exploration through public release publication. It remains deterministic: this
release does not add AI summarization or semantic deduplication.

## User-visible changes

- Upgrades the administrative event explorer with combinable filters,
  pagination, escaped detail views, and defensive sensitive-metadata redaction.
- Adds a release preview and change-set selection step before draft creation.
- Detects release-window gaps and overlaps, incomplete evidence coverage, and
  evidence reused by another release.
- Replaces raw section textareas with a structured item editor supporting
  editing, movement, ordering, deletion, manual items, and no-JavaScript use.
- Adds private inline evidence inspection for authorized release editors.
- Adds revision history and permission-controlled Draft, Ready for review,
  Published, and Archived states, including revision reversion.
- Adds stable readable public slugs, collision handling, retained slug history,
  canonical links, and permanent redirects from historical and numeric URLs.
- Hardens public list and detail pages with scalar-only template variables,
  semantic and responsive markup, keyboard focus styles, explicit cache
  contexts/tags, and lifecycle cache-invalidation coverage.

Changelogify 1.5 supports Drupal core `^10.3 || ^11` and PHP 8.1 or newer,
subject to each Drupal release's own PHP requirements.

## Upgrade procedure

1. Review the [security and operations guide](SECURITY_AND_OPERATIONS.md), the
   [editorial workflow](EDITORIAL_WORKFLOW.md), and any custom public changelog
   template overrides.
2. Back up the database and active configuration and retain the prior module
   code artifact.
3. Deploy Changelogify 1.5.0 and run:

   ```bash
   drush updb -y
   drush cr
   ```

4. Let database updates finish. Existing releases become revisionable, receive
   Draft or Published editorial states matching their prior publication state,
   and receive stable slugs. The updates are batched and safe to resume after
   interruption.
5. Review and grant the new workflow permissions deliberately: publishing,
   review submission, archiving, revision viewing, and revision reversion are
   separate from general release management.
6. Verify existing published releases at their slug URLs. Numeric URLs redirect
   permanently only when the release is publicly accessible.
7. If the site overrides either public Twig template, update it to the stable
   scalar variables in [the theming guide](PUBLIC_CHANGELOG_THEMING.md); public
   templates no longer receive a release entity object.
8. Exercise draft creation, evidence inspection, editorial transitions,
   revision history, public listing/detail pages, and upstream cache behavior in
   the target environment.

## Rollback

Changelogify does not provide a reverse update. Restore the pre-upgrade
database, active configuration, and previous module code as one matched set.
Do not roll back code alone after `drush updb`: the 1.5 entity schema adds
revision tables, workflow fields, and multi-value slug history that older code
does not understand. Purge upstream caches after restoring the matched backup.

## Release verification

The stable tag must satisfy the [release checklist](RELEASE_CHECKLIST.md) on the
exact tagged commit. Required jobs include the Drupal 10.3/PHP 8.1 floor,
rolling Drupal 10 and 11 lanes, maximum supported PHP, PHPUnit, PHPStan, PHPCS,
ESLint, and Stylelint. Manual, skipped, canceled, warning, or allowed-failure
results do not satisfy the release gate.
