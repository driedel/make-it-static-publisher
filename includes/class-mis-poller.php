<?php
/**
 * WP-Cron poller for Make it Static jobs.
 *
 * @package Make_It_Static
 */

defined( 'ABSPATH' ) || exit;

/**
 * Polls the service for the status of active jobs and drives retries.
 */
class MIS_Poller {

	const CRON_HOOK     = 'mis_poll_jobs';
	const CRON_SCHEDULE = 'mis_30s';

	/**
	 * Wire the cron schedule and the poll hook.
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'poll' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Add the custom 30-second schedule.
	 *
	 * @param array $schedules Existing schedules.
	 */
	public static function add_cron_schedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => 30,
				'display'  => __( 'Every 30 seconds (Make it Static)', 'make-it-static-publisher' ),
			);
		}

		return $schedules;
	}

	/**
	 * Schedule the recurring poll event (plugin activation).
	 */
	public static function activate(): void {
		// Activation runs after plugins_loaded, so register() never ran and the
		// custom schedule is unknown to wp_schedule_event() without this filter.
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedule' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Clear the recurring poll event (plugin deactivation).
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Poll every active job. A failure on one job must not affect the rest.
	 */
	public static function poll(): void {
		$rows = MIS_Job_Store::get_active();

		if ( empty( $rows ) ) {
			return;
		}

		$client      = new MIS_API_Client();
		$retry_count = (int) MIS_Settings::get( 'retry_count', 3 );

		foreach ( $rows as $row ) {
			try {
				self::poll_row( $row, $client, $retry_count );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Defensive: never let a single bad row break the poll run.
			}
		}
	}

	/**
	 * Poll a single job row and reconcile its status with the service.
	 *
	 * @param array          $row         Normalized store row.
	 * @param MIS_API_Client $client      API client.
	 * @param int            $retry_count Max republish attempts.
	 */
	private static function poll_row( array $row, MIS_API_Client $client, int $retry_count ): void {
		$job_id = isset( $row['job_id'] ) ? (string) $row['job_id'] : '';

		if ( '' === $job_id ) {
			// A row without a service job id can never finish polling.
			MIS_Job_Store::update(
				(int) $row['id'],
				array(
					'status' => 'failed',
					'error'  => __( 'Job row has no remote job ID.', 'make-it-static-publisher' ),
				)
			);
			return;
		}

		$job = $client->get_job( $job_id );

		if ( is_wp_error( $job ) ) {
			if ( 'mis_job_not_found' === $job->get_error_code() ) {
				// Results expire server-side ~1h after the job ends.
				MIS_Job_Store::update( (int) $row['id'], array( 'status' => 'expired' ) );
			}
			// Any other error is treated as transient; retry on the next run.
			return;
		}

		$status = isset( $job['status'] ) ? (string) $job['status'] : '';

		if ( '' === $status ) {
			return;
		}

		if ( ! MIS_Job_Store::is_terminal( $status ) ) {
			MIS_Job_Store::update( (int) $row['id'], array( 'status' => $status ) );
			return;
		}

		switch ( $status ) {
			case 'finished':
				MIS_Job_Store::update(
					(int) $row['id'],
					array(
						'status' => 'finished',
						'result' => isset( $job['result'] ) && is_array( $job['result'] ) ? $job['result'] : null,
						'error'  => null,
					)
				);
				break;

			case 'failed':
				if ( (int) $row['attempts'] < $retry_count ) {
					$republished = MIS_Publisher::republish_row( $row );

					if ( is_wp_error( $republished ) ) {
						MIS_Job_Store::update(
							(int) $row['id'],
							array(
								'status' => 'failed',
								'error'  => $republished->get_error_message(),
							)
						);
					}
					// On success republish_row() updates the row itself.
					return;
				}

				MIS_Job_Store::update(
					(int) $row['id'],
					array(
						'status' => 'failed',
						'error'  => __( 'Job failed — check server logs. On the Make it Static host, run `docker compose logs worker` for details.', 'make-it-static-publisher' ),
					)
				);
				break;

			default:
				// stopped / canceled.
				$update = array( 'status' => $status );

				if ( ! empty( $job['error'] ) && is_string( $job['error'] ) ) {
					$update['error'] = $job['error'];
				}

				MIS_Job_Store::update( (int) $row['id'], $update );
		}
	}
}
