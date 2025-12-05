# Changelogify for Drupal

**Automatically collect site changes, group them into releases, and publish a public changelog.**

Changelogify captures events from your Drupal site (content changes, module installations, user events) and helps you turn them into polished release notes for clients, stakeholders, or public consumption.

---

## ✨ Features

- **Automatic Event Capture** — Logs content creates/updates/deletes, module installs, and user changes
- **Release Management** — Group events into releases with sections: Added, Changed, Fixed, Removed, Security, Other
- **Public Changelog** — Publish releases at `/changelog` with a clean, themeable UI
- **Admin Dashboard** — Quick stats and one-click release generation
- **Drupal 10/11 Compatible** — Built with modern Drupal best practices

---

## 📋 Requirements

- Drupal 10.x or 11.x
- PHP 8.1+
- Node and User modules (core)

---

## 🚀 Installation

### For Development (with DDEV)

```bash
# Clone and set up
git clone https://github.com/Erics1337/changelogify-drupal.git
cd changelogify-drupal

# Start DDEV and install Drupal
ddev start
ddev composer create-project "drupal/recommended-project:^10" . --no-install
ddev composer install
ddev composer require drush/drush
ddev drush site:install standard --site-name="My Site" -y

# Enable Changelogify
ddev drush en changelogify -y
ddev drush cr
```

### For Existing Drupal Sites

Copy the `web/modules/custom/changelogify` folder to your site's `modules/custom` directory and enable via Drush or the admin UI:

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

Edit the bullet points, then **Save and Publish**.

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
| **Track content changes** | Log node create/update/delete events |
| **Track module changes**  | Log module install/uninstall events  |
| **Track user changes**    | Log user creation and role changes   |
| **Event retention**       | Days to keep events (0 = forever)    |

---

## 🔐 Permissions

| Permission                     | Description                            |
| ------------------------------ | -------------------------------------- |
| `administer changelogify`      | Access settings and dashboard          |
| `manage changelogify releases` | Create, edit, delete, publish releases |
| `view changelogify releases`   | View public changelog pages            |

By default, anonymous users can view the public changelog.

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

This project is licensed under the GPL-2.0+ license, consistent with Drupal core.

---

## 👤 Author

**Eric Swanson**  
[GitHub](https://github.com/Erics1337)
