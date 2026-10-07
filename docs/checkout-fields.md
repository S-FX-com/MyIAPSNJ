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

Regular Membership · Associate Membership · Lifetime Membership. Set the page
URL in **Dues → Membership Products → Join page URL**.

Variation ids differ between staging and production — rebuild the buttons
after the product import (`docs/not-in-git.md`).

## 2. Checkout page

**FluentCart's own fields** (FluentCart → Settings → Checkout Fields): first
name, last name (required), email (system), phone (billing, **required**),
billing address (all required except line 2), no shipping (digital). Turn on
*User account creation = automatic*, guest checkout off. Optional: FluentCart's
*Agree to terms* checkbox.

Keep FluentCart's own fields light enough for a plain store checkout: they
are store-wide (FluentCart has no per-product checkout fields), so an event
registration or a T-shirt asks for exactly the same FluentCart fields as a
membership. Shipping address and methods appear only when the cart holds a
*physical* product.

**My IAPSNJ fields**, rendered above the payment methods
(`fluent_cart/before_payment_methods`) and defined in **My IAPSNJ → Checkout
Builder**.

### Checkout Builder: forms and levels

* The builder holds one or more **checkout forms**. Each form has a name
  (admin only), the section heading and intro shown above its fields, and
  its own field list. Forms can be created (with the built-in fields),
  edited, **duplicated** (fields, heading, intro) and deleted (not while a
  level uses it, and never the last one).
* **Form per level.** IAPSNJ has two levels, each assigned one form:
  **Regular Membership (Police Officer)** and **Associate Membership
  (Business Owner / Friend)**. Lifetime is a Regular membership without an
  expiry, so a Lifetime product uses the Regular form; Honorary is never
  sold. Renewals use the same form as joining (prefilled from the CRM).
* **Which form a checkout shows** comes from the cart: the highest member
  type among the cart's products configured in **Membership Products**
  (same ranking as the payment: Regular < Associate < Lifetime). A cart with
  no membership product — event registration, merchandise, any product not
  mapped there — shows **no application fields and validates none**, so
  FluentCart works as an ordinary shop for those. Map every membership
  product, or its checkout has no application.
* The form id is stored on the order (`_my_iapsnj_checkout_form`), so the
  answers are labelled and written to the CRM with that form's definitions
  even after the forms change (keys only another form defines are still
  read).
* If the cart changes on the checkout page to one that needs another form
  (an order bump, an item added), the order is refused with one message
  asking the member to reload; the page records the form it printed in a
  hidden `iapsnj__form` input.
* Upgrade (data version 10, 4.7): the single field list and the heading /
  intro settings become the form **Membership application**, used by both
  levels, so nothing changes on the checkout until an admin edits the forms.
  To give Associates their own form: Duplicate → rename → hide
  Department / Rank, require Employer → assign it to Associate.

### Editing a form

* **Active / Inactive.** The editor lists the shown fields first, in
  checkout order, and an **Inactive** section below. Unticking *Show* moves
  a field to Inactive and clears and locks *Required* (a hidden field is
  never required; the server enforces the same rule, also for rows saved
  before 4.8). Ticking *Show* moves it to the end of the active list.
* **Drag and drop (4.9).** Drag a row by its ☰ handle to reorder fields and
  section headings; drop it into Inactive to hide it (Required is cleared),
  or drag a hidden field or an *Other FluentCRM field* up into the active
  list to show it exactly where it is dropped. The page scrolls while
  dragging near the window edge. ▲▼ still move a row one step (keyboard).
  Uses WordPress's bundled `jquery-ui-sortable`; nothing is saved until
  *Save form*.
* **Conditional display (4.10).** Indent a field under the field above it —
  drag it to the right (the drop outline shifts right), or press ▶ — and
  it becomes that field's *child*: the checkout shows it only while the
  parent's answer matches. The rule is picked in the child's row: for a
  dropdown / radio parent, one or more of its options (none selected = any
  answer); for a tick box, "is ticked"; for any other field, "has an
  answer". One level only: a child has no children, a heading ends a
  group, a parent moves (▲▼, drag, Show, Inactive) together with its
  children, and hiding a parent hides its children. ◀ or dragging left
  makes a child an ordinary field again. On the checkout a hidden child is
  disabled (not submitted, not browser-validated) and the server applies
  the same rule (`visible_inputs()`): a hidden child is never required and
  its answer is not stored, even if one was typed before the parent
  changed. Stored per row as `parent` (the parent's key) and `show_when`.
  If the parent's options change so that none of a child's chosen answers
  exists any more, the child stays hidden (never widened to "any answer")
  and the editor refuses to save until new answers are picked. A field
  dropped right above a child joins that group; a top-level field dropped
  into a group lands after it, so no child is ever re-parented silently.
* **Section headings.** *+ Add section heading* (or type *Section heading*)
  adds a heading row. On the checkout the fields after it, up to the next
  heading, are grouped under it (`<h3>` plus the optional help text as a
  line under the heading). A heading with no shown field under it is not
  printed. Headings are never required and store nothing.
* **Every other FluentCRM field.** Inactive also lists, under *Other
  FluentCRM fields*, every CRM contact custom field (and the *Prefix*
  contact field) that no row of this form writes to, with the CRM field's
  label, type and options (select → dropdown, radio → radio, one-option
  checkbox → tick box, several options → dropdown, date / date-time → date,
  anything else → text). Tick *Show* to add one to the checkout; left
  hidden, it is not saved and simply offered again next time, so new CRM
  fields appear automatically. The membership-state fields
  (`member_type`, `paid_through`, `member_number`, `join_date`,
  `legacy_pmpro_level`, `My_IAPSNJ_Schema::system_fields()`) are never
  offered, cannot be picked as a target, and are never written from a
  checkout — only payments set them. FluentCart already collects name,
  email, phone and address, so those contact columns are not offered.
* **One row per CRM field (4.10).** As soon as a row's *Stored in FluentCRM
  as* names a CRM field, that field leaves *Other FluentCRM fields* (and
  comes back when no row writes to it any more), and the other rows'
  pickers cannot choose it. `save_config()` refuses a form in which two
  rows write to the same CRM field (including a "+ Create new" whose slug
  another row already uses), naming them; nothing is saved or created.
* **View checkout (4.10).** Each form on the Checkout Builder list (and the
  form editor, for the saved version) has *View*: it opens the
  real checkout page in a new tab with a mapped membership product of the
  form's level in your cart (`?fluent-cart=instant_checkout&item_id=…`,
  plus `iapsnj_preview=<form>` and a nonce, which FluentCart forwards to
  the checkout page). Administrators then see that form, with a
  "Preview" notice, whatever the cart holds; nothing is charged unless an
  order is placed, and an order whose cart needs another form is refused.
  Disabled until a membership product is mapped.

Each form's field list is a small builder: order, show,
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

Field types: text, paragraph, dropdown, radio, date, checkbox, section heading.

| Key | Type | Default | CRM target | Notes |
|---|---|---|---|---|
| `department` | dropdown | on, required | custom `department` | Options imported (see below); **no "N/A"**. Associate treatment still to confirm with the client (make it optional, or relabel "Employer / affiliation"). |
| `rank_level` | dropdown | on, required | custom `rank_level` | Options imported; built-in NJ rank list as last resort. |
| `retired` | checkbox "I am retired" | on, optional | not stored | 4.17: ticked on the checkout when the contact has a retirement date |
| `retirement_date` | date | on, required when shown | custom `retirement_date` | 4.17: conditional child of `retired` (shown only once the box is ticked); not after today |
| `phone_work` | phone | on, optional | custom `phone_work` | |
| `phone2` | phone | on, optional | custom `phone2` | alternate phone |
| `union_affiliation` | text | on, optional | custom `union_affiliation` | |
| `union_position` | text | on, optional | custom `union_position` | |
| `date_of_birth` | date | on, **required** (4.16) | contact `date_of_birth` | Never in the future. Data version 11 requires it in every saved form (the added row writing `date_of_birth`, if a form has one). |
| `marital_status` | dropdown | on, optional | custom `marital_status` | Single / Married / Divorced / Widowed / Separated (ACF choices win if present) |
| `spouse_name` | text | on, optional | custom `spouse_name` | capitalised like a name (below) |
| `armed_service` | checkbox | on, optional | custom `armed_service` | stored as `["Yes"]` (FluentCRM checkbox field) |
| `company_name` | text | on, optional | custom `company_name` | Associate members' employer |
| `company_title` | text | on, optional | custom `company_title` | |
| `company_type` | text | on, optional | custom `company_type` | |
| `referred_by` | text | on, optional | custom `referred_by` | capitalised like a name (below) |
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
* Only carts with a product mapped in Membership Products get an
  application (see *Checkout Builder* above); a membership product that is
  not mapped checks out like any other product and, on payment, the order
  gets the note "membership not applied". The Dashboard flags mapped
  variation ids that no longer exist in FluentCart (products recreated).
* Record a Check (admin) never renders, validates or records an application.

## 2b. The checkout page around the application (4.16)

Client requests of 2026-09-29, in `My_IAPSNJ_Checkout_Page` (FluentCart
1.6.5). "Membership checkout" means a cart with a product mapped in
Membership Products; event and merchandise checkouts keep FluentCart's page.

| Request | What the plugin does | Admin side |
|---|---|---|
| Title by membership type (4.17; was "Membership Application" in 4.16) | `the_title` / `document_title_parts` on FluentCart's checkout page (store setting `checkout_page_id`) for a membership cart, and the heading above the fields, both read `My_IAPSNJ_Checkout_Fields::application_title()`: the form's *Application heading* when set, else the highest member type in the cart — "Regular Member Application", "Associate Member Application", "Lifetime Member Application". An administrator's *View checkout* uses the previewed form's level. The WordPress page itself stays "Checkout". | Leave *Application heading* empty in each form to get the automatic title; a heading typed there wins for every level using that form (a Lifetime cart on a Regular form headed "Regular Member Application" reads that). |
| Order summary below the application, mobile and desktop | The wrapper gets `my-iapsnj-membership-checkout` (`fluent_cart/checkout_page_css_classes`); the stylesheet makes it one column (FluentCart: 55 / 45 side by side, and under 767px of *checkout* width `column-reverse` puts the summary on top — a container query, so a narrow theme column shows it on top on desktop too). A footer script moves `.fct_checkout_summary` right after the application, before Payment; FluentCart's AJAX refreshes only replace the summary's contents, so it keeps working. Without scripts the summary comes after the form. | — |
| "Coupon" → "Discount Code" everywhere | `gettext_fluent-cart` (also `_with_context`, `ngettext`): every storefront string with "coupon" (toggle "Have a Discount Code?", screen-reader label, AJAX error messages), plus exact wording for "Applied Successfully" → "Discount code applied", "Coupon removed!" → "Discount code removed", placeholder "Apply Here" → "Enter discount code", "No matching coupon found for this code." → "No matching discount code found.". FluentCart's admin screens (wp-admin pages, its REST API, admin-ajax called from wp-admin) keep "Coupon". The button stays "Apply". | Keep the coupon field on (FluentCart → Settings → Store Settings: *hide coupon field* off) and create the family-member codes in FluentCart → Coupons. |
| Summary line "$30/year, billed automatically" | For a line that is a yearly membership subscription with no end date: FluentCart's "%1$s %2$s %3$s" / "per %s" / "until cancel" become "%1$s%2$s, %3$s" / "/%s" / "billed automatically" while that line is printed, and whole-dollar amounts lose ".00" in that line only. A recurring discount keeps FluentCart's strike-through ("~~$50~~ $40/year, billed automatically"). Lifetime (one-time) shows no billing line. Thank-you page and receipts keep FluentCart's text. | — |
| Family-member note | Printed right after the Discount Code field (`fluent_cart/checkout/before_summary_total`) on membership checkouts, while FluentCart shows the field. Default: "If you are a family member of a regular member, contact us for a discount code." | Checkout Builder → Checkout settings → *Note under Discount Code* (links allowed, empty = none). |
| Pay by Check second, with a USPS-delay preface | `fluent_cart/checkout_active_payment_methods` puts `offline_payment` second on membership checkouts (the first method, the card, stays preselected; the filter also runs at order submit with a single gateway, which it leaves alone). The note is printed as a `<template>` and a footer script places it right under the Pay by Check option, again after FluentCart refreshes the payment methods (FluentCart's per-method hook prints inside the label). Default (4.17): "Mail your check to: **The Italian American Police Society of New Jersey, PO Box 352, Lyndhurst, NJ 07071**" then "Please note: mailing a check will considerably delay your application because of USPS mail delays. Pay by card to be approved sooner." The address is the website's *For Dues or Ticket Payments* address (iapsnj.org/address/, `My_IAPSNJ_Checkout_Page::CHECK_ADDRESS`), **not** FluentCRM's business address (P.O. Box 576 Rockaway, correspondence only). Data v12 adds the address line to a saved note and puts it in FluentCart's offline instructions where they still read "P.O. Box ____". | *Note under Pay by Check* in the same place. FluentCart's own order (Settings → Payments, drag and drop, option `fluent_cart_payment_methods_order`) still applies to other checkouts. If the dues address changes, update the constant and the saved note / instructions. |
| Retirement date looked mandatory (4.17) | Built-in checkbox `retired` "I am retired" right above Retirement date, which becomes its conditional child, required when shown (label "Retirement date", no "(if retired)"). Not stored: a renewing member with a retirement date sees the box ticked and the date filled. Data v12 pairs them in every saved form where Retirement date is shown without another condition. | — |
| First Name / Last Name | FluentCart setting, not code: Settings → Store Settings → Checkout Fields → turn on First Name and Last Name (the Checkout Builder warns while the single Name field is on). FluentCart then joins the two and splits them again at the last space on its customer and address records ("Mary" + "Van Dyke" → "Mary Van" / "Dyke"). The plugin keeps what was typed: the order carries `_my_iapsnj_typed_name`, the CRM contact and the application row use it, and the FluentCart customer is corrected in its `saving` event when its full name matches. | Switch the setting on. |
| DOB required | Built-in default required; data version 11 requires it in every saved form; a future date is refused. | An admin can make it optional again in the Checkout Builder. |

### Capitalisation of names and addresses

`My_IAPSNJ_Capitalization` changes the stored value, not only how it looks
(CSS `text-transform` would leave the exports as typed):

* **Where:** as the member leaves a field on the checkout (billing and
  shipping first / last / full name, street lines, city; Spouse's name and
  Referred by), so the member sees it; in FluentCart's request object before
  the place-order handler reads it (FluentCart copies `$_POST` when it
  loads, so `$_POST` is not touched); in the `saving` events of FluentCart's
  Customer, CustomerAddresses and OrderAddress models (saved addresses of a
  logged-in customer replace the posted ones; these also catch admin and
  customer-portal edits); before the CRM contact and the application row are
  written.
* **Rules:** a word typed in lower case, or any word of a value typed all
  in capitals, gets a capital; a word typed in mixed case is kept (McDonald,
  DeLuca, LaSalle); Mc and O' / D' / L' names (McDonald, O'Brien); each part
  of a hyphenated name (Smith-Jones); suffixes II–VIII in capitals after the
  first word (a first name "Vi" stays "Vi"), Jr / Sr; two consonants are
  initials (TJ) except Jr, Sr, St, Ng, Mc, Mr, Ms, Dr; "Mac" is left alone
  (Mack, Macy). Streets: PO Box (from "po box", "P.O. BOX", "pobox"),
  unit letters next to digits (4B, #12A, NJ-35) but ordinals stay 1st / 22nd,
  PO / US / NJ / NY / PA / CR / RR / NE / NW / SE / SW in capitals, "of",
  "the", "and" lower case after the first word. In a value typed in mixed
  case, a street or city word of up to three capitals is taken as an acronym
  and kept (JFK Blvd) unless it is a street word (APT, ST, AVE …); unit
  letters, PO and suffixes do not count as mixed-case typing. Applying the
  rule twice changes nothing (it repeats until stable). State, CRM only: two
  letters → capitals, any other single token kept (NSW); FluentCart's own
  records keep its state codes untouched (shipping zones match on them).
  Invalid UTF-8 is left as it is and a value is never blanked.
* **Known limits:** a mixed-case prefix typed in lower case cannot be
  guessed ("deluca" → "Deluca", "macdonald" → "Macdonald"); an all-caps
  acronym typed in an all-caps address is lowered ("JFK BLVD" → "Jfk Blvd");
  without PHP's mbstring extension, words with accented letters are left as
  typed.
* **Existing contacts:** Settings → Configurations → *Names & addresses* →
  Preview / Apply now, or `wp iapsnj capitalize [--dry-run]`: first name,
  last name, street lines, city and state of every CRM contact. Direct table
  writes: no automations fire and WordPress profiles are not re-mirrored.

## 3. Renewal

With annual products sold as FluentCart subscriptions, most renewals are
automatic: the renewal order fires `fluent_cart/renewal_paid` and the plugin
extends `paid_through` by the term rule (`docs/crm-schema.md`). For a member
without an active subscription (lapsed, check payer, migrated from PMPro) a
logged-in click on **Renew** — the `[iapsnj_renew_link]` shortcode (member
area, dues emails) — lands on the checkout of the renewal product configured
for their type (**Dues → Membership Products → Renewal product**, Regular → Regular
Membership, Associate → Associate Membership). Name, email, address
come from FluentCart's customer record; the application fields come prefilled
from the CRM. Lifetime / Honorary members get no link; visitors get the Join
page.

The application row is recorded as `renewal`, so the new-member notification
does not fire and the Welcome automation is skipped (Paid history exists).

## 4. Handoff integrity checklist (staging)

- [ ] Join page button → checkout with the right product preselected
- [ ] Application section visible above the payment methods; required marks
- [ ] Regular / Lifetime product shows the Regular form, Associate product
      the Associate form (heading, fields, required marks per form)
- [ ] Event or merchandise product alone → no application section; order
      goes through with FluentCart's fields only
- [ ] Duplicate a form → copy opens in the editor; deleting a form in use is
      refused
- [ ] Untick Show on a required field → it moves to Inactive with Required
      cleared; checkout no longer asks for it
- [ ] Drag a field by ☰ to a new position, drag one into Inactive and one
      CRM field up into the list, Save → the checkout shows that order
- [ ] Indent Spouse's name under Marital status, pick "Married", Save →
      checkout: hidden until Married is chosen; required only then; choosing
      Single again hides it and the order goes through without it
- [ ] Pick a CRM field in one row → it disappears from Other FluentCRM
      fields and cannot be picked in another row
- [ ] View checkout on each form → the real checkout opens with that form
      and the Preview notice; a logged-out visitor never sees the notice
- [ ] Add two section headings → checkout groups the fields under them; a
      heading with nothing shown under it is not printed
- [ ] A CRM custom field created in FluentCRM appears under Inactive → Other
      FluentCRM fields; Show + Save → on the checkout, answer lands in the
      contact; Member Type / Paid Through are never listed
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

## Phone numbers (4.13)

Phone inputs are formatted as they are typed, on every FluentCart checkout
(billing / shipping phone) and for the application's **Phone** fields: a US
number becomes `+1 908-415-2478`. A number typed with `+` and another country
code is left as typed. The server applies the same rule (`My_IAPSNJ_Phone`),
so a number that reaches it unformatted is still stored correctly:

| Where | Stored as | Why |
|---|---|---|
| Contact **Phone** (from the billing phone) | `+19084152478` (E.164) | what FluentCRM's own phone input saves; FluentCRM shows it as `+1 908-415-2478` with the flag |
| **Work phone**, **Alternate phone**, any Phone-type field | `+1 908-415-2478` | plain CRM text fields, which FluentCRM does not format |

A Phone field that is filled in must be a 10-digit US number (or 11 digits
starting with 1) or an international number starting with `+`; otherwise the
checkout shows an error. The billing phone is FluentCart's field and is never
blocked, only formatted.

The Checkout Builder offers the **Phone (auto-formatted)** type for new fields.
A FluentCRM text field whose slug or label contains "phone", "mobile" or
"cell" is offered as a Phone field. Work phone and Alternate phone are Phone
fields in every form, including forms saved before 4.13.

Numbers already in the CRM: **Settings → Configurations → Phone numbers →
Preview / Apply now**, or `wp iapsnj phones [--dry-run]`. This reformats the
contact Phone and the Work / Alternate phone fields to the formats above. It
lists numbers it cannot read (too few digits, extensions, letters) and leaves
them unchanged, and no FluentCRM automations fire.
