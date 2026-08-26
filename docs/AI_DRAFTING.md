# Optional BYOK AI drafting

Changelogify 1.8 provides the optional `changelogify_ai` submodule for
evidence-grounded release synthesis and reviewable wording suggestions. Core
Changelogify remains deterministic and does not depend on Drupal AI. The
submodule requires Drupal AI `^1.4`, Drupal core `^10.5 || ^11.2`, and a
separately configured chat provider. Provider credentials remain in Drupal AI,
its provider module, or Key; Changelogify configuration, queues, logs, and
operation history do not store them.

## AI workflows

The release-generation preview offers two independent commit paths:

- **Create draft release** keeps deterministic, one-item-per-change-set
  generation with editor-selected sections.
- **Create AI draft release** considers every eligible change set in the date
  window by default and synthesizes a smaller categorized release. Editors
  choose a profile, length, and optional one-time exclusions, then review the
  exact filtered evidence before the job is queued.

Existing per-item and whole-release humanization remain secondary editing
tools. They stage reviewable wording and never publish a release.

## Three independent evidence controls

1. **Site-wide eligibility** selects which event-source categories may be used:
   content, extensions, users, configuration, and contributed/custom sources.
   Existing sites without this setting use all five categories until an
   administrator saves a narrower policy.
2. **Privacy policy** controls which fields from eligible evidence may leave
   the site. Eligibility does not override redaction. The recommended preset
   removes usernames, actor/entity IDs, paths, unpublished labels, and
   correlation IDs; configuration keys and credential-like values are always
   blocked.
3. **One-time exclusions** let an authorized editor exclude categories, exact
   event sources, or individual evidence records for one synthesis job. They
   cannot include evidence prohibited by site eligibility.

The release form displays the exact post-eligibility, post-exclusion,
privacy-filtered JSON. Changing an exclusion invalidates the fingerprint and
requires **Update AI evidence preview** before the job can be created. No
provider request occurs during preview.

Evidence documents are bounded and can include redacted messages, event types,
counts, timestamps, sources, bundles, changed field names, correlation
presence, allowlisted values, and explicit truncation or policy-exclusion
markers. A truncated document remains usable evidence, but its missing detail
cannot support an inference.

## Profiles, length, and factual boundaries

Profiles affect tone only: Public product, Client report, Internal technical,
or Concise. Final synthesis limits are:

| Preset | Maximum final notes |
| --- | ---: |
| Short | 5 |
| Standard | 12 |
| Detailed | 25 |

Every factual note must cite provided evidence. The model may group related
records, report supported counts, prioritize significant recorded activity,
and identify evidence-grounded themes. It may not invent intent, impact,
causality, fixes, or security implications. Unsupported sections, citations,
HTML, duplicate IDs, excessive notes, and oversized or malformed responses are
rejected.

## Hierarchical processing and finalization

Requests are deterministically split at 100 evidence documents or 32 KiB of
provider-visible JSON, whichever comes first. Intermediate rounds can return
at most 50 candidates per batch. Candidates are recursively consolidated until
one final bounded request remains. Queue entries contain only a job ID and
batch ID; credentials and payload text remain outside queue records.

After submission, the editor is taken to a dedicated job page that updates
automatically. It reports waiting, evidence analysis, recursive consolidation,
draft creation, completion, failure, or cancellation in editorial language.
Polling is read-only: only a configured background worker claims queue work or
contacts the provider. The page pauses updates while its browser tab is hidden, recovers
from temporary network failures, and includes a manual refresh fallback when
JavaScript is unavailable. A completed job links directly to its unpublished
draft and evidence provenance.

The indexed operation history is paginated, filterable, responsive, and keeps
active work first. Its compact list shows creation time, editorial operation
type, status, progress, result, and actions. Provider/model identity, token
usage, contract versions, coverage, and safe failure diagnostics remain on the
job detail page. Jobs support bounded retries, owner cancellation, duplicate
delivery and submission protection, and retention cleanup. Cancellation,
refusal, stale evidence, malformed output, or terminal failure cannot create a
release.

AI settings include background-processing health: the last site-cron,
dedicated-runner, and synthesis-worker heartbeats, queued count, and oldest
wait. A queued job is
reported as delayed after 15 minutes without relevant worker activity. An
authorized AI administrator receives a direct link to the in-product health
panel; editors never need a command-line tool to follow or complete their
workflow.

For production, schedule the narrow worker command once per minute through the
hosting platform or server scheduler:

```bash
drush changelogify:ai-worker --time-limit=55
```

This command processes only Changelogify synthesis references and records a
runner heartbeat. Drupal cron remains a compatible fallback, but request-driven
Automated Cron can leave jobs waiting until both its interval is due and the
site receives another request. Do not schedule generic `queue:run` for this
workflow. Status polling never invokes either command, claims queue items, or
makes provider requests.

A request that fits the evidence and payload bounds normally uses one provider
call. Larger requests use deterministic batches and, only when needed,
additional consolidation calls. Queue rows represent bounded synthesis work;
they are not one humanization request per tracked event.

Immediately before persistence, Changelogify re-previews the date range and
revalidates eligibility, exclusions, policy and prompt versions, source
availability, final output, and transitive provenance. The release write and
job-finalized marker share a database transaction and lock. A successful job
creates exactly one unpublished draft; repeated workers cannot create another.

## Provenance, coverage, and retention

Final candidate citations resolve to original change-set and event IDs.
Version 2 release provenance retains complete IDs and counts, but no more than
200 allowlisted event snapshots across the release. It records evidence
considered, cited, excluded by eligibility/policy/editor, and eligible but not
surfaced. Intermediate provider text and temporary instructions are removed at
terminal states; successful final text lives in the release revision.

Public changelog pages, feeds, blocks, and API responses do not expose AI
operation data or private provenance. A job creator with `use changelogify ai`
may view and cancel their own active synthesis job. Operation history requires
`view changelogify ai history`; administrators with `administer changelogify
ai` may cancel other users' active work. Release provenance requires `manage
changelogify releases`. Legacy jobs without a recorded creator are visible
only through privileged history access.

The AI history-retention setting controls terminal job metadata and completed
results waiting for cleanup. Core event retention and release-provenance
retention remain separate controls.

## Troubleshooting

- **Not ready:** grant external-processing consent and select an available
  Drupal AI chat provider/model.
- **No eligible evidence:** review the site-wide category allowlist separately
  from privacy redaction and one-time exclusions.
- **Preview changed:** update the evidence preview; events, policies, settings,
  or exclusions changed after the prior fingerprint.
- **Invalid response:** use a model with native structured output or one that
  reliably returns strict JSON. Provider prose and fenced JSON are not accepted.
- **Delayed job:** administrators should confirm that the dedicated worker
  heartbeat is current and that the hosting scheduler runs once per minute.
  Drupal cron can be used as a fallback, but status polling cannot process work.
- **Failed job:** review the safe failure guidance and Drupal logs, then retry
  with a new preview after the terminal failure. Do not copy
  credentials or private payload text into support tickets.

Provider output quality and availability remain provider/model limitations.
Changelogify validates structure and evidence references; it does not certify a
provider's privacy policy or guarantee that every eligible event appears in the
final editorial summary.

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

Automated tests use `FakeSummarizer` and make no external requests or require
network credentials.
