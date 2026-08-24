# Correlated configuration import events

Changelogify records a successful Drupal configuration synchronization only
after Drupal dispatches its completed import event. One `config_import_succeeded`
event contains create, update, and delete membership across every configuration
collection. The event and its member evidence share the event correlation ID.

Membership contains technical configuration names and classifier metadata only;
configuration values and exported YAML are never read. Sensitive classifications
are excluded by default. Administrators may opt into sensitive technical names or
exclude additional names with one shell-style pattern per line.

At most 200 members are retained per operation event. Exact create/update/delete,
excluded, and truncated totals are always stored, so large imports do not silently
lose accounting information. Module and theme lifecycle hooks marked as config
synchronization are not recorded separately.

Validation failures use `config_import_failed`, contain only an error count, and
never claim that changes were applied. Runtime integrations that catch a later
import exception can call `ConfigImportSubscriber::recordFailure()` with the same
importer event to produce the same privacy-bounded failure record.
