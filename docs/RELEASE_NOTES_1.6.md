# Changelogify 1.6.0 release notes

Changelogify 1.6 adds optional, evidence-backed BYOK AI drafting through the
Drupal AI provider ecosystem. Core Changelogify remains fully usable without
Drupal AI and never sends evidence to an external provider on its own.

## User-visible changes

- Adds the optional `changelogify_ai` submodule for humanizing an individual
  release item or generating a complete unpublished draft from selected change
  sets.
- Supports the site-wide default Drupal AI chat model or an explicitly selected
  provider and model; credentials remain managed by the provider or Key module.
- Requires explicit administrator consent and provides a preview of the
  policy-filtered outbound evidence before external processing.
- Redacts usernames, actor and entity identifiers, paths, unpublished labels,
  and arbitrary field values by default.
- Adds four versioned editorial profiles: public product, client report,
  internal technical, and concise.
- Validates generated JSON, sections, item bounds, and evidence identifiers
  before any result can affect a draft. Invalid or stale results leave the
  release unchanged.
- Keeps every complete AI draft unpublished and requires an editor to accept an
  item suggestion before it creates a release revision.
- Adds bounded synchronous and queued execution, cancellation, retry handling,
  duplicate prevention, privacy-limited operation history, usage metadata when
  available, and configurable history retention.

Core Changelogify 1.6 supports Drupal core `^10.3 || ^11` and PHP 8.1 or newer.
The optional `changelogify_ai` submodule requires Drupal AI `^1.4` and Drupal
core `^10.5 || ^11.2`, subject to each Drupal release's own PHP requirements.

## Upgrade and enablement

1. Back up the database and active configuration and retain the prior module
   code artifact.
2. Upgrade Changelogify and complete normal database maintenance:

   ```bash
   composer require 'drupal/changelogify:^1.6'
   drush updb -y
   drush cr
   ```

3. Core-only sites require no additional dependency or configuration.
4. To use AI drafting, install a compatible Drupal AI release and the desired
   provider module, then enable the optional submodule:

   ```bash
   composer require 'drupal/ai:^1.4'
   drush en changelogify_ai -y
   ```

5. Configure the provider through Drupal AI, then visit Changelogify's AI
   settings. Review the outbound-payload preview and policy before granting
   consent for external processing.
6. Grant the AI administration, drafting, and history permissions separately.
   Run the documented provider compatibility smoke test before production use.

## Rollback

Disable and uninstall `changelogify_ai` before removing Drupal AI or its
provider modules. Uninstalling the optional submodule removes its configuration
and bounded operation history but does not remove Changelogify releases,
release revisions, or evidence. Restore the previous Changelogify code and the
matching pre-upgrade database/configuration backup if a full rollback is
required. Do not roll back code alone after database updates.

## Release verification

The stable tag must satisfy the [release checklist](RELEASE_CHECKLIST.md) on the
exact tagged commit. Automated tests use deterministic fakes and make no paid or
credentialed provider requests. Provider-specific compatibility remains the
manual smoke test described in [AI_DRAFTING.md](AI_DRAFTING.md).
