---
name: wp-org-compliance
description: WordPress.org compliance rules for this plugin — text domain, i18n, escaping, Plugin Check annotations, version alignment, release gate, and how to verify everything before any submission or release
type: prompt
whenToUse: When writing or modifying plugin PHP/JS code, preparing a release, submitting to WordPress.org, renaming anything user-facing, or when the wp.org plugin scanner/reviewer flags findings
---

# WordPress.org compliance — Make it Static Publisher

This plugin ships to the WordPress.org Plugin Directory under the slug
**`make-it-static-publisher`** (GitHub repo `driedel/make-it-static-publisher`). The
original slug/name `make-it-static` is unavailable — a third-party plugin closed since
2018 owns it. Directory slugs and display names are never reused, even for closed plugins.

Verify everything below **before any submission or release**. The GitHub Actions release
gate re-checks the version trio automatically; run the local checks yourself when changing
code.

## Hard rules

1. **Text domain = slug.** Every i18n call (`__`, `_e`, `_x`, `_n`, `esc_html__`,
   `esc_html_e`, `esc_attr__`, `esc_html_x`, …) uses `'make-it-static-publisher'` as its
   last argument, and the plugin header has `Text Domain: make-it-static-publisher`.
   Internal identifiers (`mis_settings` option, `mis_jobs` table, `mis_*` hooks/transients,
   `MIS_*` constants, the settings page slug `options-general.php?page=make-it-static`)
   are NOT the text domain — never rename them for wp.org reasons.
2. **Regenerate the POT** after adding/changing strings:
   `docker run --rm -v "$PWD:/plugin" wordpress:cli wp i18n make-pot /plugin /plugin/languages/make-it-static-publisher.pot --domain=make-it-static-publisher`
   Keep translators comments on every string with placeholders.
3. **Escape on output** (`esc_html__`, `esc_attr`, `esc_url`, …). Known-accepted patterns
   that carry justified `phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped`
   annotations (do NOT remove them): `echo` of `render_detail_section()` (HTML assembled
   from escaped parts) and `printf` of integers via `%d` (`$attempts`, `$remaining`).
4. **Custom-table queries** (`includes/class-mis-job-store.php`) carry justified ignores:
   `WordPress.DB.DirectDatabaseQuery.DirectQuery`/`NoCaching` (no higher-level API for
   custom tables; the 30 s poller must read fresh rows) and
   `PluginCheck.Security.DirectDB.UnescapedDBParameter` (table name derives from
   `$wpdb->prefix` only). `uninstall.php`'s `DROP TABLE` carries the `SchemaChange` ignore.
   Never silence a Plugin Check finding without a reason in the annotation.
5. **Package hygiene** (`.distignore` is the source of truth): dev files (`.github`,
   `AGENTS.md`, `.kimi-code`, dotfiles, README/CHANGELOG) must stay excluded; never ship
   `.DS_Store`. The plugin ZIP root folder must be `make-it-static-publisher/`.
6. **No telemetry, no third-party calls**: the only outbound HTTP goes to the user-configured
   service URL. Keep it that way.

## Version alignment (release gate)

Three values must match exactly (the deploy workflow fails otherwise):

- `Version:` header and `MIS_VERSION` in `make-it-static.php`
- `Stable tag:` in `readme.txt`
- the git tag (`vX.Y.Z`, no `v` in the other two)

Both changelogs need an entry per release: `= X.Y.Z =` in `readme.txt`
(`== Changelog ==` and `== Upgrade Notice ==`) and `[X.Y.Z] - DATE` in `CHANGELOG.md`.

## Release / submission process

1. CI (`ci.yml`) gates every push to `main`, every PR, and every `v*` tag: PHPCS
   WordPress-Core, PHPCS WordPress-Extra (security sniffs, warnings non-blocking), and the
   official WordPress Plugin Check.
2. **First submission** of a new plugin requires manual approval at
   https://wordpress.org/plugins/developers/add (upload the ZIP); the SVN repo only exists
   after approval. Subsequent releases go through the GitHub Release → `deploy.yml`.
3. Publishing a GitHub Release triggers `deploy.yml`, whose `verify` job (version trio +
   PHPCS) must pass before the SVN deploy and asset sync run. Never create the release
   before wp.org approval.
4. Display name ("Make it Static Publisher") lives in the `Plugin Name:` header and the
   `=== … ===` line of `readme.txt`; in-dashboard menus may keep the shorter brand text.

## Verifying locally

Host has no PHP/wp-cli — run checks in Docker or in the POC (plugin bind-mounted):

```bash
# PHPCS (Core = blocking, Extra = security sniffs, warnings OK)
docker run --rm -v "$PWD:/app" -w /app composer:2 sh -c \
  'composer global config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true && \
   composer global require --no-interaction squizlabs/php_codesniffer dealerdirect/phpcodesniffer-composer-installer wp-coding-standards/wpcs && \
   export PATH="$(composer global config home --absolute | cut -d\" -f2)/vendor/bin:$PATH" && \
   phpcs --standard=WordPress-Core --extensions=php --ignore=.wordpress-org,.git,node_modules,vendor . && \
   phpcs --standard=WordPress-Extra --extensions=php --ignore=.wordpress-org,.git,node_modules,vendor --runtime-set ignore_warnings_on_exit 1 .'

# Plugin Check (official scanner) — in the POC, plugin dir = make-it-static-publisher
cd ../make-it-an-poc && docker compose exec -T wpcli wp --allow-root --path=/var/www/html \
  plugin check make-it-static-publisher --format=csv
```

Known Plugin Check quirks: warnings do not fail `wp plugin check` (CI stays green) — read
the report, don't just the exit code; `uninstall.php` only honors **trailing** same-line
`phpcs:ignore` annotations; expected leftover warnings in a dev checkout are the dev-file
ones (`.github`, `AGENTS.md`, dotfiles) that `.distignore` keeps out of the package.
