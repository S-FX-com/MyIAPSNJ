# UAT test matrix (Phase 8)

Run on staging with production-scale data after the delta/re-clone. Outbound
email is blocked — verify emails in the mail log / catcher. Tick every row.

| # | Scenario | Steps | Expected — FluentCart | Expected — FluentCRM | Expected — My IAPSNJ | ✓ |
|---|---|---|---|---|---|---|
| 1 | New member join, card | Join page button → checkout (FluentCart fields + application fields) → Stripe test card | Order paid, completed; receipt sent; order note "application received" | Contact created/updated with address, department, rank …; `Paid-2027`; `member_type=Regular`; `paid_through=2027-12-31`; no `Checkout-Abandoned` | Application `paid`, kind `join`; new-member notification with name, address, email, phone, department; WP user exists, login works (password reset mail) | |
| 1b | Validation | Same, leave Department empty / certification unticked → Place order | Inline error; no order created | | | |
| 2 | New member join, check, marked paid later | Join page button → checkout → *Pay by Check* | Order on-hold/pending; "order placed (offline)" email with instructions | `Payment-Pending-Check`; no `Checkout-Abandoned`; department / rank already on the contact | Appears in Pending Checks with the answers under the item; application `awaiting_check` | |
| 2b | … then treasurer marks paid | Pending Checks → tick → check # → deposit date → Mark paid | Payment `paid`, order completed, receipt sent | `Paid-2027`, `member_type`, `paid_through`; pending tag removed | Batch total equals deposit slip; row result ✓; notification sent | |
| 3 | Check from a member who never used the website | Pending Checks → *Record a check* → search member → product → check # | Order created **and** paid; no "please pay" email; receipt sent | State updated as in 1 | Order tagged `manual_check`; no application row; not in orphan report | |
| 4 | Renewal, existing member, card | Log in → `[iapsnj_renew_link]` → checkout (application fields prefilled from the CRM) → card | Order paid | `Paid-2027` added to existing history; `paid_through` extended; type unchanged | Application kind `renewal`, `paid`; **no** new-member notification; renewal confirmation automation only | |
| 5 | Payment before the cutover (what the old Catch-Up product did) | Pending Checks → *Record a check* for a member without `Paid-2026` → Regular → deposit date `2026-09-30` | Order created and paid | `Paid-2026` only; `paid_through = 2026-12-31` | Order note "membership updated": `paid_through=2026-12-31 (paid 2026-09-30 …)`, tags added `Paid-2026` | |
| 6 | Refund | FluentCart → order from #1 → full refund | `refunded` | `Paid-2027` removed; type/paid_through restored to pre-payment values | Application `refunded`; order note "membership reverted" | |
| 7 | Abandoned checkout | Join page → checkout → type name + email → close browser | Cart only | Contact (name, email) + `Checkout-Abandoned` | Reports → *Applications awaiting payment* lists it (kind `join`, status `pending`); follow-up automation fires after the wait | |
| 8 | Honorary member | Contact with `member_type=Honorary`, tag `Honorary`, `paid_through` empty | — | Receives **no** dues/renewal/catch-up email from any automation or campaign; `paid_through` stays null | Dashboard counts under Honorary | |
| 9 | Lifetime member | Same with Lifetime; also: buy Lifetime product by card | Order paid | `Lifetime` tag, `member_type=Lifetime`, `paid_through` **deleted** even if it had a date | No dues email | |
| 10 | Answers survive a reload | Fill the application fields, reload the checkout page (or change the payment method) | Typed answers still present | | Order reconciles to the application through the cart ↔ order link | |
| 11 | Only one-year products | Open the Join page; FluentCart → Products | No Multi-Year or Catch-Up product or button | | Membership Products lists only Regular, Associate and Lifetime; no "Years covered" column | |
| 12 | Aging | Leave a check order 30+ days (or backdate `created_at` on staging) | | | Reports → Aging lists it; dashboard count | |
| 13 | Mismatched deposit | Select checks totalling $90, type $60 on the slip | | | "slip is $30.00 less" shown before marking | |
| 14 | Logins | `wp iapsnj verify-logins --expected=N` | | | 0 problems | |
| 15 | Timezone | Member with `paid_through=2027-12-31`; view notification, dashboard, CRM | | | Every render says Dec 31, 2027 | |
| 16 | Profile mirror | Edit address in FluentCRM | | | WP user meta updated (Profile Mirror preview shows in sync) | |
| 17 | User delete | Delete a test WP user | | Contact kept, `user_id` cleared | | |
| 18 | Form per level | Checkout Builder: duplicate the form, edit the copy, assign it to Associate; open a Regular, a Lifetime and an Associate checkout | Order note names the checkout form used | Answers written to the fields of that form | Regular + Lifetime show the Regular form, Associate the copy (heading, fields, required marks) | |
| 19 | Plain store checkout | Buy an event registration or merchandise product only (not in Membership Products) | Order completes with FluentCart's fields only | No `Checkout-Abandoned`, no application fields written | No application section on the checkout; no application row | |
| 20 | Conditional field | Checkout Builder: indent Spouse's name under Marital status (shown when Married), Save; checkout as a new member | Order completes with Single and no spouse; with Married the spouse is required | `spouse_name` written only when Married | Field appears / disappears as the answer changes; hidden field never blocks the order | |
| 21 | View checkout | Checkout Builder → View checkout on the Associate form | Real checkout, Associate product in the cart | | Associate form with the Preview notice (administrators only) | |
| 22 | Checkout page copy (4.16) | Join page → Regular checkout, on a phone and on a desktop | Page, browser tab and the heading above the fields titled *Regular Member Application* (*Associate Member Application* on an Associate checkout, *Lifetime Member Application* on a Lifetime one, whatever the form's Application heading says); one column; Order summary right under the application, above Payment; line reads "$30/year, billed automatically"; "Have a Discount Code?" and, under the field, the family-member note; an event checkout still says *Checkout* and keeps FluentCart's layout | | | |
| 23 | Discount code messages | Apply a wrong code, then a valid one, then remove it | "No matching discount code found." / "Discount code applied" / "Discount code removed"; the word *coupon* appears nowhere on the page | | | |
| 24 | Pay by Check | Same checkout | Card first and preselected, *Pay by Check* second with "Mail your check to: The Italian American Police Society of New Jersey, PO Box 352, Lyndhurst, NJ 07071" and the USPS-delay note under it; the mailing instructions (same address, no "____") show once it is picked | | | |
| 25 | Name split and capitals | FluentCart First Name + Last Name on; type `mary` / `van dyke jr`, street `12 main st apt 4b`, city `MT LAUREL` → leave each field | Fields turn into "Mary", "Van Dyke Jr", "12 Main St Apt 4B", "Mt Laurel" as you leave them; order address and customer the same | Contact first name `Mary`, last name `Van Dyke Jr` (not "Mary Van Dyke" / "Jr"), address as shown | | |
| 26 | Date of birth required | Leave Date of birth empty, then pick a future date | Inline error each time; no order | | | |
| 27 | Retired (4.17) | Regular checkout as a new member: leave "I am retired" unticked; then tick it and leave the date empty; then renew as a member who has a retirement date | Unticked: no date field, order goes through; ticked: Retirement date appears and is required; renewal: box already ticked, date filled | `retirement_date` written only when ticked | | |

## Client UAT (acceptance gate)

* **Sebbie, Pat and Billy** each complete one real join (card) and one real
  renewal (card) without developer assistance.
* Billy confirms the new-member notification contains what he needs to mail a
  certificate (name, full mailing address, department).
* Pat confirms the Pending Checks flow with a real mailed check on staging
  (mark paid, total matches).
* Sign-off recorded here: `__________________  date __________`
