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

Both custom ranges and **Since last release** use the same preview and commit
contract. The original preview timestamps are retained through commit so a
change to the latest published release cannot silently move the window between
steps. No more than 5,000 events can enter a preview. An empty draft is allowed
only after the editor explicitly checks the empty-draft confirmation.
