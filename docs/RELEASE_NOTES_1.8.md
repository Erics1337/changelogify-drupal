# Changelogify 1.8.0 release notes

Changelogify 1.8 introduces Enhanced AI Summaries: an optional, durable
release-level workflow that turns large volumes of eligible system evidence
into concise, categorized, evidence-grounded draft notes.

## User-visible changes

- Adds an AI-first path to the existing release-window preview. It considers
  every site-eligible change set by default instead of requiring one generated
  note per tracked event or one manual selection per change set.
- Adds Public product, Client report, Internal technical, and Concise profiles,
  plus default Auto grouping (1–25 notes), Short (5), Standard (12), and
  Detailed (25) final-note limits. Auto clusters related evidence and chooses
  the natural number of meaningful notes instead of mirroring input records.
- Separates site-wide evidence eligibility, outbound privacy/redaction policy,
  and one-time category/source/evidence exclusions.
- Shows the exact post-eligibility, post-exclusion, privacy-filtered evidence
  before processing. Changed exclusions or evidence require a new preview.
- Sends the entire reviewed evidence boundary in one provider request when the
  editor presses **Create AI draft release**. Synthesis does not use batching,
  intermediate candidates, overflow calls, queue workers, or Drupal cron.
- Adds stable duplicate-submission protection, safe terminal cleanup, and a
  privacy-bounded job detail/status surface for concurrent requests and
  troubleshooting. Normal success redirects directly to the unpublished draft.
- Replaces the wide diagnostic history grid with an indexed, paginated,
  filterable and responsive operation list. Detailed provider, model, token,
  version, coverage, and safe failure information is available on each job.
- Resolves every final citation directly to original change-set and event IDs.
  Private coverage reports evidence considered,
  cited, excluded, and eligible but not surfaced.
- Opens synthesized drafts in a public-style changelog preview, with a separate
  structured summary-note editor and collapsed supporting evidence. Unsaved
  wording, category, ordering, and removal changes update the preview locally.
- Revalidates current evidence, policies, exclusions, contract versions,
  output, and provenance before an atomic final commit. Successful jobs create
  exactly one unpublished draft; refusal, stale evidence, cancellation, or
  invalid output creates no partial release.
- Preserves deterministic release generation and the existing per-item and
  whole-release humanization tools.

AI synthesis does not publish releases. Prompt version 3 instructs providers to
state only explicitly supported facts and avoid unsupported intent, impact,
purpose, capabilities, causality, outcomes, and qualitative claims. Citations
provide traceability but cannot prove semantic entailment, so editors must
review every draft. Provider quality, availability, cost, and data-processing
terms remain the operator's responsibility, and not every eligible event is
guaranteed a final note.

The non-sensitive provider and browser verification environment is recorded in
[the 1.8 release-readiness record](RELEASE_READINESS_1.8.md).

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
6. Test that one Generate action makes one provider request, redirects directly
   to one unpublished draft, reuses an equivalent concurrent submission, and
   reports responsive operation history, provenance, and coverage. Confirm
   Drupal cron never contacts the provider.
7. Complete the cloud/local provider compatibility checks in
   [AI_DRAFTING.md](AI_DRAFTING.md) before production use.

No data migration is required merely to keep using deterministic generation.
Existing releases and version 1 provenance remain supported; synthesized
releases use the additive version 2 provenance shape.

## Rollback

There is no automated database or provenance downgrade. Prevent new AI
requests and allow any in-flight web request to finish. Restore the prior
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
