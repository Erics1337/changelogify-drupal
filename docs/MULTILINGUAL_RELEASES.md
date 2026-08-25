# Multilingual releases

Changelogify releases use Drupal's Language and Content Translation systems.
Enable translation for **Release** at **Configuration > Regional and language
> Content language and translation**, then add translations from a release's
**Translate** tab.

## Translated and shared data

Each translation has its own title, public slug, release items, publication
status, and editorial state. Version, release dates, scheduling, evidence, and
provenance remain shared by the release. Release item IDs remain stable across
translations so integrations can correlate equivalent items without exposing
internal event IDs on public endpoints.

Public list, detail, block, RSS, Atom, and API output follows Drupal's interface
language negotiation. A translated slug is canonical in that language.
Unpublished translations are never exposed merely because the source release
is published.

## Missing translations

Choose the public behavior at **Configuration > Development > Changelogify**:

- **Hide the release** omits a release when the requested translation is
  missing or unpublished.
- **Show the source language** uses the published source translation.
- **Show and label the source language** also displays a fallback-language
  notice in rendered pages and blocks.

Existing releases retain their original language as their source translation
after the multilingual database update.

## AI output language

When Changelogify AI is enabled, its **Output language** setting controls the
language requested from the configured provider. It is independent of the
language of the supporting evidence. Review generated copy in the intended
release translation before publishing it.
