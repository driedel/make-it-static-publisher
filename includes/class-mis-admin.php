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
				self::render_build_report( $result );
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
	 * Render the optional build report shipped in the job result.
	 *
	 * Older jobs have no `report` key; every sub-section is skipped when its
	 * data is missing or malformed, since the result comes from external JSON.
	 *
	 * @param array $result Decoded job result.
	 */
	private static function render_build_report( array $result ): void {
		if ( empty( $result['report'] ) || ! is_array( $result['report'] ) ) {
			return;
		}

		$report = $result['report'];

		ob_start();
		self::render_report_pages( $report );
		self::render_report_assets( $report );
		self::render_report_steps( $report );
		self::render_report_top_savings( $report );
		$inner = (string) ob_get_clean();

		if ( '' !== $inner ) {
			// Sections escape their own output; only the wrapper is added here.
			echo '<div class="mis-report">' . $inner . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Render the "Pages scraped" summary line.
	 *
	 * @param array $report Report data from the job result.
	 */
	private static function render_report_pages( array $report ): void {
		if ( empty( $report['pages'] ) || ! is_array( $report['pages'] ) || ! isset( $report['pages']['count'] ) || ! is_numeric( $report['pages']['count'] ) ) {
			return;
		}

		$bytes = isset( $report['pages']['bytes'] ) && is_numeric( $report['pages']['bytes'] ) ? (int) $report['pages']['bytes'] : 0;

		printf(
			'<p class="mis-report-pages">%s</p>',
			esc_html(
				sprintf(
					/* translators: %1$s: number of pages, %2$s: total page size. */
					__( 'Pages scraped: %1$s (%2$s)', 'make-it-static' ),
					number_format_i18n( (int) $report['pages']['count'] ),
					size_format( $bytes )
				)
			)
		);
	}

	/**
	 * Render the per-type asset table with totals.
	 *
	 * @param array $report Report data from the job result.
	 */
	private static function render_report_assets( array $report ): void {
		if ( empty( $report['assets'] ) || ! is_array( $report['assets'] ) ) {
			return;
		}

		$labels = array(
			'images' => __( 'Images', 'make-it-static' ),
			'css'    => __( 'CSS', 'make-it-static' ),
			'js'     => __( 'JS', 'make-it-static' ),
			'fonts'  => __( 'Fonts', 'make-it-static' ),
			'videos' => __( 'Videos', 'make-it-static' ),
			'html'   => __( 'HTML', 'make-it-static' ),
			'other'  => __( 'Other', 'make-it-static' ),
		);

		$rows = array();
		foreach ( $labels as $type => $label ) {
			$asset = $report['assets'][ $type ] ?? null;
			if ( ! is_array( $asset ) || empty( $asset['count'] ) || ! is_numeric( $asset['count'] ) ) {
				continue;
			}
			$rows[ $type ] = array(
				'label'    => $label,
				'count'    => (int) $asset['count'],
				'original' => isset( $asset['original_bytes'] ) && is_numeric( $asset['original_bytes'] ) ? (int) $asset['original_bytes'] : 0,
				'final'    => isset( $asset['final_bytes'] ) && is_numeric( $asset['final_bytes'] ) ? (int) $asset['final_bytes'] : 0,
			);
		}

		if ( empty( $rows ) ) {
			return;
		}

		$totals = ( isset( $report['totals'] ) && is_array( $report['totals'] ) ) ? $report['totals'] : array();
		?>
		<table class="mis-report-assets">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Type', 'make-it-static' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Files', 'make-it-static' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Original', 'make-it-static' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Final', 'make-it-static' ); ?></th>
					<th scope="col"><?php echo esc_html_x( 'Δ%', 'table column: size change in percent', 'make-it-static' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $type => $asset ) : ?>
					<tr>
						<td><?php echo esc_html( $asset['label'] ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $asset['count'] ) ); ?></td>
						<td><?php echo esc_html( size_format( $asset['original'] ) ); ?></td>
						<td><?php echo esc_html( size_format( $asset['final'] ) ); ?></td>
						<td class="<?php echo esc_attr( self::delta_class( $asset['original'], $asset['final'] ) ); ?>">
							<?php echo esc_html( self::format_delta_pct( $asset['original'], $asset['final'] ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( isset( $totals['files'], $totals['original_bytes'], $totals['final_bytes'] ) && is_numeric( $totals['files'] ) && is_numeric( $totals['original_bytes'] ) && is_numeric( $totals['final_bytes'] ) ) : ?>
					<tr class="mis-report-totals">
						<td><?php esc_html_e( 'Total', 'make-it-static' ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $totals['files'] ) ); ?></td>
						<td><?php echo esc_html( size_format( (int) $totals['original_bytes'] ) ); ?></td>
						<td><?php echo esc_html( size_format( (int) $totals['final_bytes'] ) ); ?></td>
						<td class="<?php echo esc_attr( self::delta_class( (int) $totals['original_bytes'], (int) $totals['final_bytes'] ) ); ?>">
							<?php
							if ( isset( $totals['saved_pct'] ) && is_numeric( $totals['saved_pct'] ) ) {
								echo esc_html(
									sprintf(
										/* translators: %s: percentage of bytes saved, e.g. "42.5". */
										__( '−%s%%', 'make-it-static' ),
										number_format_i18n( (float) $totals['saved_pct'], 1 )
									)
								);
							} else {
								echo esc_html( self::format_delta_pct( (int) $totals['original_bytes'], (int) $totals['final_bytes'] ) );
							}
							?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php

		$videos = $report['assets']['videos'] ?? null;
		if ( is_array( $videos ) && ! empty( $videos['converted'] ) && is_numeric( $videos['converted'] ) ) {
			$cache_hits = isset( $videos['cache_hits'] ) && is_numeric( $videos['cache_hits'] ) ? (int) $videos['cache_hits'] : 0;
			printf(
				'<p class="mis-report-videos%s">%s</p>',
				esc_attr( $cache_hits > 0 ? ' mis-report-cache' : '' ),
				esc_html(
					sprintf(
						/* translators: %1$d: number of videos converted, %2$d: number of cache hits. */
						__( '%1$d converted, %2$d cache hits', 'make-it-static' ),
						(int) $videos['converted'],
						$cache_hits
					)
				)
			);
		}
	}

	/**
	 * Render the per-step timing line.
	 *
	 * @param array $report Report data from the job result.
	 */
	private static function render_report_steps( array $report ): void {
		if ( empty( $report['steps_seconds'] ) || ! is_array( $report['steps_seconds'] ) ) {
			return;
		}

		$labels = array(
			'scrape'      => _x( 'scrape', 'build step', 'make-it-static' ),
			'postprocess' => _x( 'postprocess', 'build step', 'make-it-static' ),
			'optimize'    => _x( 'optimize', 'build step', 'make-it-static' ),
			'upload'      => _x( 'upload', 'build step', 'make-it-static' ),
		);

		$parts = array();
		foreach ( $labels as $step => $label ) {
			if ( ! isset( $report['steps_seconds'][ $step ] ) || ! is_numeric( $report['steps_seconds'][ $step ] ) ) {
				continue;
			}
			$parts[] = sprintf(
				/* translators: %1$s: build step name, %2$s: duration in seconds. */
				__( '%1$s %2$ss', 'make-it-static' ),
				$label,
				number_format_i18n( (float) $report['steps_seconds'][ $step ], 1 )
			);
		}

		if ( empty( $parts ) ) {
			return;
		}

		printf(
			'<p class="mis-report-steps">%s</p>',
			esc_html( implode( ' · ', $parts ) )
		);
	}

	/**
	 * Render the list of top size savings (at most 10 entries).
	 *
	 * @param array $report Report data from the job result.
	 */
	private static function render_report_top_savings( array $report ): void {
		if ( empty( $report['top_savings'] ) || ! is_array( $report['top_savings'] ) ) {
			return;
		}

		$entries = array_slice( $report['top_savings'], 0, 10 );
		$items   = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['path'] ) || ! is_string( $entry['path'] ) ) {
				continue;
			}
			$original = isset( $entry['original_bytes'] ) && is_numeric( $entry['original_bytes'] ) ? (int) $entry['original_bytes'] : 0;
			$final    = isset( $entry['final_bytes'] ) && is_numeric( $entry['final_bytes'] ) ? (int) $entry['final_bytes'] : 0;
			if ( $original <= 0 ) {
				continue;
			}
			$saved   = round( ( 1 - $final / $original ) * 100, 1 );
			$items[] = array(
				'path'  => $entry['path'],
				'sizes' => sprintf(
					/* translators: %1$s: original size, %2$s: final size, %3$s: percentage saved. */
					__( '%1$s → %2$s (−%3$s%%)', 'make-it-static' ),
					size_format( $original ),
					size_format( $final ),
					number_format_i18n( $saved, 1 )
				),
			);
		}

		if ( empty( $items ) ) {
			return;
		}

		echo '<p class="mis-report-top-label">' . esc_html__( 'Top savings', 'make-it-static' ) . '</p>';
		echo '<ul class="mis-report-top">';
		foreach ( $items as $item ) {
			echo '<li><code>' . esc_html( $item['path'] ) . '</code> ' . esc_html( $item['sizes'] ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Format the size change between two byte counts as a signed percentage.
	 *
	 * Negative means the file got smaller (bytes saved).
	 *
	 * @param int $original_bytes Original size in bytes.
	 * @param int $final_bytes    Final size in bytes.
	 */
	private static function format_delta_pct( int $original_bytes, int $final_bytes ): string {
		if ( $original_bytes <= 0 ) {
			return '—';
		}
		$delta = round( ( $final_bytes / $original_bytes - 1 ) * 100, 1 );
		if ( 0.0 === $delta ) {
			return '0%';
		}
		return sprintf( '%+.1f%%', $delta );
	}

	/**
	 * CSS class for a delta value: green when bytes were saved.
	 *
	 * @param int $original_bytes Original size in bytes.
	 * @param int $final_bytes    Final size in bytes.
	 */
	private static function delta_class( int $original_bytes, int $final_bytes ): string {
		$class = 'mis-report-delta';
		if ( $original_bytes > 0 && $final_bytes < $original_bytes ) {
			$class .= ' mis-report-delta--saved';
		}
		return $class;
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
