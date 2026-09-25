# The application on the checkout page (Phase 5, revised)

There is **no separate application form**. The member picks a product on the
Join page and fills everything, including the application, on the FluentCart
checkout page — the same one-page flow as the SureCart build the client has
seen. Fluent Forms is not part of the stack.

```
Join page (buttons)  →  FluentCart checkout  →  pay (card / Pay by Check)  →  order_paid
   one instant-           FluentCart fields:          FluentCart                 My IAPSNJ:
   checkout link          name, email, phone,                                    tags, member_type,
   per product            billing address                                        paid_through,
                          + My IAPSNJ fields:                                    application → CRM,
                          department, rank, …                                    WP login, notification
```

## 1. Join page (built in WordPress, any page builder)

One button or card per membership product. Each button's URL is the
instant-checkout link printed in **My IAPSNJ → Membership Products →
Checkout links**:

```
https://SITE/?fluent-cart=instant_checkout&item_id={VARIATION_ID}&quantity=1
```

Regular Membership · Associate Membership · Lifetime Membership · Multi-Year
Membership. Set the page URL in **Sync & Settings → Join page URL**.

Variation ids differ between staging and production — rebuild the buttons
after the product import (`docs/not-in-git.md`).

## 2. Checkout page

**FluentCart's own fields** (FluentCart → Settings → Checkout Fields): first
name, last name (required), email (system), phone (billing, **required**),
billing address (all required except line 2), no shipping (digital). Turn on
*User account creation = automatic*, guest checkout off. Optional: FluentCart's
*Agree to terms* checkbox.

**My IAPSNJ fields**, rendered above the payment methods
(`fluent_cart/before_payment_methods`) and defined in **Sync & Settings →
Application fields**. The screen is a small field builder: order, show,
required, label, type (text, paragraph, dropdown, radio, date, checkbox),
options, help text, and *Stored in FluentCRM as* — an existing custom field,
a contact field (date of birth, prefix), **a new custom field created on
save**, or not stored. Built-in fields can be hidden but not removed; added
fields get a key `app_<label>` and, when "create new" is chosen, a FluentCRM
custom field of the matching type with the same slug.

Built-in fields (4.3.0: the whole ACF-era onboarding form, per Shane
2026-09-24 "take everything that was in the other onboarding form and move it
into the main checkout"). Left out on purpose: what FluentCart collects (name,
email, phone, billing address), what the payment sets (member number, type,
join / expiration dates) and `admin_notes` (FluentCRM Notes).

| Key | Type | Default | CRM target | Notes |
|---|---|---|---|---|
| `department` | dropdown | on, required | custom `department` | Options imported (see below); **no "N/A"**. Associate treatment still to confirm with the client (make it optional, or relabel "Employer / affiliation"). |
| `rank_level` | dropdown | on, required | custom `rank_level` | Options imported; built-in NJ rank list as last resort. |
| `retirement_date` | date | on, optional | custom `retirement_date` | |
| `phone_work` | text | on, optional | custom `phone_work` | |
| `phone2` | text | on, optional | custom `phone2` | alternate phone |
| `union_affiliation` | text | on, optional | custom `union_affiliation` | |
| `union_position` | text | on, optional | custom `union_position` | |
| `date_of_birth` | date | on, optional | contact `date_of_birth` | |
| `marital_status` | dropdown | on, optional | custom `marital_status` | Single / Married / Divorced / Widowed / Separated (ACF choices win if present) |
| `spouse_name` | text | on, optional | custom `spouse_name` | |
| `armed_service` | checkbox | on, optional | custom `armed_service` | stored as `["Yes"]` (FluentCRM checkbox field) |
| `company_name` | text | on, optional | custom `company_name` | Associate members' employer |
| `company_title` | text | on, optional | custom `company_title` | |
| `company_type` | text | on, optional | custom `company_type` | |
| `referred_by` | text | on, optional | custom `referred_by` | |
| `additional_information` | paragraph | on, optional | custom `additional_information` | |
| `elo_title` | dropdown | **off** | custom `elo_title` | meaning unconfirmed with the client; switch on once the options are imported |
| `certify` | checkbox | on, required | — | "I certify that the information I provided is accurate …" |

Input names are prefixed `iapsnj_` (`iapsnj_department`, …). A dropdown or
radio group with no options renders as a text box.

**Dropdown options.** The option lists are not in git. On install / upgrade
(data version 8) and from the *Fill empty dropdown options* button the plugin
fills every empty dropdown / radio list, per field, from the first source that
has anything: the choices of the ACF user field with the same name (the old
onboarding form, when ACF is still active), then the distinct values already
stored in the CRM custom field (most frequent first, `N/A`-style entries
dropped, capped at 300), then the distinct values in the ACF-era user meta of
the same name, then the built-in list (rank, marital status). Lists
that already have options are never touched; edit them freely. Export the
result with the option (`docs/not-in-git.md`) so production gets the same
lists.

**Behaviour**

* Validation is server-side (`fluent_cart/checkout/validate_data`): required,
  valid date, value in the option list. FluentCart shows the errors inline.
* When FluentCart creates the order (`fluent_cart/checkout/prepare_other_data`,
  before payment) the values are stored on the order
  (`_my_iapsnj_application`), the application row is opened/updated and an
  order note "My IAPSNJ: application received" lists the answers.
* As soon as the email is typed (`fluent_cart/checkout/form_data_changed`)
  the CRM contact is created (name + email), tagged `Checkout-Abandoned`, and
  an application row is opened with `kind` = **join** (no Paid-YYYY history,
  not comped) or **renewal**. The row is keyed by FluentCart's cart hash.
* On `order_placed_offline` (check) and `order_paid` the plugin copies the
  values onto the CRM contact (`My_IAPSNJ_Checkout_Fields::apply_to_contact`).
  Blank answers never erase existing CRM data; it runs once per order so a
  manual CRM edit between "check placed" and "check deposited" survives.
  `member_type`, `paid_through` and the tags are set by the payment only.
* **Prefill from the CRM.** The contact behind the checkout is resolved as
  the logged-in user's contact (by user id or email), the FluentCRM
  secure-link cookie (a member arriving from a CRM email), or the email
  already typed into the cart. From it the plugin fills FluentCart's own
  name, email, phone and billing address fields
  (`fluent_cart/checkout_page_name_fields_schema`,
  `fluent_cart/checkout_renderer/billing_fields`; country and state are
  matched against FluentCart's option lists by code or name) and the
  application fields. Only empty fields are filled; what the member typed in
  this session wins. Typed values survive FluentCart's client-side re-renders
  (sessionStorage, cleared on the receipt page).
* The answers are stored on the order even when none of its items is mapped
  in Membership Products; the order then gets a warning note "product not
  mapped" and, on payment, "membership not applied". The Dashboard flags
  mapped variation ids that no longer exist in FluentCart (products recreated).
* Record a Check (admin) never renders, validates or records an application.

## 3. Renewal

With annual products sold as FluentCart subscriptions, most renewals are
automatic: the renewal order fires `fluent_cart/renewal_paid` and the plugin
extends `paid_through` by the term rule (`docs/crm-schema.md`). For a member
without an active subscription (lapsed, check payer, migrated from PMPro) a
logged-in click on **Renew** — the `[iapsnj_renew_link]` shortcode (member
area, dues emails) — lands on the checkout of the renewal product configured
for their type (**Sync & Settings → Renewal product**, Regular → Regular
Membership, Associate → Associate Membership). Name, email, address
come from FluentCart's customer record; the application fields come prefilled
from the CRM. Lifetime / Honorary members get no link; visitors get the Join
page.

The application row is recorded as `renewal`, so the new-member notification
does not fire and the Welcome automation is skipped (Paid history exists).

## 4. Handoff integrity checklist (staging)

- [ ] Join page button → checkout with the right product preselected
- [ ] Application section visible above the payment methods; required marks
- [ ] Submit with Department empty → inline error, order not created
- [ ] Pay → order note "application received"; contact has `department`,
      `rank_level` …; `Paid-YYYY`, `member_type`, `paid_through`; Reports →
      *Applications awaiting payment* no longer lists it
- [ ] Type email, close the tab → contact has `Checkout-Abandoned`; entry
      listed as *pending* with kind *join*
- [ ] Pay by Check → `Payment-Pending-Check`; entry *awaiting check*; Pending
      Checks shows the answers under the item
- [ ] Log in as a paid member → fields prefilled; `[iapsnj_renew_link]` points
      at the right product; after paying, kind is *renewal*, no new-member email
- [ ] Reload the checkout half-way → typed answers still there
