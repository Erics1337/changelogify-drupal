# Changelogify 1.8.0 release notes

Changelogify 1.8 introduces Enhanced AI Summaries: an optional, durable
release-level workflow that turns large volumes of eligible system evidence
into concise, categorized, evidence-grounded draft notes.

## User-visible changes

- Adds an AI-first path to the existing release-window preview. It considers
  every site-eligible change set by default instead of requiring one generated
  note per tracked event or one manual selection per change set.
- Adds Public product, Client report, Internal technical, and Concise profiles,
  plus Short (5), Standard (12), and Detailed (25) final-note limits.
- Separates site-wide evidence eligibility, outbound privacy/redaction policy,
  and one-time category/source/evidence exclusions.
- Shows the exact post-eligibility, post-exclusion, privacy-filtered evidence
  before processing. Changed exclusions or evidence require a new preview.
- Adds deterministic hierarchical batching for large inputs, recursive
  candidate consolidation, bounded retry, cancellation, idempotency, cleanup,
  and operation-history progress.
- Resolves every final citation through intermediate candidates to original
  change-set and event IDs. Private coverage reports evidence considered,
  cited, excluded, and eligible but not surfaced.
- Revalidates current evidence, policies, exclusions, contract versions,
  output, and provenance before an atomic final commit. Successful jobs create
  exactly one unpublished draft; refusal, stale evidence, cancellation, or
  invalid output creates no partial release.
- Preserves deterministic release generation and the existing per-item and
  whole-release humanization tools.

AI synthesis does not publish releases, infer unsupported intent or impact, or
guarantee that every eligible event becomes a final note. Provider quality,
availability, cost, and data-processing terms remain the operator's
responsibility.

## Compatibility

Core Changelogify supports Drupal core `^10.3 || ^11` and PHP 8.1 or newer,
subject to each Drupal release's PHP constraints. The optional
`changelogify_ai` submodule requires Drupal AI `^1.4` and Drupal core
`^10.5 || ^11.2`, plus a compatible separately configured chat provider.
Automated tests use deterministic fakes and require no paid request, network
credential, or provider account.

## Upgrade and enablement

1. Back up the database and active configuration and retain the exact prior
   Changelogify code artifact.
2. Upgrade the package and complete normal Drupal maintenance:

   ```bash
   composer require 'drupal/changelogify:^1.8'
   drush updb -y
   drush cr
   ```

3. Confirm `composer.json` and the installed module report compatible Drupal,
   PHP, and optional Drupal AI versions.
4. Existing AI sites should review **Configuration → Development →
   Changelogify → AI**. If no explicit eligibility allowlist exists, runtime
   compatibility considers all five source categories until an administrator
   saves the desired list. Review eligibility separately from privacy policy.
5. Preview a non-sensitive payload, confirm external-processing consent, and
   review `administer changelogify ai`, `use changelogify ai`, and `view
   changelogify ai history` role grants.
6. Ensure Drupal cron/queue processing runs regularly. Test creation,
   operation-history progress, cancellation, unpublished finalization,
   provenance, and coverage in a staging environment.
7. Complete the cloud/local provider compatibility checks in
   [AI_DRAFTING.md](AI_DRAFTING.md) before production use.

No data migration is required merely to keep using deterministic generation.
Existing releases and version 1 provenance remain supported; synthesized
releases use the additive version 2 provenance shape.

## Rollback

There is no automated database or provenance downgrade. Stop queue processing,
cancel active AI synthesis jobs, and prevent new AI requests. Restore the prior
code artifact together with its matching pre-upgrade database and active
configuration backup, then rebuild caches. Do not roll back code alone after
creating version 2 synthesis provenance: older code can discard or reject the
additional coverage/source metadata when a release is saved.

If only the optional feature must be withdrawn while remaining on 1.8, cancel
active jobs, disable `changelogify_ai`, and keep core Changelogify enabled.
Previously accepted release text and private provenance remain in release
revisions; disabling the submodule does not rewrite or publish them.

## Release verification

The stable tag must point to the exact commit that passes every blocking lane
in [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md). Manual, skipped, canceled,
warning, or allowed-failure results do not satisfy a required gate. The release
preparation issue records the full commit SHA and pipeline results; creating or
pushing a tag and publishing GitHub or Drupal.org releases require separate
explicit authorization.
