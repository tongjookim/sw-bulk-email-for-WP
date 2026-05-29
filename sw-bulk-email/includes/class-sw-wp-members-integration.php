<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles integration with the WP-Members plugin.
 */
class SW_WP_Members_Integration {

	public function __construct() {
		add_filter( 'wpmem_fields',           [ $this, 'add_fields'             ], 10, 2 );
		add_action( 'wpmem_post_register_data', [ $this, 'handle_registration'  ] );
		add_action( 'wpmem_post_update_data',   [ $this, 'handle_profile_update'], 10, 2 );
	}

	/**
	 * Inject opt-in checkboxes into both the registration and profile-edit forms.
	 *
	 * wpmem_fields fires with $toggle = 'register' (new sign-up) or 'profile' (edit).
	 * For profile display, user meta is the canonical source that WP-Members reads.
	 * If a subscriber exists in our DB but has no meta yet (e.g. registered before
	 * this feature), we populate the meta here so the checkbox appears pre-checked.
	 *
	 * @param array  $fields
	 * @param string $toggle 'register' | 'profile' | 'all' | …
	 * @return array
	 */
	public function add_fields( array $fields, string $toggle ): array {
		if ( 'register' !== $toggle && 'profile' !== $toggle ) {
			return $fields;
		}

		// Lazy meta migration for existing subscribers viewing profile edit.
		if ( 'profile' === $toggle ) {
			$user_id = get_current_user_id();
			if ( $user_id ) {
				$user = get_userdata( $user_id );
				if ( $user && is_email( $user->user_email ) ) {
					$row = SW_DB::get_by_email( $user->user_email );
					if ( $row && ! get_user_meta( $user_id, 'sw_subscribe_newsletter', true ) ) {
						update_user_meta( $user_id, 'sw_subscribe_newsletter', '1' );
						if ( ! empty( $row['ad_opt_in'] ) ) {
							update_user_meta( $user_id, 'sw_subscribe_ad', '1' );
						}
					}
				}
			}
		}

		if ( ! isset( $fields['sw_subscribe_newsletter'] ) ) {
			$fields['sw_subscribe_newsletter'] = [
				'label'           => __( '뉴스레터 구독 동의', 'sw-bulk-email' ),
				'type'            => 'checkbox',
				'checked_value'   => '1',
				'checked_default' => false,
				'register'        => true,
				'required'        => false,
				'profile'         => true,
				'native'          => false,
			];
		}

		if ( ! isset( $fields['sw_subscribe_ad'] ) ) {
			$fields['sw_subscribe_ad'] = [
				'label'           => __( '광고성 정보 수신 동의 (선택)', 'sw-bulk-email' ),
				'type'            => 'checkbox',
				'checked_value'   => '1',
				'checked_default' => false,
				'register'        => true,
				'required'        => false,
				'profile'         => true,
				'native'          => false,
			];
		}

		return $fields;
	}

	/**
	 * Process opt-in fields after new WP-Members registration.
	 *
	 * wpmem_post_register_data passes the full $post_data array.
	 * $post_data['ID']                    = new user ID
	 * $post_data['user_email']            = submitted email
	 * $post_data['sw_subscribe_newsletter'] = '1' if checked, '' if not
	 * $post_data['sw_subscribe_ad']         = '1' if checked, '' if not
	 *
	 * @param array $post_data
	 */
	public function handle_registration( array $post_data ) {
		if ( empty( $post_data['sw_subscribe_newsletter'] ) ) {
			return;
		}

		$user_id = isset( $post_data['ID'] ) ? (int) $post_data['ID'] : 0;
		$email   = isset( $post_data['user_email'] ) ? sanitize_email( $post_data['user_email'] ) : '';

		if ( ! $user_id || ! is_email( $email ) ) {
			return;
		}

		$ad_opt_in = ! empty( $post_data['sw_subscribe_ad'] );

		// Persist to user meta so the profile-edit page reflects the correct state.
		update_user_meta( $user_id, 'sw_subscribe_newsletter', '1' );
		if ( $ad_opt_in ) {
			update_user_meta( $user_id, 'sw_subscribe_ad', '1' );
		}

		if ( ! SW_DB::get_by_email( $email ) ) {
			$token = bin2hex( random_bytes( 32 ) );
			SW_DB::add_subscriber( $email, $token, $ad_opt_in );
			SW_DB::confirm_subscriber( $token );
		} else {
			SW_DB::set_ad_opt_in_by_email( $email, $ad_opt_in );
		}
	}

	/**
	 * Sync our subscribers table when a user updates their profile.
	 *
	 * wpmem_post_update_data passes ($post_data, $user_id, $prev_data).
	 * WP-Members already saves user meta for all profile fields automatically,
	 * so here we only need to keep our custom table in sync.
	 *
	 * @param array $post_data
	 * @param int   $user_id
	 */
	public function handle_profile_update( array $post_data, int $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		$email             = $user->user_email;
		$newsletter_opt_in = ! empty( $post_data['sw_subscribe_newsletter'] );
		$ad_opt_in         = ! empty( $post_data['sw_subscribe_ad'] );

		if ( $newsletter_opt_in ) {
			if ( ! SW_DB::get_by_email( $email ) ) {
				$token = bin2hex( random_bytes( 32 ) );
				SW_DB::add_subscriber( $email, $token, $ad_opt_in );
				SW_DB::confirm_subscriber( $token );
			} else {
				SW_DB::set_ad_opt_in_by_email( $email, $ad_opt_in );
			}
		} else {
			// User explicitly unchecked newsletter opt-in — remove from subscribers.
			SW_DB::delete_by_email( $email );
		}
	}
}
