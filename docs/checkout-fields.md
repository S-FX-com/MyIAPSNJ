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

Regular Member 2027 · Associate Member 2027 · Lifetime Member · Multi-Year
2027–2031 · 2026 Catch-Up + 2027 (Oct–Dec only; remove the button after
Dec 31). Set the page URL in **Sync & Settings → Join page URL**.

Variation ids differ between staging and production — rebuild the buttons
after the product import (`docs/not-in-git.md`).

## 2. Checkout page

**FluentCart's own fields** (FluentCart → Settings → Checkout Fields): first
name, last name (required), email (system), phone (billing, **required**),
billing address (all required except line 2), no shipping (digital). Turn on
*User account creation = automatic*, guest checkout off. Optional: FluentCart's
*Agree to terms* checkbox.

**My IAPSNJ fields**, rendered above the payment methods
(`fluent_cart/before_payment_methods`) and configured in **Sync & Settings →
Application fields** (show / required / label / help / dropdown options):

| Key | Type | Default | CRM target | Notes |
|---|---|---|---|---|
| `department` | dropdown | on, required | custom `department` | Options one per line; **no "N/A"**. Associate treatment still to confirm with the client (make it optional, or relabel "Employer / affiliation"). |
| `rank_level` | dropdown | on, required | custom `rank_level` | |
| `retirement_date` | date | on, optional | custom `retirement_date` | |
| `date_of_birth` | date | on, optional | contact `date_of_birth` | |
| `referred_by` | text | on, optional | custom `referred_by` | |
| `union_affiliation` | text | off | custom `union_affiliation` | candidate to retire |
| `union_position` | text | off | custom `union_position` | candidate to retire |
| `certify` | checkbox | on, required | — | "I certify that the information I provided is accurate …" |

Input names are prefixed `iapsnj_` (`iapsnj_department`, …). A dropdown with
no options renders as a text box.

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
* Logged-in members see the fields prefilled from their CRM profile; typed
  values also survive FluentCart's client-side re-renders (sessionStorage,
  cleared on the receipt page).
* Record a Check (admin) never renders, validates or records an application.

## 3. Renewal

A logged-in member clicks **Renew** — the `[iapsnj_renew_link]` shortcode
(member area, dues emails) — and lands on the checkout of the renewal product
configured for their type (**Sync & Settings → Renewal product**, Regular →
Regular Member 2027, Associate → Associate Member 2027). Name, email, address
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
