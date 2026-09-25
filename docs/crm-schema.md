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
| `Checkout-Abandoned` | `checkout-abandoned` | email typed at the FluentCart checkout (`checkout/form_data_changed`) | `order_placed_offline`, `order_paid` | Checkout started, no payment — the follow-up list that did not exist before |
| `Honorary` | `honorary` | migration (`migrate_comped`) / admin by hand | admin | Comped; **excluded from every dues automation** |
| `Lifetime` | `lifetime` | migration (`migrate_comped`), Lifetime product paid | admin | Comped; **excluded from every dues automation** |

Honorary is **never a product**: it is admin-assigned (tag + `member_type`).

## Custom fields

| Slug | Type | Values | Notes |
|---|---|---|---|
| `member_type` | select-one | `Regular` / `Associate` / `Lifetime` / `Honorary` | **Mandatory.** The exclusion condition for dues campaigns is `member_type` is none of `Lifetime`, `Honorary`. A purchase never lowers the type. |
| `paid_through` | date (Y-m-d) | e.g. `2027-12-31` | Set from the product (fixed calendar-year date), only ever extended (`max`). **Null for Lifetime and Honorary** — the plugin deletes the value; never a far-future date. |
| `member_number` | number | | Migrated from ACF `MemberNum` (already synced pre-4.0). Printed on the memo line of checks; shown in Pending Checks. |
| `department` | text (or select-one, see below) | | Required on the checkout application (dropdown options set in My IAPSNJ → Sync & Settings). |
| `rank_level` | text/select-one | | Same. |
| `join_date` | date | `Y-m-d` | Migrated from ACF `join_date`; for new members set to the payment date of their first paid order (4.4.0), never overwritten. |
| `legacy_pmpro_level` | text | e.g. `3` or `3, 5` | Written by migration for members/orders on deleted PMPro levels (IDs 3, 5). Diagnostic only. |
| `phone2` | text | | Alternate phone; checkout application (4.3.0). Existed pre-4.0; `crm-schema` creates it if missing. |
| `phone_work` | text | | Work phone; checkout application. |
| `retirement_date` | date | | Created by `crm-schema`; filled from the checkout application. |
| `union_affiliation`, `union_position` | text | | Checkout application (shown by default since 4.3.0). |
| `marital_status` | select-one | Single / Married / Divorced / Widowed / Separated | Checkout application. |
| `spouse_name` | text | | Checkout application. |
| `armed_service` | checkbox | `Yes` | Checkout application; stored as an array (`["Yes"]`) like every FluentCRM checkbox field. |
| `additional_information` | textarea | | Checkout application. |
| `company_name`, `company_title`, `company_type` | text | | Associate members' employer; checkout application. Created by `crm-schema` if missing. |
| `referred_by` | text | | Created by `crm-schema`; filled from the checkout application. |
| `elo_title` | text (select in ACF) | | Confirm with client whether still used; the checkout field exists but is hidden by default. |
| `admin_notes` | textarea | **retire** → use FluentCRM Notes | Already searchable via Notes Search |

Default FluentCRM fields used: `first_name`, `last_name`, `email`, `phone`,
`address_line_1`, `address_line_2`, `city`, `state`, `postal_code`, `country`,
`date_of_birth`, `prefix`.

`department` and `rank_level` were mapped as *select* in the ACF era. Keep them
`select-one` in FluentCRM only if the option list is maintained there; the
checkout dropdown (My IAPSNJ → Sync & Settings → Application fields) is the
real constraint (non-submittable placeholder, no "N/A"). Its option lists are
imported from the ACF choices / existing CRM values (`docs/checkout-fields.md`).

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
| union_affiliation / union_position | ✔ | same slugs | on the checkout (client: "all of them", 2026-09-24) |
| date_of_birth | ✔ | `date_of_birth` | |
| marital_status / spouse_name | ✔ | same slugs | on the checkout |
| armed_service | ✔ | `armed_service` | on the checkout |
| additional_information | ✔ | `additional_information` | on the checkout |
| company_name / company_title / company_type | ✔ | same slugs | Associate-member employer data; on the checkout |
| admin_notes | ✖ | FluentCRM Notes | notes are first-class in the CRM |
| referred_by | ✔ | `referred_by` | on the checkout |
| elo_title | ✔ (hidden) | `elo_title` | checkout field exists, off by default — **confirm** meaning with the client |

Retire decision (2026-09-24): the client wants every field of the old
onboarding form on the checkout, so only `admin_notes` is retired. `elo_title`
is kept but hidden until its meaning is confirmed. Any field can still be
hidden from My IAPSNJ → Sync & Settings → Application fields.

## Profile mirror (CRM → WordPress user meta)

Seeded by `seed_default_mappings()` in 4.0 and editable in My IAPSNJ → Profile
Mirror. Direction is always CRM → WP. Targets keep the ACF-era meta keys so
the existing member-area profile screen keeps rendering:

`first_name`, `last_name`, `user_email`, `MemberNum ← member_number`,
`member_status ← member_type`, `expiration_date ← paid_through`, `join_date`,
`primary_phone ← phone`, `address`, `address2`, `city`, `state`,
`zip_code ← postal_code`, `department`, `rank_level`.

Installs upgraded from 3.x carried mappings to the retired CRM slugs
`member_status` / `expiration_date`; data version 9 (4.4.0) points them at
`member_type` / `paid_through` and adds the membership rows if missing. The
mirror runs on every CRM contact save **and** at the end of each paid /
check-placed order, so the WordPress profile (ACF meta) reflects the payment
immediately. Extra ACF fields (e.g. a separate `member_type`) need no code:
create the ACF field and add the row in Profile Mirror.

## Products → membership state (FluentCart)

Configured in My IAPSNJ → Membership Products (option `my_iapsnj_products`).
Products carry **no year**: a product says which member type it grants and
how many years one payment covers. Annual products are FluentCart yearly
subscriptions (auto-renew); Lifetime and Multi-Year are one-time.

| Product | Price | member_type | Years covered | Billing |
|---|---|---|---|---|
| Regular Membership | $30 | Regular | 1 | yearly subscription |
| Associate Membership | $50 | Associate | 1 | yearly subscription |
| Lifetime Membership | $300 | Lifetime | — | one-time |
| Multi-Year Membership | $120 | Regular | 5 | one-time |

**Term rule** (`My_IAPSNJ_Dates::membership_term`, cutover in Sync & Settings,
default `10-01`): the payment date decides the term, not the product.

| Paid on | 1-year product | 5-year product |
|---|---|---|
| 2026-09-24 | through 2026-12-31, `Paid-2026` | through 2030-12-31, `Paid-2026…2030` |
| 2026-10-01 or later | through 2027-12-31, `Paid-2027` | through 2031-12-31, `Paid-2027…2031` |

For checks the deposit date is the payment date. Subscription renewal orders
(type `renewal`, hook `fluent_cart/renewal_paid`) go through the same rule on
their own payment date. The former "Catch-Up" product is unnecessary: a
payment before the cutover simply buys the current year.

Rules applied on `order_paid` / `renewal_paid`:

* `paid_through` = max(existing, computed) — never shortened.
* `member_type` = the higher of existing and product (Honorary/Lifetime stay).
* Lifetime → `paid_through` deleted, `Lifetime` tag added.
* `Payment-Pending-Check` and `Checkout-Abandoned` removed.
* Full refund reverses only what that order added (snapshot on the order).

**Known gap with subscriptions:** FluentCart charges the renewal on the
purchase anniversary. A member who joins on March 1 is paid through Dec 31 and
charged again on March 1 of the next year, so on paper `paid_through` is past
from Jan 1 to Mar 1 while the subscription is active. Only Oct–Dec joiners line
up with Dec 31. Decision pending with the client: treat an active subscription
as in good standing, move the next billing date to Jan 1 in FluentCart, or use
one-time products with the January renewal campaign.
