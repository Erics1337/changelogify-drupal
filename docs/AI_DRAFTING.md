# Optional BYOK AI drafting

Changelogify 1.6 provides an optional `changelogify_ai` submodule. Core Changelogify neither depends on Drupal AI nor sends evidence to any provider. The submodule requires Drupal AI `^1.4` on Drupal `^10.5 || ^11.2`, plus a separately configured provider; provider credentials remain in that provider's configuration or Key integration. Core Changelogify remains compatible with Drupal `^10.3 || ^11`.

Before enabling external generation, an administrator must explicitly grant consent and review the policy-filtered payload. By default, usernames, actor and entity identifiers, paths, and unpublished labels are removed. Only selected change sets enter a request. Built-in profiles change tone, not factual requirements. The response must be bounded JSON with only known evidence IDs; HTML, unsupported sections, duplicate IDs, and malformed or oversized output are rejected without changing a release.

Complete drafts always start unpublished. A provider refusal, validation error, or network failure leaves the draft unchanged. Editors must explicitly accept item suggestions; AI cannot alter publication state.

## Manual provider compatibility smoke test

Use non-sensitive fixtures and test at least one cloud provider (for example,
OpenRouter) and one local Drupal AI provider (for example, Ollama). This is a
manual compatibility check; automated tests never make paid or credentialed
requests.

For each provider:

1. Verify both the site-default model and an explicit Changelogify model.
2. Preview the exact filtered evidence, then run Short, Standard, and Detailed
   synthesis. Confirm every result stays within its 5, 12, or 25-note limit.
3. Include enough evidence to show multiple background batches and at least
   one recursive consolidation round. Confirm progress completes, every final
   note has original evidence provenance, and coverage counts are consistent.
4. Confirm a model with native structured output receives the versioned
   response schema. With a model lacking that capability, confirm the strict
   JSON fallback succeeds only for conforming JSON and safely rejects prose,
   fenced JSON, malformed JSON, unknown citations, and oversized output.
5. Exercise refusal and temporary provider failure. Retry once, cancel one
   running job, and confirm neither path creates a partial release.
6. Confirm a successful synthesis creates exactly one unpublished draft and
   that a repeated worker/finalizer run cannot create a duplicate.
7. Review operation history and Drupal logs. Provider credentials, filtered
   payload text, temporary instructions, and provider exception text must not
   appear; missing token usage should display as unavailable.

Automated tests use `FakeSummarizer` and make no external requests or require network credentials.
