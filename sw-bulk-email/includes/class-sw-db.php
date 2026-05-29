<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SW_BULK_EMAIL_TEMPLATES_TABLE' ) ) {
	define( 'SW_BULK_EMAIL_TEMPLATES_TABLE', 'sw_email_templates' );
}

if ( ! defined( 'SW_BULK_EMAIL_ARCHIVE_TABLE' ) ) {
	define( 'SW_BULK_EMAIL_ARCHIVE_TABLE', 'sw_email_archive' );
}

class SW_DB {

	/**
	 * Create the wp_sw_email_templates table.
	 */
	public static function create_templates_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . SW_BULK_EMAIL_TEMPLATES_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			template_name   VARCHAR(200)        NOT NULL,
			subject         TEXT                NOT NULL,
			body            LONGTEXT            NOT NULL,
			mail_type       VARCHAR(20)         NOT NULL, -- 'subscriber' or 'system'
			created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}


	/**
	 * Create the wp_sw_subscribers table on activation.
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . SW_BULK_EMAIL_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			email           VARCHAR(200)        NOT NULL,
			confirmed       TINYINT(1)          NOT NULL DEFAULT 0,
			ad_opt_in       TINYINT(1)          NOT NULL DEFAULT 0,
			opt_in_date     DATETIME                     DEFAULT NULL,
			unsubscribe_token VARCHAR(64)        NOT NULL,
			created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			UNIQUE KEY unsubscribe_token (unsubscribe_token)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'sw_bulk_email_db_version', SW_BULK_EMAIL_VERSION );
	}

	// -----------------------------------------------------------------------
	// Subscriber helpers
	// -----------------------------------------------------------------------

	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . SW_BULK_EMAIL_TABLE;
	}

	/**
	 * Insert a new pending subscriber. Returns the new row ID or false.
	 *
	 * @param string $email
	 * @param string $token  Unique unsubscribe / verification token.
	 * @return int|false
	 */
	public static function add_subscriber( string $email, string $token, bool $ad_opt_in = false ) {
		global $wpdb;
		$table = self::get_table_name();

		$result = $wpdb->insert(
			$table,
			[
				'email'             => $email,
				'confirmed'         => 0,
				'unsubscribe_token' => $token,
				'ad_opt_in'         => $ad_opt_in ? 1 : 0,
			],
			[ '%s', '%d', '%s', '%d' ]
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Confirm a subscriber by token. Returns true on success.
	 *
	 * @param string $token
	 * @return bool
	 */
	public static function confirm_subscriber( string $token ): bool {
		global $wpdb;
		$table = self::get_table_name();

		$updated = $wpdb->update(
			$table,
			[
				'confirmed'   => 1,
				'opt_in_date' => current_time( 'mysql' ),
			],
			[ 'unsubscribe_token' => $token, 'confirmed' => 0 ],
			[ '%d', '%s' ],
			[ '%s', '%d' ]
		);

		return $updated !== false && $updated > 0;
	}

	/**
	 * Unsubscribe (delete) a subscriber by token.
	 *
	 * @param string $token
	 * @return bool
	 */
	public static function delete_by_token( string $token ): bool {
		global $wpdb;
		$table = self::get_table_name();

		$deleted = $wpdb->delete( $table, [ 'unsubscribe_token' => $token ], [ '%s' ] );
		return $deleted !== false && $deleted > 0;
	}

	public static function delete_by_email( string $email ): bool {
		global $wpdb;
		$table = self::get_table_name();

		$deleted = $wpdb->delete( $table, [ 'email' => $email ], [ '%s' ] );
		return $deleted !== false && $deleted > 0;
	}

	public static function delete_by_id( int $id ): bool {
		global $wpdb;
		$table = self::get_table_name();

		$deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
		return $deleted !== false && $deleted > 0;
	}

	/**
	 * Fetch all confirmed subscribers (optionally paginated).
	 *
	 * @param int $limit
	 * @param int $offset
	 * @return array
	 */
	public static function get_confirmed_subscribers( int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, email, opt_in_date, unsubscribe_token FROM {$table} WHERE confirmed = 1 LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
	}

	/**
	 * Total count of confirmed subscribers.
	 *
	 * @return int
	 */
	public static function count_confirmed(): int {
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE confirmed = 1" );
	}

	/**
	 * Fetch all confirmed ad subscribers (optionally paginated).
	 *
	 * @param int $limit
	 * @param int $offset
	 * @return array
	 */
	public static function get_confirmed_ad_subscribers( int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, email, opt_in_date, unsubscribe_token FROM {$table} WHERE confirmed = 1 AND ad_opt_in = 1 LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
	}

	/**
	 * Total count of confirmed ad subscribers.
	 *
	 * @return int
	 */
	public static function count_confirmed_ad_subscribers(): int {
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE confirmed = 1 AND ad_opt_in = 1" );
	}

	/**
	 * Return only email addresses for ALL rows (confirmed + unconfirmed).
	 * Used by the system-mail batch to include every subscriber regardless of opt-in status.
	 *
	 * @return string[]
	 */
	public static function get_all_subscriber_emails(): array {
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_col( "SELECT DISTINCT email FROM {$table}" ) ?: [];
	}

	/**
	 * Look up a subscriber row by email.
	 *
	 * @param string $email
	 * @return array|null
	 */
	public static function get_by_email( string $email ): ?array {
		global $wpdb;
		$table = self::get_table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s LIMIT 1", $email ),
			ARRAY_A
		);

		return $row ?: null;
	}

	// -----------------------------------------------------------------------
	// Template helpers
	// -----------------------------------------------------------------------

	public static function get_templates( string $mail_type ): array {
		global $wpdb;
		$table = $wpdb->prefix . SW_BULK_EMAIL_TEMPLATES_TABLE;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, template_name FROM {$table} WHERE mail_type = %s ORDER BY template_name ASC",
				$mail_type
			),
			ARRAY_A
		);
	}

	public static function get_template( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . SW_BULK_EMAIL_TEMPLATES_TABLE;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	public static function save_template( string $name, string $subject, string $body, string $mail_type ): int {
		global $wpdb;
		$table = $wpdb->prefix . SW_BULK_EMAIL_TEMPLATES_TABLE;

		$wpdb->insert(
			$table,
			[
				'template_name' => $name,
				'subject'       => $subject,
				'body'          => $body,
				'mail_type'     => $mail_type,
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		return $wpdb->insert_id;
	}

	public static function delete_template( int $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . SW_BULK_EMAIL_TEMPLATES_TABLE;

		$deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
		return $deleted !== false && $deleted > 0;
	}

	public static function set_ad_opt_in_by_email( string $email, bool $status ): bool {
		global $wpdb;
		$table = self::get_table_name();

		$updated = $wpdb->update(
			$table,
			[ 'ad_opt_in' => $status ? 1 : 0 ],
			[ 'email' => $email ],
			[ '%d' ],
			[ '%s' ]
		);

		return $updated !== false;
	}

	// -----------------------------------------------------------------------
	// Archive helpers
	// -----------------------------------------------------------------------

	/**
	 * Create the wp_sw_email_archive table.
	 */
	public static function create_archive_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . SW_BULK_EMAIL_ARCHIVE_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			subject      TEXT NOT NULL,
			body         LONGTEXT NOT NULL,
			mail_type    VARCHAR(20) NOT NULL DEFAULT 'subscriber',
			status       VARCHAR(20) NOT NULL DEFAULT 'sent',
			sent_count   INT UNSIGNED NOT NULL DEFAULT 0,
			failed_count INT UNSIGNED NOT NULL DEFAULT 0,
			is_public    TINYINT(1) NOT NULL DEFAULT 1,
			created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Save a new archive entry. Returns the new row ID.
	 */
	public static function archive_save( string $subject, string $body, string $mail_type, string $status = 'sent' ): int {
		global $wpdb;
		$table  = $wpdb->prefix . SW_BULK_EMAIL_ARCHIVE_TABLE;
		$status = in_array( $status, [ 'draft', 'sent' ], true ) ? $status : 'sent';

		$result = $wpdb->insert(
			$table,
			compact( 'subject', 'body', 'mail_type', 'status' ),
			[ '%s', '%s', '%s', '%s' ]
		);

		// INSERT 실패 시 status 컬럼 누락 여부를 확인하고 컬럼을 추가한 뒤 재시도.
		if ( false === $result ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'status'" ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'sent' AFTER `mail_type`" );
			}
			$wpdb->insert(
				$table,
				compact( 'subject', 'body', 'mail_type', 'status' ),
				[ '%s', '%s', '%s', '%s' ]
			);
		}

		return (int) $wpdb->insert_id;
	}

	public static function archive_update_status( int $id, string $status ): bool {
		global $wpdb;
		$table = $wpdb->prefix . SW_BULK_EMAIL_ARCHIVE_TABLE;

		$updated = $wpdb->update(
			$table,
			[ 'status' => in_array( $status, [ 'draft', 'sent' ], true ) ? $status : 'sent' ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);

		return $updated !== false;
	}

	/**
	 * Update sent/failed stats for an archive entry.
	 */
	public static function archive_update_stats( int $id, int $sent, int $failed ): bool {
		global $wpdb;
		$table = $wpdb->prefix . SW_BULK_EMAIL_ARCHIVE_TABLE;

		$updated = $wpdb->update(
			$table,
			[
				'sent_count'   => $sent,
				'failed_count' => $failed,
			],
			[ 'id' => $id ],
			[ '%d', '%d' ],
			[ '%d' ]
		);

		return $updated !== false;
	}

	/**
	 * Update subject and body of an archive entry.
	 */
	public static function archive_update_content( int $id, string $subject, string $body ): bool {
		global $wpdb;
		$table = $wpdb->prefix . SW_BULK_EMAIL_ARCHIVE_TABLE;

		$updated = $wpdb->update(
			$table,
			[
				'subject' => $subject,
				'body'    => $body,
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		return $updated !== false;
	}

	/**
	 * Get a single archive entry by ID.
	 */
	public static function archive_get( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . SW_BULK_EMAIL_ARCHIVE_TABLE;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get a paginated list of archive entries (newest first).
	 */
	public static function archive_list( int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . SW_BULK_EMAIL_ARCHIVE_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, subject, mail_type, status, sent_count, failed_count, is_public, created_at FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Total count of archive entries.
	 */
	public static function archive_count(): int {
		global $wpdb;
		$table = $wpdb->prefix . SW_BULK_EMAIL_ARCHIVE_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Delete an archive entry by ID.
	 */
	public static function archive_delete( int $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . SW_BULK_EMAIL_ARCHIVE_TABLE;

		$deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
		return $deleted !== false && $deleted > 0;
	}

	/**
	 * Get public archive entries (is_public = 1), paginated.
	 */
	public static function archive_list_public( int $limit = 10, int $offset = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . SW_BULK_EMAIL_ARCHIVE_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, subject, mail_type, sent_count, created_at FROM {$table} WHERE is_public = 1 ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Total count of public archive entries.
	 */
	public static function archive_count_public(): int {
		global $wpdb;
		$table = $wpdb->prefix . SW_BULK_EMAIL_ARCHIVE_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_public = 1" );
	}

	/**
	 * Toggle the is_public flag for an archive entry.
	 */
	public static function archive_toggle_public( int $id, bool $public ): bool {
		global $wpdb;
		$table = $wpdb->prefix . SW_BULK_EMAIL_ARCHIVE_TABLE;

		$updated = $wpdb->update(
			$table,
			[ 'is_public' => $public ? 1 : 0 ],
			[ 'id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);

		return $updated !== false;
	}
}
