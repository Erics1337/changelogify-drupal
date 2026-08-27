# Changelogify 1.8 release-readiness record

This record contains no credentials, prompts, or evidence payloads. It records
only the compatibility environment and bounded outcomes used to prepare the
1.8 release candidate.

## Local compatibility environment

- Date: 2026-08-27
- Drupal: 11.4.5
- PHP: 8.3.31
- Drupal AI: 1.4.7
- OpenRouter provider: 1.1.6

## Real-provider checks

OpenRouter with `openai/gpt-4.1` passed the non-sensitive nine-change-group
Public product acceptance case. One immediate provider request created one
unpublished draft containing two neutral, grouped notes. Test-provider and
internal-provider maintenance was not surfaced, and the notes contained no
unsupported improvement or capability claims.

OpenRouter with `openai/gpt-4.1-mini` did not reliably honor mandatory Public
product omissions. Changelogify rejected a non-conforming result and created no
draft, confirming the safe-failure path. Operators remain responsible for
selecting a model that follows the configured editorial contract.

The maintainer explicitly waived the local Ollama provider check for this
release-readiness pass.

## Automated and browser checks

- PHPUnit: 230 tests and 2,333 assertions passed locally.
- PHPCS, PHPStan, ESLint, Stylelint, Composer validation, project spelling, and
  `git diff --check` passed for the release tree.
- Desktop and narrow-screen checks covered Preview/Edit controls, public date
  formatting, empty metadata, live safe-text updates, note ordering/removal,
  supporting-evidence links, responsive layout, and console errors.

The exact release commit and required Drupal.org pipeline are recorded in issue
#67 after the release candidate is pushed. A historical green pipeline does not
certify a successor commit.
