# FluentCRM schema (Phase 2)

FluentCRM is the single source of truth for member records. WordPress users
hold credentials and a role only: nothing about a member is copied onto the
user (the Profile Mirror / Profile Sync were retired in 4.35). ACF user fields
are **retired**, not synced.

`wp iapsnj crm-schema` (or My IAPSNJ → Settings → Configurations → *Create missing tags &
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
| `Member-Active` | `member-active` | `order_paid` / `renewal_paid`, daily expiry job (`wp iapsnj expire`), Members → Lapsed Members → *Apply now* | daily expiry job when `paid_through` (+ grace days) is past; full refund that leaves the contact lapsed | **Status**, not history: present while in good standing (or comped). Drives the WordPress role (Settings → Configurations → WordPress roles) and is the tag to key "membership expired" automations on (Tag Removed). |

Honorary is **never a product**: it is admin-assigned (tag + `member_type`).

## Custom fields

| Slug | Type | Values | Notes |
|---|---|---|---|
| `member_type` | select-one | `Regular` / `Associate` / `Lifetime` / `Honorary` | **Mandatory.** The exclusion condition for dues campaigns is `member_type` is none of `Lifetime`, `Honorary`. A purchase never lowers the type. |
| `paid_through` | date (Y-m-d) | e.g. `2027-12-31` | Set from the product (fixed calendar-year date), only ever extended (`max`). **Null for Lifetime and Honorary** — the plugin deletes the value; never a far-future date. |
| `member_number` | number | | Migrated from ACF `MemberNum` (already synced pre-4.0). Printed on the memo line of checks; shown in Pending Checks. |
| `department` | text (or select-one, see below) | | Required on the checkout application (dropdown options set in My IAPSNJ → Dues → Checkout Builder). |
| `iapsnj_rank` | select-one | | The CRM's own Rank dropdown; its option list is what the checkout and profile offer (4.29). Earlier versions wrote a duplicate text field `rank_level`: the 4.29 upgrade copies its values here (see *Slugs the plugin once wrote*). |
| `join_date` | date | `Y-m-d` | Migrated from ACF `join_date`; for new members set to the payment date of their first paid order (4.4.0), never overwritten. |
| `legacy_pmpro_level` | text | e.g. `3` or `3, 5` | Written by migration for members/orders on deleted PMPro levels (IDs 3, 5). Diagnostic only. |
| `retirement_date` | date | | Created by `crm-schema`; filled from the checkout application. |
| `union_affiliation`, `union_position` | text | | Checkout application (shown by default since 4.3.0). |
| `marital_status` | select-one | Single / Married / Divorced / Widowed / Separated | Checkout application. |
| `spouse` | text | | Checkout application and profile (4.29; was `spouse_name`). |
| `armed_services` | checkbox | Army, Air Force, Coast Guard, Marines, Navy, n/a | "Veteran Status": a dropdown on the checkout application and profile (4.29; was `armed_service`; a dropdown of branches since 4.33, before that a "Yes" tick box); stored as an array (`["Army"]`) like every FluentCRM checkbox field. An existing CRM field keeps its own options; a field created with only `Yes` should be given the branches in FluentCRM → Custom Fields. |
| `explain_your_ancestry` | textarea | | Checkout application and profile (4.29). |
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
checkout dropdown (My IAPSNJ → Checkout Builder) is the
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
| alternate_phone | ✔ | pmpro_b* (7 keys) | ✔ merged then ✖ | address fields | consolidated by migration; PMPro meta is transaction history, never written |
| address / address2 / city / state / zip_code | ✔ | address fields | |
| department | ✔ | `department` | required on join form |
| rank_level | ✔ | `iapsnj_rank` | |
| work_phone | ✔ | retirement_date | ✔ | `retirement_date` | |
| union_affiliation / union_position | ✔ | same slugs | on the checkout (client: "all of them", 2026-09-24) |
| date_of_birth | ✔ | `date_of_birth` | |
| marital_status / spouse_name | ✔ | `marital_status` / `spouse` | on the checkout |
| armed_service | ✔ | `armed_services` | on the checkout |
| additional_information | ✔ | `additional_information` | on the checkout |
| company_name / company_title / company_type | ✔ | same slugs | Associate-member employer data; on the checkout |
| admin_notes | ✖ | FluentCRM Notes | notes are first-class in the CRM |
| referred_by | ✔ | `referred_by` | on the checkout |
| elo_title | ✔ (hidden) | `elo_title` | checkout field exists, off by default — **confirm** meaning with the client |

Retire decision (2026-09-24): the client wants every field of the old
onboarding form on the checkout, so only `admin_notes` is retired. `elo_title`
is kept but hidden until its meaning is confirmed. Any field can still be
hidden from My IAPSNJ → Checkout Builder.

## WordPress user: link and role (4.35)

Until 4.34 a *Profile Mirror* copied a configurable list of CRM fields (name,
email, member number / type / expiry, phone, address, department, rank) into
the user's meta in the ACF-era keys, run from *Profile Sync*. Members now edit
their contact directly ([iapsnj_edit_profile]) and nothing reads those copies,
so both screens, the REST routes (`/fields`, `/mappings`, `/bulk-sync`) and the
saved map were removed; data version 18 deletes the option
`my_iapsnj_field_mappings`, `my_iapsnj_last_bulk_sync`, the field-list caches
and the three mirror switches. The copies already in user meta stay where they
are (they can be deleted with the ACF user fields). A WordPress user's name and
email are therefore no longer updated from the CRM: change them in both places.

What remains of `My_IAPSNJ_Engine`:

* the **link** between a user and its contact: `subscriber.user_id`, cached in
  user meta `_my_iapsnj_subscriber_id`; a new user is linked to the contact with
  the same email, and a deleted user unlinks its contact (never deletes it);
* the **WordPress role** (Settings → Configurations → *WordPress roles*):
  `member_type` → role slug, and a "when expired" role. It is applied on every
  CRM contact save, at the end of each paid / check-placed order and refund,
  by the daily expiry job (Members → Lapsed Members → *Apply now* brings every
  account up to date) and when a new user is linked. New users are created as
  Subscriber and get the mapped role in the same request. Guard rails: the
  administrator role is never assignable, and a user whose current roles
  include anything other than a mapped role or Subscriber (editors, staff) is
  never changed. Unmapped types are skipped.

**Expiration** (4.6.0): a membership is *active* while `paid_through` plus
the grace period (Members → Lapsed Members, default 0 days) is today or later, or the
type is Lifetime / Honorary. WP-Cron runs `My_IAPSNJ_Membership::run_expirations()`
daily at 00:30 site time (`my_iapsnj_daily`; also `wp iapsnj expire` and
Members → Lapsed Members → Expirations → Preview / Apply now): lapsed contacts lose
`Member-Active` and drop to the *when expired* role (default Subscriber);
contacts in good standing that lack the tag (migrated members, manual CRM
edits) get it and their mapped role. Staff roles are never touched. A
payment reactivates immediately (`reconcile_contact()` runs inside
`order_paid`, and after a full refund; a refund that leaves the contact with
no member type removes `Member-Active` and moves a managed member role to the
*when expired* role). `Paid-YYYY` tags are never removed.
Action `my_iapsnj/membership_expired` fires per lapsed contact.

Expiration dates: every payment covers one year. Paid before the cutover
(default Oct 1) it is paid through **Dec 31 of the payment year**; on/after
it, Dec 31 of the next year. So every membership lapses on
Jan 1 (the daily run of Jan 1 removes the tag / role for anyone without
`Paid-{new year}`), which is exactly the January renewal cycle. See the known
gap with subscription anniversary billing below.

## Products → membership state (FluentCart)

Configured in My IAPSNJ → Membership Products (option `my_iapsnj_products`).
Products carry **no year**: a product only says which member type it grants,
and every payment covers one year. Annual products are FluentCart yearly
subscriptions (auto-renew); Lifetime is one-time. There is no Multi-Year
product (removed in 4.15).

| Product | Price | member_type | Billing |
|---|---|---|---|
| Regular Membership | $30 | Regular | yearly subscription |
| Associate Membership | $50 | Associate | yearly subscription |
| Lifetime Membership | $300 | Lifetime | one-time |

**Term rule** (`My_IAPSNJ_Dates::membership_term`, cutover in Dues → Membership Products,
default `10-01`): the payment date decides the term, not the product.

| Paid on | Term |
|---|---|
| 2026-09-24 | through 2026-12-31, `Paid-2026` |
| 2026-10-01 or later | through 2027-12-31, `Paid-2027` |

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

## Department, rank, state, and slugs the plugin once wrote (4.29)

* **Department is FluentCRM's Companies module** (the CRM labels it
  "Departments"): a contact's *primary company* is their department. The
  checkout and the profile list the companies as the Department dropdown (the
  `department` custom field's own options when the module is off), prefill from
  the primary company, and on save make the chosen company the primary one
  (replacing the previous) and keep the `department` custom field in step.
  A department typed under "Other (not listed)" **creates the company** (the
  member is expected to look the list up first), unless a company with that
  name exists, in which case it is reused whatever the capitals ("newark pd" is
  "Newark PD"): the typed name is trimmed and its spaces collapsed, nothing else
  is changed.
  **Close matches are proposed first (4.30).** A new department (or rank) that is
  the same as a listed one apart from capitals and punctuation ("newark p.d.") is
  simply that option. One that closely matches a listed one (same apart from
  filler words police / department / dept / pd / the / of / and, or contained in
  it, or a letter or two off, a swap of neighbours counting as one) gets a
  "Did you mean …?" box under the text as it is typed, with a button per listed
  match and "No, mine is different". The server checks again on submit and
  refuses an unconfirmed close match, naming the listed one, so a form sent
  without the script cannot slip a near-duplicate in (it can only choose the
  listed one). Rules: `My_IAPSNJ_Similar` (PHP) and `public/js/similar-options.js`
  (the same rules in the browser; lengths and edits are counted in characters on both sides, so accented names such as "San Germán" behave the same in each).
* **Dropdown lists come from the CRM**: a row writing to a select-one custom
  field (Rank, Marital status …) offers that field's own option list; a field
  with no list keeps the list set in the Checkout Builder.
* **State is the full name** ("New Jersey"): a dropdown on the profile (59 US
  states, DC, territories and APO / FPO / DPO, with "State not listed? Add it
  here" for another country's region). Every plugin write to a contact turns a
  US code into the name (`My_IAPSNJ_Capitalization::state()`), and the upgrade
  converted the codes already in the CRM (US or blank country only). FluentCart
  keeps its own codes.
* **Slugs the plugin once wrote** (`rank_level`, `spouse_name`,
  `armed_service`) duplicated fields the CRM already had. The 4.29 upgrade
  points the Checkout Builder rows at `iapsnj_rank`,
  `spouse`, `armed_services`, and copies the values collected so far into them
  where those are empty. The old fields are left in place: delete them in
  FluentCRM once you have looked.
