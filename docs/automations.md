# Automations and notifications (Phase 6)

What is **in code** (this plugin) versus what must be **built in the FluentCRM
UI**. Everything dues-related must exclude Lifetime and Honorary; the reusable
condition is defined once below and repeated in every automation.

## The reusable exclusion condition

In every FluentCRM automation that sends dues / renewal / catch-up content,
add a **Conditional** block (or trigger condition) immediately after the
trigger:

```
Contact custom field  member_type  is not  Lifetime
AND
Contact custom field  member_type  is not  Honorary
AND
Contact tag  does not include  Honorary, Lifetime
```

Both the field and the tags are checked because either can be set by hand.
Verify on **each** automation individually (open it, confirm the block is the
first step after the trigger, test with a Lifetime contact).

## In code — fires on `fluent_cart/order_paid` (card or check, identical path)

`includes/class-membership.php::apply_paid_order()`:

1. Apply `Paid-YYYY` tag(s) from the product configuration.
2. Set `member_type` (never lowered) and `paid_through` (never shortened; null
   for Lifetime/Honorary).
3. Remove `Payment-Pending-Check` and `Checkout-Abandoned`.
4. Fill empty CRM address fields from the checkout billing address.
5. Create the WordPress user if none exists (password-reset email, not a
   password), link contact ↔ user ↔ FluentCart customer.
6. Resolve and close the Fluent Forms application.
7. **New member admin notification** (Sebbie's request, Billy's certificate
   trigger): plain-text email to *Sync & Settings → Recipients* containing
   name, member type, paid-through, product, payment method + amount, order
   number, **email, phone, full mailing address, department**, rank, member
   number, and links to the CRM contact and the FluentCart order. Sent only
   when the payment is confirmed and the contact is a new member (join form,
   or first payment on record). Never on application submitted.
8. Actions for extensions: `my_iapsnj/membership_paid`, `my_iapsnj/new_member`,
   `my_iapsnj/membership_refunded`; filter `my_iapsnj/new_member_notification`.

Timezone rule (audit P1-4): `paid_through` is a calendar date string and is
rendered with `My_IAPSNJ_Dates::ymd_display()` (UTC round-trip) so 12/31 is
12/31 everywhere; order timestamps are rendered with `wp_date()`.

`fluent_cart/order_placed_offline` → tag `Payment-Pending-Check`, untag
`Checkout-Abandoned`, mark the application *awaiting check*.

`fluent_cart/order_fully_refunded` → remove the tags that order added, restore
`member_type` / `paid_through` from the snapshot taken before payment, mark the
application refunded. (Test explicitly on staging — Phase 4.)

## FluentCart emails (configure in FluentCart → Settings → Emails)

| Email | Keep? | Notes |
|---|---|---|
| Order paid — customer (receipt) | **Yes** | The member receipt for card *and* check. Uses the existing sender infrastructure. |
| Order paid — admin | Optional | Overlaps with the plugin's new-member notification; keep only if Pat/Sebbie want the FluentCart layout too. |
| Order placed (offline) — customer | **Yes** | "We received your order; mail your check to …" — include the mailing address and the memo-line instruction (also shown as *Checkout Instructions*). Suppressed automatically for *Record a Check* orders. |
| Order placed (offline) — admin | Optional | Treasurer heads-up that a check is coming. |
| Refund emails | Yes | default |

## FluentCRM automations to build (UI)

Triggers available: FluentCart *Order Paid*, *Order Refunded*, *Cart Abandoned*;
FluentCRM *Tag Applied*, *Tag Removed*, *Custom field updated*, *Contact created*;
Fluent Forms *Form submitted*. Recommended set:

### A. Welcome (new member)

* Trigger: **Tag Applied → `Paid-YYYY`** (current year) with condition
  *contact has no earlier `Paid-*` tag* (Conditional: tag does not include
  Paid-2024, Paid-2025 …) — or trigger on Fluent Forms **join form submitted**
  and *wait until* tag `Paid-*` applied (FluentCRM "Wait for event"). Prefer
  the tag trigger: it fires only after payment.
* Exclusion condition (above).
* Email: the **rewritten Welcome** — under the new flow the application is
  complete at signup, so drop the "please complete your application" wording.
  Client supplies copy.

### B. Renewal confirmation

* Trigger: Tag Applied → `Paid-YYYY`, condition: contact already had a `Paid-*`
  tag for an earlier year.
* Exclusion condition. Short "thanks, you're paid through {{contact.custom.paid_through}}".

### C. Dues reminder campaign (January 1 renewal)

* Sequence / campaign to segment: `member_type` in (Regular, Associate) AND tag
  does not include `Paid-{next year}` AND exclusion condition.
* Send Oct, Nov, Dec, Jan. Each email links to the **renewal form**, not to
  checkout directly (the form prefills the contact and passes the token).
* Dynamic segment refreshes as payments land; nobody who has `Paid-{next year}`
  receives the next send.

### D. Abandoned application follow-up

* Trigger: **Tag Applied → `Checkout-Abandoned`**. Wait 2 days. Conditional:
  tag still includes `Checkout-Abandoned` (it is removed on payment). Send
  "finish your membership" with the checkout link. Optional second nudge at
  7 days. Exclusion condition not needed (no member_type yet) but harmless.
* The same list is visible any time in My IAPSNJ → Reports → *Applications
  awaiting payment*.

### E. Check pending reminder

* Trigger: Tag Applied → `Payment-Pending-Check`. Wait 21 days. Conditional:
  tag still present. Email "we have not received your check". Internal note to
  treasurer optional. Aging report in My IAPSNJ covers the 30+ day cases.

### F. Refund

* Trigger: FluentCart *Order Refunded (Full)* → internal email to treasurer.
  The plugin already reverts the CRM state.

### G. Certificate (Billy)

Handled by the plugin's new-member notification (paid only). If Billy prefers a
FluentCRM email instead, build: Trigger Tag Applied → `Paid-YYYY`, condition
*new member* as in A, exclusion condition, email to Billy with SmartCodes
`{{contact.full_name}}`, `{{contact.address_line_1}}`, `{{contact.city}}`,
`{{contact.state}}`, `{{contact.postal_code}}`, `{{contact.custom.department}}`,
`{{contact.email}}`, `{{contact.phone}}`. **Never** trigger on form submitted.

## Deliverability

Keep the current sending infrastructure (same SMTP/service, same From
domain). FluentCart receipts go through `wp_mail`, i.e. the same route as
today. If the sender must change, warm it up before October 1 — the first send
at the new configuration must not be the 4,000-member renewal blast.

## Verification list (do on staging for every automation)

- [ ] Exclusion block present and first, with both field and tag checks
- [ ] Test contact `member_type = Lifetime`: receives nothing from A–E
- [ ] Test contact `member_type = Honorary`: receives nothing from A–E
- [ ] Join by card → Welcome once, notification once, receipt once
- [ ] Join by check → offline email once; mark paid → Welcome, notification, receipt; `Payment-Pending-Check` gone
- [ ] Renewal by card → B once, no Welcome, no notification
- [ ] Abandon after form → D fires; pay → D stops
- [ ] Dates in every email show 12/31 (not 12/30)
