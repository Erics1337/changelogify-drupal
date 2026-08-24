# Extension and permission event semantics

Direct module and theme lifecycle changes use the `extension` source and record
`module_installed`, `module_uninstalled`, `theme_installed`, or
`theme_uninstalled`. Metadata identifies `extension_name` and `extension_type`.
Changelogify skips its own installation and suppresses module/theme events while
Drupal's configuration installer is synchronizing; those changes belong to the
correlated configuration-import evidence instead.

Permission-definition changes are configuration changes. Imported `user.role.*`
objects are classified as the sensitive `role` category and are included only
when sensitive technical configuration names are enabled.

Assignments of roles to an individual account are separate user events named
`user_role_assignments_changed`. They are never described as permission-definition
changes and do not share a configuration-import correlation ID.
