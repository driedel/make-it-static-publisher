# AGENTS.md

Guidance for AI coding agents working on this repository.

## Project overview

**Make it Static** is a WordPress plugin (PHP, GPL v2 or later) that publishes a WordPress site as a fully static website via a **self-hosted "Make it Static" service** (a separate FastAPI + RQ worker + Redis + S3-compatible storage application, deployed independently — see <https://github.com/driedel/make-it-static>).

The plugin is a **pure HTTP client**. It does not scrape, optimize, or upload anything itself. It:

1. Watches for content changes (publish/update/trash/delete of enabled post types) and manual "Publish static" actions.
2. Debounces and coalesces triggers into at most one active publish job plus one queued follow-up.
3. Sends an **HMAC-SHA256-signed** `POST /publish` request to the service (`X-Signature` = hex HMAC of the exact JSON body, `X-Timestamp` = Unix time, accepted within ±300 s — clocks must be NTP-synced).
4. Records each job in a custom DB table and polls job status every 30 s via WP-Cron until a terminal state (`finished`, `failed`, `stopped`, `canceled`) or until the result expires server-side (~1 h, tracked locally as `expired`).
5. Automatically retries failed jobs (re-POSTs the stored payload with a fresh signature) up to a configurable retry count; manual retry/cancel actions exist in the admin.

**Requirements:** PHP 8.1+, WordPress 6.0+. The service's worker must be able to reach WordPress over HTTP; it identifies as user agent `MakeItStaticBot/1.0`.

**History:** the plugin ships to WordPress.org under the slug `make-it-static-publisher` (GitHub repo `driedel/make-it-static-publisher`) because the original slug `make-it-static` is unavailable — it belongs to a third-party plugin closed since 2018. The text domain matches the slug.

## Repository layout

```
make-it-static.php          Plugin bootstrap: header, constants (MIS_VERSION, MIS_PLUGIN_DIR/URL),
                            activation/deactivation hooks, starts MIS_Plugin singleton.
uninstall.php               Full cleanup (multisite-aware): deletes option, transients, cron hooks,
                            drops the mis_jobs table.
includes/                   All plugin classes, one class per file, no autoloader (MIS_Plugin
                            require_once's each file explicitly):
  class-mis-plugin.php      Singleton bootstrap; loads class files and calls each component's
                            static register() on plugins_loaded.
  class-mis-settings.php    Single `mis_settings` option (defaults, get/get_all/get_secret),
                            Settings API page under Settings → Make it Static, sanitize(),
                            test-connection AJAX. The MAKE_IT_STATIC_HMAC_SECRET constant in
                            wp-config.php takes precedence over the stored secret.
  class-mis-signer.php      MIS_Signer::signature_headers() — HMAC headers for POST /publish.
  class-mis-api-client.php  Thin WP HTTP API wrapper: publish(), get_job(), cancel_job(), health().
                            JSON body is encoded exactly once so the same bytes are signed and sent.
                            15 s timeout. Surfaces the API's `detail` field in WP_Error messages.
  class-mis-publisher.php   Debounced publish orchestration: post-status/trash/delete triggers,
                            pending/follow-up transients, single-event cron `mis_run_publish`,
                            builds the publish payload, publish_now() / republish_row().
  class-mis-poller.php      30-second WP-Cron poller (`mis_poll_jobs`, custom `mis_30s` schedule);
                            reconciles each active row with the service, drives auto-retries.
                            Activation must add the cron schedule filter before scheduling.
  class-mis-job-store.php   CRUD for the `{prefix}mis_jobs` table (created via dbDelta on
                            activation). Defines the status vocabulary (RQ statuses + `expired`)
                            and terminal-state helpers; payload/result stored as JSON.
  class-mis-admin.php       Top-level "Make it Static" jobs screen, admin notices, admin-bar
                            "Static: running" indicator, cancel/retry AJAX actions.
  class-mis-post-ui.php     Post-level UI: "Publish static" row action, classic meta box,
                            block editor sidebar, mis_publish_now AJAX.
assets/css/mis-admin.css    Styles for the jobs screen.
assets/js/                  Vanilla JS (no build step, no framework): mis-admin.js (jobs page,
                            fetch-based AJAX), mis-settings.js (test connection), mis-editor.js
                            (block editor sidebar via wp.plugins/wp.element, no JSX — createElement).
languages/make-it-static-publisher.pot  Translation template; text domain `make-it-static-publisher` everywhere.
.github/workflows/ci.yml    CI: PHPCS (WordPress-Core) + WordPress Plugin Check.
.github/workflows/deploy.yml  Release deploy to WordPress.org SVN.
.wordpress-org/             WordPress.org banners/icons/screenshots (not shipped in the plugin ZIP).
CHANGELOG.md                Keep a Changelog format.
readme.txt                  WordPress.org readme (description, FAQ, changelog, upgrade notice).
README.md                   Developer/user documentation (architecture, Docker setup, settings).
.distignore                 Files excluded from the WordPress.org release package.
```

## Technology stack

- **PHP 8.1+** (typed properties, nullables, `final class` for leaf utilities), **WordPress 6.0+** APIs only: Settings API, Options/Transients, WP-Cron, `$wpdb` + `dbDelta`, WP HTTP API, AJAX (`wp_ajax_*`), admin bar, block editor sidebar via enqueued script using the `wp.*` globals.
- **No Composer dependencies, no npm, no build step.** JS is hand-written ES5-style vanilla or `wp.element` (no JSX/Babel). Nothing is vendored.
- External runtime dependency: the Make it Static HTTP service (not in this repo). Key endpoints: `POST /publish`, `GET /jobs/{id}`, `DELETE /jobs/{id}`, `GET /health`.

## Code style guidelines

- **WordPress Coding Standards (WordPress-Core)** are enforced by CI on all PHP files. Run locally before committing:

  ```sh
  composer global require squizlabs/php_codesniffer dealerdirect/phpcodesniffer-composer-installer wp-coding-standards/wpcs
  phpcs --standard=WordPress-Core --extensions=php --ignore=.wordpress-org,.git,node_modules,vendor .
  ```

- Every PHP file starts with `defined( 'ABSPATH' ) || exit;` (uninstall.php guards on `WP_UNINSTALL_PLUGIN`).
- Classes are named `MIS_*` in `includes/class-mis-*.php`; constants/options/hooks/transients are prefixed `mis_`/`MIS_`. Each component exposes a static `register()` that wires its hooks; `MIS_Plugin::init()` calls them on `plugins_loaded`.
- All user-facing strings are internationalized with the `make-it-static-publisher` text domain; escape on output (`esc_html__`, `esc_attr`, etc.). Regenerate `languages/make-it-static-publisher.pot` (e.g. `wp i18n make-pot`) when adding strings.
- SQL goes through `$wpdb->prepare()`; interpolated table names carry a `phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared` annotation (existing convention).
- AJAX handlers verify the shared `mis_admin_nonce` (`check_ajax_referer`) and a capability (`manage_options` or `edit_post`) before acting.
- Docblocks on every class and method (WordPress style, `@package` tag; note: job-store/poller files use `@package Make_It_Static`, the rest `MakeItStatic`).

## Key runtime behavior to respect when editing

- **Sign exactly the bytes you send.** `MIS_API_Client::publish()` JSON-encodes the body once and both signs and sends that string; never re-encode between signing and sending.
- **The timestamp is not part of the signed message**; the server only checks it is within ±300 s.
- **One active job at a time**, at most one queued follow-up — enforced by transients (`mis_pending_publish`, `mis_followup_publish`) plus the single scheduled `mis_run_publish` event. Whole-site requests (post_id 0) win over single-post requests.
- Job statuses are validated against `MIS_Job_Store::STATUSES`; terminal set is `finished`, `failed`, `stopped`, `canceled`, with `expired` as a local-only state for server-side result expiry (404 on poll).
- The `options` payload is **omitted when all five optimization flags are on** (service default); it is sent only when something is disabled.
- `post_id` in the publish payload must be truthy server-side: whole-site publishes send the front-page ID (or 1).
- Deactivation clears cron hooks but keeps settings and the jobs table; uninstall removes everything (multisite-aware via `switch_to_blog`).

## Testing instructions

There is **no automated test suite** in this repository (no PHPUnit, no JS tests). Quality gates are CI-only:

1. **PHPCS** with the WordPress-Core standard (see command above) — runs on pushes to `main` and all PRs.
2. **WordPress Plugin Check** (`wp plugin check make-it-static-publisher`) — CI spins up MySQL 8 + a WP-CLI-installed WordPress, activates the plugin, and runs the official plugin-check plugin.

Manual verification requires a running Make it Static service (or a stub of its four endpoints). The settings screen's **Test connection** button pings `GET /health` and reports Redis availability.

## Deployment / release process

Releases follow **Semantic Versioning** and publish to WordPress.org via GitHub Actions:

1. Bump the `Version:` header in `make-it-static.php` and the `MIS_VERSION` constant.
2. Update `Stable tag:` in `readme.txt`.
3. Add the release entry to `readme.txt` (`== Changelog ==` and `== Upgrade Notice ==`) and to `CHANGELOG.md` (Keep a Changelog format).
4. Commit, `git tag vX.Y.Z`, push the tag.
5. Publish a GitHub Release from the tag. `deploy.yml` then:
   - pushes to WordPress.org SVN via `10up/action-wordpress-plugin-deploy` (slug `make-it-static-publisher`, ZIP generated and attached to the release),
   - syncs `readme.txt` and `.wordpress-org/` assets via `10up/action-wordpress-plugin-asset-update`.
   Required repo secrets: `SVN_USERNAME`, `SVN_PASSWORD`.

**The git tag, the `Version:` header, and the `Stable tag:` must all match** — a mismatch breaks the deploy. The `.distignore` file controls what is excluded from the release package (CI dirs, README/CHANGELOG, tooling files).

## Security considerations

- The HMAC secret should be defined as the `MAKE_IT_STATIC_HMAC_SECRET` constant in `wp-config.php` when possible (it takes precedence over the DB-stored setting); the settings form never re-prints a stored secret and keeps the old one when the field is submitted empty.
- Every outbound request except `POST /publish` is unsigned by design; only publish is HMAC-signed. Clock skew > 300 s causes 401s — both hosts need NTP.
- The plugin makes no outbound calls except to the user-configured service URL — keep it that way (no telemetry).
- The service scrapes with UA `MakeItStaticBot/1.0`; docs must warn users not to block it and not to serve it logged-in cached pages.
- The service intentionally returns only a generic failure message ("check server logs"); do not add code paths that expect detailed server-side error info.
