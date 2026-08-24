# Versioned event contract

First-party event producers use `EventInput` and `EventManagerInterface::logEventInput()`.
The contract is immutable and currently uses schema version `1`.

The legacy `EventManagerInterface::logEvent(array $data)` method remains supported
through the 1.x release line. It adapts the existing array keys to `EventInput` and
applies the same validation before an entity is created. New integrations should use
the typed method.

`eventType`, `source`, related entity type, and bundle are lowercase machine
identifiers (`a-z`, `0-9`, and `_`) of at most 64 characters. A correlation ID is at
most 128 characters and may contain letters, numbers, `.`, `_`, `:`, and `-`.
Messages are limited to 512 characters. Timestamps are positive Unix timestamps,
actor and related entity IDs are non-negative integers, and metadata must be
JSON-serializable.

Allowed section hints are `added`, `changed`, `fixed`, `removed`, `security`, and
`other`.
