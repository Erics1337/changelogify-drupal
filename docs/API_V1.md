# Changelogify public API v1

Changelogify provides a read-only JSON contract for published releases beneath
the configured public changelog path:

- `GET /changelog/api/v1/releases?limit=10&offset=0`
- `GET /changelog/api/v1/releases/{slug}`

The path prefix follows the Changelogify setting. Page size is clamped to 1–20
and offset to 0–10,000. Results are ordered by release date descending, then by
internal record order descending; internal identifiers are never serialized.

## Contract

List responses use `changelogify.release-list.v1`; detail responses use
`changelogify.release.v1`. A release contains `uuid`, `slug`, absolute `url`,
`title`, nullable `version`, `language`, ISO 8601 `release_date`, nullable ISO
8601 coverage dates, and public `sections`. Section objects contain a translated
`label` and `items`; each item contains only public `text`.

Draft, review, archived, and otherwise inaccessible releases behave as missing
resources. Numeric entity IDs, evidence/event IDs, provenance, actors, workflow
state, revision data, and AI history are intentionally outside this contract.

## Compatibility

Within v1, existing fields keep their meaning and type. New optional fields may
be added, so clients must ignore unknown object members. Removing or renaming a
field, changing its type or meaning, or exposing a different resource model
requires a new API version. Responses provide `ETag`, and `Last-Modified` when
the page contains a release, for conditional requests.
