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
  cutover (default Oct 1, Dues → Membership Products) covers through Dec 31 of that year;
  on/after it, through Dec 31 of the next year. Multi-year products add whole
  years. Checks count from the deposit date.
* **New-member notification** — plain-text email on *payment* with name, full
  mailing address, email, phone, department, rank, member number, product,
  amount and links. Never on application submitted.
* **Application inside the checkout** — the Join page is a set of buttons
  (one instant-checkout link per membership product). On the checkout page
  the plugin adds the application fields (the whole former onboarding form:
  department, rank, retirement date, phones, union, date of birth, marital
  status, spouse, armed service, employer, referred by, additional
  information, certification), validates them server-side,
  stores them on the order and writes them to the CRM contact when the order
  is placed by check or paid. Logged-in members see them prefilled from the
  CRM (renewals). The **Checkout Builder** holds one or more checkout forms
  (duplicate a form to start a variant) and says which form each membership
  level uses: Regular (Lifetime uses the Regular form) and Associate. In a
  form: add fields, pick the type and where the answer is stored in FluentCRM
  (existing custom field, contact field, or a new custom field created on
  save), group fields under section headings, and switch on any other
  FluentCRM field from the Inactive list (hidden fields are never
  required). Rows are arranged by drag and drop, including between the
  active and inactive lists; a field indented under another is shown only
  when that field's answer matches (conditional display). Each form has a
  *View checkout* button that opens the real checkout showing it. A cart without a membership product (events, merchandise) shows no
  application fields and checks out with FluentCart's own fields only. No
  Fluent Forms.
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
* **Profile Mirror** — CRM → WordPress user meta, configurable field map,
  plus a WordPress role per member type (Settings → Profile Sync; staff accounts
  are never touched).
* **Expirations** — status tag `Member-Active` and the role are given on
  payment and taken away by a daily WP-Cron job once `paid_through` is past
  (grace period configurable); `wp iapsnj expire` and a Preview / Apply
  button on Members → Lapsed Members. `Paid-YYYY` history is never removed.
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
3. My IAPSNJ → Settings → Configurations → *Create missing tags & fields*, review the
   application fields (the Department and Rank dropdown options are imported
   from the ACF field choices or the existing CRM values; *Fill empty
   dropdown options* re-runs that), set the
   renewal products and notification recipients, apply the "Pay by Check"
   label.
4. My IAPSNJ → Membership Products → map the FluentCart products, then build
   the Join page from the *Checkout links* it prints.

Upgrading from 3.x: data-version 5 drops PMPro-sourced and `pmpro_b*`
mappings, forces the mirror to CRM → WP, removes the PMPro options/cron and
creates the applications table. Data-version 6 (4.1) removes the Fluent Forms
settings, adds `cart_hash` / `fields` to the applications table and seeds the
default checkout fields. Data-version 7 (4.2) converts product rows to *years
covered* and the checkout fields to the builder format. Data-version 8 (4.3)
adds the remaining onboarding-form fields to the checkout, shows the union
fields, fills empty dropdown option lists (ACF choices → CRM values →
built-in list) and creates the CRM custom fields those answers are written to
(existing fields are never modified). Data-version 9 (4.4) points 3.x-era
Profile Mirror rows at `member_type` / `paid_through` and adds the
membership rows (member status, expiration date, join date, member number)
if missing. Data-version 10 (4.7) moves the checkout field list and the
application heading / intro into the Checkout Builder as the form
"Membership application", used by both levels (the checkout looks the same
until the forms are changed).

## Admin screens (My IAPSNJ menu, `manage_options`)

The submenu is grouped under headings (**Dues**, **Members**, **Settings**);
slugs of the screens that existed before 4.11 are unchanged.

| Group | Screen | Slug | Purpose |
|---|---|---|---|
| | Dashboard | `my-iapsnj` | counts, members by type (active / lapsed), paid years, environment checklist |
| Dues | Membership Products | `my-iapsnj-products` | FluentCart variation → member type / years covered; renewal season, renewal product per type, Join page URL, checkout links |
| Dues | Pending Checks | `my-iapsnj-checks` | batch mark paid; record a check |
| Dues | Checkout Builder | `my-iapsnj-checkout` | checkout forms and the form per level; billing address → CRM; Pay by Check label & instructions |
| Members | Active Membership | `my-iapsnj-members` | members in good standing: search, filters, sort, CSV |
| Members | Lapsed Members | `my-iapsnj-lapsed` | lapsed members and data checks; grace period; expirations Preview / Apply now |
| Members | Notes Search | `my-iapsnj-notes-search` | |
| | Reports | `my-iapsnj-reports` | open applications, orphan orders, aging, WP↔CRM orphans; aging threshold, go-live date |
| Settings | Configurations | `my-iapsnj-sync` | new-member notification, CRM schema, phone number format (formerly "Sync & Settings") |
| Settings | Profile Mirror | `my-iapsnj-mapping` | CRM → WP field map with sample preview |
| Settings | Profile Sync | `my-iapsnj-profile-sync` | mirror triggers, mirror all contacts now, WordPress role per member type |
| Settings | Migrate PMPro | `my-iapsnj-migration` | PMPro → CRM steps (shown while PMPro tables exist) |

### Member lists (4.12)

**Members → Active Membership** and **Members → Lapsed Members** list FluentCRM
contacts that have a `member_type`, split by the same rule as the daily expiry
job (`My_IAPSNJ_Schema::is_active_state()`; the cutoff is today minus the grace
period). Each list has views per member type, search (name, email, member
number), filters (Active: not yet paid for next year, lapses within N days, comped;
Lapsed: paid-through year or no date, lapsed in the last N days), sorting by
name, member type and paid through, 25–200 rows per page, and
**Export CSV** of the current filter (`wp_ajax_my_iapsnj_members_csv`, admins
only; cells are formula-safe). Rows link to the CRM contact, the WordPress user
and the last membership order.

The Lapsed page also shows **data checks**: paid_through not `YYYY-MM-DD`,
Member-Active tag on a contact with no member type, lapsed members still
tagged Member-Active, and a Lifetime/Honorary tag without the matching type. A paid_through that is not strictly
`YYYY-MM-DD` counts as lapsed in the list and is flagged there; fix it in the CRM.

Legacy slugs (`fcrm-wp-sync*`, `my-iapsnj-mismatches`, `my-iapsnj-pmp`)
redirect. My IAPSNJ sits in the admin sidebar just below FluentHub (or below
Dashboard when FluentHub is not installed); no other menu is moved.

## Shortcode

`[iapsnj_renew_link text="Renew my membership" join_text="Join IAPSNJ" class="button"]`
— renewal checkout link for the logged-in member (product per member type in
Dues → Membership Products), Join page link for visitors, nothing for Lifetime / Honorary.

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
wp iapsnj phones [--dry-run]
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
Filters: `my_iapsnj/new_member_notification($mail, …)`.

FluentCart hooks consumed: `fluent_cart/order_paid`, `fluent_cart/renewal_paid`,
`fluent_cart/order_placed_offline`, `fluent_cart/order_fully_refunded`,
`fluent_cart/should_send_email_notification`; checkout:
`fluent_cart/before_payment_methods` (render),
`fluent_cart/checkout/validate_data`, `fluent_cart/checkout/prepare_other_data`,
`fluent_cart/checkout/form_data_changed`, `fluent_cart/after_receipt_first_time`,
`fluent_cart/checkout_page_name_fields_schema`, `fluent_cart/checkout_renderer/billing_fields`
(CRM → checkout prefill), `fluent_cart/subscription_renewed`.

## Data

Options: `my_iapsnj_settings`, `my_iapsnj_products`, `my_iapsnj_checkout_forms`
(`my_iapsnj_checkout_fields` is the ≤ 4.6 list, kept for a rollback),
`my_iapsnj_field_mappings`, `my_iapsnj_last_bulk_sync`,
`my_iapsnj_data_version`. Table: `{prefix}my_iapsnj_applications`. User meta:
`_my_iapsnj_subscriber_id`. FluentCart order meta: `_my_iapsnj_application`,
`_my_iapsnj_application_applied`, `_my_iapsnj_checkout_form`, `_my_iapsnj_applied`, `_my_iapsnj_snapshot`,
`_my_iapsnj_source`, `_my_iapsnj_check_number`, `_my_iapsnj_deposit_date`,
`_my_iapsnj_pending_check`, `_my_iapsnj_refunded`, `_my_iapsnj_notified`.
Deactivation deletes nothing.

## Development

```
my-iapsnj.php                     Bootstrap, activation, data migrations
includes/class-schema.php         CRM tags/fields/member types + ensure_crm_schema()
includes/class-dates.php          Timezone-safe date helpers (P1-4)
includes/class-membership.php     FluentCart → CRM membership state, notification
includes/class-checkout-fields.php Checkout Builder forms on the FluentCart checkout, renewal link
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
