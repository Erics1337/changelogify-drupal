# Administrative event explorer

The event explorer at `/admin/content/changelogify/events` is a read-only review
and diagnostic interface. Access requires `administer changelogify`; neither raw
events nor their detail pages are exposed by public changelog routes.

Filters for date range, source, event type, actor user ID, entity type, bundle,
section hint, correlation ID, and release inclusion can be combined. Results are
ordered by timestamp and event ID, newest first, and remain paginated. Invalid
or reversed date ranges produce an explicit error state. **Clear filters**
returns to the unfiltered listing.

Each row links to an event detail page containing its normalized fields,
change-set membership, release inclusion, and up to 500 events with the same
correlation ID. This bound prevents a malformed or reused correlation ID from
creating an unbounded detail query.

Metadata is rendered as escaped plain text. Capture policies remain the primary
privacy boundary: data excluded at capture time cannot be recovered here. As a
defense in depth, metadata keys that resemble passwords, secrets, tokens, API
keys, or private keys are redacted again at render time. The detail page does
not turn stored labels, messages, or metadata into executable markup.

Release inclusion is derived from stable event IDs stored on release items. It
does not mutate releases or event evidence. Expired events are absent from the
event explorer and remain represented only by the privacy-bounded release
provenance described in [Release provenance](RELEASE_PROVENANCE.md).
