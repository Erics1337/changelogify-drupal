# Changelogify 1.7.0 release notes

Changelogify 1.7 expands how published releases reach readers and makes the
capture, generation, and editorial workflows substantially easier to operate.

## User-visible changes

- Adds RSS and Atom feeds, cache-aware latest/recent release blocks, a
  versioned read-only releases API, and a stable release-publication event for
  integrations.
- Adds revision-safe scheduled publishing for releases in review.
- Adds translatable release content with source-language fallback and public
  language negotiation.
- Redesigns the release editor into a compact editorial workspace with inline
  AI rewriting for individual items and whole existing releases.
- Redesigns Changelogify AI setup around actionable readiness guidance,
  understandable privacy presets, provider selection, consent, and clearer
  provider errors. AI remains optional and requires Drupal AI plus a compatible
  provider.
- Adds privacy-safe automatic discovery for new content types and bundles on
  clean installations. Existing sites retain their prior capture behavior
  until an administrator enables the new global setting.
- Adds prominent captured-event navigation and filtered event counters to the
  dashboard.
- Makes release generation compact and source-grouped, with search, filtering,
  grouped selection, summarized coverage warnings, and explicit reuse rows.
  Internal change-set identifiers are no longer shown to editors.
- Prevents creation of new empty releases and removes the confusing empty-draft
  and global evidence-reuse confirmation checkboxes. Historical empty releases
  remain readable and editable.
- Replaces Unix-epoch date output with meaningful unbounded-history labels and
  clarifies generated title and version defaults.

Changelogify 1.7 supports Drupal core `^10.3 || ^11` and PHP 8.1 or newer. The
optional `changelogify_ai` submodule requires Drupal AI `^1.4` and Drupal core
`^10.5 || ^11.2`, subject to each Drupal release's PHP requirements.

## Upgrade and enablement

1. Back up the database and active configuration and retain the prior module
   code artifact.
2. Upgrade Changelogify and complete normal database maintenance:

   ```bash
   composer require 'drupal/changelogify:^1.7'
   drush updb -y
   drush cr
   ```

3. Review **Configuration → Development → Changelogify**. Existing sites keep
   their previous capture policy; enable **Automatically track new privacy-safe
   content types and bundles** if that is the desired policy.
4. Review permissions for captured events, release management, publishing,
   scheduling, API access, feeds, and public changelog viewing.
5. If using AI, update Drupal AI and the selected provider within their
   supported constraints, then verify Changelogify AI readiness and preview the
   outbound evidence policy before granting external-processing consent.
6. Confirm feeds, blocks, API consumers, language negotiation, cron-driven
   scheduled publication, and public cache behavior in the target environment.

## Rollback

There is no automated database downgrade. Disable dependent integrations and
`changelogify_ai` where applicable, then restore the previous Changelogify code
artifact together with its matching pre-upgrade database and configuration
backup. Do not roll back code alone after database updates. Existing releases,
revisions, translations, scheduling metadata, and provenance should be treated
as data requiring backup before rollback.

## Release verification

The stable tag must satisfy the [release checklist](RELEASE_CHECKLIST.md) on the
exact tagged commit. The automated suite covers clean installation, supported
updates, lifecycle operations, publishing, feeds, blocks, API responses,
translations, scheduling, capture policy, release generation, and optional AI
workflows using deterministic fakes.
