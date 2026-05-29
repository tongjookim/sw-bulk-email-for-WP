<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central mail-sending helper.
 *
 * - Reads WP Mail SMTP settings (via its Options class when available, or raw
 *   option fallback) to surface the correct From name/email in the admin UI.
 * - All actual delivery still goes through wp_mail() so WP Mail SMTP's SMTP
 *   transport, logging, and overrides all apply automatically.
 */
class SW_Mailer {

	// -----------------------------------------------------------------------
	// Sender info
	// -----------------------------------------------------------------------

	/**
	 * Return the effective sender info, preferring WP Mail SMTP values.
	 *
	 * @return array{from_email: string, from_name: string, force_email: bool, force_name: bool, smtp_active: bool}
	 */
	public static function get_sender_info(): array {
		// Use WP Mail SMTP's own Options class when available — it respects
		// WPMS_ constants that override DB values.
		if ( class_exists( '\WPMailSMTP\Options' ) ) {
			$opts        = \WPMailSMTP\Options::init();
			$from_email  = (string) $opts->get( 'mail', 'from_email' );
			$from_name   = (string) $opts->get( 'mail', 'from_name' );
			$force_email = (bool)   $opts->get( 'mail', 'from_email_force' );
			$force_name  = (bool)   $opts->get( 'mail', 'from_name_force' );
			$smtp_active = true;
		} else {
			// Fallback: raw option array (plugin inactive / not installed).
			$raw         = get_option( 'wp_mail_smtp', [] );
			$smtp_active = ! empty( $raw );
			$from_email  = ! empty( $raw['mail']['from_email'] ) ? $raw['mail']['from_email'] : get_option( 'admin_email' );
			$from_name   = ! empty( $raw['mail']['from_name'] )  ? $raw['mail']['from_name']  : get_bloginfo( 'name' );
			$force_email = ! empty( $raw['mail']['from_email_force'] );
			$force_name  = ! empty( $raw['mail']['from_name_force'] );
		}

		return compact( 'from_email', 'from_name', 'force_email', 'force_name', 'smtp_active' );
	}

	// -----------------------------------------------------------------------
	// Header builder
	// -----------------------------------------------------------------------

	/**
	 * Build HTML email headers.
	 * Only injects a From header when WP Mail SMTP is not already forcing one,
	 * to avoid conflicts with SMTP provider requirements.
	 *
	 * @return string[]
	 */
	public static function build_headers(): array {
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		$info    = self::get_sender_info();

		if ( ! $info['force_email'] ) {
			$from      = ! empty( $info['from_name'] )
				? $info['from_name'] . ' <' . $info['from_email'] . '>'
				: $info['from_email'];
			$headers[] = 'From: ' . $from;
		}

		return $headers;
	}

	// -----------------------------------------------------------------------
	// Send methods
	// -----------------------------------------------------------------------

	/**
	 * General-purpose HTML send via wp_mail().
	 *
	 * @param string $to
	 * @param string $subject
	 * @param string $body  HTML body.
	 * @return bool
	 */
	public static function send( string $to, string $subject, string $body ): bool {
		$footer = SW_Email_Footer::get_html();
		return wp_mail( $to, $subject, $body . $footer, self::build_headers() );
	}

	/**
	 * Send a subscriber (opted-in) email with the public footer + unsubscribe link.
	 *
	 * @param string $to
	 * @param string $subject
	 * @param string $body
	 * @param string $token  Subscriber's unique unsubscribe token.
	 * @return bool
	 */
	public static function send_subscribed( string $to, string $subject, string $body, string $token ): bool {
		$footer = SW_Email_Footer::get_html( SW_Unsubscribe::unsubscribe_url( $token ) );
		return wp_mail( $to, $subject, $body . $footer, self::build_headers() );
	}
}
