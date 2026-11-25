# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
