# Changelogify for Drupal

**Automatically collect site changes, group them into releases, and publish a public changelog.**

Changelogify captures events from your Drupal site (content changes, module installations, user events) and helps you turn them into polished release notes for clients, stakeholders, or public consumption.

---

## ✨ Features

- **Automatic Event Capture** — Logs opted-in content, module, and user changes
- **Release Management** — Group events into releases with sections: Added, Changed, Fixed, Removed, Security, Other
- **Public Changelog** — Publish releases at `/changelog` with a clean, themeable UI
- **Admin Dashboard** — Quick stats and one-click release generation
- **Event Log** — Review captured changes before generating a release
- **Drupal 10.3/11 Compatible** — Attribute-based hooks with Drupal 10 compatibility shims

---

## 📋 Requirements

- Drupal 10.3+ or 11.x
- PHP 8.1+
- Node, Options, and User modules (core)

---

## 🚀 Installation

### For Development (with DDEV)

```bash
# Clone and set up
git clone https://github.com/Erics1337/changelogify-drupal.git
cd changelogify-drupal

# Scaffold Drupal around the module and start DDEV
ddev add-on get ddev/ddev-drupal-contrib
ddev start
ddev poser
ddev symlink-project
ddev config --update
ddev drush site:install standard --site-name="Changelogify" --account-name=admin --account-pass=admin -y

# Enable Changelogify
ddev drush en changelogify -y
ddev drush role:perm:add anonymous "view changelogify releases"
ddev drush role:perm:add authenticated "view changelogify releases"
ddev drush cr
```

### For Existing Drupal Sites

Copy this module directory into your site's `web/modules/custom/changelogify` directory and enable it via Drush or the admin UI:

```bash
drush en changelogify -y
```

---

## 📖 Usage

### 1. Dashboard

Navigate to **Configuration → Development → Changelogify** or visit:

```
/admin/config/development/changelogify
```

Here you'll see:

- Event count statistics
- Quick actions to generate releases
- Recent releases list

### 2. Generate a Release

1. Click **"Generate New Release"**
2. Choose: "Since last release" or "Custom date range"
3. Optionally set a title and version
4. Submit to create a draft release

### 3. Edit Release

The release edit form shows all sections:

- **Added** — New features, content types, functionality
- **Changed** — Updates and modifications
- **Fixed** — Bug fixes
- **Removed** — Deprecated features or content
- **Security** — Security patches
- **Other** — Miscellaneous changes

Edit the bullet points, select **Published**, and save the release. Unpublished
drafts are never available on the public list or detail routes.

### 4. Public Changelog

Published releases appear at:

```
/changelog
```

Individual releases are viewable at `/changelog/{release-id}`.

---

## ⚙️ Configuration

Visit **Configuration → Development → Changelogify → Settings** to configure:

| Setting                   | Description                          |
| ------------------------- | ------------------------------------ |
| **Changelog path**        | Public list and detail URL prefix    |
| **Track content changes** | Log node create/update/delete events |
| **Track unpublished content** | Include private node titles and paths in the internal event log |
| **Track module changes**  | Log module install/uninstall events  |
| **Track user changes**    | Log user creation and role changes   |
| **Event retention**       | Days to keep events (0 = forever)    |

User tracking and unpublished-content tracking are disabled by default. Event
retention runs in bounded batches during Drupal cron; the default is 90 days.

Captured events can be reviewed at `/admin/content/changelogify/events` by
users with the `administer changelogify` permission.

---

## 🔐 Permissions

| Permission                     | Description                            |
| ------------------------------ | -------------------------------------- |
| `administer changelogify`      | Access settings and dashboard          |
| `manage changelogify releases` | Create, edit, delete, publish releases |
| `view changelogify releases`   | View public changelog pages            |

Grant `view changelogify releases` to the anonymous role when the changelog should be public. The DDEV development setup above grants it to both anonymous and authenticated users.

Event metadata can contain node titles, paths, usernames, and role changes when
the corresponding tracking settings are enabled. Treat access to the dashboard
and event log as privileged administrative access.

---

## 🧪 Development checks

The DDEV contrib environment provides the project checks:

```bash
ddev phpcs
ddev phpstan
ddev phpunit
```

`ddev phpunit` runs the unit and functional suite, including draft access,
custom date generation, hook compatibility, retention, query indexes, and
tracking privacy behavior.

Stable releases are governed by the [compatibility matrix and release
checklist](docs/RELEASE_CHECKLIST.md). The Drupal.org pipeline tests the
declared Drupal 10.3/PHP 8.1 floor, rolling Drupal 10 and 11 releases, and the
maximum supported PHP version; PHPUnit, PHPStan, PHPCS, and applicable frontend
lint jobs must all pass.

### Upgrades and uninstall

Back up the database and exported configuration before running Drupal database
updates. Changelogify supports upgrades from the 1.1, 1.2, and 1.3 beta lines;
updates preserve stored event metadata, release sections, and administrator
configuration while adding missing defaults and query indexes. Failed schema
updates log recovery guidance and may be safely rerun after the database issue
is corrected.

Drupal blocks uninstall while Changelogify event or release content exists and
provides separate **Remove events** and **Remove releases** confirmation links
on the module uninstall screen. A blocked attempt preserves the module, its
configuration, and all records. After those records are explicitly removed,
confirming module uninstall permanently deletes the entity tables and
`changelogify.settings` active configuration. Export or back up records before
confirming their removal. Reinstalling starts with empty entity storage and the
defaults documented above.

---

## 🛣️ Roadmap

- [ ] **Latest Releases block** — Place in sidebars
- [ ] **AI Submodule** (`changelogify_ai`) — Auto-generate summaries with LLMs
- [ ] **Config entity export** — Deploy releases across environments
- [ ] **RSS/Atom feed** — Subscribe to changelog updates

---

## 🤝 Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Submit a pull request

---

## 📄 License

This project is licensed under GPL-2.0-or-later, consistent with Drupal core.

---

## 👤 Author

**Eric Swanson**  
[GitHub](https://github.com/Erics1337)
