# Claude Code Instructions – My IAPSNJ

See `README.md` for what the plugin does and how its screens fit together, and
`docs/` for the PMPro → FluentCRM/FluentCart migration project (findings, CRM
schema, runbook, automations, forms, UAT matrix, what is not in git).

## Stack facts that shape the code

- FluentCRM is the single source of truth. The only writers are the FluentCart
  integration (`class-membership.php`), the Fluent Forms integration
  (`class-applications.php`) and the migration toolkit. `class-engine.php` is a
  one-way CRM → WordPress mirror; never add a WP → CRM write path.
- Paid Memberships Pro is retired. Read its tables with `$wpdb` only (migration);
  never call PMPro functions, never write to `pmpro_*` tables or `pmpro_b*` meta.
- FluentCart hooks were verified against the 1.6.3 source (see
  `docs/phase1-findings.md`). `fluent_cart/order_paid` is the one path for card
  and check payments; do not hook `payment_status_changed_to_paid` for that.
- Tag slugs, custom-field slugs and member types live in `class-schema.php`;
  never repeat the string literals.
- `paid_through` is a calendar-date string: format with
  `My_IAPSNJ_Dates::ymd_display()`, never `wp_date()`/`date()`. Lifetime and
  Honorary have it **deleted**, never a far-future date.

## Plugin Version

**Increment the plugin version before every commit.** Both locations must be
updated together, and CI fails the release if they disagree:

1. The `Version:` header in `my-iapsnj.php` (line ~6)
2. The `MY_IAPSNJ_VERSION` constant in `my-iapsnj.php` (line ~19)

`my-iapsnj.php` is the single source of truth for the version — do not record it
anywhere else, including in this file.

### Versioning scheme (semver)

| Change type | Bump |
|---|---|
| Bug fix | Patch — `1.1.1` → `1.1.2` |
| New feature, backward-compatible | Minor — `1.1.x` → `1.2.0` |
| Breaking change | Major — `1.x.x` → `2.0.0` |

### Example workflow

```bash
# 1. Edit code
# 2. Bump version in my-iapsnj.php (header + constant)
# 3. Lint
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
# 4. Stage everything including my-iapsnj.php
git add my-iapsnj.php <other changed files>
# 5. Commit
git commit -m "..."
# 6. Push
git push -u origin <branch>
```

### Releases are automatic

When a PR is merged to `main`, `.github/workflows/release.yml` reads the
`Version:` header, checks it matches the constant, lints every PHP file, and
creates a tagged GitHub Release (e.g. `v2.3.0`) with an installable
`my-iapsnj.zip` attached. **No manual release creation is needed.**

The WordPress auto-updater calls the GitHub `/releases/latest` API, which only
returns full (non-pre-release, non-draft) releases. The workflow always creates
full releases, so the updater picks up new versions as soon as the PR lands on
`main`.

The zip is built with `git archive`, so anything marked `export-ignore` in
`.gitattributes` never ships to a site.

## Data migrations

Changes to stored options or seeded mappings belong in
`My_IAPSNJ_Plugin::maybe_upgrade()`, guarded by a new step and a bumped
`DATA_VERSION`. It runs on `plugins_loaded` and on activation, so existing
installs pick the change up without a reinstall. Keep each step idempotent.

## Git branch

Develop on the branch specified in the current session's system prompt. Never
push to `main` directly.

**4.x must not be merged to `main` before the production cutover.** Merging
publishes a release the auto-updater installs on production within hours, and
4.x has no PMPro integration. Merge as a cutover step (see
`docs/migration-runbook.md`).

## Code conventions

- PHP 7.4+ syntax; WordPress coding standards. `str_contains()`,
  `str_starts_with()`, `str_ends_with()`, `match`, `?->`, and the `mixed` type
  are **PHP 8.0+** and must not be used.
- 4-space indentation in PHP and JS.
- All user-facing strings wrapped in `esc_html_e()` / `esc_html__()`.
- AJAX handlers: always `check_ajax_referer()` + `current_user_can( 'manage_options' )`.
- Never `echo` unescaped output.
- Dates: `wp_date()` when formatting a real instant (a Unix timestamp from PMPro
  or WP-Cron) so it renders in the site timezone; `gmdate()` when round-tripping
  a date *string* through `strtotime()`. Never bare `date()` — it silently uses
  the UTC process timezone and shifts 12/31 expirations by a day.
- A method with a declared return type must return only that type. Return
  `WP_Error` only from methods whose return type is left off with a `@return`
  docblock instead.
