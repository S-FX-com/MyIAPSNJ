# FluentCart configuration (Phase 4)

Done in the FluentCart UI on staging, exported/re-created on production
(`docs/not-in-git.md`). Then mapped in My IAPSNJ → Membership Products.

## Products (no year in the name; annual ones are subscriptions)

FluentCart → Products → Add. Product type *simple* (one variation) or one
product "IAPSNJ Membership" with one **variation per line** below — variations
are what the Join-page buttons point at (`item_id` = variation id).
Fulfillment **digital** (no shipping) so paid orders auto-complete.

| Product / variation | Price | Billing | Notes |
|---|---|---|---|
| Regular Membership | $30 | subscription, every 1 year | auto-renews; each renewal payment extends the term |
| Associate Membership | $50 | subscription, every 1 year | |
| Lifetime Membership | $300 | one-time | |
| Multi-Year Membership | $120 | one-time | covers 5 years |

Honorary is **not** a product. No "Catch-Up" product: the term rule in
`docs/crm-schema.md` gives a payment before the cutover the current year.

Then My IAPSNJ → Membership Products: enable each, set member type and *years
covered per payment* (1, 1, —, 5). Set the renewal-season cutover (default
`10-01`) in Sync & Settings.

Subscriptions work in FluentCart free with Stripe; Pro is not required for
them. Renewal reminder emails: FluentCart → Settings → Emails → Reminders.
Read the "known gap with subscriptions" note in `docs/crm-schema.md` before
choosing subscription billing.

## Payments

* **Stripe** and **PayPal** in **test mode** on staging. Live credentials never
  touch staging.
* **Offline method** (FluentCart → Settings → Payments → *Cash on Delivery* →
  Manage): enable; **Checkout Label** = `Pay by Check`; **Checkout
  Instructions** = mailing address + "Make checks payable to IAPSNJ. Write your
  **member number** on the memo line. Your membership is activated when the
  check is deposited." (Or apply from My IAPSNJ → Sync & Settings.) Confirm on
  the checkout page that the label reads *Pay by Check* and that "Cash" appears
  nowhere the member can see (payment list, thank-you page, receipt,
  emails). If any string still says cash, report it — a gettext override can be
  added to the plugin.
* Drag the offline method **below** the card options.

## Checkout

* Settings → Cart & Checkout: **User account creation = automatic** (a
  WordPress login is created for new members; the plugin also creates one if
  missing). Guest checkout off.
* Hide coupon field unless the client wants promo codes.
* Checkout fields: name + email + phone required; billing address required
  (this is the address that fills gaps in the CRM). No shipping section
  (digital).
* Receipt numbering prefix e.g. `IAPSNJ-`.

## Tax

**Ask the client** whether dues are taxable in NJ before assuming not.
Association membership dues are generally not subject to NJ sales tax, but
confirm with the treasurer/accountant. Default: tax disabled (Settings → Tax).
If enabled, verify the checkout total for a NJ address equals the intended
price.

## Emails

See `docs/automations.md` → *FluentCart emails*. Sender = the existing site
sender. Edit the *Order placed (offline)* customer template to carry the check
instructions.

## Refund test (do explicitly — young plugin)

1. Pay for Regular Membership with a Stripe test card.
2. Confirm CRM: `Paid-YYYY` for the year the rule gives, `member_type = Regular`,
   `paid_through = YYYY-12-31`.
3. FluentCart → order → **Refund** (full).
4. Confirm: FluentCart payment status `refunded`; CRM tag `Paid-YYYY` removed,
   `member_type`/`paid_through` back to the pre-payment values (order note "My
   IAPSNJ: membership reverted"); application row `refunded`; refund email sent
   (check the mail log — outbound is blocked).
5. Partial refund: state is **not** reverted (by design); confirm the order
   shows `partially_refunded`.

## Direct checkout links

Product editor → *Direct Checkout* (simple) or the ⋮ menu on a variation. Same
format as My IAPSNJ prints:
`/?fluent-cart=instant_checkout&item_id={variation}&quantity=1`.
