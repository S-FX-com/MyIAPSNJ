# Migration runbook — PMPro → FluentCRM / FluentCart

Covers plan **Phase 3** (data migration on staging), **Phase 8** (delta and
production cutover) and rollback. Everything here is executed either with
WP-CLI (`wp iapsnj …`) or from My IAPSNJ → Migration in wp-admin; the two are
the same code (`includes/class-migration.php`).

Principles that are enforced in code:

* Every step has a **dry run** that writes nothing and prints a report with
  counts, sections and sample rows. Run it, read it, then apply.
* Steps are idempotent — re-running converges.
* PMPro tables are **read only**, with `$wpdb`. PMPro can be deactivated; the
  tables are never touched, never dropped.
* Honorary and Lifetime come from `pmpro_memberships_users`, never from orders.
* `pmpro_b*` billing meta is never written.
* Date strings are handled in UTC round-trip; instants in the site timezone
  (`includes/class-dates.php`).

## 0. Before you start (staging)

1. Staging is a full clone, outbound email blocked at the server, noindex,
   HTTP auth. Record the clone timestamp: `_______________`.
2. Plugins active: FluentCRM (Pro current), Fluent Forms Pro, FluentCart +
   Pro, My IAPSNJ 4.0.0. PMPro may stay active during Phase 3 on staging; it
   is deactivated at cutover (never deleted).
3. Baseline numbers (fill in):

   ```
   wp user list --format=count            → WP users: ______
   wp iapsnj census                        → active members: ______, orders: ______
   ```

4. Create the CRM schema:

   ```
   wp iapsnj crm-schema --years=2024-2032
   ```

5. Decide the level map from the census output (level id → type). Example:

   ```
   --level-map=1:Regular,4:Associate,2:Lifetime,6:Honorary,3:Regular,5:Regular
   ```

   Levels 3 and 5 are deleted; the census reports how many members/orders
   reference them. **Ask the client what they were** before applying.

## 1. Phase 3 — data migration (staging)

Run each step as a dry run, review, then apply. `--limit` is the page size
(default 200); the CLI loops pages automatically.

```bash
LM="--level-map=1:Regular,4:Associate,2:Lifetime,6:Honorary"

# 1. Census (read-only). Save the output with the findings note.
wp iapsnj census $LM --format=json > census.json

# 2. Canonical subscriber link (P3-7): subscriber_id → user meta, contact.user_id
wp iapsnj migrate link_subscribers --dry-run
wp iapsnj migrate link_subscribers

# 3. Addresses (P1-3): ACF + pmpro_b* → CRM. Review both_differ rows in the report.
wp iapsnj migrate consolidate_addresses --dry-run --report=addresses-dry.json
wp iapsnj migrate consolidate_addresses            # --address-mode=prefer_acf|prefer_pmpro|fill_empty if the client prefers

# 4. Paid-YYYY tags from orders (2024+). $0 orders are skipped; deleted levels tagged + recorded.
wp iapsnj migrate backfill_year_tags --dry-run --from-year=2024 --report=tags-dry.json
wp iapsnj migrate backfill_year_tags --from-year=2024

# 5. Honorary / Lifetime from memberships_users (no orders!)
wp iapsnj migrate migrate_comped --dry-run $LM
wp iapsnj migrate migrate_comped $LM

# 6. member_type + paid_through for active Regular/Associate
wp iapsnj migrate set_member_state --dry-run $LM --report=state-dry.json
wp iapsnj migrate set_member_state $LM

# 7. Logins intact
wp iapsnj verify-logins --expected=<baseline WP users>

# 8. Reconciliation (before/after totals)
wp iapsnj reconcile --format=json > reconciliation.json

# Treasurer's record — keep outside the database
wp iapsnj export-orders --file=/secure/path/pmpro-orders-$(date +%F).csv
```

### What each step reports and what to look for

| Step | Key counts | Red flags |
|---|---|---|
| `link_subscribers` | `linked_by_user_id/meta/email`, `unmatched`, `email_conflicts` | `email_conflicts` > 0: two WP users share an email with one contact — fix by hand before continuing |
| `consolidate_addresses` | `acf_only`, `pmpro_only`, `both_same`, `both_differ`, `chose_acf/pmpro`, `contacts_updated`, `no_address_anywhere` | `no_address_anywhere` is the residual list for the client; `both_differ` rows deserve a spot check |
| `backfill_year_tags` | `tags_by_year`, `orphan_level_orders`, `skipped_zero_total`, `contacts_created` | A year with an unexpectedly low count → check `order_statuses` (PMPro marks cancelled subscriptions' orders `cancelled`; add it with `--order-statuses=success,cancelled` if the client wants them counted) |
| `migrate_comped` | `members_by_type` | must equal the census `active_members` for those levels |
| `set_member_state` | `members_by_type`, `paid_through_derived_from_orders`, `paid_through_unknown`, `orphan_level_members` | `paid_through_unknown` > 0 → members with no end date and no paid order; list for the client |
| `verify_logins` | `users`, `missing_login`, `invalid_email`, `unhashed_password`, `duplicate_emails` | anything non-zero except `duplicate_emails` (report those) |
| `reconciliation` | sections `pmpro_active_by_level`, `crm_member_type`, `crm_tags`, `addresses`, `integrity` | `users_without_crm_contact` should be ~0; `crm_contacts_pointing_at_missing_user` should be 0 |

Address heuristic ("prefer the more recently modified"): ACF has no modified
timestamp. `prefer_recent` chooses PMPro billing when the member has a
successful order within `--pmpro-fresh-days` (365), otherwise the ACF profile.
Gaps are filled from the other side (e.g. phone). Country defaults to `US`.

PMPro datetimes: PMPro ≥ 2.x stores UTC; `--order-tz=site` if the install is
older than that (check a known order against the PMPro admin screen).

## 2. Phase 8 — delta / re-clone before cutover

Production keeps taking joins during the build, so the Phase 0 clone is stale.
Two options; **re-clone is simpler and safer**:

**A. Re-clone (recommended)**
1. Export the *configuration that is not in git* from staging (see
   `docs/not-in-git.md`): FluentCart products/settings, FluentCRM automations
   and custom fields, Fluent Forms (export JSON), My IAPSNJ options
   (`wp option get my_iapsnj_settings`, `my_iapsnj_products`,
   `my_iapsnj_field_mappings`).
2. Re-clone production → staging (DB + uploads). Re-apply the email block.
3. Re-import the configuration; re-run **all** Phase 3 steps (idempotent).
4. Re-run UAT smoke (one join, one renewal, one check).

**B. Delta** (only if re-clone is impossible)
1. Note the clone timestamp `T0`.
2. New/changed users since `T0`: `SELECT ID FROM wp_users WHERE user_registered >= T0`
   plus `pmpro_memberships_users.modified >= T0` and
   `pmpro_membership_orders.timestamp >= T0`.
3. Re-run steps 2–6 — they are idempotent, so running them over everyone is
   fine; the delta only tells you what to spot-check.

## 3. Production cutover (October 1)

Freeze window: announce no joins/renewals for ~2 hours. **Clean cutover only —
never run PMPro checkout and FluentCart checkout in parallel.**

1. **Backup** production DB + `wp-content` (verify restorable). Record
   `wp user list --format=count`.
2. Put the site in maintenance mode (or disable the PMPro checkout page).
3. Install/activate FluentCart + Pro, Fluent Forms Pro; import products,
   forms, automations, My IAPSNJ options from staging (`docs/not-in-git.md`).
   Payment gateways in **live** mode; offline method enabled and labelled
   "Pay by Check".
4. Update My IAPSNJ to 4.0.0 (merge the PR to `main`; the release/updater
   picks it up, or upload the zip). Activation creates the applications
   table and runs data-version 5 (drops PMPro mappings, forces CRM → WP).
5. **Deactivate PMPro** (Plugins → Deactivate). Do **not** delete. Tables stay.
6. `wp iapsnj crm-schema --years=2024-2032`
7. Run the Phase 3 sequence exactly as rehearsed (dry-run first, then apply),
   with the level map from staging.
8. `wp iapsnj verify-logins --expected=<count from step 1>` must pass.
9. `wp iapsnj reconcile` — compare with the staging reconciliation.
10. `wp iapsnj export-orders --file=…` — hand to the treasurer.
11. Smoke test with a real card (small product, then refund) and one check flow.
12. Point the Join / Renew menu links at the Fluent Forms pages. Redirect the
    old PMPro `/membership-account/`, `/membership-checkout/` URLs to the new
    pages (theme or redirect plugin).
13. Leave maintenance mode. Watch `wp-content/debug.log` and My IAPSNJ →
    Reports for 48 hours.
14. Set the **cutover date** in My IAPSNJ → Sync & Settings so the orphan
    report ignores pre-cutover orders.
15. Tear down staging (PII of law-enforcement officers) after client sign-off.

## 4. Rollback

Rollback is only realistic **before real FluentCart orders exist**. Once
members have paid through FluentCart, roll *forward* (fix in place).

1. Maintenance mode on.
2. Restore the DB backup from step 1 (this also restores the pre-migration
   CRM state — the migration wrote tags/fields into `fc_*` tables which are in
   the same DB).
3. Reactivate PMPro; deactivate FluentCart. Reinstall My IAPSNJ **3.0.0** from
   the GitHub release (`v3.0.0` zip) — 4.0.0 has no PMPro code.
4. Restore menu links. Maintenance mode off.

Partial rollback of CRM data without a DB restore is possible but manual: the
migration adds tags and custom-field values only; remove `paid-*`, `honorary`,
`lifetime` tags and delete `member_type`/`paid_through`/`legacy_pmpro_level`
meta rows (`fc_subscriber_meta`, `object_type = custom_field`). Addresses that
were overwritten cannot be recovered without the backup — which is why the
address step's dry-run report must be kept.

## 5. Verification checklist (acceptance)

- [ ] `verify_logins`: 0 problems, count equals pre-cutover count
- [ ] Every active PMPro member has a CRM contact with `member_type`
- [ ] Honorary and Lifetime counts equal the census; `paid_through` null for all of them
- [ ] `crm_tags.paid-2026` ≈ number of members who paid in 2026 (`census.orders_by_level_and_status` for 2026)
- [ ] `addresses.crm_linked_contacts_with_address` ≥ `users_with_any_address_before`
- [ ] Orphan levels 3 and 5 accounted for (`legacy_pmpro_level` count = census users)
- [ ] PMPro order CSV exported and stored off-site
- [ ] PMPro deactivated, tables present
