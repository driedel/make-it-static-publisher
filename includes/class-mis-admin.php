<?php
/**
 * Admin jobs screen, notices and admin bar indicator.
 *
 * @package MakeItStatic
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the jobs list page and handles its AJAX actions.
 */
class MIS_Admin {

	/**
	 * Wire up hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_node' ), 100 );
		add_action( 'admin_head', array( __CLASS__, 'admin_bar_styles' ) );
		add_action( 'wp_head', array( __CLASS__, 'admin_bar_styles' ) );
		add_action( 'wp_ajax_mis_cancel_job', array( __CLASS__, 'ajax_cancel_job' ) );
		add_action( 'wp_ajax_mis_retry_job', array( __CLASS__, 'ajax_retry_job' ) );
	}

	/**
	 * Register the top-level jobs page.
	 */
	public static function add_menu_page(): void {
		add_menu_page(
			esc_html__( 'Make it Static', 'make-it-static' ),
			esc_html__( 'Make it Static', 'make-it-static' ),
			'manage_options',
			'mis-jobs',
			array( __CLASS__, 'render_jobs_page' ),
			'dashicons-download',
			80
		);
	}

	/**
	 * Enqueue assets only on the plugin's own admin page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_mis-jobs' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'mis-admin',
			MIS_PLUGIN_URL . 'assets/css/mis-admin.css',
			array(),
			MIS_VERSION
		);

		wp_enqueue_script(
			'mis-admin',
			MIS_PLUGIN_URL . 'assets/js/mis-admin.js',
			array(),
			MIS_VERSION,
			true
		);

		wp_localize_script(
			'mis-admin',
			'misAdmin',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mis_admin_nonce' ),
				'strings' => array(
					'working'       => __( 'Working…', 'make-it-static' ),
					'requestFailed' => __( 'The request failed. Please try again.', 'make-it-static' ),
					'confirmCancel' => __( 'Cancel this job?', 'make-it-static' ),
					'publishQueued' => __( 'Publish job queued. Reloading…', 'make-it-static' ),
				),
			)
		);
	}

	/**
	 * Render the jobs list page.
	 */
	public static function render_jobs_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'make-it-static' ) );
		}

		$jobs         = MIS_Job_Store::get_recent( 50 );
		$settings_url = admin_url( 'options-general.php?page=make-it-static' );
		?>
		<div class="wrap mis-jobs">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Make it Static — Jobs', 'make-it-static' ); ?></h1>

			<div class="mis-jobs-toolbar">
				<button type="button" id="mis-republish-site" class="button button-primary">
					<?php esc_html_e( 'Republish whole site', 'make-it-static' ); ?>
				</button>
				<span class="mis-action-msg" id="mis-republish-msg" aria-live="polite"></span>
				<a class="button" href="<?php echo esc_url( $settings_url ); ?>">
					<?php esc_html_e( 'Settings', 'make-it-static' ); ?>
				</a>
			</div>

			<?php if ( empty( $jobs ) ) : ?>
				<p><?php esc_html_e( 'No publish jobs yet.', 'make-it-static' ); ?></p>
			<?php else : ?>
				<table class="widefat fixed striped mis-jobs-table">
					<thead>
						<tr>
							<th scope="col" class="mis-col-job"><?php esc_html_e( 'Job', 'make-it-static' ); ?></th>
							<th scope="col" class="mis-col-status"><?php esc_html_e( 'Status', 'make-it-static' ); ?></th>
							<th scope="col" class="mis-col-attempts"><?php esc_html_e( 'Attempts', 'make-it-static' ); ?></th>
							<th scope="col" class="mis-col-date"><?php esc_html_e( 'Created', 'make-it-static' ); ?></th>
							<th scope="col" class="mis-col-date"><?php esc_html_e( 'Updated', 'make-it-static' ); ?></th>
							<th scope="col" class="mis-col-result"><?php esc_html_e( 'Details', 'make-it-static' ); ?></th>
							<th scope="col" class="mis-col-actions"><?php esc_html_e( 'Actions', 'make-it-static' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $jobs as $row ) : ?>
							<?php self::render_job_row( $row ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a single jobs table row.
	 *
	 * @param array $row Job row from the store.
	 */
	private static function render_job_row( array $row ): void {
		$status     = isset( $row['status'] ) ? (string) $row['status'] : '';
		$group      = self::status_group( $status );
		$is_active  = ! MIS_Job_Store::is_terminal( $status ) && 'expired' !== $status;
		$can_retry  = in_array( $status, array( 'failed', 'expired' ), true );
		$job_id     = isset( $row['job_id'] ) ? (string) $row['job_id'] : '';
		$short_id   = strlen( $job_id ) > 12 ? substr( $job_id, 0, 12 ) . '…' : $job_id;
		$badge_mods = 'mis-badge mis-badge--' . $group . ( $is_active ? ' mis-badge--pulse' : '' );
		?>
		<tr data-row-id="<?php echo esc_attr( (string) (int) $row['id'] ); ?>">
			<td class="mis-col-job">
				<code class="mis-job-id" title="<?php echo esc_attr( $job_id ); ?>"><?php echo esc_html( $short_id ); ?></code>
				<?php if ( ! empty( $row['post_id'] ) ) : ?>
					<div class="mis-job-meta">
						<?php
						printf(
							/* translators: %d: post ID. */
							esc_html__( 'Post #%d', 'make-it-static' ),
							(int) $row['post_id']
						);
						?>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $row['url'] ) ) : ?>
					<div class="mis-job-meta mis-job-url">
						<a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $row['url'] ); ?>
						</a>
					</div>
				<?php endif; ?>
			</td>
			<td class="mis-col-status">
				<span class="<?php echo esc_attr( $badge_mods ); ?>" data-status="<?php echo esc_attr( $status ); ?>">
					<?php echo esc_html( $status ); ?>
				</span>
			</td>
			<td class="mis-col-attempts"><?php echo (int) $row['attempts']; ?></td>
			<td class="mis-col-date"><?php echo esc_html( self::human_diff( $row['created_at'] ?? '' ) ); ?></td>
			<td class="mis-col-date"><?php echo esc_html( self::human_diff( $row['updated_at'] ?? '' ) ); ?></td>
			<td class="mis-col-result"><?php self::render_result_cell( $row ); ?></td>
			<td class="mis-col-actions">
				<?php if ( $is_active ) : ?>
					<button type="button" class="button mis-action" data-action="cancel" data-row-id="<?php echo esc_attr( (string) (int) $row['id'] ); ?>">
						<?php esc_html_e( 'Cancel', 'make-it-static' ); ?>
					</button>
				<?php endif; ?>
				<?php if ( $can_retry ) : ?>
					<button type="button" class="button mis-action" data-action="retry" data-row-id="<?php echo esc_attr( (string) (int) $row['id'] ); ?>">
						<?php esc_html_e( 'Retry', 'make-it-static' ); ?>
					</button>
				<?php endif; ?>
				<span class="mis-action-msg" aria-live="polite"></span>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the details cell: result summary, failure hint or expiry note.
	 *
	 * @param array $row Job row from the store.
	 */
	private static function render_result_cell( array $row ): void {
		$status = isset( $row['status'] ) ? (string) $row['status'] : '';

		if ( 'finished' === $status ) {
			// Store getters return result already decoded; tolerate a raw JSON string too.
			$result = $row['result'] ?? null;
			if ( is_string( $result ) ) {
				$result = json_decode( $result, true );
			}
			if ( is_array( $result ) ) {
				$fields = array(
					__( 'Files uploaded', 'make-it-static' ) => isset( $result['files_uploaded'] ) ? (string) (int) $result['files_uploaded'] : '—',
					__( 'Bucket', 'make-it-static' )       => ! empty( $result['bucket'] ) ? (string) $result['bucket'] : '—',
					__( 'Site', 'make-it-static' )         => ! empty( $result['site'] ) ? (string) $result['site'] : '—',
					__( 'Prefix', 'make-it-static' )       => ! empty( $result['prefix'] ) ? (string) $result['prefix'] : '—',
					__( 'Invalidation', 'make-it-static' ) => ! empty( $result['invalidation_id'] ) ? (string) $result['invalidation_id'] : '—',
				);
				echo '<dl class="mis-result">';
				foreach ( $fields as $label => $value ) {
					printf(
						'<div class="mis-result-row"><dt>%s</dt><dd>%s</dd></div>',
						esc_html( $label ),
						esc_html( $value )
					);
				}
				echo '</dl>';
			} else {
				esc_html_e( 'Finished.', 'make-it-static' );
			}
			return;
		}

		if ( 'failed' === $status ) {
			$error = ! empty( $row['error'] ) ? (string) $row['error'] : __( 'Job failed — check server logs', 'make-it-static' );
			printf( '<p class="mis-error">%s</p>', esc_html( $error ) );
			printf(
				'<p class="mis-hint">%s</p>',
				esc_html__( 'Check the Make it Static worker logs (e.g. docker compose logs worker).', 'make-it-static' )
			);
			return;
		}

		if ( 'expired' === $status ) {
			printf(
				'<p class="mis-note">%s</p>',
				esc_html__( 'Results expire server-side 1 hour after a job ends; the outcome is no longer available.', 'make-it-static' )
			);
			return;
		}

		echo '<span aria-hidden="true">—</span>';
	}

	/**
	 * Map a raw status to a badge color group.
	 *
	 * @param string $status Raw store/RQ status.
	 */
	private static function status_group( string $status ): string {
		if ( 'finished' === $status ) {
			return 'finished';
		}
		if ( in_array( $status, array( 'failed', 'stopped', 'canceled' ), true ) ) {
			return 'failed';
		}
		if ( 'expired' === $status ) {
			return 'expired';
		}
		return 'running';
	}

	/**
	 * Format a MySQL datetime as a human-readable relative time.
	 *
	 * @param string $datetime MySQL datetime in WP local time.
	 */
	private static function human_diff( string $datetime ): string {
		// The stored datetime is WP-local; get_gmt_from_date() yields a real UTC timestamp.
		$timestamp = (int) strtotime( get_gmt_from_date( $datetime ) );
		if ( ! $timestamp ) {
			return '—';
		}
		return sprintf(
			/* translators: %s: human-readable time difference. */
			__( '%s ago', 'make-it-static' ),
			human_time_diff( $timestamp, time() )
		);
	}

	/**
	 * Show admin notices while jobs are active or the last job failed.
	 */
	public static function render_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'toplevel_page_mis-jobs' === $screen->id ) {
			return; // The jobs page already surfaces this state inline.
		}

		if ( MIS_Job_Store::count_active() > 0 ) {
			printf(
				'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'A static publish job is running…', 'make-it-static' ),
				esc_url( admin_url( 'admin.php?page=mis-jobs' ) ),
				esc_html__( 'View jobs', 'make-it-static' )
			);
			return;
		}

		$recent = MIS_Job_Store::get_recent( 1 );
		if ( ! empty( $recent ) && 'failed' === ( $recent[0]['status'] ?? '' ) ) {
			printf(
				'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'The most recent static publish job failed.', 'make-it-static' ),
				esc_url( admin_url( 'admin.php?page=mis-jobs' ) ),
				esc_html__( 'View jobs', 'make-it-static' )
			);
		}
	}

	/**
	 * Add a "Static: running" node to the admin bar while jobs are active.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public static function admin_bar_node( WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! current_user_can( 'manage_options' ) || MIS_Job_Store::count_active() < 1 ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'mis-status',
				'title' => '<span class="mis-admin-bar-spinner"></span>' . esc_html__( 'Static: running', 'make-it-static' ),
				'href'  => admin_url( 'admin.php?page=mis-jobs' ),
			)
		);
	}

	/**
	 * Print the small spinner style for the admin bar node (front and back end).
	 */
	public static function admin_bar_styles(): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) || MIS_Job_Store::count_active() < 1 ) {
			return;
		}
		?>
		<style id="mis-admin-bar">
			#wpadminbar #wp-admin-bar-mis-status .mis-admin-bar-spinner {
				display: inline-block;
				width: 10px;
				height: 10px;
				margin-right: 6px;
				border: 2px solid currentColor;
				border-top-color: transparent;
				border-radius: 50%;
				vertical-align: -1px;
				animation: mis-spin 0.8s linear infinite;
			}
			@keyframes mis-spin {
				to { transform: rotate( 360deg ); }
			}
		</style>
		<?php
	}

	/**
	 * AJAX: cancel an active job.
	 */
	public static function ajax_cancel_job(): void {
		check_ajax_referer( 'mis_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'make-it-static' ) ), 403 );
		}

		$row_id = isset( $_POST['row_id'] ) ? absint( $_POST['row_id'] ) : 0;
		$row    = $row_id ? MIS_Job_Store::get( $row_id ) : null;
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Job not found.', 'make-it-static' ) ), 404 );
		}

		if ( MIS_Job_Store::is_terminal( (string) $row['status'] ) || 'expired' === $row['status'] ) {
			wp_send_json_error( array( 'message' => __( 'This job is no longer active.', 'make-it-static' ) ), 409 );
		}

		$client   = new MIS_API_Client();
		$response = $client->cancel_job( (string) $row['job_id'] );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ), 400 );
		}

		// Re-fetch once so the stored status matches server truth; fall back to 'canceled'.
		$status = 'canceled';
		$job    = $client->get_job( (string) $row['job_id'] );
		if ( ! is_wp_error( $job ) && ! empty( $job['status'] ) ) {
			$status = self::sanitize_status( (string) $job['status'] );
		}

		MIS_Job_Store::update( $row_id, array( 'status' => $status ) );

		wp_send_json_success(
			array(
				'status'  => $status,
				'message' => __( 'Job cancelled.', 'make-it-static' ),
			)
		);
	}

	/**
	 * AJAX: retry a failed or expired job.
	 */
	public static function ajax_retry_job(): void {
		check_ajax_referer( 'mis_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'make-it-static' ) ), 403 );
		}

		$row_id = isset( $_POST['row_id'] ) ? absint( $_POST['row_id'] ) : 0;
		$row    = $row_id ? MIS_Job_Store::get( $row_id ) : null;
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Job not found.', 'make-it-static' ) ), 404 );
		}

		if ( ! in_array( $row['status'], array( 'failed', 'expired' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Only failed or expired jobs can be retried.', 'make-it-static' ) ), 409 );
		}

		$result = MIS_Publisher::republish_row( $row );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'status'  => 'queued',
				'row_id'  => (int) $result,
				'message' => __( 'Job re-queued.', 'make-it-static' ),
			)
		);
	}

	/**
	 * Restrict a status string from the API to the known set.
	 *
	 * @param string $status Raw status.
	 */
	private static function sanitize_status( string $status ): string {
		$status  = sanitize_key( $status );
		$allowed = array_merge( MIS_Job_Store::TERMINAL, array( 'queued', 'started', 'deferred', 'scheduled', 'expired' ) );
		return in_array( $status, $allowed, true ) ? $status : 'queued';
	}
}
