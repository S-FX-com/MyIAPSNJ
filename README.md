# My IAPSNJ

Member data sync and CRM tools for the Italian American Police Society of New
Jersey (IAPSNJ) website.

The plugin keeps WordPress user records and FluentCRM contacts in step, pulls
membership state out of Paid Memberships Pro, and gives administrators tools to
find and repair records that have drifted apart.

- **Bidirectional field sync** between WordPress users and FluentCRM contacts,
  driven by a configurable field map (WP user object, user meta, ACF, PMPro).
- **Paid Memberships Pro integration** — level-based tag assignment, join and
  expiration dates, and billing-address propagation.
- **Mismatch Resolver** — a side-by-side view of every field where the two
  systems disagree, with per-field resolution.
- **Notes Search** — full-text search across FluentCRM contact notes, with
  inline tag assignment.
- **Built-in updater** — checks GitHub Releases and installs updates through
  the normal WordPress plugins screen.

---

## Requirements

| | |
|---|---|
| WordPress | 5.8+ |
| PHP | 7.4+ |
| Required | [FluentCRM](https://fluentcrm.com/) |
| Optional | [Paid Memberships Pro](https://www.paidmembershipspro.com/), [Advanced Custom Fields](https://www.advancedcustomfields.com/) |

FluentCRM is a hard dependency — without it the plugin shows an admin notice
and does nothing else. The PMPro and ACF integrations activate automatically
when those plugins are present.

---

## Installation

1. Download `my-iapsnj.zip` from the
   [latest release](https://github.com/S-FX-com/MyIAPSNJ/releases/latest).
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and select the zip.
3. Activate **My IAPSNJ**.

On first activation the plugin seeds the IAPSNJ default field mappings and
migrates any settings from the plugin's former `fcrm_wp_sync_*` option keys.

### Updates

The plugin checks `S-FX-com/MyIAPSNJ` on GitHub for new releases and surfaces
them on the **Plugins** screen like any other update. Use the **Check for
Updates** link in the plugin's row action links to force an immediate check.

Releases are published automatically — see [Releases](#releases) below.

---

## Admin screens

All screens live under the **My IAPSNJ** menu and require the `manage_options`
capability.

### Field Mapping

Lists every FluentCRM field with a WordPress field to map it to. Unmapped rows
are pre-filled with a recommendation but stay disabled until you tick
**Enabled**.

Each row carries:

- **Field Type** — text, select, date, checkbox, number, email, textarea.
  Select rows can define a value translation map (WP value ↔ CRM value).
- **Sync Direction** — both, WP → FluentCRM, or FluentCRM → WP. Read-only
  WordPress fields (User ID, username, PMPro data) are forced to WP → CRM.
- **WP date format** — the format the WordPress side stores dates in. ACF date
  pickers usually use `m/d/Y`; PMPro fields are `Y-m-d`.

**Sample Data Preview** at the bottom shows both sides of every mapping for one
user, which is the quickest way to confirm a mapping before enabling it.

Ordering matters: when two mappings target the same FluentCRM field, the later
row wins — but only if its WordPress value is non-empty. The seeded mappings use
this deliberately, e.g. PMPro billing address is seeded *before* the ACF address
so a filled ACF profile still takes precedence and PMPro fills the gap.

### Sync & Settings

Bulk sync in either direction, with a per-field checklist so you can push one
field without touching the rest. Runs in pages to avoid timeouts.

Sync triggers:

| Setting | Fires on |
|---|---|
| On User Register | `user_register` |
| On Profile Update | `profile_update`, `updated_user_meta` |
| On User Delete | `delete_user` (unlinks the contact, never deletes it) |
| On FluentCRM Update | `fluent_crm/contact_created`, `fluent_crm/contact_updated` |
| On PMP Membership Change | `pmpro_after_change_membership_level` |

### Mismatch Resolver

Scans users that have a linked FluentCRM contact and lists every field where the
two sides disagree, comparing only mappings set to sync **both** ways. Dates are
normalised before comparison so `12/31/2026` and `2026-12-31` are not reported
as a conflict.

Per record you can keep the WordPress value, keep the FluentCRM value, or **Sync
Empty** — fill whichever side is blank without touching fields that differ.

Scanning stops as soon as the current page is full, so the reported total is a
lower bound (shown as `N+`) until the scan reaches the end of the user list.

### Memberships (PMPro only)

- **Tag Mappings** — which FluentCRM tags to apply for each membership level.
  Tags managed here are removed automatically when a member leaves the level.
- **Expiration Date Sync** — pushes the PMPro expiration date to the FluentCRM
  `expiration_date` custom field, on demand or via a daily WP-Cron job. For
  members with no fixed end date it falls back to the next scheduled renewal.
- **Backfill Billing Addresses** — copies PMPro checkout billing address into
  FluentCRM for contacts whose CRM address is empty. Never overwrites an
  address that is already populated.

### Notes Search

Searches `title` and `description` across FluentCRM subscriber notes, excluding
company notes and system logs. Results link to the contact and let you attach a
tag inline.

---

## REST API

Namespace `my-iapsnj/v1`. Every route requires `manage_options`.

| Method | Route | Purpose |
|---|---|---|
| `GET` | `/status` | Counts, last bulk sync, plugin version, settings |
| `GET` | `/fields` | Discoverable WordPress and FluentCRM fields |
| `GET` | `/mappings` | Saved field mappings |
| `POST` | `/mappings` | Replace the field mappings |
| `POST` | `/bulk-sync` | Paginated bulk sync |
| `GET` | `/mismatches` | Paginated mismatch list |
| `POST` | `/mismatches/resolve` | Resolve one field, all fields, or empty fields |

---

## Options

| Option | Contents |
|---|---|
| `my_iapsnj_field_mappings` | The field map |
| `my_iapsnj_settings` | Sync triggers |
| `my_iapsnj_pmp_tag_mappings` | PMPro level ID → FluentCRM tag IDs |
| `my_iapsnj_pmp_expiry_cron_enabled` | Daily expiry cron toggle |
| `my_iapsnj_pmp_expiry_last_sync` | Timestamp of the last expiry sync |
| `my_iapsnj_last_bulk_sync` | Timestamp of the last completed bulk sync |
| `my_iapsnj_data_version` | Data-migration schema version |

Each WordPress user also carries `_my_iapsnj_subscriber_id`, a cached pointer to
their FluentCRM contact so lookups survive an email change.

Deactivating clears the expiry cron. No data is deleted.

---

## Development

```bash
git clone https://github.com/S-FX-com/MyIAPSNJ.git
# Symlink or copy into wp-content/plugins/my-iapsnj
```

The plugin directory **must** be named `my-iapsnj` — the updater and asset URLs
depend on it.

There is no build step. PHP autoloading maps `My_IAPSNJ_Foo_Bar` to
`includes/class-foo-bar.php`.

```
my-iapsnj.php                    Bootstrap, activation, data migrations
includes/class-engine.php        Bidirectional sync + value formatting
includes/class-field-mapper.php  Field discovery, mapping CRUD, seeds
includes/class-admin.php         Admin screens and AJAX handlers
includes/class-mismatch-detector.php  Mismatch scanning and resolution
includes/class-pmp-integration.php    PMPro tags, dates, expiry cron
includes/class-rest-api.php      REST routes
includes/class-github-updater.php GitHub Releases updater
```

Lint before committing:

```bash
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
```

### Releases

Bump **both** the `Version:` header and the `MY_IAPSNJ_VERSION` constant in
`my-iapsnj.php`, then merge to `main`. The
[`release.yml`](.github/workflows/release.yml) workflow verifies the two agree,
lints every PHP file, tags `v<version>`, publishes a GitHub Release, and attaches
an installable `my-iapsnj.zip`.

The updater reads `/releases/latest`, which only returns full (non-draft,
non-prerelease) releases — the workflow always publishes those, so a merge to
`main` is all that is needed for sites to see the update.
