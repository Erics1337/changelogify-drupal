# Release publication events

Changelogify dispatches `changelogify.release_published` after a default
release revision successfully transitions from non-public to published. Use
`Drupal\changelogify\Event\ReleasePublishedEvent` when registering a Symfony
event subscriber.

The immutable event contains:

- `releaseUuid`: the stable public release UUID;
- `canonicalUrl`: its absolute public URL;
- `revisionId`: the exact revision that became public;
- `language`: the release language code;
- `publishedAt`: the canonical Unix timestamp of the transition; and
- `idempotencyId`: `changelogify:publication:{uuid}:{language}:{revisionId}`.

Consumers must persist the idempotency identifier before performing a remote
side effect and safely ignore a replay. Handlers should enqueue slow work and
must not assume delivery to an external system. Changelogify catches and logs a
subscriber exception so a notification failure cannot roll back the already
saved release.

The event intentionally excludes internal entity IDs, actors, evidence and
provenance, item source IDs, prompts, AI history, and provider configuration.
Editing an already published release does not redispatch it. Unpublishing and
archiving do not currently dispatch events; future lifecycle notifications, if
added, will use separate event classes and names.
