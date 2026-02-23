# Changelogify for Drupal

**Automatically collect site changes, group them into releases, and publish a public changelog.**

Changelogify captures events from your Drupal site (content changes, module installations, user events) and helps you turn them into polished release notes for clients, stakeholders, or public consumption.

---

## 💡 Why Keep a Changelog?

Based on the principles of maintaining a product and communicating with an audience, here is why any website or project should maintain a public changelog:

1. **Builds Trust and Transparency:** A changelog serves as a record of activity. For users, seeing regular updates, bug fixes, and new features proves that the website is actively maintained and that the developers are listening to feedback. This is especially important for sites that handle data or payments.
2. **Educates and Re-engages Users:** New features are often missed by casual users. A changelog acts as a central hub to explain how new tools work and why they were added. It provides a reason to send out a newsletter or social media update, bringing users back to the site to try out the improvements.
3. **Improves Internal Alignment:** For the team behind the website, the process of writing a changelog forces a moment of reflection. It helps align different departments (design, engineering, marketing) on what was actually achieved and ensures everyone is moving toward the same goals.
4. **Provides a Searchable History:** As a project grows, it becomes difficult to remember when a specific change was made or why a feature was altered. A changelog provides an easy-to-search archive for both the team and power users to reference past versions and technical shifts.
5. **Showcases "Craft" and Quality:** Publicly documenting small fixes and "polish" items shows that you care about the user experience. It signals to competitors, users, and potential collaborators that you value high-quality work and attention to detail.
6. **Reduces Support Volume:** By proactively listing known bug fixes and interface changes, you can reduce the number of support tickets from users who might otherwise think a change is a "glitch" or are looking for a feature that has been moved.

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

## Installation

Install as you would normally install a contributed Drupal module. For further
information, see
[Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).
See https://www.drupal.org/docs/develop/managing-a-drupalorg-theme-module-or...

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

## 👤 Author

**Eric Swanson**  
[GitHub](https://github.com/Erics1337)
