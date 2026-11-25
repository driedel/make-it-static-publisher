# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The plugin now automatically adds the site's `home_url()` host (with port, when non-standard) to `extra_cdn` when it differs from the scrape base host, so post content carrying absolute public-host URLs is downloaded and rewritten by the service.
- Job build report on the jobs screen: pages scraped, per-type asset sizes (original → optimized) with savings percentage, per-step timings, video conversion/cache stats, and top savings — rendered from the `report` key of the job result (requires a service version that emits it; older results render unchanged).
- New **Automatic publish** setting with three modes: *Every save* (current behavior), *Only first publish* (updating an already-published post no longer triggers a publish) and *Manual only* (no automatic triggers — use the publish buttons). Trashing or deleting content publishes the whole site unless *Manual only* is selected.

## [1.0.0] - 2026-11-23

### Added

- Initial release of Make it Static.
- Publish WordPress content to a self-hosted Make it Static static-site service via its HTTP API.
- HMAC-SHA256 signed publish requests (`X-Signature` / `X-Timestamp` headers) with a ±300-second timestamp window.
- Configurable API base URL, HMAC secret (settings field or `MAKE_IT_STATIC_HMAC_SECRET` PHP constant), scrape URL base override, CloudFront distribution ID, extra CDN domains, and optimization options (bundle CSS, bundle JS, compress images, compress HTML, convert fonts).
- Automatic publishing on post publish/update and whole-site re-publish on trash/delete, with configurable debounce and coalescing (at most one active job plus one queued follow-up).
- Asynchronous job tracking in a custom `mis_jobs` database table, polled via WP-Cron every 30 seconds.
- Automatic retries of failed jobs by re-publishing (configurable retry count).
- Job status admin screen with publish-now, retry, and cancel actions.
- Settings screen with a connection test against the service health endpoint.

[1.0.0]: https://github.com/driedel/make-it-static-wp-plugin/releases/tag/1.0.0
