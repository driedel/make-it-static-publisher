<?php
/**
 * Debounced publish orchestration for Make it Static.
 *
 * @package MakeItStatic
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coalesces publish triggers and talks to the API client.
 */
class MIS_Publisher {

	const PENDING_TRANSIENT  = 'mis_pending_publish';
	const FOLLOWUP_TRANSIENT = 'mis_followup_publish';
	const RUN_HOOK           = 'mis_run_publish';

	/**
	 * Wire WordPress hooks.
	 */
	public static function register(): void {
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 10, 3 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_remove_post' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_remove_post' ) );
		add_action( self::RUN_HOOK, array( __CLASS__, 'run_scheduled_publish' ) );
	}

	/**
	 * Queue a publish whenever a save lands on "publish" for an enabled post type.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public static function on_transition_post_status( string $new_status, string $old_status, $post ): void {
		if ( 'publish' !== $new_status ) {
			return;
		}
		$mode = (string) MIS_Settings::get( 'auto_publish', 'always' );
		if ( 'manual' === $mode ) {
			return;
		}
		if ( 'first_publish' === $mode && 'publish' === $old_status ) {
			// Updates of an already-published post do not trigger in this mode.
			return;
		}
		if ( ! $post || ! in_array( $post->post_type, self::enabled_post_types(), true ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) ) {
			return;
		}
		self::request_publish( (int) $post->ID );
	}

	/**
	 * Trashing or deleting content changes listing pages everywhere, so re-publish the whole site.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_remove_post( int $post_id ): void {
		if ( 'manual' === (string) MIS_Settings::get( 'auto_publish', 'always' ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::enabled_post_types(), true ) ) {
			return;
		}
		self::request_publish( 0 );
	}

	/**
	 * Debounce entry point. Coalesces triggers into one pending publish.
	 *
	 * @param int $post_id Post ID, or 0 for the whole site.
	 */
	public static function request_publish( int $post_id = 0 ): void {
		$post_id  = max( 0, $post_id );
		$existing = get_transient( self::PENDING_TRANSIENT );

		if ( false === $existing ) {
			$pending = $post_id;
		} elseif ( 0 === (int) $existing || 0 === $post_id ) {
			// Whole-site wins over any single-post request.
			$pending = 0;
		} else {
			$pending = (int) $existing;
		}

		set_transient( self::PENDING_TRANSIENT, $pending, HOUR_IN_SECONDS );

		if ( ! wp_next_scheduled( self::RUN_HOOK ) ) {
			wp_schedule_single_event( time() + self::debounce_interval(), self::RUN_HOOK );
		}
	}

	/**
	 * Cron callback: publish once nothing is active, otherwise queue one follow-up run.
	 */
	public static function run_scheduled_publish(): void {
		if ( MIS_Job_Store::count_active() > 0 ) {
			// At most one queued follow-up: the single re-scheduled event itself.
			set_transient( self::FOLLOWUP_TRANSIENT, (int) get_transient( self::PENDING_TRANSIENT ), HOUR_IN_SECONDS );
			if ( ! wp_next_scheduled( self::RUN_HOOK ) ) {
				wp_schedule_single_event( time() + self::debounce_interval(), self::RUN_HOOK );
			}
			return;
		}

		$pending = get_transient( self::PENDING_TRANSIENT );
		delete_transient( self::PENDING_TRANSIENT );
		delete_transient( self::FOLLOWUP_TRANSIENT );

		if ( false === $pending ) {
			return;
		}

		self::publish_now( (int) $pending );
	}

	/**
	 * Publish immediately via the API and record the job.
	 *
	 * @param int $post_id Post ID, or 0 for the whole site.
	 * @return int|WP_Error Job row ID, or WP_Error on failure.
	 */
	public static function publish_now( int $post_id = 0 ) {
		$post_id = max( 0, $post_id );
		$base    = trim( (string) MIS_Settings::get( 'scrape_url_base', '' ) );
		if ( '' === $base ) {
			$base = home_url();
		}

		if ( $post_id > 0 ) {
			$permalink = get_permalink( $post_id );
			if ( ! $permalink ) {
				return new WP_Error( 'mis_invalid_post', __( 'Cannot determine the permalink for this post.', 'make-it-static' ) );
			}
			$path = wp_parse_url( $permalink, PHP_URL_PATH );
			$path = is_string( $path ) && '' !== $path ? $path : '/';
			$url  = trailingslashit( untrailingslashit( $base ) . '/' . ltrim( $path, '/' ) );
			// The API requires a truthy post_id; the real one always is.
			$payload_post_id = $post_id;
		} else {
			$url             = trailingslashit( $base );
			$front_page      = (int) get_option( 'page_on_front' );
			$payload_post_id = $front_page > 0 ? $front_page : 1;
		}

		$args = array(
			'url'                        => $url,
			'post_id'                    => $payload_post_id,
			'cloudfront_distribution_id' => (string) MIS_Settings::get( 'cloudfront_distribution_id', '' ),
			'extra_cdn'                  => self::extra_cdn_with_home_host( $base ),
			'options'                    => (array) MIS_Settings::get( 'options', array() ),
		);

		$client = new MIS_API_Client();
		$result = $client->publish( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$job_id = isset( $result['job_id'] ) ? (string) $result['job_id'] : '';
		if ( '' === $job_id ) {
			return new WP_Error( 'mis_missing_job_id', __( 'The service did not return a job ID.', 'make-it-static' ) );
		}

		$row_id = MIS_Job_Store::insert(
			array(
				'job_id'  => $job_id,
				'post_id' => $post_id,
				'url'     => $url,
				'payload' => wp_json_encode( $args ),
				'status'  => 'queued',
			)
		);

		if ( false === $row_id ) {
			return new WP_Error( 'mis_store_error', __( 'Could not store the publish job.', 'make-it-static' ) );
		}

		return $row_id;
	}

	/**
	 * Re-POST a stored job's payload and update the same row with the new job ID.
	 *
	 * @param array $row Job store row.
	 * @return int|WP_Error Row ID, or WP_Error on failure.
	 */
	public static function republish_row( array $row ) {
		// Store getters return payload already decoded; tolerate a raw JSON string too.
		$payload = $row['payload'] ?? null;
		if ( is_string( $payload ) ) {
			$payload = json_decode( $payload, true );
		}
		if ( ! is_array( $payload ) || empty( $payload['url'] ) || empty( $payload['post_id'] ) ) {
			return new WP_Error( 'mis_invalid_payload', __( 'The stored job payload is missing or invalid.', 'make-it-static' ) );
		}

		$client = new MIS_API_Client();
		$result = $client->publish( $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$job_id = isset( $result['job_id'] ) ? (string) $result['job_id'] : '';
		if ( '' === $job_id ) {
			return new WP_Error( 'mis_missing_job_id', __( 'The service did not return a job ID.', 'make-it-static' ) );
		}

		$updated = MIS_Job_Store::update(
			(int) $row['id'],
			array(
				'job_id'   => $job_id,
				'status'   => 'queued',
				'attempts' => (int) ( $row['attempts'] ?? 1 ) + 1,
				'result'   => null,
				'error'    => null,
			)
		);

		if ( ! $updated ) {
			return new WP_Error( 'mis_store_error', __( 'Could not update the publish job.', 'make-it-static' ) );
		}

		return (int) $row['id'];
	}

	/**
	 * Enabled post types from settings.
	 *
	 * @return array
	 */
	private static function enabled_post_types(): array {
		return array_map( 'strval', (array) MIS_Settings::get( 'post_types', array( 'post', 'page' ) ) );
	}

	/**
	 * Configured debounce interval in seconds (minimum 1).
	 *
	 * @return int
	 */
	private static function debounce_interval(): int {
		return max( 1, (int) MIS_Settings::get( 'debounce_interval', 60 ) );
	}

	/**
	 * Extra CDN domains plus the home_url() host when it differs from the scrape base host.
	 *
	 * Real sites store absolute content URLs pointing at the public host (home_url),
	 * while the scrape usually targets an internal base (container network, reverse
	 * proxy). The service downloads and rewrites extra CDN hosts end to end, so the
	 * public host is included automatically — otherwise those absolute URLs would
	 * leak into the static output and 404 there. Non-default ports are kept in the
	 * entry (e.g. "localhost:8300"); default ports (80/443) are dropped.
	 *
	 * @param string $base Scrape base URL.
	 * @return array
	 */
	private static function extra_cdn_with_home_host( string $base ): array {
		$domains = MIS_Settings::get_extra_cdn();

		$home = wp_parse_url( home_url() );
		$from = wp_parse_url( $base );

		if ( ! is_array( $home ) || empty( $home['host'] ) || ! is_array( $from ) || empty( $from['host'] ) ) {
			return $domains;
		}

		$home_entry = self::host_with_port( $home );
		$base_entry = self::host_with_port( $from );

		if ( $home_entry !== $base_entry && ! in_array( $home_entry, $domains, true ) ) {
			$domains[] = $home_entry;
		}

		return $domains;
	}

	/**
	 * Builds a "host[:port]" entry from URL parts, keeping only non-default ports.
	 *
	 * @param array $parts URL parts from wp_parse_url().
	 * @return string
	 */
	private static function host_with_port( array $parts ): string {
		$entry = (string) $parts['host'];
		if ( isset( $parts['port'] ) && ! in_array( (int) $parts['port'], array( 80, 443 ), true ) ) {
			$entry .= ':' . (int) $parts['port'];
		}
		return $entry;
	}
}
