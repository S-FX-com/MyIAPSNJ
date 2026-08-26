# Fluent Forms build spec (Phase 5)

Two forms, both configured in My IAPSNJ → Sync & Settings → *Fluent Forms*
(join form id, renewal form id, email field name). Field names below are the
Fluent Forms **name attributes**; they must match the FluentCRM feed mapping.

## Common wiring (both forms)

1. **FluentCRM feed** (Integrations → FluentCRM): map email, first/last name,
   phone, address fields and every custom field in `docs/crm-schema.md`;
   *Skip if exists* **off** (update existing); add tag `Checkout-Abandoned`
   (the plugin adds it too — belt and braces); enable *Conditional logic* only
   if needed.
2. **Confirmation** → *Redirect to custom URL* →
   `https://iapsnj.org/?fluent-cart=instant_checkout&item_id={VARIATION_ID}&quantity=1`
   (copy from My IAPSNJ → Membership Products → *Checkout links*). If the
   product choice is on the form, use a dropdown whose **option values are the
   variation ids** and redirect to
   `…instant_checkout&item_id={inputs.membership_product}&quantity=1`.
   The plugin appends `iapsnj_app={token}` automatically (filter
   `fluentform/redirect_url_value`) — do not add it by hand.
3. Field name for the product dropdown: `membership_product` (or set *Product
   field name* in settings).
4. Email field name: `email`.
5. Turn **off** Fluent Forms' own email notifications for these forms, or keep
   an admin-only "application received" if Pat wants it — it must not be the
   certificate trigger.

What happens on submit (code): row in `wp_my_iapsnj_applications`
(status `pending`), CRM contact created/updated minimally, tag
`Checkout-Abandoned`, token appended to the redirect. On the checkout page the
email is prefilled and locked to the application email so the order cannot
orphan. On payment the row becomes `paid` and the tag is removed.

## Join form (multi-step)

Every required field is enforced **at the step level** (Fluent Forms → Form
Step → *validate on next*), so nobody advances past an incomplete section.

**Step 1 — Who you are**
* `names` (first, last) — required
* `email` — required, validate email, unique check *off* (renewals use the
  other form)
* `phone` — required
* `date_of_birth` — optional (date picker)

**Step 2 — Mailing address** (all required except line 2)
* `address_line_1`, `address_line_2`, `city`, `state` (dropdown, NJ default),
  `postal_code`, `country` (hidden, `US`)

**Step 3 — Membership**
* `membership_product` — dropdown, required, **placeholder option is not
  submittable** ("— Select membership —" with empty value). Options = Regular
  Member 2027 / Associate Member 2027 / Lifetime Member / Multi-Year 2027–2031
  / 2026 Catch-Up + 2027 (Oct–Dec only; hide with conditional logic or remove
  after Dec 31). Option values = variation ids.
* `member_type_hint` — hidden or radio "I am a: Regular (active/retired law
  enforcement) / Associate" if the product does not imply it.
* `department` — dropdown, **required when Regular** (conditional logic on the
  product/type choice), placeholder not submittable, **no "N/A" option**.
  Associate treatment: **confirm with client** (proposal: Associate sees a
  free-text "Employer / affiliation" instead).
* `rank_level` — dropdown, required when Regular.
* `retirement_date` — optional, shown when rank indicates retired.
* `union_affiliation`, `union_position` — optional (or retire, see schema).
* `referred_by` — optional.

**Step 4 — Review & pay**
* Summary of entered values (Fluent Forms *Form Step* summary or a rich text
  block with `{inputs.*}`).
* Checkbox: "I certify the information is accurate" — required.
* Submit button label: **Continue to payment**.

Confirmation redirect → FluentCart checkout, product preselected, email
locked. Payment options at checkout: card (Stripe/PayPal) or **Pay by Check**.

## Renewal form (short path, < 2 minutes)

Logged-in members only (Fluent Forms *Form Restrictions → require login*), or
allow guests with email + last name and rely on the FluentCRM feed to match.

**Step 1 — Review**
* All fields **prefilled from the CRM** for the logged-in member. Fluent Forms
  Pro → each field's *Default value* → dynamic FluentCRM smartcodes
  (`{fluentcrm.contact.first_name}`, `{fluentcrm.contact.custom.department}`, …;
  Pro's "Populate from FluentCRM contact" where available). Fields: names,
  email (read-only), phone, address block, department, rank.
* `membership_product` dropdown, required, defaults to the member's type for
  next year (Regular → Regular Member 2027; Associate → Associate Member 2027).
  Multi-Year offered as an option.

**Step 2 — Pay**
* Certification checkbox + **Continue to payment**.

FluentCRM feed: update contact (same mapping as join). Confirmation redirect:
same checkout URL pattern. The plugin records the application as `renewal`, so
the new-member notification does **not** fire and the Welcome automation is
skipped (Paid-* history exists).

## Handoff integrity checklist

- [ ] Token in redirect: submit the form, confirm the browser lands on
      `/checkout/?iapsnj_app=NNN-xxxxxxxx…`
- [ ] Email field prefilled and read-only at checkout
- [ ] Pay → My IAPSNJ → Reports → *Applications awaiting payment* no longer
      lists the entry; FluentCart order note shows "My IAPSNJ: membership
      updated"; CRM contact has `Paid-YYYY`, `member_type`, `paid_through`
- [ ] Abandon → entry stays listed; contact has `Checkout-Abandoned`
- [ ] Choose "Pay by Check" → contact has `Payment-Pending-Check`; entry status
      *awaiting check*; order appears in Pending Checks
