# Release revisions and editorial workflow

Every release save creates a revision containing its title, dates, sections,
private provenance, editorial state, publication status, owner, revision user,
timestamp, and log message. Existing releases receive a preserved initial
revision during the 1.5 update. Revision history and restoration use Drupal's
revision APIs without requiring Content Moderation.

The editorial states are:

- **Draft** — editable, not publicly viewable.
- **Ready for review** — awaiting approval, not publicly viewable.
- **Published** — the only publicly viewable state.
- **Archived** — retained privately, not publicly viewable.

The entity's boolean publication status remains authoritative for public access
and is synchronized with the state on every save. Review and archived states
always have unpublished status. A restored revision restores both values.

State transitions require `manage changelogify releases` plus the relevant
workflow permission: `submit changelogify releases for review`, `publish
changelogify releases`, or `archive changelogify releases`. Revision history
and restoration use separate `view changelogify release revisions` and `revert
changelogify release revisions` permissions. The publish and revert permissions
are restricted-access permissions.

Allowed transitions are draft to review/published/archived, review to
draft/published/archived, published to draft/archived, and archived to draft.
Saving in the same state is always allowed for release managers. Publication,
unpublication, archive, restoration, generated drafts, and intentional evidence
reuse receive revision log entries.
