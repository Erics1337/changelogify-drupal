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
