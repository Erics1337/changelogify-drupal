# Content capture policy

Changelogify records content only when the content source, entity type, and
bundle are all enabled. Existing installations retain the historical defaults:
nodes, media, custom blocks, and taxonomy terms remain enabled after the update,
including their existing bundles. On a fresh installation, only entity types
present in shipped configuration are enabled. Types discovered later are disabled
and require an administrator to opt in to both the type and bundle.

The settings form discovers current content entity definitions and bundles.
Types commonly associated with personal or access-controlled data display an
additional privacy warning. Unpublished publishable entities remain controlled
by the separate “Track unpublished content” option.

Configuration may retain entries for uninstalled entity types or deleted
bundles. These entries are ignored safely and do not enable another type or
bundle with a different ID. Configuration imports therefore do not require the
referenced extension or bundle to exist at import time.
