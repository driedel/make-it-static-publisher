<?php
/**
 * HTTP client for the Make it Static API service.
 *
 * @package MakeItStatic
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the WP HTTP API for the FastAPI service.
 */
final class MIS_API_Client {

	/**
	 * Request timeout in seconds.
	 */
	private const TIMEOUT = 15;

	/**
	 * Trailing-slashed API base URL.
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Reads configuration from MIS_Settings.
	 */
	public function __construct() {
		$this->base_url = trailingslashit( (string) MIS_Settings::get( 'api_base_url', 'http://make-it-static-api:8000' ) );
	}

	/**
	 * Queues a publish job on the service.
	 *
	 * The body is JSON-encoded exactly once; the same string is signed and
	 * sent, so the server can verify the HMAC over the raw bytes.
	 *
	 * @param array $args {
	 *     Publish arguments.
	 *
	 *     @type string $url                        URL to scrape (required).
	 *     @type int    $post_id                    Originating post ID, 0 for whole-site (required, truthy server-side per call site).
	 *     @type string $cloudfront_distribution_id Optional distribution ID.
	 *     @type array  $extra_cdn                  Optional list of CDN domains.
	 *     @type array  $options                    Optional map of the five bool flags.
	 * }
	 * @return array|WP_Error Decoded response body on HTTP 200, WP_Error otherwise.
	 */
	public function publish( array $args ) {
		$body = array(
			'url'     => (string) ( $args['url'] ?? '' ),
			'post_id' => (int) ( $args['post_id'] ?? 0 ),
		);

		$distribution_id = trim( (string) ( $args['cloudfront_distribution_id'] ?? '' ) );
		if ( '' !== $distribution_id ) {
			$body['cloudfront_distribution_id'] = $distribution_id;
		}

		$extra_cdn = array_values( array_filter( array_map( 'trim', (array) ( $args['extra_cdn'] ?? array() ) ) ) );
		if ( ! empty( $extra_cdn ) ) {
			$body['extra_cdn'] = $extra_cdn;
		}

		// Omitting options means "all on"; only send it when something is off.
		if ( isset( $args['options'] ) && is_array( $args['options'] ) ) {
			$flags = array();
			foreach ( array( 'bundle_css', 'bundle_js', 'compress_images', 'compress_html', 'convert_fonts' ) as $flag ) {
				$flags[ $flag ] = ! empty( $args['options'][ $flag ] );
			}
			if ( in_array( false, $flags, true ) ) {
				$body['options'] = $flags;
			}
		}

		$raw_body = wp_json_encode( $body );
		if ( false === $raw_body ) {
			return new WP_Error( 'mis_api_error', __( 'Could not encode the publish request.', 'make-it-static-publisher' ) );
		}

		$headers = array_merge(
			array( 'Content-Type' => 'application/json' ),
			MIS_Signer::signature_headers( $raw_body, MIS_Settings::get_secret() )
		);

		$response = wp_remote_post(
			$this->base_url . 'publish',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $headers,
				'body'    => $raw_body,
			)
		);

		return $this->decode_response( $response );
	}

	/**
	 * Fetches the status of a job.
	 *
	 * @param string $job_id Job ID returned by publish().
	 * @return array|WP_Error Decoded job on HTTP 200; WP_Error with code
	 *                        mis_job_not_found on 404, mis_api_error otherwise.
	 */
	public function get_job( string $job_id ) {
		$response = wp_remote_get(
			$this->job_url( $job_id ),
			array( 'timeout' => self::TIMEOUT )
		);

		return $this->decode_response( $response, 404, 'mis_job_not_found' );
	}

	/**
	 * Cancels a job.
	 *
	 * @param string $job_id Job ID returned by publish().
	 * @return array|WP_Error Decoded response on HTTP 200, WP_Error otherwise.
	 */
	public function cancel_job( string $job_id ) {
		$response = wp_remote_request(
			$this->job_url( $job_id ),
			array(
				'method'  => 'DELETE',
				'timeout' => self::TIMEOUT,
			)
		);

		return $this->decode_response( $response );
	}

	/**
	 * Checks service health.
	 *
	 * @return array|WP_Error Decoded body ({"ok":bool,"redis":bool}) or WP_Error.
	 */
	public function health() {
		$response = wp_remote_get(
			$this->base_url . 'health',
			array( 'timeout' => self::TIMEOUT )
		);

		return $this->decode_response( $response );
	}

	/**
	 * Builds the URL for a single job resource.
	 *
	 * @param string $job_id Job ID.
	 * @return string
	 */
	private function job_url( string $job_id ): string {
		return $this->base_url . 'jobs/' . rawurlencode( $job_id );
	}

	/**
	 * Turns an HTTP response into a decoded array or a WP_Error carrying the
	 * server's `detail` message.
	 *
	 * @param array|WP_Error $response        Result of a wp_remote_* call.
	 * @param int            $special_status  Optional status mapped to a special error code.
	 * @param string         $special_code    Error code for $special_status.
	 * @return array|WP_Error
	 */
	private function decode_response( $response, int $special_status = 0, string $special_code = '' ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 === $status ) {
			return is_array( $body ) ? $body : array();
		}

		$detail = ( is_array( $body ) && isset( $body['detail'] ) && is_string( $body['detail'] ) )
			? $body['detail']
			: sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Unexpected response from the API (HTTP %d).', 'make-it-static-publisher' ),
				$status
			);

		$code = ( $special_status === $status && '' !== $special_code ) ? $special_code : 'mis_api_error';

		return new WP_Error( $code, $detail, $status );
	}
}
