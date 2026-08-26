# UAT test matrix (Phase 8)

Run on staging with production-scale data after the delta/re-clone. Outbound
email is blocked — verify emails in the mail log / catcher. Tick every row.

| # | Scenario | Steps | Expected — FluentCart | Expected — FluentCRM | Expected — My IAPSNJ | ✓ |
|---|---|---|---|---|---|---|
| 1 | New member join, card | Join form (all 4 steps) → checkout → Stripe test card | Order paid, completed; receipt sent | Contact created/updated with all form fields; `Paid-2027`; `member_type=Regular`; `paid_through=2027-12-31`; no `Checkout-Abandoned` | Application `paid`; new-member notification with name, address, email, phone, department; WP user exists, login works (password reset mail) | |
| 2 | New member join, check, marked paid later | Join form → checkout → *Pay by Check* | Order on-hold/pending; "order placed (offline)" email with instructions | `Payment-Pending-Check`; no `Checkout-Abandoned` | Appears in Pending Checks; application `awaiting_check` | |
| 2b | … then treasurer marks paid | Pending Checks → tick → check # → deposit date → Mark paid | Payment `paid`, order completed, receipt sent | `Paid-2027`, `member_type`, `paid_through`; pending tag removed | Batch total equals deposit slip; row result ✓; notification sent | |
| 3 | Check from a member who never used the form | Pending Checks → *Record a check* → search member → product → check # | Order created **and** paid; no "please pay" email; receipt sent | State updated as in 1 | Order tagged `manual_check`; not in orphan report | |
| 4 | Renewal, existing member, card | Log in → renewal form (prefilled) → confirm → card | Order paid | `Paid-2027` added to existing history; `paid_through` extended; type unchanged | Application `renewal`/`paid`; **no** new-member notification; renewal confirmation automation only | |
| 5 | Multi-year purchase | Renewal form → Multi-Year 2027–2031 → card | Order paid $120 | `Paid-2027…Paid-2031` (5 tags); `paid_through = 2031-12-31` exactly | | |
| 6 | Refund | FluentCart → order from #1 → full refund | `refunded` | `Paid-2027` removed; type/paid_through restored to pre-payment values | Application `refunded`; order note "membership reverted" | |
| 7 | Abandoned checkout | Join form → land on checkout → close browser | Cart only | Contact with full profile + `Checkout-Abandoned` | Reports → *Applications awaiting payment* lists it; follow-up automation fires after the wait | |
| 8 | Honorary member | Contact with `member_type=Honorary`, tag `Honorary`, `paid_through` empty | — | Receives **no** dues/renewal/catch-up email from any automation or campaign; `paid_through` stays null | Dashboard counts under Honorary | |
| 9 | Lifetime member | Same with Lifetime; also: buy Lifetime product by card | Order paid | `Lifetime` tag, `member_type=Lifetime`, `paid_through` **deleted** even if it had a date | No dues email | |
| 10 | Email lock | Join form with a@x.com → at checkout try to change email | Email field read-only, value a@x.com | | Order reconciles to the application by token | |
| 11 | Catch-up product | Buy 2026 Catch-Up + 2027 | Paid $45 | `Paid-2026` and `Paid-2027`; `paid_through=2027-12-31` | | |
| 12 | Aging | Leave a check order 30+ days (or backdate `created_at` on staging) | | | Reports → Aging lists it; dashboard count | |
| 13 | Mismatched deposit | Select checks totalling $90, type $60 on the slip | | | "slip is $30.00 less" shown before marking | |
| 14 | Logins | `wp iapsnj verify-logins --expected=N` | | | 0 problems | |
| 15 | Timezone | Member with `paid_through=2027-12-31`; view notification, dashboard, CRM | | | Every render says Dec 31, 2027 | |
| 16 | Profile mirror | Edit address in FluentCRM | | | WP user meta updated (Profile Mirror preview shows in sync) | |
| 17 | User delete | Delete a test WP user | | Contact kept, `user_id` cleared | | |

## Client UAT (acceptance gate)

* **Sebbie, Pat and Billy** each complete one real join (card) and one real
  renewal (card) without developer assistance.
* Billy confirms the new-member notification contains what he needs to mail a
  certificate (name, full mailing address, department).
* Pat confirms the Pending Checks flow with a real mailed check on staging
  (mark paid, total matches).
* Sign-off recorded here: `__________________  date __________`
