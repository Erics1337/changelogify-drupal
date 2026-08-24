# Public release slugs

Each release receives a unique lowercase public slug of at most 128 characters.
Titles are transliterated, punctuation and whitespace become hyphens, and
numeric-only results receive a `release-` prefix. Collisions use deterministic
numeric suffixes such as `summer-launch-2`.

The slug is generated only when empty. Editing a title does not change an
existing slug. Editors may override the field; the submitted value is normalized
and collision-checked on the server. Changing a slug retains prior values in a
private revisionable history so old links permanently redirect to the current
canonical URL.

Accessible legacy numeric URLs also redirect with HTTP 301. Current, historical,
and numeric resolution all perform release access checks before redirecting or
rendering. Inaccessible and nonexistent drafts both return 404, so public routes
do not disclose draft existence. The configurable changelog prefix applies to
canonical and redirect targets without changing stored slugs.

Slug history is administrative routing state. Public templates receive only the
current slug and never expose numeric IDs, prior slugs, editorial state, or
release evidence.
