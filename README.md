# My IAPSNJ

Membership operations plugin for the Italian American Police Society of New
Jersey (IAPSNJ) website — version 4.x, built for the FluentCRM / Fluent Forms /
FluentCart stack that replaced Paid Memberships Pro.

**FluentCRM is the single source of truth.** WordPress users are credentials
plus a one-way mirror of a few profile fields; FluentCart payments set
membership state; Fluent Forms collects applications; this plugin runs the
workflow around them and ships the PMPro → FluentCRM migration toolkit.

| Layer | Plugin | Role |
|---|---|---|
| Data | FluentCRM | member records (`member_type`, `paid_through`, `Paid-YYYY` tags …) |
| Collection | Fluent Forms | join and renewal applications |
| Payment | FluentCart | orders, receipts, refunds; check payments as the offline method |
| Login | WordPress users | credentials, mirrored one way from the CRM |
| Operations | **My IAPSNJ** | membership automation, pending checks, reports, migration |

Documentation: [`docs/phase1-findings.md`](docs/phase1-findings.md) ·
[`docs/crm-schema.md`](docs/crm-schema.md) ·
[`docs/fluentcart-config.md`](docs/fluentcart-config.md) ·
[`docs/forms.md`](docs/forms.md) · [`docs/automations.md`](docs/automations.md) ·
[`docs/migration-runbook.md`](docs/migration-runbook.md) ·
[`docs/uat-test-matrix.md`](docs/uat-test-matrix.md) ·
[`docs/not-in-git.md`](docs/not-in-git.md)

---

## What it does

* **Membership state from payments** — on `fluent_cart/order_paid` (card at
  checkout, check marked paid, or a check recorded by hand — one code path)
  the linked CRM contact gets `Paid-YYYY` tags, `member_type`, `paid_through`
  (never shortened; null for Lifetime/Honorary), loses `Payment-Pending-Check`
  / `Checkout-Abandoned`, gets a WordPress login if it has none, and the
  application is closed. Full refunds revert exactly what the order applied.
* **New-member notification** — plain-text email on *payment* with name, full
  mailing address, email, phone, department, rank, member number, product,
  amount and links. Never on application submitted.
* **Application tracking** — every join/renewal submission is recorded, the
  contact is tagged `Checkout-Abandoned`, a token rides the redirect to
  FluentCart's instant checkout, the email is locked at checkout, and the
  order reconciles back to the application.
* **Pending Checks** — all unpaid check orders in one list; batch *mark paid*
  with deposit date and check numbers; the batch total is shown against the
  deposit slip. **Record a check** for a member who never used the website.
* **Reports** — applications awaiting payment, paid orders without an
  application, checks pending 30+ days, WordPress ↔ CRM orphans.
* **Profile Mirror** — CRM → WordPress user meta, configurable field map.
* **Migration toolkit** — census, subscriber linking, address consolidation,
  Paid-YYYY backfill, Honorary/Lifetime from `pmpro_memberships_users`,
  member state, login verification, reconciliation, PMPro order CSV export.
  Dry-run everywhere, WP-CLI and wp-admin.
* **Notes Search** — full-text search across FluentCRM notes with inline tags.
* **Built-in updater** — GitHub Releases.

## Requirements

| | |
|---|---|
| WordPress | 5.8+ |
| PHP | 7.4+ |
| Required | [FluentCRM](https://fluentcrm.com/) |
| Recommended | [FluentCart](https://fluentcart.com/) (+ Pro), [Fluent Forms](https://fluentforms.com/) Pro |
| Optional | WP-CLI for the migration commands |

Without FluentCart the membership, checks and orphan-order features are
inactive (the screens say so); without Fluent Forms, application tracking is
inactive. Paid Memberships Pro is **not** required — its tables are read by the
migration if present.

## Installation

1. Download `my-iapsnj.zip` from the
   [latest release](https://github.com/S-FX-com/MyIAPSNJ/releases/latest).
2. Plugins → Add New → Upload Plugin → Activate.
3. My IAPSNJ → Sync & Settings → *Create missing tags & fields*, set the
   join/renewal form ids and notification recipients, apply the "Pay by
   Check" label.
4. My IAPSNJ → Membership Products → map the FluentCart products.

Upgrading from 3.x: data-version 5 drops PMPro-sourced and `pmpro_b*`
mappings, forces the mirror to CRM → WP, removes the PMPro options/cron and
creates the applications table. Nothing in the CRM is changed by the upgrade.

## Admin screens (My IAPSNJ menu, `manage_options`)

| Screen | Slug | Purpose |
|---|---|---|
| Dashboard | `my-iapsnj` | counts, members by type, paid years, environment checklist |
| Pending Checks | `my-iapsnj-checks` | batch mark paid; record a check |
| Membership Products | `my-iapsnj-products` | FluentCart variation → member type / paid_through / years; checkout links |
| Reports | `my-iapsnj-reports` | open applications, orphan orders, aging, WP↔CRM orphans |
| Profile Mirror | `my-iapsnj-mapping` | CRM → WP field map with sample preview |
| Sync & Settings | `my-iapsnj-sync` | mirror now, triggers, forms, notification, checkout, CRM schema |
| Migration | `my-iapsnj-migration` | PMPro → CRM steps (shown while PMPro tables exist) |
| Notes Search | `my-iapsnj-notes-search` | |

Legacy slugs (`fcrm-wp-sync*`, `my-iapsnj-mismatches`, `my-iapsnj-pmp`)
redirect. CRM, Users, FluentCart, Fluent Forms and My IAPSNJ are pinned to the
top of the admin sidebar (filter `my_iapsnj_top_menu_slugs`).

## WP-CLI

```
wp iapsnj census [--level-map=1:Regular,4:Associate,2:Lifetime,6:Honorary]
wp iapsnj migrate <step|all> [--dry-run] [--level-map=…] [--from-year=2024]
                  [--order-statuses=success] [--order-tz=utc|site]
                  [--address-mode=prefer_recent|prefer_acf|prefer_pmpro|fill_empty]
                  [--limit=200] [--report=file.json]
wp iapsnj export-orders --file=<path>
wp iapsnj verify-logins [--expected=4000]
wp iapsnj reconcile
wp iapsnj crm-schema [--years=2024-2032]
wp iapsnj offline-label --label="Pay by Check" [--instructions="…"]
```

Steps: `census`, `link_subscribers`, `consolidate_addresses`,
`backfill_year_tags`, `migrate_comped`, `set_member_state`, `verify_logins`,
`reconciliation`. See `docs/migration-runbook.md`.

## REST API (`my-iapsnj/v1`, `manage_options`)

| Method | Route |
|---|---|
| GET | `/status`, `/summary`, `/fields`, `/mappings`, `/pending-checks` |
| POST | `/mappings`, `/bulk-sync`, `/checks/mark-paid`, `/checks/record` |
| GET | `/reports/{open-applications|orders-without-application|aging|users-without-contact|contacts-missing-user}` |

## Hooks

Actions: `my_iapsnj/membership_paid($subscriber, $order, $applied)`,
`my_iapsnj/new_member(...)`, `my_iapsnj/membership_refunded(...)`,
`my_iapsnj/application_recorded($row, $form_data, $form)`.
Filters: `my_iapsnj/new_member_notification($mail, …)`, `my_iapsnj_top_menu_slugs`.

FluentCart hooks consumed: `fluent_cart/order_paid`,
`fluent_cart/order_placed_offline`, `fluent_cart/order_fully_refunded`,
`fluent_cart/checkout_page_name_fields_schema`,
`fluent_cart/should_send_email_notification`. Fluent Forms:
`fluentform/submission_inserted`, `fluentform/redirect_url_value`,
`fluent_crm/contact_updated_by_fluentform`.

## Data

Options: `my_iapsnj_settings`, `my_iapsnj_products`,
`my_iapsnj_field_mappings`, `my_iapsnj_last_bulk_sync`,
`my_iapsnj_data_version`. Table: `{prefix}my_iapsnj_applications`. User meta:
`_my_iapsnj_subscriber_id`. FluentCart order meta: `_my_iapsnj_applied`,
`_my_iapsnj_snapshot`, `_my_iapsnj_source`, `_my_iapsnj_check_number`,
`_my_iapsnj_deposit_date`, `_my_iapsnj_pending_check`, `_my_iapsnj_refunded`.
Deactivation deletes nothing.

## Development

```
my-iapsnj.php                     Bootstrap, activation, data migrations
includes/class-schema.php         CRM tags/fields/member types + ensure_crm_schema()
includes/class-dates.php          Timezone-safe date helpers (P1-4)
includes/class-membership.php     FluentCart → CRM membership state, checkout token, notification
includes/class-applications.php   Fluent Forms applications table + hooks
includes/class-checks.php         Pending checks, batch mark paid, record a check
includes/class-reports.php        Orphans, aging, summary
includes/class-migration.php      PMPro → CRM steps (dry-run, paged)
includes/class-cli.php            wp iapsnj …
includes/class-engine.php         CRM → WP mirror
includes/class-field-mapper.php   Field discovery + mirror map
includes/class-admin.php          Screens + AJAX
includes/class-rest-api.php       REST routes
includes/class-github-updater.php GitHub Releases updater
admin/js/admin.js, admin/css/admin.css
docs/                             Project documentation (not shipped in the zip)
```

No build step. Autoloading maps `My_IAPSNJ_Foo_Bar` → `includes/class-foo-bar.php`.
PHP 7.4 syntax only. Lint: `find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l`.

### Releases

Bump both the `Version:` header and `MY_IAPSNJ_VERSION` in `my-iapsnj.php`,
merge to `main`; `.github/workflows/release.yml` tags, builds `my-iapsnj.zip`
and publishes the release the updater reads. **4.0.0 removes all PMPro code:
merge it to `main` only as part of the cutover**, after PMPro is deactivated on
production (see `docs/migration-runbook.md`).
