# What is not in git — export before cutover, re-import on production

The plugin code is in this repository. Everything below lives in the database
of the site it was configured on and must be exported from staging and
re-created / imported on production (and kept as part of the project record).

| Item | Where | Export | Import |
|---|---|---|---|
| FluentCart products & variations (5) | FluentCart → Products | FluentCart 1.6.2+ *Tools → Export* (products CSV/JSON); otherwise screenshot + recreate | Import or recreate; **variation ids will differ** → re-map in My IAPSNJ → Membership Products and update form dropdown values / redirect URLs |
| FluentCart settings (store, payments incl. offline label/instructions, emails, tax, checkout fields) | FluentCart → Settings | No export; document in this file with screenshots | Recreate by hand; run My IAPSNJ *Apply label & instructions* |
| Stripe / PayPal live keys | FluentCart → Settings → Payments | Never exported; from the gateway dashboards | Enter on production only |
| Fluent Forms: join form, renewal form (fields, steps, conditional logic, FluentCRM feed, confirmation redirect) | Fluent Forms → Tools → Export | JSON export of both forms | Tools → Import; **form ids may differ** → set in My IAPSNJ → Sync & Settings; feed re-links to CRM fields by slug |
| FluentCRM custom fields (`member_type`, `paid_through`, …) | FluentCRM → Settings → Custom Fields | `wp option get fluentcrm-contact_custom_fields`? (option key `contact_custom_fields` via `fluentcrm_get_option`) or just run `wp iapsnj crm-schema` | `wp iapsnj crm-schema --years=2024-2032` recreates the required ones |
| FluentCRM tags | FluentCRM → Contacts → Tags | created by `crm-schema`; extra tags: FluentCRM export | `crm-schema` |
| FluentCRM automations (A–G in `docs/automations.md`) and email sequences/templates | FluentCRM → Automations | FluentCRM Pro *Export* per automation (JSON) | Import; **re-check every exclusion condition and every referenced tag/field/form** |
| Welcome email copy (client-supplied) | FluentCRM automation A | included in automation export | |
| My IAPSNJ options | wp_options | `wp option get my_iapsnj_settings --format=json`, `my_iapsnj_products`, `my_iapsnj_field_mappings` | `wp option update … --format=json`; then fix product/variation and form ids |
| Level map used for migration | CLI argument | write it here: `--level-map=__________________` | |
| PMPro order history CSV | `wp iapsnj export-orders` | file | store with treasurer records, off-site |
| Migration dry-run reports (addresses, tags, state) | `--report=…json` | files | keep for audit |
| Server: email block on staging, HTTP auth, noindex | server config | n/a | remove/verify on production |

Keep this file updated as configuration changes; it is the recovery map if
production ever has to be rebuilt.
