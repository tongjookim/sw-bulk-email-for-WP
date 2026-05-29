<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles:
 *  - [sw_optin_form] shortcode
 *  - Form submission (POST)
 *  - Confirmation link (?sw_confirm=<token>)
 *  - Confirmation email sending
 */
class SW_Optin {

	public function __construct() {
		add_shortcode( 'sw_optin_form', [ $this, 'render_form' ] );
		add_action( 'init', [ $this, 'handle_confirm' ] );
		add_action( 'init', [ $this, 'handle_form_post' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );

		// External embed AJAX (no login required, token-validated)
		add_action( 'wp_ajax_sw_embed_subscribe',        [ $this, 'ajax_embed_subscribe' ] );
		add_action( 'wp_ajax_nopriv_sw_embed_subscribe', [ $this, 'ajax_embed_subscribe' ] );
	}

	public function enqueue_styles() {
		wp_enqueue_style(
			'sw-bulk-email-public',
			SW_BULK_EMAIL_URL . 'public/css/sw-public.css',
			[],
			SW_BULK_EMAIL_VERSION
		);
	}

	// -----------------------------------------------------------------------
	// Shortcode
	// -----------------------------------------------------------------------

	public function render_form( $atts ): string {
		// Allow per-shortcode button label override.
		$atts = shortcode_atts( [ 'button' => __( 'Subscribe', 'sw-bulk-email' ) ], $atts, 'sw_optin_form' );

		// Show success/error messages passed via query string after redirect.
		$message = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['sw_optin'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$status = sanitize_key( $_GET['sw_optin'] );
			if ( 'pending' === $status ) {
				$message = '<p class="sw-optin-notice sw-optin-notice--success">'
					. esc_html__( 'Almost there! Please check your email and click the confirmation link.', 'sw-bulk-email' )
					. '</p>';
			} elseif ( 'exists' === $status ) {
				$message = '<p class="sw-optin-notice sw-optin-notice--info">'
					. esc_html__( 'This email address is already subscribed or awaiting confirmation.', 'sw-bulk-email' )
					. '</p>';
			} elseif ( 'error' === $status ) {
				$message = '<p class="sw-optin-notice sw-optin-notice--error">'
					. esc_html__( 'Something went wrong. Please try again.', 'sw-bulk-email' )
					. '</p>';
			}
		}

		ob_start();
		?>
		<div class="sw-optin-wrap">
			<?php echo wp_kses_post( $message ); ?>
			<form class="sw-optin-form" method="post" action="">
				<?php wp_nonce_field( 'sw_optin_subscribe', 'sw_optin_nonce' ); ?>
				<input type="hidden" name="sw_optin_action" value="subscribe" />
				<label for="sw_optin_email" class="sw-optin-label">
					<?php esc_html_e( 'Email address', 'sw-bulk-email' ); ?>
				</label>
				<input
					type="email"
					id="sw_optin_email"
					name="sw_optin_email"
					class="sw-optin-input"
					required
					placeholder="<?php esc_attr_e( 'you@example.com', 'sw-bulk-email' ); ?>"
				/>
				<div class="sw-optin-checkbox-wrap">
					<input type="checkbox" id="sw_optin_ad" name="sw_optin_ad" value="1" />
					<label for="sw_optin_ad">[선택] 광고 및 프로모션 정보 수신에 동의합니다.</label>
				</div>
				<button type="submit" class="sw-optin-btn">
					<?php echo esc_html( $atts['button'] ); ?>
				</button>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	// -----------------------------------------------------------------------
	// Form POST handler
	// -----------------------------------------------------------------------

	public function handle_form_post() {
		if (
			! isset( $_POST['sw_optin_action'] )
			|| 'subscribe' !== $_POST['sw_optin_action']
		) {
			return;
		}

		// Verify nonce.
		if (
			! isset( $_POST['sw_optin_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sw_optin_nonce'] ) ), 'sw_optin_subscribe' )
		) {
			wp_die( esc_html__( 'Security check failed.', 'sw-bulk-email' ) );
		}

		$email = isset( $_POST['sw_optin_email'] )
			? sanitize_email( wp_unslash( $_POST['sw_optin_email'] ) )
			: '';

		$ad_opt_in = ( isset( $_POST['sw_optin_ad'] ) && '1' === $_POST['sw_optin_ad'] );

		if ( ! is_email( $email ) ) {
			$this->redirect_back( 'error' );
		}

		// Check for duplicates.
		$existing = SW_DB::get_by_email( $email );
		if ( $existing ) {
			$this->redirect_back( 'exists' );
		}

		$token = $this->generate_token();
		$id    = SW_DB::add_subscriber( $email, $token, $ad_opt_in );

		if ( ! $id ) {
			$this->redirect_back( 'error' );
		}

		$this->send_confirmation_email( $email, $token );
		$this->redirect_back( 'pending' );
	}

	private function redirect_back( string $status ): void {
		$url = add_query_arg( 'sw_optin', $status, wp_get_referer() ?: home_url( '/' ) );
		wp_safe_redirect( $url );
		exit;
	}

	// -----------------------------------------------------------------------
	// Confirmation link handler
	// -----------------------------------------------------------------------

	public function handle_confirm() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['sw_confirm'] ) ? sanitize_text_field( wp_unslash( $_GET['sw_confirm'] ) ) : '';

		if ( empty( $token ) ) {
			return;
		}

		$confirmed = SW_DB::confirm_subscriber( $token );

		wp_die(
			$confirmed
				? esc_html__( 'Thank you! Your subscription has been confirmed.', 'sw-bulk-email' )
				: esc_html__( 'This confirmation link is invalid or has already been used.', 'sw-bulk-email' ),
			esc_html__( 'Subscription Confirmed', 'sw-bulk-email' ),
			[ 'response' => 200, 'back_link' => true ]
		);
	}

	// -----------------------------------------------------------------------
	// Email helper
	// -----------------------------------------------------------------------

	private function send_confirmation_email( string $email, string $token ): void {
		$confirm_url = add_query_arg( 'sw_confirm', rawurlencode( $token ), home_url( '/' ) );
		$site_name   = get_bloginfo( 'name' );

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Please confirm your subscription', 'sw-bulk-email' ),
			$site_name
		);

		$body = '<div style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;">'
			. '<h2>' . esc_html( $site_name ) . '</h2>'
			. '<p>' . sprintf(
				/* translators: %s: site name */
				esc_html__( 'Thank you for subscribing to %s!', 'sw-bulk-email' ),
				esc_html( $site_name )
			) . '</p>'
			. '<p>' . esc_html__( 'Please click the button below to confirm your email address:', 'sw-bulk-email' ) . '</p>'
			. '<p style="margin:24px 0;">'
			. '<a href="' . esc_url( $confirm_url ) . '" '
			. 'style="background:#0073aa;color:#fff;padding:12px 24px;border-radius:4px;text-decoration:none;display:inline-block;">'
			. esc_html__( 'Confirm Subscription', 'sw-bulk-email' )
			. '</a></p>'
			. '<p style="font-size:12px;color:#888;">' . esc_html__( 'If you did not request this, please ignore this email.', 'sw-bulk-email' ) . '</p>'
			. '</div>';

		SW_Mailer::send( $email, $subject, $body );
	}

	// -----------------------------------------------------------------------
	// External embed AJAX endpoint
	// -----------------------------------------------------------------------

	public function ajax_embed_subscribe(): void {
		// CORS headers so external sites can POST here.
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type' );

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			exit;
		}

		$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		if ( $token !== get_option( 'sw_bulk_email_embed_token' ) ) {
			wp_send_json_error( [ 'message' => '유효하지 않은 요청입니다.' ], 403 );
		}

		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => '올바른 이메일 주소를 입력해 주세요.' ] );
		}

		$ad_opt_in = ( '1' === ( $_POST['ad_opt_in'] ?? '0' ) );

		if ( SW_DB::get_by_email( $email ) ) {
			wp_send_json_error( [ 'message' => '이미 구독 중이거나 확인 대기 중인 이메일입니다.' ] );
		}

		$sub_token = $this->generate_token();
		$id        = SW_DB::add_subscriber( $email, $sub_token, $ad_opt_in );

		if ( ! $id ) {
			wp_send_json_error( [ 'message' => '구독 처리 중 오류가 발생했습니다. 다시 시도해 주세요.' ] );
		}

		$this->send_confirmation_email( $email, $sub_token );
		wp_send_json_success( [ 'message' => '감사합니다! 이메일을 확인하여 구독을 완료해 주세요.' ] );
	}

	// -----------------------------------------------------------------------
	// Utility
	// -----------------------------------------------------------------------

	private function generate_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}
