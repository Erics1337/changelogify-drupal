# Deterministic change sets

`ChangeSetAggregatorInterface` converts up to 5,000 normalized events into
ordered immutable change sets before release generation. Every input event is
included exactly once or appears in `AggregationResult::suppressedEvents` with a
stable reason.

Grouping precedence is:

1. Explicit correlation ID, including one configuration import operation.
2. Tagged `ChangeSetGroupingStrategyInterface` services, ordered by descending
   priority and then class name.
3. Repeated update events for the same entity type, entity ID, source, release
   section, and five-minute activity window.
4. A standalone set for the individual event.

Message equality is never a grouping rule, so unrelated entities remain separate.
Legacy generic updates emitted at the same entity timestamp as publication events
and events with empty messages are reported as intentionally suppressed.

Change-set IDs are deterministic hashes of the grouping kind and correlation or
source event IDs. Each set exposes its kind, time window, event IDs, suggested
section, stable summary context, and schema/correlation provenance. Release
generation consumes these sets while retaining the existing release-item shape.
