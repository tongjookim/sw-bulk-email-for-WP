<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the front-end unsubscribe URL:
 *   /?sw_unsubscribe=<token>
 */
class SW_Unsubscribe {

	public function __construct() {
		add_action( 'init', [ $this, 'handle_unsubscribe' ] );
	}

	public function handle_unsubscribe() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['sw_unsubscribe'] ) ? sanitize_text_field( wp_unslash( $_GET['sw_unsubscribe'] ) ) : '';

		if ( empty( $token ) ) {
			return;
		}

		$deleted = SW_DB::delete_by_token( $token );

		wp_die(
			$deleted
				? esc_html__( 'You have been successfully unsubscribed.', 'sw-bulk-email' )
				: esc_html__( 'Invalid or already-used unsubscribe link.', 'sw-bulk-email' ),
			esc_html__( 'Unsubscribe', 'sw-bulk-email' ),
			[ 'response' => 200, 'back_link' => true ]
		);
	}

	/**
	 * Build the unsubscribe URL for a given token.
	 *
	 * @param string $token
	 * @return string
	 */
	public static function unsubscribe_url( string $token ): string {
		return add_query_arg( 'sw_unsubscribe', rawurlencode( $token ), home_url( '/' ) );
	}
}
