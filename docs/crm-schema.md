# FluentCRM schema (Phase 2)

FluentCRM is the single source of truth for member records. WordPress users
hold credentials only, plus a one-way mirror of a few profile fields
(My IAPSNJ → Profile Mirror). ACF user fields are **retired**, not synced.

`wp iapsnj crm-schema` (or My IAPSNJ → Sync & Settings → *Create missing tags &
fields*) creates every tag and custom field below idempotently. Constants live
in `includes/class-schema.php`.

## Tags

| Tag (title) | slug | Set by | Removed by | Purpose |
|---|---|---|---|---|
| `Paid-2024` … `Paid-2031` (one per year, ongoing) | `paid-YYYY` | migration (`backfill_year_tags`), FluentCart `order_paid` | full refund of the order that added it | Year-by-year payment history, queryable |
| `Payment-Pending-Check` | `payment-pending-check` | `order_placed_offline` (check chosen at checkout) | `order_paid` | Treasurer follow-up; Pending Checks screen |
| `Checkout-Abandoned` | `checkout-abandoned` | Fluent Forms submission (join/renewal) | `order_placed_offline`, `order_paid` | Complete application, no payment — the follow-up list that did not exist before |
| `Honorary` | `honorary` | migration (`migrate_comped`) / admin by hand | admin | Comped; **excluded from every dues automation** |
| `Lifetime` | `lifetime` | migration (`migrate_comped`), Lifetime product paid | admin | Comped; **excluded from every dues automation** |

Honorary is **never a product**: it is admin-assigned (tag + `member_type`).

## Custom fields

| Slug | Type | Values | Notes |
|---|---|---|---|
| `member_type` | select-one | `Regular` / `Associate` / `Lifetime` / `Honorary` | **Mandatory.** The exclusion condition for dues campaigns is `member_type` is none of `Lifetime`, `Honorary`. A purchase never lowers the type. |
| `paid_through` | date (Y-m-d) | e.g. `2027-12-31` | Set from the product (fixed calendar-year date), only ever extended (`max`). **Null for Lifetime and Honorary** — the plugin deletes the value; never a far-future date. |
| `member_number` | number | | Migrated from ACF `MemberNum` (already synced pre-4.0). Printed on the memo line of checks; shown in Pending Checks. |
| `department` | text (or select-one, see below) | | Required for Regular on the join form. |
| `rank_level` | text/select-one | | |
| `join_date` | date | | Migrated from ACF `join_date`. |
| `legacy_pmpro_level` | text | e.g. `3` or `3, 5` | Written by migration for members/orders on deleted PMPro levels (IDs 3, 5). Diagnostic only. |
| `phone2` | text | | Alternate phone (keep — existed pre-4.0) |
| `phone_work` | text | | Work phone |
| `retirement_date` | date | | |
| `union_affiliation`, `union_position` | text | | |
| `marital_status` | select-one | | |
| `spouse_name` | text | | |
| `armed_service` | checkbox | | |
| `additional_information` | textarea | | |
| `referred_by` | text | | |
| `elo_title` | select-one | | Confirm with client whether still used |
| `admin_notes` | textarea | **retire** → use FluentCRM Notes | Already searchable via Notes Search |

Default FluentCRM fields used: `first_name`, `last_name`, `email`, `phone`,
`address_line_1`, `address_line_2`, `city`, `state`, `postal_code`, `country`,
`date_of_birth`, `prefix`.

`department` and `rank_level` were mapped as *select* in the ACF era. Keep them
`select-one` in FluentCRM only if the option list is maintained there; the
join form's own dropdown is the real constraint (non-submittable placeholder,
no "N/A").

## The 32 ACF-era fields — decision per field

Source: `class-field-mapper.php::seed_default_mappings()` in 3.0.0 (29 ACF
fields + first/last/email). ✔ = migrate to CRM field, 🏷 = becomes a tag,
✖ = retire. "Already in CRM" means the pre-4.0 sync created the custom field
and populated it — the data is there; only the ACF side is dropped.

| ACF field | Decision | CRM target | Reason |
|---|---|---|---|
| first_name / last_name / user_email | ✔ | default fields | core identity |
| MemberNum | ✔ | `member_number` | already in CRM; used on checks |
| member_status | ✔ → replaced | `member_type` | the old free-form status becomes the controlled type |
| join_date | ✔ | `join_date` | already in CRM |
| expiration_date | ✔ → replaced | `paid_through` | derived from PMPro end date / orders, then owned by FluentCart |
| last_payment_date | 🏷 | `Paid-YYYY` | year granularity is what the client actually asks for |
| primary_phone | ✔ | `phone` | |
| alternate_phone | ✔ | `phone2` | |
| pmpro_b* (7 keys) | ✔ merged then ✖ | address fields | consolidated by migration; PMPro meta is transaction history, never written |
| address / address2 / city / state / zip_code | ✔ | address fields | |
| department | ✔ | `department` | required on join form |
| rank_level | ✔ | `rank_level` | |
| work_phone | ✔ | `phone_work` | |
| retirement_date | ✔ | `retirement_date` | |
| union_affiliation / union_position | ✔ | same slugs | **confirm with client** — candidates to retire |
| date_of_birth | ✔ | `date_of_birth` | |
| marital_status / spouse_name | ✔ | same slugs | **confirm with client** — candidates to retire |
| armed_service | ✔ | `armed_service` | |
| additional_information | ✔ | `additional_information` | |
| company_name / company_title / company_type | ✖ | — | Associate-member employer data; **retire unless the client uses it** (probably unused) |
| admin_notes | ✖ | FluentCRM Notes | notes are first-class in the CRM |
| referred_by | ✔ | `referred_by` | cheap to keep; useful for the join form |
| elo_title | ✖ | — | **confirm** — almost certainly unused |

Aggressive-retire list to put in front of the client (default: retire):
`company_name`, `company_title`, `company_type`, `admin_notes`, `elo_title`,
`union_affiliation`, `union_position`, `marital_status`, `spouse_name`.

## Profile mirror (CRM → WordPress user meta)

Seeded by `seed_default_mappings()` in 4.0 and editable in My IAPSNJ → Profile
Mirror. Direction is always CRM → WP. Targets keep the ACF-era meta keys so
the existing member-area profile screen keeps rendering:

`first_name`, `last_name`, `user_email`, `MemberNum ← member_number`,
`member_status ← member_type`, `expiration_date ← paid_through`, `join_date`,
`primary_phone ← phone`, `address`, `address2`, `city`, `state`,
`zip_code ← postal_code`, `department`, `rank_level`.

## Products → membership state (FluentCart)

Configured in My IAPSNJ → Membership Products (option `my_iapsnj_products`).
All one-time purchases; no subscriptions.

| Product | Price | member_type | Sets paid_through | Paid-YYYY tags | Availability |
|---|---|---|---|---|---|
| Regular Member 2027 | $30 | Regular | 2027-12-31 | 2027 | ongoing |
| Associate Member 2027 | $50 | Associate | 2027-12-31 | 2027 | ongoing |
| Lifetime Member | $300 | Lifetime | null | — (tag `Lifetime`) | ongoing |
| Multi-Year 2027–2031 | $120 | Regular | 2031-12-31 | 2027, 2028, 2029, 2030, 2031 | ongoing |
| 2026 Catch-Up + 2027 | $45 | Regular | 2027-12-31 | 2026, 2027 | Oct–Dec 2026 only (FluentCart product visibility / schedule) |

Rules applied on `order_paid`:

* `paid_through` = max(existing, product) — never shortened.
* `member_type` = the higher of existing and product (Honorary/Lifetime stay).
* Lifetime → `paid_through` deleted, `Lifetime` tag added.
* `Payment-Pending-Check` and `Checkout-Abandoned` removed.
* Full refund reverses only what that order added (snapshot on the order).
