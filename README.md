# My IAPSNJ

Membership operations plugin for the Italian American Police Society of New
Jersey (IAPSNJ) website — version 4.x, built for the FluentCRM / FluentCart
stack that replaced Paid Memberships Pro.

**FluentCRM is the single source of truth.** WordPress users are credentials
plus a one-way mirror of a few profile fields; the membership application is
collected on the FluentCart checkout page; FluentCart payments set membership
state; this plugin runs the workflow around them and ships the PMPro →
FluentCRM migration toolkit.

| Layer | Plugin | Role |
|---|---|---|
| Data | FluentCRM | member records (`member_type`, `paid_through`, `Paid-YYYY` tags …) |
| Application + payment | FluentCart | one checkout page: FluentCart's name / email / phone / address fields plus the application fields this plugin injects; orders, receipts, refunds; check payments as the offline method |
| Login | WordPress users | credentials, mirrored one way from the CRM |
| Operations | **My IAPSNJ** | checkout application fields, membership automation, pending checks, reports, migration |

Documentation: [`docs/phase1-findings.md`](docs/phase1-findings.md) ·
[`docs/crm-schema.md`](docs/crm-schema.md) ·
[`docs/fluentcart-config.md`](docs/fluentcart-config.md) ·
[`docs/checkout-fields.md`](docs/checkout-fields.md) · [`docs/automations.md`](docs/automations.md) ·
[`docs/migration-runbook.md`](docs/migration-runbook.md) ·
[`docs/uat-test-matrix.md`](docs/uat-test-matrix.md) ·
[`docs/not-in-git.md`](docs/not-in-git.md)

---

## What it does

* **Membership state from payments** — on `fluent_cart/order_paid` /
  `fluent_cart/renewal_paid` (card at checkout, subscription renewal, check
  marked paid, or a check recorded by hand — one code path) the linked CRM
  contact gets `Paid-YYYY` tags, `member_type`, `paid_through` (never
  shortened; null for Lifetime/Honorary), loses `Payment-Pending-Check` /
  `Checkout-Abandoned`, gets a WordPress login if it has none, and the
  application is closed. Full refunds revert exactly what the order applied.
* **Term rule** — products carry no year. A payment before the renewal-season
  cutover (default Oct 1, Sync & Settings) covers through Dec 31 of that year;
  on/after it, through Dec 31 of the next year. Multi-year products add whole
  years. Checks count from the deposit date.
* **New-member notification** — plain-text email on *payment* with name, full
  mailing address, email, phone, department, rank, member number, product,
  amount and links. Never on application submitted.
* **Application inside the checkout** — the Join page is a set of buttons
  (one instant-checkout link per membership product). On the checkout page
  the plugin adds the application fields (department, rank, retirement date,
  date of birth, referred by, certification …), validates them server-side,
  stores them on the order and writes them to the CRM contact when the order
  is placed by check or paid. Logged-in members see them prefilled from the
  CRM (renewals). Sync & Settings has a small field builder: add fields, pick
  the type and where the answer is stored in FluentCRM (existing custom
  field, contact field, or a new custom field created on save). No Fluent
  Forms.
* **Application tracking** — as soon as an email is typed at checkout the
  contact exists and is tagged `Checkout-Abandoned`, and an application row
  (join or renewal, decided from the contact's Paid history) is opened. The
  order reconciles to it through FluentCart's own cart ↔ order link.
* **Renewal link** — `[iapsnj_renew_link]` sends a logged-in Regular /
  Associate member to the configured renewal product's checkout and a
  visitor to the Join page; Lifetime / Honorary members get nothing.
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
| Recommended | [FluentCart](https://fluentcart.com/) 1.6+ (+ Pro) |
| Optional | WP-CLI for the migration commands |

Without FluentCart the membership, checkout application, checks and
orphan-order features are inactive (the screens say so). Fluent Forms is not
used. Paid Memberships Pro is **not** required — its tables are read by the
migration if present.

## Installation

1. Download `my-iapsnj.zip` from the
   [latest release](https://github.com/S-FX-com/MyIAPSNJ/releases/latest).
2. Plugins → Add New → Upload Plugin → Activate.
3. My IAPSNJ → Sync & Settings → *Create missing tags & fields*, review the
   application fields (dropdown options for Department and Rank), set the
   renewal products and notification recipients, apply the "Pay by Check"
   label.
4. My IAPSNJ → Membership Products → map the FluentCart products, then build
   the Join page from the *Checkout links* it prints.

Upgrading from 3.x: data-version 5 drops PMPro-sourced and `pmpro_b*`
mappings, forces the mirror to CRM → WP, removes the PMPro options/cron and
creates the applications table. Data-version 6 (4.1) removes the Fluent Forms
settings, adds `cart_hash` / `fields` to the applications table and seeds the
default checkout fields. Data-version 7 (4.2) converts product rows to *years
covered* and the checkout fields to the builder format. Nothing in the CRM is
changed by an upgrade.

## Admin screens (My IAPSNJ menu, `manage_options`)

| Screen | Slug | Purpose |
|---|---|---|
| Dashboard | `my-iapsnj` | counts, members by type, paid years, environment checklist |
| Pending Checks | `my-iapsnj-checks` | batch mark paid; record a check |
| Membership Products | `my-iapsnj-products` | FluentCart variation → member type / years covered; checkout links |
| Reports | `my-iapsnj-reports` | open applications, orphan orders, aging, WP↔CRM orphans |
| Profile Mirror | `my-iapsnj-mapping` | CRM → WP field map with sample preview |
| Sync & Settings | `my-iapsnj-sync` | mirror now, triggers, application fields, renewal products, notification, checkout, CRM schema |
| Migration | `my-iapsnj-migration` | PMPro → CRM steps (shown while PMPro tables exist) |
| Notes Search | `my-iapsnj-notes-search` | |

Legacy slugs (`fcrm-wp-sync*`, `my-iapsnj-mismatches`, `my-iapsnj-pmp`)
redirect. CRM, Users, FluentCart and My IAPSNJ are pinned to the top of the
admin sidebar (filter `my_iapsnj_top_menu_slugs`).

## Shortcode

`[iapsnj_renew_link text="Renew my membership" join_text="Join IAPSNJ" class="button"]`
— renewal checkout link for the logged-in member (product per member type in
Sync & Settings), Join page link for visitors, nothing for Lifetime / Honorary.

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
`my_iapsnj/application_recorded($row, $cart)`.
Filters: `my_iapsnj/new_member_notification($mail, …)`, `my_iapsnj_top_menu_slugs`.

FluentCart hooks consumed: `fluent_cart/order_paid`, `fluent_cart/renewal_paid`,
`fluent_cart/order_placed_offline`, `fluent_cart/order_fully_refunded`,
`fluent_cart/should_send_email_notification`; checkout:
`fluent_cart/before_payment_methods` (render),
`fluent_cart/checkout/validate_data`, `fluent_cart/checkout/prepare_other_data`,
`fluent_cart/checkout/form_data_changed`, `fluent_cart/after_receipt_first_time`.

## Data

Options: `my_iapsnj_settings`, `my_iapsnj_products`, `my_iapsnj_checkout_fields`,
`my_iapsnj_field_mappings`, `my_iapsnj_last_bulk_sync`,
`my_iapsnj_data_version`. Table: `{prefix}my_iapsnj_applications`. User meta:
`_my_iapsnj_subscriber_id`. FluentCart order meta: `_my_iapsnj_application`,
`_my_iapsnj_application_applied`, `_my_iapsnj_applied`, `_my_iapsnj_snapshot`,
`_my_iapsnj_source`, `_my_iapsnj_check_number`, `_my_iapsnj_deposit_date`,
`_my_iapsnj_pending_check`, `_my_iapsnj_refunded`, `_my_iapsnj_notified`.
Deactivation deletes nothing.

## Development

```
my-iapsnj.php                     Bootstrap, activation, data migrations
includes/class-schema.php         CRM tags/fields/member types + ensure_crm_schema()
includes/class-dates.php          Timezone-safe date helpers (P1-4)
includes/class-membership.php     FluentCart → CRM membership state, notification
includes/class-checkout-fields.php Application fields on the FluentCart checkout, renewal link
includes/class-applications.php   Applications table (checkout → paid)
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
public/css/checkout-fields.css    Front-end styles for the checkout application section
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
