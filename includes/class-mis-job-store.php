<?php
/**
 * Persistence layer for Make it Static jobs (custom table {$wpdb->prefix}mis_jobs).
 *
 * @package Make_It_Static
 */

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for the mis_jobs table.
 */
class MIS_Job_Store {

	/**
	 * Terminal job statuses (RQ).
	 *
	 * @var string[]
	 */
	const TERMINAL = array( 'finished', 'failed', 'stopped', 'canceled' );

	/**
	 * All statuses the store may hold.
	 *
	 * @var string[]
	 */
	const STATUSES = array(
		'queued',
		'started',
		'deferred',
		'scheduled',
		'finished',
		'failed',
		'stopped',
		'canceled',
		'expired',
	);

	/**
	 * Fully-qualified table name.
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mis_jobs';
	}

	/**
	 * Create the jobs table. Runs on plugin activation.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id VARCHAR(64) NOT NULL,
			post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			url TEXT NULL,
			payload LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			result LONGTEXT NULL,
			error TEXT NULL,
			attempts INT(10) UNSIGNED NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY job_id (job_id),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Insert a new job row.
	 *
	 * @param array $data Column values; payload/result may be arrays.
	 * @return int|false Row id on success, false on failure.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$row = array(
			'job_id'     => isset( $data['job_id'] ) ? (string) $data['job_id'] : '',
			'post_id'    => isset( $data['post_id'] ) ? absint( $data['post_id'] ) : 0,
			'url'        => isset( $data['url'] ) ? (string) $data['url'] : null,
			'payload'    => self::encode_json( $data['payload'] ?? null ),
			'status'     => self::sanitize_status( $data['status'] ?? 'queued' ),
			'result'     => self::encode_json( $data['result'] ?? null ),
			'error'      => isset( $data['error'] ) ? (string) $data['error'] : null,
			'attempts'   => isset( $data['attempts'] ) ? max( 1, absint( $data['attempts'] ) ) : 1,
			'created_at' => $now,
			'updated_at' => $now,
		);

		$formats = array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom-table insert; WordPress has no higher-level API for custom tables.
		$inserted = $wpdb->insert( self::table(), $row, $formats );

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing row; always touches updated_at.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Column values; payload/result may be arrays.
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;

		$allowed = array( 'job_id', 'post_id', 'url', 'payload', 'status', 'result', 'error', 'attempts' );
		$row     = array();
		$formats = array();

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}

			switch ( $key ) {
				case 'post_id':
				case 'attempts':
					$row[ $key ] = absint( $data[ $key ] );
					$formats[]   = '%d';
					break;
				case 'payload':
				case 'result':
					$row[ $key ] = self::encode_json( $data[ $key ] );
					$formats[]   = '%s';
					break;
				case 'status':
					$row[ $key ] = self::sanitize_status( $data[ $key ] );
					$formats[]   = '%s';
					break;
				default:
					$row[ $key ] = null === $data[ $key ] ? null : (string) $data[ $key ];
					$formats[]   = '%s';
			}
		}

		$row['updated_at'] = current_time( 'mysql' );
		$formats[]         = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write-through; a cached copy would serve stale rows to the 30s poller.
		$updated = $wpdb->update( self::table(), $row, array( 'id' => $id ), $formats, array( '%d' ) );

		return false !== $updated;
	}

	/**
	 * Fetch a row by its internal id.
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix . 'mis_jobs', never user input; fresh rows are required every poll.
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ? self::normalize( $row ) : null;
	}

	/**
	 * Fetch the newest row for a given service-side job id.
	 */
	public static function get_by_job_id( string $job_id ): ?array {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix . 'mis_jobs', never user input; fresh rows are required every poll.
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %s ORDER BY id DESC LIMIT 1", $job_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ? self::normalize( $row ) : null;
	}

	/**
	 * Most recent rows, newest first.
	 *
	 * @param int $limit Max rows.
	 * @return array[]
	 */
	public static function get_recent( int $limit = 50 ): array {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix . 'mis_jobs', never user input; fresh rows are required every poll.
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map( array( self::class, 'normalize' ), $rows ? $rows : array() );
	}

	/**
	 * Rows that still need polling: non-terminal and not expired.
	 *
	 * @return array[]
	 */
	public static function get_active(): array {
		global $wpdb;

		$table        = self::table();
		$inactive     = array_merge( self::TERMINAL, array( 'expired' ) );
		$placeholders = implode( ', ', array_fill( 0, count( $inactive ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix . 'mis_jobs', never user input; fresh rows are required every poll.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated from the value count and all values are prepared.
				"SELECT * FROM {$table} WHERE status NOT IN ({$placeholders}) ORDER BY id ASC",
				$inactive
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'normalize' ), $rows ? $rows : array() );
	}

	/**
	 * Number of rows still needing polling.
	 */
	public static function count_active(): int {
		global $wpdb;

		$table        = self::table();
		$inactive     = array_merge( self::TERMINAL, array( 'expired' ) );
		$placeholders = implode( ', ', array_fill( 0, count( $inactive ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix . 'mis_jobs', never user input; fresh rows are required every poll.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated from the value count and all values are prepared.
				"SELECT COUNT(*) FROM {$table} WHERE status NOT IN ({$placeholders})",
				$inactive
			)
		);
	}

	/**
	 * Whether a status is terminal.
	 */
	public static function is_terminal( string $status ): bool {
		return in_array( $status, self::TERMINAL, true );
	}

	/**
	 * JSON-encode array values for storage; pass through strings/null.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function encode_json( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value );
		}

		return (string) $value;
	}

	/**
	 * Restrict statuses to the known set.
	 *
	 * @param mixed $status Raw status.
	 */
	private static function sanitize_status( $status ): string {
		$status = (string) $status;
		return in_array( $status, self::STATUSES, true ) ? $status : 'queued';
	}

	/**
	 * Cast a raw DB row and decode its JSON columns.
	 *
	 * @param array $row Raw row from $wpdb.
	 */
	private static function normalize( array $row ): array {
		$row['id']       = (int) $row['id'];
		$row['post_id']  = (int) $row['post_id'];
		$row['attempts'] = (int) $row['attempts'];
		$row['payload']  = self::decode_json( $row['payload'] ?? null );
		$row['result']   = self::decode_json( $row['result'] ?? null );

		return $row;
	}

	/**
	 * Decode a stored JSON column back to an array (or null).
	 *
	 * @param string|null $value Stored value.
	 */
	private static function decode_json( ?string $value ): ?array {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
