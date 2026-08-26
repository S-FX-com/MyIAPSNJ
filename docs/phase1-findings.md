# Phase 1 — Findings note

Status of the five unknowns that gate the design. Items 1, 2 and 5 were resolved
by reading the FluentCart **1.6.3** source (the current wordpress.org release,
downloaded 2026-08-25) — not from the public docs, which do not cover them.
Items 3 and 4 need the production database; the queries are ready to run.

| # | Unknown | Status |
|---|---|---|
| 1 | FluentCart checkout URL format | **Resolved** — instant checkout URL, extra params preserved |
| 2 | Offline payment method labelled "Cash on Delivery" | **Resolved** — renameable via saved settings, no custom gateway needed |
| 3 | Orphaned membership levels (IDs 3 and 5) | **Query ready** — run `wp iapsnj census` on staging |
| 4 | Honorary / Lifetime census | **Query ready** — same command |
| 5 | Manual order creation for an existing customer | **Resolved** — supported; hooks verified; plugin automates it |

---

## 1. Checkout URL format (Fluent Forms → FluentCart handoff)

FluentCart 1.6 exposes an **instant checkout** route. Verified in
`app/Http/Routes/WebRoutes.php::registerRoutes()` and
`app/Models/ProductVariation.php` (method that builds the "Direct Checkout"
link shown in the product editor):

```
https://iapsnj.org/?fluent-cart=instant_checkout&item_id={VARIATION_ID}&quantity=1
```

* `item_id` is the **product variation id** (`fct_product_variations.id`), not
  the product post id. Every product has at least one variation.
* Optional `coupons=CODE1,CODE2`.
* Optional `redirect_to=` (same-host only, filterable).
* **Every other query parameter is preserved and forwarded to the checkout
  page.** The route rebuilds the query string minus `fluent-cart`, `item_id`,
  `quantity`, `coupons` and redirects to the checkout page with the rest
  attached. This is what carries the application token.

**Prefilling billing fields via URL is not supported by FluentCart itself.**
There is no `?email=` handling. What exists is the filter
`fluent_cart/checkout_page_name_fields_schema` (`$fields, ['cart','scope']`),
which is how FluentCart's own FluentCRM connector prefills name/email for a
recognised contact. My IAPSNJ hooks the same filter:

* reads the application token (`iapsnj_app=` query param, then a 2-hour cookie),
* stores it on the cart (`fct_carts.checkout_data.__iapsnj_app`) so it survives
  webhooks,
* prefills `billing_email` / name from the application and marks the email
  field `readonly` (plus a small script that re-applies `readonly` after
  FluentCart's client-side re-render).

Fluent Forms side: the redirect URL is filterable (`fluentform/redirect_url_value`),
and the plugin appends `iapsnj_app={token}` automatically for the configured
join/renewal forms. The admin only pastes the instant-checkout URL above into
the form's Confirmation → "Redirect to custom URL". `{inputs.x}` shortcodes work
in that URL if a per-member variation id ever needs to be dynamic.

**Escalation not needed.** Direct preselection works; the flow design stands.

## 2. Offline payment method label

The built-in offline gateway is `app/Modules/PaymentMethods/Cod/Cod.php`,
route/slug **`offline_payment`**, hard-coded title `Cash`, description
"Pay with cash upon delivery", checkout hint "Cash upon delivery, bank transfer
or other manual process."

`AbstractPaymentGateway::getMeta()` overrides the title with
`settings.checkout_label` and adds `settings.checkout_instructions` when they
are present in the gateway's saved settings, and
`PaymentMethodController` saves both keys from the admin UI
(FluentCart → Settings → Payments → *Cash on Delivery* → Manage → *Checkout
Label* / *Checkout Instructions*). So:

* **No custom gateway is required.** Set the label to `Pay by Check` and the
  instructions to the mailing address + "write your member number on the memo
  line".
* My IAPSNJ → Sync & Settings has an *Apply label & instructions* button that
  writes the same two keys (`fct_meta`, key
  `fluent_cart_payment_settings_offline_payment`), and there is
  `wp iapsnj offline-label --label="Pay by Check" --instructions="…"`.
  The method must have been saved once in FluentCart first (the row must exist).
* The 1.3.2 "Thank You page payment instructions" feature shows the
  instructions after checkout too. **Verify on staging** that the label
  renders in the payment-method list and on the receipt; the label is used for
  `payment_method_title` on the order at placement time.

Offline order lifecycle (verified in `CodHandler::handlePayment`,
`OrderController::markAsPaid`, `StatusHelper::syncOrderStatuses`):

* placed → order `on-hold`, payment `pending`, one pending transaction with no
  `vendor_charge_id`; fires `fluent_cart/order_placed_offline`.
* admin *Mark as paid* (or My IAPSNJ Pending Checks) → the pending transaction
  is updated to `succeeded`, `syncOrderStatuses()` sets payment `paid`, order
  `processing` → `completed` (digital), and dispatches **`fluent_cart/order_paid`
  synchronously** plus `fluent_cart/order_paid_done` asynchronously (Action
  Scheduler). Receipt emails hang off `order_paid_done`.
* card payments reach the same `syncOrderStatuses()` from the gateway webhook,
  so **card and check converge on one hook** — that is the hook the plugin
  uses.

## 3. Orphaned membership levels

Run on staging (PMPro may be active or not — only the tables are read):

```
wp iapsnj census
```

or My IAPSNJ → Migration → *1. Census*. It prints, per level id found in
`pmpro_memberships_users` and `pmpro_membership_orders` but missing from
`pmpro_membership_levels`: membership rows, distinct users, still-active
rows, order count, order users, first and last order date. Raw SQL equivalent:

```sql
SELECT membership_id, status, COUNT(*) n, COUNT(DISTINCT user_id) users
FROM wp_pmpro_memberships_users GROUP BY membership_id, status;

SELECT membership_id, status, COUNT(*) n, COUNT(DISTINCT user_id) users,
       MIN(`timestamp`) first_order, MAX(`timestamp`) last_order
FROM wp_pmpro_membership_orders GROUP BY membership_id, status;
```

How the migration handles them (no silent skipping):

* `backfill_year_tags` still applies `Paid-YYYY` for their paid orders (the
  payment happened) and records the deleted level id in the CRM field
  `legacy_pmpro_level`, and reports `orphan_level_orders` per id.
* `set_member_state` maps unknown levels to **Regular** unless the level map
  says otherwise (`--level-map=3:Associate`), and records `legacy_pmpro_level`.
* **Decision for the client:** what were levels 3 and 5? If either was
  Associate or a comped level, pass it in the level map before applying.

_Result to be filled in after running on staging:_

| level id | name (deleted) | membership rows | users | still active | orders | first order | last order | decision |
|---|---|---|---|---|---|---|---|---|
| 3 | | | | | | | | |
| 5 | | | | | | | | |

## 4. Honorary and Lifetime census

Same command. The `honorary_lifetime_census` section lists, for each level the
level map calls Honorary or Lifetime (default guess: names containing
"honor"/"life"; override with `--level-map=2:Lifetime,6:Honorary`): active
members, how many have *any* order, how many have a *paid* (success, total > 0)
order, and how many have none. The expectation is Honorary → none.

The migration reads these members from `pmpro_memberships_users`
(`migrate_comped`), never from orders, tags them `Honorary` / `Lifetime`, sets
`member_type`, and **deletes** `paid_through` (null, never a far-future date).

_Result to be filled in after running on staging:_

| level | name | active | with any order | with paid order | without any order |
|---|---|---|---|---|---|
| 6 | Honorary | | | | |
| 2 | Lifetime | | | | |

## 5. Manual order creation

FluentCart admin: Orders → *Create Order* → pick existing customer → add
product → *Mark as paid* (offline). Verified code path
(`OrderController::store` → `OrderResource::updatedPlaceOrder` →
`AdminOrderProcessor::createDraftOrder` → COD gateway → `order_placed_offline`;
then `markAsPaid` → `syncOrderStatuses` → `order_paid`). Automations that fire:

* `fluent_cart/order_placed_offline` → FluentCart "order placed (offline)"
  customer + admin emails.
* `fluent_cart/order_paid` (sync) → **My IAPSNJ membership update**.
* `fluent_cart/order_paid_done` (async) → FluentCart receipt, FluentCRM
  "Order Paid" automation trigger, integration feeds.

My IAPSNJ → Pending Checks → *Record a check* does the same thing in one step
for a member who mailed a check without using the website: creates the
FluentCart customer if missing, creates the offline order, suppresses the
"please pay" email (the check is already in hand), marks it paid with the check
number and deposit date, and lets `order_paid` do the rest. The order carries
`_my_iapsnj_source = manual_check` so the orphan report does not flag it.

---

## Other verified facts used by the build

| Fact | Where verified |
|---|---|
| FluentCart tables: `fct_orders`, `fct_order_items`, `fct_order_transactions`, `fct_customers` (`user_id`, `email`, `contact_id`), `fct_order_addresses`, `fct_order_meta`, `fct_carts` (`checkout_data` JSON, `order_id`), `fct_meta`; amounts in **cents** | `app/Models/*` |
| Order statuses `on-hold/processing/completed/canceled/failed`; payment statuses `pending/paid/partially_paid/failed/refunded/partially_refunded/authorized` | `app/Helpers/Status.php` |
| Refund hooks `fluent_cart/order_refunded`, `…/order_fully_refunded`, `…/order_partially_refunded` (`order`, `refunded_amount` cents, `transaction`, `customer`, `type`) | `app/Events/Order/OrderRefund.php` |
| Email gate `fluent_cart/should_send_email_notification` (`event`, `mail_name`, `order`) | `EmailNotificationMailer.php` |
| Fluent Forms `fluentform/submission_inserted($insertId, $formData, $form)` fires **before** the confirmation redirect is computed; `fluentform/redirect_url_value($url, $insertId, $form, $formData)` filters the redirect | `SubmissionHandlerService.php` |
| FluentCRM feed for Fluent Forms supports primary fields, custom fields, tags, remove tags, skip-if-exists, conditional logic; fires `fluent_crm/contact_updated_by_fluentform($subscriber, $entry, $form, $feed)` | fluent-crm `Services/ExternalIntegrations/FluentForm/Bootstrap.php` |
| FluentCRM custom field types: `text, textarea, number, select-one, select-multi, radio, checkbox, date, date_time`; definitions live in option `contact_custom_fields` | `Models/CustomContactField.php` |
| FluentCRM ↔ FluentCart automation triggers: Order Paid, Order Refunded (Full), Order Canceled, Cart Abandoned, … | docs.fluentcrm.com |
