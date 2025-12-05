# Changelogify for Drupal

Product Requirements Document (PRD)

## 1. Product Overview

**Name**  
Changelogify for Drupal

**Summary**  
Changelogify automatically collects site changes from Drupal, lets admins group them into releases, and publishes a public changelog page. An optional AI submodule can turn raw events into human readable release notes.

**Core idea**

1. Capture events from Drupal (content changes, configuration changes, user and module events) into a normalized event log.
2. Provide an admin UI to generate and edit "releases" from these events.
3. Publish releases on a public changelog list and detail page.
4. Optional AI submodule to summarize and rewrite raw events into polished notes.

---

## 2. Goals and Non Goals

### Goals

- Simple, opinionated way to maintain a public changelog for a Drupal site.
- Reduce manual effort when preparing "what changed" updates.
- Help agencies and teams produce recurring release notes for clients or stakeholders.
- Work on a standard Drupal 10+ site within a ddev environment.
- Respect privacy and work in environments that cannot use external SaaS.

### Non Goals (v1)

- Not a full compliance or security audit trail.
- Not a deployment orchestration or CI tool.
- Not an analytics or error monitoring suite.
- No cross site multitenant management dashboard (beyond normal Drupal multisite support).

---

## 3. Target Users and Use Cases

### Personas

1. **Agency developer / site builder**  
   Maintains many client sites and sends monthly maintenance or feature update reports.

2. **Product owner or marketing manager**  
   Runs a SaaS or product site built on Drupal and wants a public "What is new" page.

3. **Internal web team**  
   Needs to show stakeholders what changed over time without exposing Git logs or raw system logs.

### Primary Use Cases

- Generate a new release entry for "October 2025 updates" that covers content tweaks, new features, and bug fixes.
- Pull all events since the last release and turn them into a draft release.
- Publish a public changelog page at `/changelog` with a list of releases.
- Use the AI submodule to draft human readable text from raw events, then refine it.

---

## 4. Product Scope

### In Scope (v1)

- Custom content entity for **Releases** with sectioned items:
  - Added
  - Changed
  - Fixed
  - Removed
  - Security
  - Other
- Internal **event log** with pluggable event source adapters.
- Admin UI:
  - Generate release from events in a date range or since last release.
  - Edit and manage releases (draft and publish).
- Public routes:
  - Release listing page.
  - Single release detail page.
- Permissions for managing releases and configuration.
- Optional **AI submodule**:
  - Summarizes groups of events per section into bullets.
  - Optionally rewrites release text for clarity and tone.

### Out of Scope (v1)

- Rich browsing UI for all raw events outside release flow.
- Git integration or deployment log integration.
- Email or Slack notifications.
- Centralized dashboard that manages multiple Drupal instances from one control plane.

---

## 5. Architecture Overview

### Modules

- `changelogify`  
  Core module with:

  - Event entity and adapters.
  - Release entity.
  - Admin UI and public routes.

- `changelogify_ai` (optional submodule)  
  Adds AI powered features:
  - Event grouping and summarization.
  - Text improvement helpers.

### High Level Data Flow

1. Hooks and plugins capture events when content, config, and system elements change.
2. Events are stored as `changelogify_event` entities (or a dedicated table) with structured metadata.
3. Admin opens the Changelogify UI and chooses a time range.
4. The system pulls events in that range and groups them into sections.
5. Optional AI processing converts raw events into readable summaries.
6. Admin reviews, edits, and saves a **Release**.
7. Releases are published on public pages.

---

## 6. Functional Requirements

### 6.1 Module Structure

- Namespace: `Drupal\changelogify` and `Drupal\changelogify_ai`.
- Use Drupal 10 coding standards.
- Provide standard services:
  - Event manager service for collecting and querying events.
  - Release generator service for building draft releases from events.
  - AI client service (in `changelogify_ai`) abstracted behind an interface.

---

### 6.2 Release Entity

Create a **content entity** `changelogify_release` with storage in SQL.

**Fields**

- `id` (int, auto generated)
- `uuid` (UUID)
- `title` (string)
  - Short label, for example "October 2025 Release" or "v1.2.0".
- `label_type` (list string)
  - Values: `date_range`, `custom`, `semantic_version`.
- `version` (string, optional)
  - For semantic versioning, example "1.2.0".
- `date` (timestamp)
  - Release date, default to now.
- `date_start` (timestamp, optional)
  - Start of the change window this release covers.
- `date_end` (timestamp, optional)
  - End of the change window.
- `sections` (JSON or multi value field)
  - Suggested structure:
    ```json
    {
      "added": [
        {
          "id": "uuid-or-random-id",
          "text": "Added new booking form for tours.",
          "event_ids": [1, 5, 6]
        }
      ],
      "changed": [],
      "fixed": [],
      "removed": [],
      "security": [],
      "other": []
    }
    ```
- `status` (boolean or enum)
  - Values: `draft`, `published`.
- `created` (timestamp)
- `changed` (timestamp)
- `uid` (entity reference to user, author)

**Admin actions**

- List releases (filter by status, search by title or version).
- Add, edit, delete release entities.
- Publish or unpublish (draft) releases.

---

### 6.3 Event Entity

Create an internal entity or storage layer `changelogify_event`.

You can use a content entity or a lightweight custom table with a small CRUD wrapper. Entity is preferred for Drupal integration.

**Fields**

- `id` (int)
- `uuid` (UUID)
- `timestamp` (timestamp)
- `event_type` (string)  
  Examples:
  - `content_created`
  - `content_updated`
  - `content_deleted`
  - `config_changed`
  - `module_installed`
  - `module_uninstalled`
  - `user_created`
  - `user_role_changed`
- `source` (string)  
  Example: `content_entity`, `config`, `user`, `system`.
- `entity_type` (string, optional)  
  Example: `node`, `user`, `taxonomy_term`.
- `entity_id` (int, optional)
- `bundle` (string, optional)
  Example: `article`, `page`.
- `user_id` (int, optional)
  - Who triggered the event, if known.
- `message` (string)
  - Short, technical description or log message.
- `metadata` (JSON)
  - Arbitrary extra data, such as:
    - Title
    - Path
    - Old and new values
- `section_hint` (string, optional)
  - Suggested mapping to release section:
    - `added`, `changed`, `fixed`, `removed`, `security`, `other`.

**Behavior**

- Events are created as changes occur via hooks.
- Events can be queried by time range and type.
- Events are never exposed directly in public routes.

---

### 6.4 Event Source Adapters

Define a plugin type: `ChangelogifyEventSource`.

**Plugin interface example**

```php
interface ChangelogifyEventSourceInterface {
  public function id(): string;
  public function label(): string;
  public function collectEvents(\DateTimeInterface $start, \DateTimeInterface $end): array;
}
```

For v1, we will primarily log events in real time via hooks, but this plugin type leaves room for future backfill or alternate sources.

### Core adapters in v1

1. **Content entity adapter (nodes only at first)**

   - Use `hook_entity_insert`, `hook_entity_update`, `hook_entity_delete` for `node` entity type.
   - On insert:
     - Create `changelogify_event` with:
       - `event_type = content_created`
       - `source = content_entity`
       - `entity_type = node`
       - `bundle =` node type
       - `message` example: `Created Article: "Title".`
       - `section_hint = added`
   - On update:
     - `event_type = content_updated`
     - `section_hint = changed`
   - On delete:
     - `event_type = content_deleted`
     - `section_hint = removed`

2. **Module install and uninstall adapter**

   - Use `hook_module_installed` and `hook_module_uninstalled`.
   - On install:
     - `event_type = module_installed`
     - `source = system`
     - `message` example: `Installed module: module_name.`
     - `section_hint = added` or `other`
   - On uninstall:
     - `event_type = module_uninstalled`
     - `section_hint = removed`

3. **User events adapter (simple v1)**
   - Use `hook_user_insert`:
     - Log `user_created`, `section_hint = added`
   - Use `hook_user_update`:
     - When roles change, log `user_role_changed`, `section_hint = changed`

The adapter system should be easy to extend by other modules that want to register custom events.

---

## 6.5 Release Generation Flow

Admin creates a new release by choosing:

- **Mode**
  - "Since last release"
  - "Custom date range"

### Steps

1. **Determine the time window:**

   - "Since last release":
     - Use the last published release `date_end` if present, otherwise `date`.
     - `start =` that timestamp, `end = now`.
   - "Custom date range":
     - Admin picks start and end date.

2. **Query events:**

   - Fetch `changelogify_event` records with `timestamp` in the window.

3. **Group events:**

   - Group by `section_hint` into sections.
   - Optionally group further by entity type and bundle.

4. **Populate draft release:**

   - Create a new `changelogify_release` entity in Draft status.
   - Fill `date_start` and `date_end`.
   - For each section:
     - Create items with:
       - A default text such as:
         - "Updated Article: Title"
         - "Installed module: module_name"
       - Store referenced `event_ids`.

5. **Present draft to admin in UI:**
   - Editable text per section and item.
   - Option to delete or merge items.
   - Option to reassign items to a different section.

---

## 6.6 Admin UI Requirements

### Routes

- `/admin/config/development/changelogify`
  - Dashboard:
    - Button "Generate new release".
    - Simple stats:
      - Total events in the last N days.
      - Number of events since last release.
    - Short list of recent releases.
- `/admin/content/changelogify/releases`
  - Table of releases:
    - Title, version, date, status, operations.

### Forms

1. **Generate Release Form**

   - Fields:
     - Mode:
       - Radio: "Since last release" or "Custom date range"
     - Start date (if custom)
     - End date (if custom)
     - Optional freeform version label input
   - On submit:
     - Use Release generator service to create draft release.
     - Redirect to Release edit form.

2. **Release Edit Form**

   - Fields:
     - Title
     - Version and label type
     - Date, `date_start`, `date_end`
     - Sections:
       - Each section displayed in a collapsible fieldset:
         - List of items with:
           - Text area for bullet text.
           - Hidden `event_ids`.
         - Controls for:
           - Add item.
           - Delete item.
           - Move item up or down.
           - Move item to different section.
   - Status field:
     - Draft or Published.
   - Actions:
     - Save draft.
     - Save and publish.

---

## 6.7 Public UX Requirements

### Routes

- `/changelog`

  - Public listing of published releases.
  - Show:
    - Title or version.
    - Date.
    - Short excerpt (first 1 or 2 bullets or a summary, if available).
  - Paged list.

- `/changelog/{changelogify_release}`
  - Public detail page.
  - Show:
    - Title, date.
    - Optional version label.
    - Sections rendered with headings:
      - Added, Changed, Fixed, Removed, Security, Other.
    - Each item rendered as bullet text.

### Implementation details

- Use a controller or entity view builder.
- Provide a default View mode for releases that site builders can theme.
- Add a block "Latest Releases" that can be placed in sidebars.

---

## 6.8 AI Submodule Requirements (`changelogify_ai`)

### Dependencies

- Requires either:
  - A generic "AI" contributed module, or
  - A simple configuration for an HTTP based LLM API.

To stay flexible, wrap the HTTP call in a service that can be swapped.

### Features

1. **Generate section summaries from raw events**

   - Button on the Release edit form:
     - "Generate from events with AI"
   - Behavior:
     - For each section:
       - Collect metadata of events in that section.
       - Build a prompt that asks for grouped, human readable bullets.
       - Call AI service.
       - Replace or append to the section text items with AI generated content.
     - Do not overwrite existing admin content without confirmation.

2. **Improve wording**

   - For each section, add a "Rewrite with AI" mini action:
     - Sends existing text to AI with a prompt like:
       - "Rewrite these release notes to be clear and concise for non technical stakeholders."

3. **Configuration**

   - Settings form at `/admin/config/development/changelogify/ai`:
     - API key field.
     - Model name or endpoint field.
     - Toggles for:
       - Allow AI generation.
       - Allow AI rewriting.

### Technical notes

- All AI interactions should be done via a service:
  - `changelogify_ai.client` with a method like:
    - `generateSummary(array $eventsBySection, array $options = []): array`
- Handle errors gracefully:
  - Show admin a message when AI fails and keep any existing text unchanged.

---

## 6.9 Permissions

Define permissions in `changelogify.permissions.yml`.

Suggested permissions:

- `administer changelogify`
  - Access configuration and dashboard.
- `manage changelogify releases`
  - Create, edit, delete releases.
  - Publish or unpublish releases.
- `view changelogify releases`
  - Required to view public changelog routes.
  - By default granted to anonymous and authenticated users.
- For AI module:
  - `use changelogify ai`
    - Controls ability to trigger AI features.

---

## 7. Configuration and Settings

Settings form at `/admin/config/development/changelogify/settings`:

- Default path for listing: `/changelog` (allow override).
- Default sections to use (checkbox list, with add or remove support).
- Toggle event sources:
  - Track content changes.
  - Track module events.
  - Track user events.
- Event retention:
  - Keep events for X days (integer).
  - Cron job to purge old events.

---

## 8. Extensibility

### Plugin types

- `ChangelogifyEventSource`
  - Other modules can provide additional event sources.

### Hooks

- `hook_changelogify_event_alter(&$event)`
  - Allow other modules to modify event data before it is saved.
- `hook_changelogify_release_sections_alter(array &$sections, ChangelogifyReleaseInterface $release)`
  - Allow modules to adjust section content before display.

### API Services

- `changelogify.event_manager`
  - Methods:
    - `logEvent(array $data): ChangelogifyEventInterface`
    - `getEventsByRange(\DateTimeInterface $start, \DateTimeInterface $end, array $filters = []): array`
- `changelogify.release_generator`
  - Methods:
    - `generateReleaseFromRange(\DateTimeInterface $start, \DateTimeInterface $end, array $options = []): ChangelogifyReleaseInterface`
