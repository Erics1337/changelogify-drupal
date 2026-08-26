# Optional BYOK AI drafting

Changelogify 1.8 provides the optional `changelogify_ai` submodule for
evidence-grounded release synthesis and reviewable wording suggestions. Core
Changelogify remains deterministic. The submodule requires Drupal AI `^1.4`,
Drupal core `^10.5 || ^11.2`, and a configured chat provider. Credentials stay
in Drupal AI, its provider module, or Key; Changelogify does not store them.

## AI workflows

The release preview offers two independent commit paths:

- **Create draft release** keeps deterministic, one-item-per-change-set
  generation with editor-selected sections.
- **Create AI draft release** considers every eligible change set in the date
  window by default and asks the configured model for a smaller categorized
  release. Pressing the button performs that provider request immediately and,
  on success, opens the new unpublished draft.

Existing per-item and whole-release humanization remain secondary editing
tools. They stage reviewable wording and never publish a release.

## Evidence controls

Three independent controls define the provider boundary:

1. **Site-wide eligibility** selects permitted source categories: content,
   extensions, users, configuration, and contributed/custom sources.
2. **Privacy policy** controls which fields from eligible evidence may leave
   Drupal. Eligibility never overrides redaction. Configuration keys and
   credential-like values are always blocked.
3. **One-time exclusions** let an editor remove categories, sources, or exact
   evidence records from one request. They cannot re-include prohibited data.

The form displays the exact post-eligibility, post-exclusion,
privacy-filtered JSON. Changing an exclusion invalidates the fingerprint and
requires **Update AI evidence preview** before generation. Previewing never
contacts a provider.

Evidence documents can include bounded redacted messages, event types, counts,
timestamps, sources, bundles, changed field names, correlation presence,
allowlisted values, and explicit truncation or exclusion markers. A truncated
document remains evidence, but missing detail cannot support a factual claim.

## One request, one response

Release synthesis contract version 2 sends the entire reviewed evidence
boundary in one provider request. It does not split evidence into batches,
create intermediate candidates, recursively consolidate results, enqueue AI
work, or wait for Drupal cron. A model or gateway that cannot accept the
request must fail safely; Changelogify never silently drops evidence or makes
overflow calls.

Profiles affect tone only: Public product, Client report, Internal technical,
or Concise. Output limits are:

| Preset | Maximum notes |
| --- | ---: |
| Short | 5 |
| Standard | 12 |
| Detailed | 25 |

Every factual note must cite original evidence IDs. The model may group
related records, report supported counts, prioritize significant recorded
activity, and identify evidence-grounded themes. It may not invent intent,
impact, causality, fixes, or security implications. Unsupported sections,
unknown citations, HTML, duplicate IDs, excessive notes, malformed JSON, and
oversized responses are rejected.

The Generate form disables the activated button and labels it **Starting…**
while the request is in flight. Changelogify records the initiating user and a
stable submission key. An equivalent prepared, running, or completed but not
finalized operation is reused instead of making another provider request.
Failed, cancelled, and finalized operations do not block a deliberate retry.

## Safe finalization and history

Immediately after a valid provider response, Changelogify re-previews the
release window and revalidates eligibility, exclusions, policy and prompt
versions, source availability, output, and direct provenance. The release
write and finalized marker share a database transaction and lock. Success
creates exactly one unpublished draft. Refusal, provider failure, stale
evidence, invalid output, or invalid provenance creates no partial release.

The indexed operation history is paginated, filterable, responsive, and
privacy-bounded. It stores safe metadata such as actor, operation state,
provider/model identity, token counts, output preset, coverage counts, and a
safe failure code. It never stores provider credentials, prompt text, filtered
payload text, exception text, or private provenance snapshots.

Job detail and JSON status routes remain available for duplicate/concurrent
submissions and troubleshooting. The creator may view their own job; users
with `view changelogify ai history` may view all jobs. Status polling is
read-only and never calls a provider. Because normal generation completes in
the initiating request, editors are sent directly to the unpublished draft
instead of a waiting screen.

Final provenance retains direct original change-set and event IDs, complete
counts, and at most 200 allowlisted event snapshots across the release.
Coverage records evidence considered, cited, excluded by eligibility/policy/
editor, and eligible but not surfaced. Public pages, feeds, blocks, and API
responses do not expose AI operation data or private provenance.

Drupal cron is used only for normal Changelogify retention cleanup. It does not
process AI synthesis, contact a provider, or create a release.

## Troubleshooting

- **Not ready:** grant external-processing consent and select an available
  Drupal AI chat provider/model.
- **No eligible evidence:** review eligibility, privacy policy, and one-time
  exclusions independently.
- **Preview changed:** update the evidence preview before generating again.
- **Request too large:** select a model/provider combination whose input limit
  accepts the reviewed boundary, narrow the release window, or exclude evidence
  explicitly. Changelogify will not silently batch or omit input.
- **Invalid response:** use a model with native structured output or one that
  reliably returns strict JSON. Prose and fenced JSON are not accepted.
- **Failed request:** review the safe failure guidance and Drupal logs, then
  retry from a fresh preview. Never copy credentials or private payload text
  into support tickets.

Provider quality, availability, cost, context limits, and data-processing terms
remain the operator's responsibility. Changelogify validates structure and
evidence references; it cannot certify a provider's privacy policy or guarantee
that every eligible event becomes a final note.

## Manual provider compatibility check

Use non-sensitive fixtures and test at least one cloud provider and one local
Drupal AI provider. Automated tests use `FakeSummarizer` and never make paid or
credentialed requests.

For each provider:

1. Verify both the site-default model and an explicit Changelogify model.
2. Run Short, Standard, and Detailed synthesis and confirm the 5, 12, and
   25-note bounds.
3. Use a large evidence window and confirm one request contains every reviewed
   evidence ID, or fails safely without a draft when the provider limit is too
   small.
4. Confirm native structured output where supported and strict-JSON fallback
   rejection of prose, fenced JSON, unknown citations, and oversized output.
5. Exercise refusal and provider failure; confirm each action makes at most one
   provider request and creates no partial release.
6. Confirm success creates exactly one unpublished draft with direct evidence
   provenance and consistent coverage.
7. Review history and logs to ensure credentials, payload text, temporary
   instructions, provider output, and raw exception text are absent.
