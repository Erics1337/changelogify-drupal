# Release provenance

Generated releases keep a separate, privacy-bounded evidence snapshot so their
text remains useful after raw changelog events expire. Snapshots contain only
technical identifiers, event type/source, timestamps, schema/correlation data,
and related entity identifiers. They never copy event messages, labels,
metadata, configuration values, usernames, or paths.

Raw-event retention marks referenced evidence `expired` before deletion.
Unexpectedly absent evidence resolves as `missing`. A separate provenance
retention setting can redact old snapshots as `removed` without changing the
release text. Malformed references are `invalid`, while mixed evidence is
reported as `partial`.

Provenance is not part of public release rendering. It is available only at the
administrative release provenance route to users with the `manage changelogify
releases` permission.

The same bounded snapshot is available in collapsed panels on the release edit
form. Links to an available raw event require the additional `administer
changelogify` permission. Evidence panels are administrative, uncacheable form
content and are never added to public release render arrays or cache entries.

When an editor explicitly confirms reuse of evidence from another release, the
affected item records only the referenced release IDs, confirming user ID, and
confirmation timestamp. This makes reuse attributable without copying release
titles, event messages, usernames, or raw metadata.

AI-synthesized releases use provenance version 2. Final notes cite one or more
original change-set IDs directly. Complete source/event IDs and counts remain available for
review, while detailed allowlisted event snapshots are capped at 200 across the
release and referenced instead of duplicated on every combined note.

Version 2 also stores server-computed coverage: evidence considered and cited,
evidence excluded by site eligibility, privacy policy, or the editor, and
eligible evidence not surfaced in the final notes. Coverage and synthesis job
metadata remain private. Public release rendering and API normalization keep
the existing public item shape and never expose these fields.
