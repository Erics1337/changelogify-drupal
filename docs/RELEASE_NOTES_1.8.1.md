# Changelogify 1.8.1 release notes

Changelogify 1.8.1 is a navigation and workflow-clarity patch for the 1.8
release line. It makes the release workspace easier to find and keeps daily
release work separate from site-wide configuration.

## User-visible changes

- Adds a **Changelogify** local task to Drupal's Content administration page.
  It redirects to the established dashboard URL, so existing bookmarks and
  integrations remain valid.
- Uses **Dashboard**, **Releases**, and **Settings** as the workspace's primary
  tabs. Release generation and captured-event review remain focused dashboard
  actions instead of competing navigation destinations.
- Moves Changelogify configuration to **Configuration → Content authoring →
  Changelogify settings** and adds standard **Configure** links on the Extend
  page for both the core and optional AI modules.
- Groups the modules together under a dedicated **Changelogify** package on the
  Extend page.
- Groups **General** and **AI settings** beneath the Settings tab. The optional
  AI configuration also remains discoverable in Drupal AI's configuration
  area.
- Renames the primary dashboard action to **Generate release** and clearly
  distinguishes the deterministic **Standard draft** from the optional **AI
  summary draft**.
- Explains that Include selections apply to the Standard draft, while AI
  synthesis considers the reviewed site-eligible evidence boundary. The
  Standard draft sends no information to an AI provider.
- Adds a direct **View AI operation history** action to advanced AI settings.

No event, release, configuration, or provenance data migration is required.
Existing route URLs and public changelog URLs are unchanged.

## Compatibility

Core Changelogify supports Drupal core `^10.3 || ^11` and PHP 8.1 or newer,
subject to each Drupal release's PHP constraints. The optional
`changelogify_ai` submodule requires Drupal AI `^1.4` and Drupal core
`^10.5 || ^11.2`, plus a compatible separately configured chat provider.

## Upgrade

1. Back up the database and active configuration.
2. Upgrade the package and complete normal Drupal maintenance:

   ```bash
   composer require 'drupal/changelogify:^1.8.1'
   drush updb -y
   drush cr
   ```

3. Confirm the Changelogify dashboard opens from the Content administration
   page and that Dashboard, Releases, and Settings are available as primary
   tabs.
4. If `changelogify_ai` is enabled, confirm General and AI settings are
   available beneath Settings and that existing provider, consent, privacy,
   and eligibility configuration is unchanged.
5. Preview both drafting paths with non-sensitive evidence before using them
   in production.

## Rollback

No schema or stored-data changes are introduced by this patch. To roll back,
restore the prior Changelogify 1.8.0 code artifact, run `drush cr`, and confirm
the earlier navigation layout. Retain a matching database and configuration
backup as part of the normal deployment procedure.

