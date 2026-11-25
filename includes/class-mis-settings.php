<?php
/**
 * Settings storage helpers and the "Make it Static" settings page.
 *
 * @package MakeItStatic
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes the single `mis_settings` option and renders the admin page.
 */
class MIS_Settings {

	const OPTION = 'mis_settings';

	const OPTION_FLAGS = array( 'bundle_css', 'bundle_js', 'compress_images', 'compress_html', 'convert_fonts' );

	/**
	 * Default values for every settings key.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'api_base_url'               => 'http://make-it-static-api:8000',
			'hmac_secret'                => '',
			'scrape_url_base'            => '',
			'cloudfront_distribution_id' => '',
			'extra_cdn'                  => '',
			'options'                    => array_fill_keys( self::OPTION_FLAGS, true ),
			'retry_count'                => 3,
			'debounce_interval'          => 60,
			'post_types'                 => array( 'post', 'page' ),
		);
	}

	/**
	 * All settings merged over the defaults.
	 *
	 * @return array
	 */
	public static function get_all(): array {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$merged            = array_merge( self::defaults(), $saved );
		$merged['options'] = array_merge(
			array_fill_keys( self::OPTION_FLAGS, true ),
			isset( $saved['options'] ) && is_array( $saved['options'] ) ? $saved['options'] : array()
		);
		return $merged;
	}

	/**
	 * Single setting value.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::get_all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $fallback;
	}

	/**
	 * Whether the HMAC secret comes from the wp-config.php constant.
	 *
	 * @return bool
	 */
	public static function secret_from_constant(): bool {
		return defined( 'MAKE_IT_STATIC_HMAC_SECRET' ) && '' !== (string) MAKE_IT_STATIC_HMAC_SECRET;
	}

	/**
	 * The HMAC secret: constant wins over the option.
	 *
	 * @return string
	 */
	public static function get_secret(): string {
		if ( self::secret_from_constant() ) {
			return (string) MAKE_IT_STATIC_HMAC_SECRET;
		}
		return (string) self::get( 'hmac_secret', '' );
	}

	/**
	 * Extra CDN domains, one per line in the stored string.
	 *
	 * @return array
	 */
	public static function get_extra_cdn(): array {
		$domains = array();
		foreach ( preg_split( '/[\r\n]+/', (string) self::get( 'extra_cdn', '' ) ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$domains[] = $line;
			}
		}
		return array_values( array_unique( $domains ) );
	}

	/**
	 * Wire the settings page, its assets and the test-connection AJAX action.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_mis_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
	}

	/**
	 * Add the page under Settings.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_options_page(
			__( 'Make it Static', 'make-it-static' ),
			__( 'Make it Static', 'make-it-static' ),
			'manage_options',
			'make-it-static',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * @return void
	 */
	public static function register_setting(): void {
		register_setting(
			'mis_settings',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitize the whole settings array on save.
	 *
	 * @param mixed $input Raw submitted values.
	 * @return array
	 */
	public static function sanitize( $input ): array {
		$existing = self::get_all();
		$input    = is_array( $input ) ? $input : array();
		$out      = self::defaults();

		$api_base_url        = isset( $input['api_base_url'] ) ? esc_url_raw( trim( (string) $input['api_base_url'] ) ) : '';
		$out['api_base_url'] = '' !== $api_base_url ? $api_base_url : self::defaults()['api_base_url'];

		// An empty submitted secret keeps the stored one; the field never re-prints it.
		$secret             = isset( $input['hmac_secret'] ) ? trim( (string) $input['hmac_secret'] ) : '';
		$out['hmac_secret'] = '' !== $secret ? sanitize_text_field( $secret ) : $existing['hmac_secret'];

		$out['scrape_url_base'] = isset( $input['scrape_url_base'] ) ? esc_url_raw( trim( (string) $input['scrape_url_base'] ) ) : '';

		// options.php already unslashes the submitted array before this callback runs.
		$out['cloudfront_distribution_id'] = isset( $input['cloudfront_distribution_id'] )
			? sanitize_text_field( (string) $input['cloudfront_distribution_id'] )
			: '';

		$out['extra_cdn'] = isset( $input['extra_cdn'] )
			? sanitize_textarea_field( (string) $input['extra_cdn'] )
			: '';

		$out['options'] = array();
		foreach ( self::OPTION_FLAGS as $flag ) {
			$out['options'][ $flag ] = ! empty( $input['options'][ $flag ] );
		}

		$retry_count        = isset( $input['retry_count'] ) ? absint( $input['retry_count'] ) : self::defaults()['retry_count'];
		$out['retry_count'] = min( 10, $retry_count );

		$debounce                 = isset( $input['debounce_interval'] ) ? absint( $input['debounce_interval'] ) : self::defaults()['debounce_interval'];
		$out['debounce_interval'] = max( 5, $debounce );

		$post_types        = isset( $input['post_types'] ) && is_array( $input['post_types'] )
			? array_map( 'sanitize_key', $input['post_types'] )
			: array();
		$out['post_types'] = array_values( array_intersect( $post_types, array_keys( get_post_types( array( 'public' => true ) ) ) ) );

		return $out;
	}

	/**
	 * Enqueue the inline test-connection script on our settings page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( 'settings_page_make-it-static' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'mis-settings',
			MIS_PLUGIN_URL . 'assets/js/mis-settings.js',
			array(),
			MIS_VERSION,
			true
		);

		wp_localize_script(
			'mis-settings',
			'misSettings',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mis_admin_nonce' ),
				'strings' => array(
					'testing' => __( 'Testing connection…', 'make-it-static' ),
					'error'   => __( 'The connection test failed unexpectedly.', 'make-it-static' ),
				),
			)
		);

		$css = '.mis-test-result{display:inline-block;margin-top:8px;padding:6px 10px;border-left:4px solid #72aee6;background:#f0f6fb;}'
			. '.mis-test-result.mis-test-ok{border-color:#00a32a;background:#edfaef;}'
			. '.mis-test-result.mis-test-error{border-color:#d63638;background:#fcf0f1;}';
		wp_register_style( 'mis-settings-inline', false, array(), MIS_VERSION );
		wp_enqueue_style( 'mis-settings-inline' );
		wp_add_inline_style( 'mis-settings-inline', $css );
	}

	/**
	 * AJAX handler: ping the API health endpoint.
	 *
	 * @return void
	 */
	public static function ajax_test_connection(): void {
		check_ajax_referer( 'mis_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'ok'    => false,
					'redis' => false,
					'human' => __( 'You are not allowed to do this.', 'make-it-static' ),
				),
				403
			);
		}

		$response = ( new MIS_API_Client() )->health();

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					'ok'    => false,
					'redis' => false,
					'human' => sprintf(
						/* translators: %s: error message returned by the HTTP layer. */
						__( 'Connection failed: %s', 'make-it-static' ),
						$response->get_error_message()
					),
				)
			);
		}

		$redis = ! empty( $response['redis'] );
		$human = $redis
			? __( 'Connection successful. The API is reachable and the job queue is ready.', 'make-it-static' )
			: __( 'The API is reachable, but it reports that Redis is unavailable — publish jobs will not run until Redis is back.', 'make-it-static' );

		wp_send_json_success(
			array(
				'ok'    => true,
				'redis' => $redis,
				'human' => $human,
			)
		);
	}

	/**
	 * Render the settings form.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings       = self::get_all();
		$from_constant  = self::secret_from_constant();
		$public_types   = get_post_types( array( 'public' => true ), 'objects' );
		$checkbox_flags = array(
			'bundle_css'      => __( 'Bundle CSS', 'make-it-static' ),
			'bundle_js'       => __( 'Bundle JavaScript', 'make-it-static' ),
			'compress_images' => __( 'Compress images', 'make-it-static' ),
			'compress_html'   => __( 'Compress HTML', 'make-it-static' ),
			'convert_fonts'   => __( 'Convert fonts', 'make-it-static' ),
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors( 'mis_settings' ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'mis_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="mis-api-base-url"><?php esc_html_e( 'API base URL', 'make-it-static' ); ?></label>
						</th>
						<td>
							<input type="url" class="regular-text" id="mis-api-base-url" name="mis_settings[api_base_url]"
								value="<?php echo esc_attr( $settings['api_base_url'] ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Base URL of the Make it Static API service.', 'make-it-static' ); ?>
							</p>
							<p>
								<button type="button" class="button" id="mis-test-connection">
									<?php esc_html_e( 'Test connection', 'make-it-static' ); ?>
								</button>
							</p>
							<div id="mis-test-result" class="mis-test-result" hidden></div>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mis-hmac-secret"><?php esc_html_e( 'HMAC secret', 'make-it-static' ); ?></label>
						</th>
						<td>
							<?php if ( $from_constant ) : ?>
								<input type="text" class="regular-text" id="mis-hmac-secret" disabled="disabled"
									placeholder="<?php esc_attr_e( 'Defined in wp-config.php', 'make-it-static' ); ?>" />
								<p class="description">
									<?php esc_html_e( 'The secret is defined via the MAKE_IT_STATIC_HMAC_SECRET constant in wp-config.php and cannot be changed here.', 'make-it-static' ); ?>
								</p>
							<?php else : ?>
								<input type="password" class="regular-text" id="mis-hmac-secret" name="mis_settings[hmac_secret]"
									value="" autocomplete="new-password" />
								<p class="description">
									<?php esc_html_e( 'Shared secret used to sign publish requests. Leave empty to keep the current secret.', 'make-it-static' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mis-scrape-url-base"><?php esc_html_e( 'Scrape URL base', 'make-it-static' ); ?></label>
						</th>
						<td>
							<input type="url" class="regular-text" id="mis-scrape-url-base" name="mis_settings[scrape_url_base]"
								value="<?php echo esc_attr( $settings['scrape_url_base'] ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Optional. Overrides the site URL sent to the API — useful when the service must reach this site through an internal address. Defaults to the WordPress home URL.', 'make-it-static' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mis-cloudfront-id"><?php esc_html_e( 'CloudFront distribution ID', 'make-it-static' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" id="mis-cloudfront-id" name="mis_settings[cloudfront_distribution_id]"
								value="<?php echo esc_attr( $settings['cloudfront_distribution_id'] ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Optional. When set, the API creates a cache invalidation after each publish.', 'make-it-static' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mis-extra-cdn"><?php esc_html_e( 'Extra CDN domains', 'make-it-static' ); ?></label>
						</th>
						<td>
							<textarea class="large-text code" rows="4" id="mis-extra-cdn" name="mis_settings[extra_cdn]"><?php echo esc_textarea( $settings['extra_cdn'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Optional. One domain per line; asset URLs on these domains are treated as local.', 'make-it-static' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Optimizations', 'make-it-static' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $checkbox_flags as $flag => $label ) : ?>
									<label for="mis-option-<?php echo esc_attr( $flag ); ?>">
										<input type="checkbox" id="mis-option-<?php echo esc_attr( $flag ); ?>"
											name="mis_settings[options][<?php echo esc_attr( $flag ); ?>]" value="1"
											<?php checked( ! empty( $settings['options'][ $flag ] ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label><br />
								<?php endforeach; ?>
								<p class="description">
									<?php esc_html_e( 'Checked optimizations are sent to the API as enabled.', 'make-it-static' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mis-retry-count"><?php esc_html_e( 'Retry count', 'make-it-static' ); ?></label>
						</th>
						<td>
							<input type="number" min="0" max="10" step="1" id="mis-retry-count" name="mis_settings[retry_count]"
								value="<?php echo esc_attr( (string) $settings['retry_count'] ); ?>" />
							<p class="description">
								<?php esc_html_e( 'How many times a failed publish job is re-submitted (0–10).', 'make-it-static' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mis-debounce-interval"><?php esc_html_e( 'Debounce interval (seconds)', 'make-it-static' ); ?></label>
						</th>
						<td>
							<input type="number" min="5" step="1" id="mis-debounce-interval" name="mis_settings[debounce_interval]"
								value="<?php echo esc_attr( (string) $settings['debounce_interval'] ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Publish triggers are coalesced and run this many seconds after the last change (minimum 5).', 'make-it-static' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Post types', 'make-it-static' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $public_types as $post_type ) : ?>
									<label for="mis-post-type-<?php echo esc_attr( $post_type->name ); ?>">
										<input type="checkbox" id="mis-post-type-<?php echo esc_attr( $post_type->name ); ?>"
											name="mis_settings[post_types][]" value="<?php echo esc_attr( $post_type->name ); ?>"
											<?php checked( in_array( $post_type->name, (array) $settings['post_types'], true ) ); ?> />
										<?php echo esc_html( $post_type->labels->singular_name ); ?>
									</label><br />
								<?php endforeach; ?>
								<p class="description">
									<?php esc_html_e( 'Publishing content of these types triggers a static publish.', 'make-it-static' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
