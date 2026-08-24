# Structured release-item editor

Release items are edited individually instead of as line-delimited section
text. Each stored item has an immutable server-validated ID, text, destination
section, and explicit order. Editors can change text, reorder items, move them
between sections, or remove them without changing the item's identity or source
event IDs.

Submitted IDs are checked against the stored release. Duplicate, unknown, or
stale IDs are rejected, and evidence is always recovered from server-side
storage rather than submitted form values. Moving an item also updates its
private provenance section. A removed item's evidence is removed from the
current revision; after revision support is enabled, the previous revision
retains the deleted item and evidence.

Three blank manual rows are available on every edit. A non-empty row receives a
new server-generated UUID and empty event evidence, which distinguishes it from
automatically evidenced content. Blank rows are ignored.

The complete editor works without JavaScript through section selectors and
numeric order controls. The optional JavaScript enhancement adds keyboard-
operable **Move up** and **Move down** buttons that update those same order
fields. It does not submit or persist any additional data.

Each stored item also has a collapsed **Source evidence** panel. It shows only
the privacy-bounded change-set kind, availability, evidence count, timestamp,
source/type, and technical entity descriptor. Available event IDs link to the
full administrative event explorer only when the editor separately has
`administer changelogify`. Expired, missing, removed, and manual evidence remain
clearly distinguished without exposing paths, messages, usernames, or metadata.
