# Release generation preview

Release generation is a two-step operation. The first submission calculates a
read-only preview and does not create or modify releases or events. Candidate
change sets show their source, grouping kind, timestamp, suggested section, and
evidence count. Editors can exclude candidates and reassign included candidates
to another release section.

Selections use deterministic change-set IDs held in Drupal's server-side form
state. Candidate messages are display-only and are never accepted as submitted
release text. When the editor creates the draft, Changelogify reloads the
bounded event range, aggregates it again, and verifies every selected ID and
section. Deleted or regrouped evidence produces a recoverable error and asks the
editor to preview again.

When optional AI drafting is available, the same date-range preview also offers
release-level synthesis. That path does not require selecting each deterministic
row: all site-eligible evidence is considered by default. Its separate panel
shows profile and length controls, optional category/source/evidence exclusions,
and the exact post-eligibility, post-exclusion, privacy-filtered JSON. Changing
an exclusion requires updating that preview before the background job is queued.
The deterministic commit button and its section assignments remain unchanged.

The preview warns about overlapping draft or published windows, a gap since the
latest earlier covered window, and evidence already referenced by another
release. Reused evidence requires explicit confirmation and stores a minimal
attribution record in private provenance. Boundary timestamps are included in
the next preview instead of being advanced by one second; any reused boundary
evidence is therefore visible and cannot be duplicated silently.

Both custom ranges and **Since last release** use the same preview and commit
contract. The original preview timestamps are retained through commit so a
change to the latest published release cannot silently move the window between
steps. No more than 5,000 events can enter a preview. Historical empty releases
remain editable, but new empty drafts are not created by deterministic or AI
generation.
