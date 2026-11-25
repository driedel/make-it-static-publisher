<?php
/**
 * Post-level UI for Make it Static: row action, meta box, block editor sidebar.
 *
 * @package MakeItStatic
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers publish actions in the post list, classic meta box, and block editor.
 */
class MIS_Post_UI {

	/**
	 * Wire WordPress hooks.
	 */
	public static function register(): void {
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'handle_row_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_classic_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor_assets' ) );
		add_action( 'wp_ajax_mis_publish_now', array( __CLASS__, 'ajax_publish_now' ) );
	}

	/**
	 * Add a "Publish static" row action on the post/page list tables.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post object.
	 * @return array
	 */
	public static function row_action( array $actions, $post ): array {
		if ( ! in_array( $post->post_type, self::enabled_post_types(), true ) ) {
			return $actions;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg( 'mis_publish_static', $post->ID, admin_url( 'edit.php' ) ),
			'mis_publish_static_' . $post->ID
		);

		$actions['mis_publish_static'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Publish static', 'make-it-static' )
		);

		return $actions;
	}

	/**
	 * Handle the row-action nonce URL: publish immediately, then redirect back with a notice flag.
	 */
	public static function handle_row_action(): void {
		if ( ! isset( $_GET['mis_publish_static'] ) ) {
			return;
		}

		$post_id = absint( $_GET['mis_publish_static'] );
		if ( ! $post_id ) {
			return;
		}

		check_admin_referer( 'mis_publish_static_' . $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to publish this post.', 'make-it-static' ), 403 );
		}

		$result = MIS_Publisher::publish_now( $post_id );

		$target = wp_get_referer();
		if ( ! $target ) {
			$target = admin_url( 'edit.php' );
		}
		$target = remove_query_arg( array( 'mis_publish_static', '_wpnonce', 'mis_publish_result', 'mis_publish_message', 'mis_publish_job' ), $target );

		if ( is_wp_error( $result ) ) {
			$target = add_query_arg(
				array(
					'mis_publish_result'  => 'error',
					'mis_publish_message' => rawurlencode( $result->get_error_message() ),
				),
				$target
			);
		} else {
			$target = add_query_arg(
				array(
					'mis_publish_result' => 'queued',
					'mis_publish_job'    => (int) $result,
				),
				$target
			);
		}

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Render the row-action outcome notice.
	 */
	public static function admin_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag passed through a redirect.
		if ( ! isset( $_GET['mis_publish_result'] ) || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag passed through a redirect.
		$result = sanitize_key( wp_unslash( $_GET['mis_publish_result'] ) );

		if ( 'queued' === $result ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag passed through a redirect.
			$job = isset( $_GET['mis_publish_job'] ) ? absint( $_GET['mis_publish_job'] ) : 0;
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: job row ID. */
						__( 'Static publish queued (job #%d).', 'make-it-static' ),
						$job
					)
				)
			);
		} elseif ( 'error' === $result ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag passed through a redirect.
			$raw_message = isset( $_GET['mis_publish_message'] )
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only notice flag; sanitized by sanitize_text_field() after decoding.
				? rawurldecode( (string) wp_unslash( $_GET['mis_publish_message'] ) )
				: '';
			$message = '' !== $raw_message
				? sanitize_text_field( $raw_message )
				: __( 'The static publish request failed.', 'make-it-static' );
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $message )
			);
		}
	}

	/**
	 * Register the meta box on all enabled post types.
	 */
	public static function add_meta_box(): void {
		foreach ( self::enabled_post_types() as $post_type ) {
			add_meta_box(
				'mis_publish',
				__( 'Make it Static', 'make-it-static' ),
				array( __CLASS__, 'render_meta_box' ),
				$post_type,
				'side'
			);
		}
	}

	/**
	 * Render the meta box: an AJAX publish button plus a status line.
	 *
	 * @param WP_Post $post Post object.
	 */
	public static function render_meta_box( $post ): void {
		$status_text = __( 'No publish job recorded for this post yet.', 'make-it-static' );

		foreach ( MIS_Job_Store::get_recent( 20 ) as $row ) {
			if ( (int) $row['post_id'] === (int) $post->ID ) {
				$status_text = sprintf(
					/* translators: 1: job status, 2: last update datetime. */
					__( 'Last job: %1$s (%2$s)', 'make-it-static' ),
					(string) $row['status'],
					(string) $row['updated_at']
				);
				break;
			}
		}
		?>
		<p>
			<button type="button" class="button button-primary" id="mis-publish-static"
				data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"
				<?php disabled( ! current_user_can( 'edit_post', $post->ID ) ); ?>>
				<?php esc_html_e( 'Publish static', 'make-it-static' ); ?>
			</button>
		</p>
		<p class="description" id="mis-publish-status"><?php echo esc_html( $status_text ); ?></p>
		<?php
	}

	/**
	 * Enqueue the inline jQuery driver for the meta box on classic edit screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_classic_assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, self::enabled_post_types(), true ) ) {
			return;
		}

		wp_register_script( 'mis-post-ui', false, array( 'jquery' ), MIS_VERSION, true );
		wp_enqueue_script( 'mis-post-ui' );
		wp_localize_script(
			'mis-post-ui',
			'misPostUi',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mis_admin_nonce' ),
				'strings' => array(
					'publishing' => __( 'Queuing publish…', 'make-it-static' ),
					'error'      => __( 'The static publish request failed.', 'make-it-static' ),
				),
			)
		);
		wp_add_inline_script(
			'mis-post-ui',
			'jQuery(function($){$(document).on("click","#mis-publish-static",function(e){'
			. 'e.preventDefault();var $btn=$(this),$status=$("#mis-publish-status");'
			. '$btn.prop("disabled",true);$status.text(misPostUi.strings.publishing);'
			. '$.post(misPostUi.ajaxurl,{action:"mis_publish_now",nonce:misPostUi.nonce,post_id:$btn.data("post-id")})'
			. '.done(function(resp){'
			. 'if(resp&&resp.success){$status.text(resp.data.message);}'
			. 'else{$status.text((resp&&resp.data&&resp.data.message)?resp.data.message:misPostUi.strings.error);}'
			. '}).fail(function(){$status.text(misPostUi.strings.error);})'
			. '.always(function(){$btn.prop("disabled",false);});'
			. '});});'
		);
	}

	/**
	 * Enqueue the block editor sidebar script.
	 */
	public static function enqueue_block_editor_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$type   = $screen && $screen->post_type ? $screen->post_type : get_post_type();
		if ( ! $type || ! in_array( $type, self::enabled_post_types(), true ) ) {
			return;
		}

		wp_enqueue_script(
			'mis-editor',
			MIS_PLUGIN_URL . 'assets/js/mis-editor.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n' ),
			MIS_VERSION,
			true
		);
		wp_localize_script(
			'mis-editor',
			'misEditor',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mis_admin_nonce' ),
			)
		);
	}

	/**
	 * AJAX handler: publish a single post or the whole site immediately.
	 */
	public static function ajax_publish_now(): void {
		check_ajax_referer( 'mis_admin_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( $post_id > 0 ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_send_json_error( array( 'message' => __( 'You are not allowed to publish this post.', 'make-it-static' ) ), 403 );
			}
		} elseif ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to publish the whole site.', 'make-it-static' ) ), 403 );
		}

		$result = MIS_Publisher::publish_now( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'job'     => array(
					'id'     => (int) $result,
					'status' => 'queued',
				),
				'message' => sprintf(
					/* translators: %d: job row ID. */
					__( 'Static publish queued (job #%d).', 'make-it-static' ),
					(int) $result
				),
			)
		);
	}

	/**
	 * Enabled post types from settings.
	 *
	 * @return array
	 */
	private static function enabled_post_types(): array {
		return array_map( 'strval', (array) MIS_Settings::get( 'post_types', array( 'post', 'page' ) ) );
	}
}
