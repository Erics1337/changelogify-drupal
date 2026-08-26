# Changelogify security, privacy, and operations

This guide describes the behavior of the core Changelogify 1.8 module. Core
release generation is deterministic and rule-based. Optional AI drafting is
provided by `changelogify_ai`; Changelogify does not provide semantic
deduplication.

## Data lifecycle

Changelogify uses three distinct states:

1. **Internal events** are technical evidence captured from enabled sources.
   They can include actor IDs and metadata, are visible only through
   administrative surfaces, and are never rendered by the public changelog
   routes.
2. **Draft releases** are editable release entities generated from a date range
   of internal events. Draft section items retain event IDs as provenance.
   Users with `manage changelogify releases` can view and edit them, but users
   with only the public-view permission cannot view them.
3. **AI synthesis jobs**, when the optional submodule is enabled, temporarily
   retain filtered evidence and intermediate candidates in server-side key/value
   storage. Queue entries contain only job/batch references. Terminal cleanup
   removes intermediate text and instructions; finalized text and bounded
   provenance move into an unpublished release revision.
4. **Published releases** contain editor-approved titles, versions, dates, and
   section text. They become visible to anyone with
   `view changelogify releases`. Raw event metadata is not rendered publicly.

Publishing is a disclosure decision. Generated text can contain labels or
other details copied from internal events. Editors must remove confidential,
personal, unpublished, or access-controlled information before publishing.

## Captured sources and defaults

These are the clean-install defaults. Upgrades preserve existing administrator
values and add only missing settings.

| Source or control | Default | Captured behavior and data |
| --- | --- | --- |
| Content tracking | Enabled | Create, update, delete, publish, and unpublish events for supported content entities. Events store entity type/ID, bundle, actor user ID, label, and a route path when available. |
| Automatically track new privacy-safe content types and bundles | Enabled on clean installations; disabled by the 1.7 update for existing sites | Newly discovered non-sensitive entity types and bundles follow this setting unless an explicit per-type or per-bundle choice exists. Users, unpublished-content capture, and privacy-sensitive types are never enabled by this default. |
| Unpublished content tracking | Disabled | When enabled, the content source also records unpublished or access-controlled entities. Labels and paths can reveal private editorial information. |
| Module tracking | Enabled | Module install/uninstall events outside configuration synchronization. Stores the module machine name and actor user ID. |
| User tracking | Disabled | User creation stores the username; role changes store the username plus old and new role IDs. Events also store the affected user ID and actor user ID. |
| Event retention | 90 days | Cron deletes at most 1,000 internal events older than the configured age per run. `0` disables expiration. |
| Public path | `/changelog` | Sets both the list path and the detail prefix. |

Content labels and paths can themselves contain personal data, client names,
unpublished campaign names, or clues about access-controlled material. User
IDs, usernames, and role assignments are personal or security-relevant data in
many organizations. Decide whether there is a lawful and operational need to
store them before enabling user or unpublished-content tracking.

Changelogify 1.8 supports Drupal 10.3 and later Drupal 10 releases, Drupal 11,
and PHP 8.1 or newer within those Drupal versions' PHP support ranges. The
upgrade and rollback procedures below apply across that supported matrix.

## Permissions and routes

| Permission | Surfaces and consequences |
| --- | --- |
| `administer changelogify` | Restricted-access permission. Opens `/admin/config/development/changelogify`, settings, and `/admin/content/changelogify/events`. Users can see internal event messages and operational data and can change privacy/retention settings. Grant only to trusted administrators. |
| `manage changelogify releases` | Opens release generation and release entity create/edit/delete operations. Users can view drafts, publish or unpublish releases, and expose edited release text. Grant only to trusted editors. |
| `view changelogify releases` | Allows viewing published release list/detail pages. Does not grant access to drafts or raw events. Granting it to Anonymous user makes all published release titles, versions, dates, and section text public. |
| `publish changelogify releases` | Allows release managers to publish and unpublish releases. Restricted access. |
| `revert changelogify release revisions` | Allows restoring private historical release content and state as a new revision. Restricted access. |
| `administer changelogify ai` | Configures provider selection, consent, eligibility, privacy, history retention, and cancellation. Restricted access. |
| `use changelogify ai` | Creates AI synthesis jobs and reviewable humanization suggestions from evidence the user can already edit. |
| `view changelogify ai history` | Displays non-secret provider/model, progress, usage, failure, and coverage metadata. Does not expose payload text. |

Ready-for-review and archived releases are unpublished and follow the same
public access denial as drafts. Revision history and evidence remain private
administrative data with separate permissions.

The default public routes are `/changelog` and
`/changelog/{changelogify_release}`. No role receives the public-view
permission from the module automatically; administrators choose the intended
roles. If the changelog is public, grant `view changelogify releases` to the
Anonymous user role only after reviewing every published release.

Changing the path in settings validates route collisions and rebuilds Drupal's
router. The old path stops resolving after the rebuild; Changelogify does not
create redirects. Update menus, canonical references, reverse-proxy/CDN rules,
and external links when changing it. Clear upstream caches if they do not honor
Drupal cache invalidation.

## Retention and cron

Drupal cron must run regularly for event expiration. For example:

```bash
drush cron
```

Each run removes up to 1,000 expired events, oldest first. Sites with a backlog
may require multiple cron runs. Retention applies only to internal events:
draft and published releases remain, including their edited section text and
stored event-ID provenance. An expired event ID can remain in a release item
after the referenced internal event has been deleted.

Use `0` only when indefinite storage is intentional and covered by the site's
privacy, database-growth, and backup policies. Lowering retention takes effect
on subsequent cron runs; it is not an undoable preview.

Optional AI operation history has a separate 1–3,650 day retention setting
(90 days by default). Cron purges terminal synthesis and humanization records.
Cancellation immediately makes queued references inert and removes temporary
evidence. Release provenance retention is also separate: it can remove bounded
event snapshots without changing accepted release text, source IDs, or coverage
counts.

The optional AI settings page reports the last site-cron, dedicated-runner, and
synthesis-worker heartbeats, queued synthesis-step count, and oldest wait. A
job that has waited 15 minutes without relevant worker activity is marked
delayed. This is an editor-facing diagnostic only: job-status polling is
read-only and cannot run the queue or contact an AI provider.

Production sites should configure their hosting scheduler to invoke the narrow
worker once per minute:

```bash
drush changelogify:ai-worker --time-limit=55
```

The command processes only the Changelogify synthesis queue and is bounded by
time, item, and lease limits. It is an operator setup step, not an editor
workflow. Drupal cron remains a compatible fallback. Request-driven Automated
Cron is less predictable because work starts only after the configured interval
is due and Drupal receives a request. Never expose the worker command through a
web route, and do not make job-status polling mutate queue or provider state.

Before enabling external AI processing, distinguish the three controls:
site-wide evidence eligibility determines which source categories may be
considered; the privacy policy determines which fields may leave Drupal; and
one-time editor exclusions only narrow a reviewed job. Review the exact payload
preview and the provider's own data-processing terms. See [optional BYOK AI
drafting](AI_DRAFTING.md).

## Upgrade and rollback preparation

Supported upgrade origins include the maintained 1.x releases through 1.7.
Before changing code:

1. Put the site in an appropriate maintenance/deployment state.
2. Back up the database, including both Changelogify entity tables.
3. Export or back up active configuration and retain the exact previous module
   code artifact.
4. Deploy the new code, run `drush updb -y`, then run `drush cr`.
5. Confirm the settings, internal event list, draft releases, published routes,
   and the site's cron schedule.
6. If `changelogify_ai` is enabled, review eligibility and privacy separately,
   preview a non-sensitive payload, run the documented provider checks,
   configure the once-per-minute dedicated worker, and verify its heartbeat,
   operation-history retention, and queue processing.

Updates preserve existing settings and event/release JSON payloads while adding
missing defaults and indexes. They are idempotent and can be rerun after an
interrupted attempt. A missing required table or failed index operation logs a
recovery message and stops the update instead of silently continuing.

There is no automated database downgrade. If rollback is required after a
database update, restore the matching pre-update database/configuration backup
and previous code together. Reverting only the module code can leave code and
schema out of sync.

## Uninstall

Drupal blocks uninstall while Changelogify event or release entities exist and
offers explicit **Remove events** and **Remove releases** actions. A blocked
uninstall preserves data and configuration. After an administrator confirms
both content-removal operations, uninstall removes the entity tables and
active `changelogify.settings` configuration. Back up or export required data
before removal; reinstalling does not restore it.
