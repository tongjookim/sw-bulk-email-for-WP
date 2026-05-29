<?php
/**
 * Plugin Name:       SW Bulk Email & Opt-in
 * Plugin URI:        https://github.com/tongjookim/sw-bulk-email-for-WP.git
 * Description:       A bulk email and double opt-in management plugin that integrates perfectly with WP Mail SMTP.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            The Suwan News Company
 * Author URI:        https://www.swn.kr
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sw-bulk-email
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SW_BULK_EMAIL_VERSION', '1.0.3' );
define( 'SW_BULK_EMAIL_DIR', plugin_dir_path( __FILE__ ) );
define( 'SW_BULK_EMAIL_URL', plugin_dir_url( __FILE__ ) );
define( 'SW_BULK_EMAIL_TABLE', 'sw_subscribers' );
define( 'SW_BULK_EMAIL_TEMPLATES_TABLE', 'sw_email_templates' );

// ---------------------------------------------------------------------------
// Activation / Deactivation
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, 'sw_bulk_email_activate' );
register_deactivation_hook( __FILE__, 'sw_bulk_email_deactivate' );

function sw_bulk_email_activate() {
	require_once SW_BULK_EMAIL_DIR . 'includes/class-sw-db.php';
	SW_DB::create_table();
	SW_DB::create_templates_table();
	SW_DB::create_archive_table();
	if ( ! get_option( 'sw_bulk_email_embed_token' ) ) {
		update_option( 'sw_bulk_email_embed_token', bin2hex( random_bytes( 16 ) ) );
	}
	flush_rewrite_rules();
}

function sw_bulk_email_deactivate() {
	flush_rewrite_rules();
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', 'sw_bulk_email_init' );

function sw_bulk_email_init() {
	add_action( 'template_redirect', 'sw_bulk_email_render_embed_form' );

	load_plugin_textdomain( 'sw-bulk-email', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	require_once SW_BULK_EMAIL_DIR . 'includes/class-sw-db.php';
	require_once SW_BULK_EMAIL_DIR . 'includes/class-sw-unsubscribe.php';
	require_once SW_BULK_EMAIL_DIR . 'includes/class-sw-email-footer.php';
	require_once SW_BULK_EMAIL_DIR . 'includes/class-sw-mailer.php';
	require_once SW_BULK_EMAIL_DIR . 'includes/class-sw-optin.php';

	if ( is_admin() ) {
		require_once SW_BULK_EMAIL_DIR . 'admin/class-sw-admin.php';
		require_once SW_BULK_EMAIL_DIR . 'admin/class-sw-footer-settings.php';
		require_once SW_BULK_EMAIL_DIR . 'admin/class-sw-archive-page.php';
		new SW_Admin();
		new SW_Footer_Settings();
	}

	require_once SW_BULK_EMAIL_DIR . 'includes/class-sw-archive-shortcode.php';
	new SW_Archive_Shortcode();

	new SW_Optin();
	new SW_Unsubscribe();

	// Conditionally load WP-Members integration.
	if ( function_exists( 'wpmem_init' ) ) {
		require_once SW_BULK_EMAIL_DIR . 'includes/class-sw-wp-members-integration.php';
		new SW_WP_Members_Integration();
	}
}

// ---------------------------------------------------------------------------
// Rewrite Rules
// ---------------------------------------------------------------------------
add_action( 'init', 'sw_bulk_email_rewrite_rules' );

function sw_bulk_email_rewrite_rules() {
	add_rewrite_tag( '%sw_embed_form%', 'true' );
	add_rewrite_rule( '^sw-embed-form/?', 'index.php?sw_embed_form=true', 'top' );
}

// ---------------------------------------------------------------------------
// Embed Form Renderer
// ---------------------------------------------------------------------------

function sw_bulk_email_render_embed_form() {
	if ( get_query_var( 'sw_embed_form' ) ) {
		require_once SW_BULK_EMAIL_DIR . 'public/embed-form-template.php';
		exit;
	}
}


// ---------------------------------------------------------------------------
// DB version check
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', 'sw_bulk_email_check_db' );

function sw_bulk_email_check_db() {
	global $wpdb;

	$archive_table = $wpdb->prefix . 'sw_email_archive';

	// v1.0.3 status 컬럼 — 버전 체크와 별개로 항상 존재 여부를 확인.
	// dbDelta()나 이전 마이그레이션이 실패했을 경우를 대비한 안전장치.
	// 컬럼이 있으면 SHOW COLUMNS 결과가 1행이므로 참, 없으면 null.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `{$archive_table}` LIKE 'status'" ) ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$archive_table}` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'sent' AFTER `mail_type`" );
	}

	if ( get_option( 'sw_bulk_email_db_version' ) !== SW_BULK_EMAIL_VERSION ) {
		require_once SW_BULK_EMAIL_DIR . 'includes/class-sw-db.php';
		SW_DB::create_table();
		SW_DB::create_templates_table();
		SW_DB::create_archive_table();
		update_option( 'sw_bulk_email_db_version', SW_BULK_EMAIL_VERSION );
	}
}
