# Privacy-aware entity differences

`EntityDifferenceServiceInterface` compares an updated fieldable entity with its
matching original revision or translation. If the original is unavailable, it
returns an empty result rather than treating every field as changed.

By default, results contain only field machine names, a single publication
transition, and entity-reference target IDs. The service never loads referenced
entities and never returns arbitrary text, files, secrets, or personal values.
Computed, read-only, revision, operational, and translation-bookkeeping fields
are ignored.

Callers may pass an explicit scalar field allowlist. Values are returned only for
supported scalar field types; multivalue and structurally unsupported values are
redacted to `null`, strings longer than 128 bytes become `[redacted]`, and fields
with secret-like names never expose values. Results and keys are deterministic.
