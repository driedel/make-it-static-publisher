=== Make it Static ===
Contributors: driedel
Tags: static, static site, cache, performance, deployment
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.0.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish your WordPress site as an optimized static site via the self-hosted Make it Static service (S3 + CloudFront).

== Description ==

Make it Static turns your dynamic WordPress site into a fast, secure, fully static website. The plugin itself is a lightweight HTTP client: it notifies your self-hosted [Make it Static](https://github.com/driedel/make-it-static) service whenever content changes, and the service does the heavy lifting.

**This plugin requires a running Make it Static service.** The service is a separate, self-hosted FastAPI application (with an RQ worker, Redis, and S3-compatible object storage) that you deploy yourself, typically with Docker Compose. See the [service repository](https://github.com/driedel/make-it-static) for setup instructions. The plugin does nothing useful without it.

How it works:

1. When you publish, update, trash, or delete content, the plugin sends a signed publish request to the Make it Static service.
2. The service scrapes your site with wget (using the `MakeItStaticBot/1.0` user agent), capturing the fully rendered HTML plus all assets.
3. It optimizes the result: bundles CSS and JavaScript, compresses images and HTML, and converts fonts (each step is configurable).
4. It uploads everything to S3-compatible object storage and optionally invalidates a CloudFront distribution.

Features:

* Automatic publishing on post publish/update for the post types you choose, plus whole-site re-publish on trash and delete.
* Smart debouncing and coalescing: rapid changes are merged, at most one job runs at a time, and at most one follow-up is queued.
* Asynchronous job tracking: jobs are polled in the background via WP-Cron and listed on a dedicated admin screen with status, result, and error details.
* Automatic retries of failed jobs (configurable), plus manual retry and cancel actions.
* Secure communication: every publish request is signed with HMAC-SHA256 and a timestamp, verified by the service.
* Connection test button on the settings screen.
* Optional CloudFront invalidation and extra CDN domain rewriting.

== Installation ==

1. Deploy the Make it Static service first — see [github.com/driedel/make-it-static](https://github.com/driedel/make-it-static). Note the URL where WordPress can reach its API (for example `http://make-it-static-api:8000` when both run in the same Docker Compose project).
2. Upload the `make-it-static` folder to `/wp-content/plugins/`, or install the plugin through the WordPress plugins screen.
3. Activate the plugin through the **Plugins** screen in WordPress.
4. Go to **Settings → Make it Static** and configure the API base URL and the HMAC secret. The secret must match the `MAKE_IT_STATIC_HMAC_SECRET` environment variable of the service. For better security, define the secret as a PHP constant in `wp-config.php` instead of storing it in the database:

`define( 'MAKE_IT_STATIC_HMAC_SECRET', 'your-long-random-secret' );`

5. Use the **Test connection** button to verify WordPress can reach the service.
6. Optionally configure CloudFront invalidation, extra CDN domains, optimization options, post types, retries, and the debounce interval.

== Frequently Asked Questions ==

= Why do I get 401 "invalid timestamp" or "timestamp out of window" errors? =

Publish signatures include a timestamp the service only accepts within a ±300-second window. The clocks of the WordPress server and the Make it Static service must be in sync — make sure both machines use NTP. Docker containers normally inherit the host clock, so check the host if containers are involved.

= The published static site is missing content, shows the admin bar, or looks broken. Why? =

The service scrapes your site over HTTP with the user agent `MakeItStaticBot/1.0`. Make sure that:

* Security or firewall plugins do not block this user agent.
* Cache plugins do not serve this user agent pages cached from a logged-in session (which would leak the admin bar or personalized content into the static site).
* The service can actually reach your WordPress site over HTTP. If WordPress runs in Docker with an internal hostname (for example `http://site_nginx`), set the **Scrape URL base** setting to that internal URL so the worker scrapes the right address.

= I published one small change. Why does the job take several minutes? =

By design: every publish triggers a full re-scrape of the whole reachable site, so a single consistent snapshot is deployed. Large sites take minutes. The plugin debounces and coalesces rapid changes, and jobs are processed asynchronously — you can keep working while a job runs.

= A job failed, but the error only says "Job failed — check server logs". Where are the logs? =

For privacy and security, the service never exposes internal error details through its API. Check the worker logs on the service host, for example `docker compose logs worker` in your Make it Static deployment.

= What happens if I deactivate the plugin? =

Scheduled polling and pending publish events are cleared. Your settings and the job history table are kept, so you can reactivate later without losing configuration.

= Does the plugin phone home or send data anywhere besides my own service? =

No. The only outbound HTTP requests go to the Make it Static service URL you configure yourself.

== Screenshots ==

1. The settings screen: API connection, signing secret, CloudFront and CDN options, optimization toggles, and behavior settings. (screenshot-1.png)
2. The job status screen: recent publish jobs with live status, results, and retry/cancel actions. (screenshot-2.png)

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
