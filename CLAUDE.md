# Claude Code Instructions – My IAPSNJ

## Plugin Version

**Increment the plugin version before every commit.** Both locations must be updated together:

1. The `Version:` header in `my-iapsnj.php` (line ~6)
2. The `MY_IAPSNJ_VERSION` constant in `my-iapsnj.php` (line ~19)

### Versioning scheme (semver)

| Change type | Bump |
|---|---|
| Bug fix | Patch — `1.1.1` → `1.1.2` |
| New feature, backward-compatible | Minor — `1.1.x` → `1.2.0` |
| Breaking change | Major — `1.x.x` → `2.0.0` |

Current version: **2.0.0**

### Example workflow

```bash
# 1. Edit code
# 2. Bump version in my-iapsnj.php (header + constant)
# 3. Stage everything including my-iapsnj.php
git add my-iapsnj.php <other changed files>
# 4. Commit
git commit -m "..."
# 5. Push
git push -u origin <branch>
```

### Releases are automatic

When a PR is merged to `main`, the GitHub Actions workflow
`.github/workflows/release.yml` reads the `Version:` header and automatically
creates a tagged GitHub Release (e.g. `v2.0.0`) if one doesn't exist yet.
**No manual release creation is needed.**

The WordPress auto-updater in the plugin calls the GitHub
`/releases/latest` API, which only sees full (non-pre-release, non-draft)
releases. The workflow always creates full releases, so the updater will
pick up new versions as soon as the PR lands on `main`.

## Git branch

Always develop on `claude/rename-plugin-iapsnj-ydnTw` (or the branch
specified in the current session's system prompt). Never push to `main` directly.

## Code conventions

- PHP 7.4+ syntax; WordPress coding standards
- All user-facing strings must be wrapped in `esc_html_e()` / `esc_html__()`
- AJAX handlers: always call `check_ajax_referer()` + `current_user_can( 'manage_options' )`
- Never use `echo` for unescaped output
