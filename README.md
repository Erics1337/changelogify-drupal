# Changelogify

Introduction
------------

Changelogify collects site changes (for example content, module, and user
events), groups them into releases, and publishes a public changelog.

The module provides:

- Event logging for supported site activity.
- Release generation and editing tools in the Drupal admin UI.
- Public changelog pages for published releases.


Requirements
------------

- Drupal `^10.3 || ^11`
- Core modules:
  - Node
  - User


Recommended modules
-------------------

- None


Installation
------------

Install as you would normally install a contributed Drupal module. Visit:
[Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-modules)
for further information.

If you use Composer:

```bash
composer require drupal/changelogify
```

Enable the module using Drush or the Drupal admin UI:

```bash
drush en changelogify -y
```


Configuration
-------------

1. Go to `Administration > Configuration > Development > Changelogify`.
2. Review the settings form and choose which events to track.
3. Configure permissions for users who should manage releases.

Relevant permissions include:

- `administer changelogify`
- `manage changelogify releases`
- `view changelogify releases`


Usage
-----

- Use the Changelogify dashboard to review captured events.
- Generate a release from recent events or a custom date range.
- Edit and publish releases to expose them on the public changelog pages.


Maintainers
-----------

Current maintainers:

- Eric Swanson (Erics1337)


Supporting organizations
------------------------

- None yet
