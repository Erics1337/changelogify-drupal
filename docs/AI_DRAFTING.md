# Optional BYOK AI drafting

Changelogify 1.6 provides an optional `changelogify_ai` submodule. Core Changelogify neither depends on Drupal AI nor sends evidence to any provider. The submodule requires Drupal AI `^1.4` on Drupal `^10.5 || ^11.2`, plus a separately configured provider; provider credentials remain in that provider's configuration or Key integration. Core Changelogify remains compatible with Drupal `^10.3 || ^11`.

Before enabling external generation, an administrator must explicitly grant consent and review the policy-filtered payload. By default, usernames, actor and entity identifiers, paths, and unpublished labels are removed. Only selected change sets enter a request. Built-in profiles change tone, not factual requirements. The response must be bounded JSON with only known evidence IDs; HTML, unsupported sections, duplicate IDs, and malformed or oversized output are rejected without changing a release.

Complete drafts always start unpublished. A provider refusal, validation error, or network failure leaves the draft unchanged. Editors must explicitly accept item suggestions; AI cannot alter publication state.

## Manual provider compatibility smoke test

Configure OpenRouter and a local provider (such as Ollama) separately in Drupal AI; this is a manual smoke test, not an automated release requirement. For each provider, verify site-default and explicit model selection, then generate an unpublished draft from non-sensitive test content. Confirm that a model with native structured-output support receives the response schema, while a model without it follows the strict JSON prompt fallback and is still rejected safely if its JSON is invalid. Also verify refusal handling, missing usage metadata displaying as unavailable, and that no credential appears in Changelogify logs or operation records.

Automated tests use `FakeSummarizer` and make no external requests or require network credentials.
