# FluentCart configuration (Phase 4)

Done in the FluentCart UI on staging, exported/re-created on production
(`docs/not-in-git.md`). Then mapped in My IAPSNJ → Membership Products.

## Products (all one-time, no subscriptions)

FluentCart → Products → Add. Product type *simple* (one variation) or one
product "IAPSNJ Membership" with one **variation per line** below — variations
are what checkout links point at (`item_id` = variation id). Fulfillment
**digital** (no shipping) so paid orders auto-complete.

| Product / variation | Price | Notes |
|---|---|---|
| Regular Member 2027 | $30 | |
| Associate Member 2027 | $50 | |
| Lifetime Member | $300 | one-time |
| Multi-Year 2027–2031 | $120 | |
| 2026 Catch-Up + 2027 | $45 | Oct–Dec 2026 only → set product visibility/private after Dec 31, or remove from the form dropdown |

Honorary is **not** a product.

Then My IAPSNJ → Membership Products: enable each, set member type,
paid_through (`2027-12-31`, `2031-12-31`, none for Lifetime) and the Paid-YYYY
years (`2027`; `2027,2028,2029,2030,2031`; `2026,2027`).

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

1. Pay for Regular Member 2027 with a Stripe test card.
2. Confirm CRM: `Paid-2027`, `member_type = Regular`, `paid_through = 2027-12-31`.
3. FluentCart → order → **Refund** (full).
4. Confirm: FluentCart payment status `refunded`; CRM tag `Paid-2027` removed,
   `member_type`/`paid_through` back to the pre-payment values (order note "My
   IAPSNJ: membership reverted"); application row `refunded`; refund email sent
   (check the mail log — outbound is blocked).
5. Partial refund: state is **not** reverted (by design); confirm the order
   shows `partially_refunded`.

## Direct checkout links

Product editor → *Direct Checkout* (simple) or the ⋮ menu on a variation. Same
format as My IAPSNJ prints:
`/?fluent-cart=instant_checkout&item_id={variation}&quantity=1`.
