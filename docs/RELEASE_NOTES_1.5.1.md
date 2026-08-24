# Changelogify 1.5.1 release notes

Changelogify 1.5.1 fixes the database update path for existing sites upgrading
to the 1.5 editorial workflow. Clean 1.5.0 installations are unaffected.

## Fixed

- Corrects the entity type and provider arguments used when installing the
  event contract, release provenance, and stable slug fields.
- Makes custom event and release storage schemas tolerate incremental field
  installation while an historical schema is being upgraded.
- Adds regression coverage that removes the new fields and exercises their
  installation through the real Drupal entity definition update manager.

Changelogify 1.5.1 supports Drupal core `^10.3 || ^11` and PHP 8.1 or newer,
subject to each Drupal release's own PHP requirements.

## Upgrade procedure

1. Back up the database and active configuration and retain the prior module
   code artifact.
2. Deploy Changelogify 1.5.1 and run:

   ```bash
   drush updb -y
   drush cr
   ```

3. Sites whose 1.5.0 update stopped with `The "changelogify" entity type does
   not exist` can deploy 1.5.1 and rerun the same commands. Completed update
   hooks are not repeated; the remaining hooks resume safely.
4. Confirm database updates complete, then verify release editing, revision
   history, and public slug URLs.

## Rollback

Changelogify does not provide a reverse update. Restore the pre-upgrade
database, active configuration, and previous module code as one matched set.
Do not roll back code alone after database updates have completed.

