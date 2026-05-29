<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI: two-tab compose page (Subscriber mail / System mail),
 * bulk-send AJAX handlers, preview modal, and test send.
 *
 * Tab routing is URL-based (?tab=subscriber | ?tab=system) to avoid
 * TinyMCE conflicts that arise with JS-only show/hide on hidden panels.
 */
class SW_Admin {

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_sw_send_batch',        [ $this, 'ajax_send_batch' ] );
		add_action( 'wp_ajax_sw_send_ad_batch',     [ $this, 'ajax_send_ad_batch' ] );
		add_action( 'wp_ajax_sw_send_system_batch', [ $this, 'ajax_send_system_batch' ] );
		add_action( 'wp_ajax_sw_test_send',         [ $this, 'ajax_test_send' ] );
		add_action( 'wp_ajax_sw_get_subscriber_count', [ $this, 'ajax_get_count' ] );

		add_action( 'wp_ajax_sw_load_template', [ $this, 'ajax_load_template' ] );
		add_action( 'wp_ajax_sw_save_template', [ $this, 'ajax_save_template' ] );
		add_action( 'wp_ajax_sw_delete_template', [ $this, 'ajax_delete_template' ] );

		// Archive AJAX handlers
		add_action( 'wp_ajax_sw_archive_save',          [ $this, 'ajax_archive_save' ] );
		add_action( 'wp_ajax_sw_archive_finish',         [ $this, 'ajax_archive_finish' ] );
		add_action( 'wp_ajax_sw_archive_update',         [ $this, 'ajax_archive_update' ] );
		add_action( 'wp_ajax_sw_archive_delete',         [ $this, 'ajax_archive_delete' ] );
		add_action( 'wp_ajax_sw_archive_toggle_public',   [ $this, 'ajax_archive_toggle_public' ] );
		add_action( 'wp_ajax_sw_archive_update_status',  [ $this, 'ajax_archive_update_status' ] );
		add_action( 'wp_ajax_sw_get_archive_body',       [ $this, 'ajax_get_archive_body' ] );
		add_action( 'wp_ajax_nopriv_sw_get_archive_body', [ $this, 'ajax_get_archive_body' ] );

		add_action( 'wp_ajax_sw_embed_regen_token',    [ $this, 'ajax_embed_regen_token' ] );
		add_action( 'wp_ajax_sw_delete_subscriber',    [ $this, 'ajax_delete_subscriber' ] );
	}

	// -----------------------------------------------------------------------
	// Menu
	// -----------------------------------------------------------------------

	public function register_menu() {
		add_menu_page(
			__( 'SW Bulk Email', 'sw-bulk-email' ),
			__( 'SW Bulk Email', 'sw-bulk-email' ),
			'manage_options',
			'sw-bulk-email',
			[ $this, 'render_compose_page' ],
			'dashicons-email-alt',
			30
		);

		add_submenu_page(
			'sw-bulk-email',
			__( 'Compose & Send', 'sw-bulk-email' ),
			__( 'Compose & Send', 'sw-bulk-email' ),
			'manage_options',
			'sw-bulk-email',
			[ $this, 'render_compose_page' ]
		);

		add_submenu_page(
			'sw-bulk-email',
			__( 'Subscribers', 'sw-bulk-email' ),
			__( 'Subscribers', 'sw-bulk-email' ),
			'manage_options',
			'sw-bulk-email-subscribers',
			[ $this, 'render_subscribers_page' ]
		);

		add_submenu_page(
			'sw-bulk-email',
			__( '발송 내역', 'sw-bulk-email' ),
			__( '발송 내역', 'sw-bulk-email' ),
			'manage_options',
			'sw-bulk-email-archive',
			[ $this, 'render_archive_page' ]
		);

		add_submenu_page(
			'sw-bulk-email',
			__( 'Embed Form', 'sw-bulk-email' ),
			__( 'Embed Form', 'sw-bulk-email' ),
			'manage_options',
			'sw-bulk-email-embed',
			[ $this, 'render_embed_page' ]
		);

		add_submenu_page(
			'sw-bulk-email',
			__( '메일 푸터 설정', 'sw-bulk-email' ),
			__( '메일 푸터 설정', 'sw-bulk-email' ),
			'manage_options',
			'sw-bulk-email-footer',
			[ $this, 'render_footer_settings_page' ]
		);

		add_submenu_page(
			'sw-bulk-email',
			__( '사용 안내', 'sw-bulk-email' ),
			__( '사용 안내', 'sw-bulk-email' ),
			'manage_options',
			'sw-bulk-email-manual',
			[ $this, 'render_manual_page' ]
		);
	}

	// -----------------------------------------------------------------------
	// Assets
	// -----------------------------------------------------------------------

	public function enqueue_assets( string $hook ) {
		$allowed_hooks = [
			'toplevel_page_sw-bulk-email',
			'sw-bulk-email_page_sw-bulk-email',
			'sw-bulk-email_page_sw-bulk-email-subscribers',
			'sw-bulk-email_page_sw-bulk-email-footer',
			'sw-bulk-email_page_sw-bulk-email-archive',
		];
		if ( ! in_array( $hook, $allowed_hooks, true ) ) {
			return;
		}

		// Media library uploader — needed for the footer settings custom social icon picker.
		if ( 'sw-bulk-email_page_sw-bulk-email-footer' === $hook ) {
			wp_enqueue_media();
		}

		wp_enqueue_style(
			'sw-admin-css',
			SW_BULK_EMAIL_URL . 'admin/css/sw-admin.css',
			[],
			SW_BULK_EMAIL_VERSION
		);

		wp_enqueue_script(
			'sw-admin-js',
			SW_BULK_EMAIL_URL . 'admin/js/sw-admin.js',
			[ 'jquery' ],
			SW_BULK_EMAIL_VERSION,
			true
		);

		$info        = SW_Mailer::get_sender_info();
		$active_tab  = $this->active_tab();
		$templates   = SW_DB::get_templates( $active_tab );

		wp_localize_script(
			'sw-admin-js',
			'swBulkEmail',
			[
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'sw_send_batch' ),
				'batchSize'  => (int) apply_filters( 'sw_bulk_email_batch_size', 50 ),
				'activeTab'  => $active_tab,
				'templates'  => $templates,
				'archiveUrl' => admin_url( 'admin.php?page=sw-bulk-email-archive' ),
				'senderInfo' => [
					'email' => esc_html( $info['from_email'] ),
					'name'  => esc_html( $info['from_name'] ),
				],
				'i18n'       => [
					'confirmSubscriber' => __( '구독 동의자에게만 발송합니다. 계속하시겠습니까?', 'sw-bulk-email' ),
					'confirmSystem'     => __( '전체 회원(WP 회원 + 모든 구독자)에게 발송합니다. 동의 여부와 관계없이 무조건 발송됩니다. 계속하시겠습니까?', 'sw-bulk-email' ),
					'sending'           => __( '발송 중…', 'sw-bulk-email' ),
					'done'              => __( '발송 완료!', 'sw-bulk-email' ),
					'error'             => __( '오류가 발생했습니다. 브라우저 콘솔을 확인하세요.', 'sw-bulk-email' ),
					'noContent'         => __( '제목과 본문을 모두 입력해 주세요.', 'sw-bulk-email' ),
					'testSend'          => __( '관리자에게 테스트 발송', 'sw-bulk-email' ),
					'sendSubscriber'    => __( '구독자에게 발송', 'sw-bulk-email' ),
					'sendSystem'        => __( '전체 발송', 'sw-bulk-email' ),
					'sendAd'            => __( '광고 메일 발송', 'sw-bulk-email' ),
					'confirmAd'         => __( '광고 수신 동의자에게만 발송합니다. 계속하시겠습니까?', 'sw-bulk-email' ),
					'progress'          => __( '{processed} / {total} 처리 (성공: {sent}, 실패: {failed})', 'sw-bulk-email' ),
					'confirmDelete'     => __( '정말로 이 템플릿을 삭제하시겠습니까?', 'sw-bulk-email' ),
					'promptTemplateName'=> __( '템플릿 이름을 입력하세요:', 'sw-bulk-email' ),
					'templateSaved'     => __( '템플릿이 저장되었습니다.', 'sw-bulk-email' ),
					'templateLoaded'    => __( '템플릿을 불러왔습니다.', 'sw-bulk-email' ),
					'templateDeleted'   => __( '템플릿이 삭제되었습니다.', 'sw-bulk-email' ),
					'saveDraft'         => __( '임시저장', 'sw-bulk-email' ),
					'saving'            => __( '저장 중…', 'sw-bulk-email' ),
					'draftSaved'              => __( '임시저장 완료!', 'sw-bulk-email' ),
					'draftSaveFail'           => __( '임시저장에 실패했습니다.', 'sw-bulk-email' ),
					'confirmDeleteSubscriber' => __( '이 구독자를 삭제하시겠습니까?', 'sw-bulk-email' ),
					'subscriberDeleted'       => __( '구독자가 삭제되었습니다.', 'sw-bulk-email' ),
				],
			]
		);
	}

	// -----------------------------------------------------------------------
	// Tab routing helper
	// -----------------------------------------------------------------------

	private function active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'subscriber';
		return in_array( $tab, [ 'subscriber', 'system', 'ad' ], true ) ? $tab : 'subscriber';
	}

	// -----------------------------------------------------------------------
	// Compose & Send page (tab router)
	// -----------------------------------------------------------------------

	public function render_compose_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sw-bulk-email' ) );
		}

		$active_tab = $this->active_tab();
		$base_url   = admin_url( 'admin.php?page=sw-bulk-email' );
		?>
		<div class="wrap sw-bulk-email-wrap">
			<h1><?php esc_html_e( 'SW Bulk Email', 'sw-bulk-email' ); ?></h1>

			<?php $this->render_smtp_info_bar(); ?>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Mail type', 'sw-bulk-email' ); ?>">
				<a href="<?php echo esc_url( $base_url . '&tab=subscriber' ); ?>"
				   class="nav-tab <?php echo 'subscriber' === $active_tab ? 'nav-tab-active' : ''; ?>">
					📧 <?php esc_html_e( '구독자 메일 (수신 동의자)', 'sw-bulk-email' ); ?>
				</a>
				<a href="<?php echo esc_url( $base_url . '&tab=system' ); ?>"
				   class="nav-tab <?php echo 'system' === $active_tab ? 'nav-tab-active' : ''; ?>">
					📢 <?php esc_html_e( '전체 발송 (시스템 / 서비스 중요 메일)', 'sw-bulk-email' ); ?>
				</a>
				<a href="<?php echo esc_url( $base_url . '&tab=ad' ); ?>"
				   class="nav-tab <?php echo 'ad' === $active_tab ? 'nav-tab-active' : ''; ?>">
					💰 <?php esc_html_e( '광고 메일 (수신 동의자)', 'sw-bulk-email' ); ?>
				</a>
			</nav>

			<div class="sw-tab-body">
				<?php
				if ( 'subscriber' === $active_tab ) {
					$this->render_subscriber_tab();
				} elseif ( 'system' === $active_tab ) {
					$this->render_system_tab();
				} elseif ( 'ad' === $active_tab ) {
					$this->render_ad_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// WP Mail SMTP info bar
	// -----------------------------------------------------------------------

	private function render_smtp_info_bar() {
		$info = SW_Mailer::get_sender_info();
		$cls  = $info['smtp_active'] ? 'sw-smtp-bar--active' : 'sw-smtp-bar--inactive';
		$label = $info['smtp_active']
			? __( 'WP Mail SMTP 활성', 'sw-bulk-email' )
			: __( 'WP Mail SMTP 비활성 (WordPress 기본값 사용)', 'sw-bulk-email' );
		?>
		<div class="sw-smtp-bar <?php echo esc_attr( $cls ); ?>">
			<strong><?php echo esc_html( $label ); ?></strong>
			&nbsp;|&nbsp;
			<?php esc_html_e( '발신자:', 'sw-bulk-email' ); ?>
			<code><?php echo esc_html( $info['from_name'] ); ?>
				&lt;<?php echo esc_html( $info['from_email'] ); ?>&gt;</code>
			<?php if ( $info['force_email'] ) : ?>
				<span class="sw-badge"><?php esc_html_e( '이메일 강제 적용 중', 'sw-bulk-email' ); ?></span>
			<?php endif; ?>
			<?php if ( $info['force_name'] ) : ?>
				<span class="sw-badge"><?php esc_html_e( '이름 강제 적용 중', 'sw-bulk-email' ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Tab: Subscriber mail
	// -----------------------------------------------------------------------

	private function render_subscriber_tab() {
		$confirmed = SW_DB::count_confirmed();
		?>
		<div class="sw-compose-panel">
			<div class="sw-panel-desc">
				<p>
					<?php
					printf(
						/* translators: %d: confirmed subscriber count */
						esc_html__( '수신 동의(더블 옵트인 완료) 구독자에게만 발송합니다. 현재 확인된 구독자: %d명', 'sw-bulk-email' ),
						(int) $confirmed
					);
					?>
				</p>
				<p class="description">
					<?php esc_html_e( '발송 시 각 이메일 하단에 수신 거부 링크가 자동으로 추가됩니다.', 'sw-bulk-email' ); ?>
				</p>
			</div>

			<div id="sw-sub-status"></div>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="sw-sub-subject"><?php esc_html_e( '제목', 'sw-bulk-email' ); ?></label>
					</th>
					<td>
						<input type="text" id="sw-sub-subject" class="large-text"
							placeholder="<?php esc_attr_e( '이메일 제목을 입력하세요', 'sw-bulk-email' ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '본문', 'sw-bulk-email' ); ?></th>
					<td>
						<?php
						wp_editor( '', 'sw_sub_body', [
							'media_buttons' => false,
							'textarea_rows' => 18,
							'teeny'         => false,
						] );
						?>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="button" id="sw-sub-draft-btn" class="button button-secondary button-large">
					💾 <?php esc_html_e( '임시저장', 'sw-bulk-email' ); ?>
				</button>
				&nbsp;
				<button type="button" id="sw-sub-send-btn" class="button button-primary button-large">
					📧 <?php esc_html_e( '구독자에게 발송', 'sw-bulk-email' ); ?>
				</button>
			</p>

			<div id="sw-sub-progress-wrap" class="sw-progress-wrap" style="display:none;">
				<progress id="sw-sub-progress-bar" value="0" max="100"></progress>
				<p id="sw-sub-progress-label"></p>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Tab: System mail
	// -----------------------------------------------------------------------

	private function render_system_tab() {
		$wp_user_count  = count( get_users( [ 'fields' => 'ID', 'number' => -1 ] ) );
		$sw_all_count   = count( SW_DB::get_all_subscriber_emails() );
		$templates      = SW_DB::get_templates( 'system' );
		?>
		<div class="sw-compose-panel">
			<div class="sw-panel-desc sw-panel-desc--system">
				<p>
					<?php
					printf(
						/* translators: 1: WP user count, 2: subscriber total count */
						esc_html__( '동의 여부와 관계없이 전체 회원에게 발송합니다. WordPress 회원: %1$d명 / 구독 DB 전체: %2$d명 (중복 제거 후 발송)', 'sw-bulk-email' ),
						(int) $wp_user_count,
						(int) $sw_all_count
					);
					?>
				</p>
				<p class="description">
					<?php esc_html_e( '시스템 공지, 정책 변경, 서비스 중요 안내 등 동의 여부와 무관하게 발송해야 하는 메일에 사용하세요. 수신 거부 링크는 포함되지 않습니다.', 'sw-bulk-email' ); ?>
				</p>
			</div>

			<?php $this->render_template_ui( 'sys', $templates ); ?>

			<div id="sw-sys-status"></div>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="sw-sys-subject"><?php esc_html_e( '제목', 'sw-bulk-email' ); ?></label>
					</th>
					<td>
						<input type="text" id="sw-sys-subject" class="large-text"
							placeholder="<?php esc_attr_e( '이메일 제목을 입력하세요', 'sw-bulk-email' ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '본문', 'sw-bulk-email' ); ?></th>
					<td>
						<?php
						wp_editor( '', 'sw_sys_body', [
							'media_buttons' => false,
							'textarea_rows' => 18,
							'teeny'         => false,
						] );
						?>
					</td>
				</tr>
			</table>

			<div class="sw-action-bar">
				<button type="button" id="sw-sys-draft-btn" class="button button-secondary button-large">
					💾 <?php esc_html_e( '임시저장', 'sw-bulk-email' ); ?>
				</button>
				<button type="button" id="sw-sys-preview-btn" class="button button-secondary button-large">
					👁 <?php esc_html_e( '미리보기', 'sw-bulk-email' ); ?>
				</button>
				<button type="button" id="sw-sys-test-btn" class="button button-secondary button-large">
					✉ <?php esc_html_e( '관리자에게 테스트 발송', 'sw-bulk-email' ); ?>
				</button>
				<button type="button" id="sw-sys-send-btn" class="button button-primary button-large sw-btn-danger">
					📢 <?php esc_html_e( '전체 발송', 'sw-bulk-email' ); ?>
				</button>
			</div>

			<div id="sw-sys-progress-wrap" class="sw-progress-wrap" style="display:none;">
				<progress id="sw-sys-progress-bar" value="0" max="100"></progress>
				<p id="sw-sys-progress-label"></p>
			</div>
		</div>

		<?php $this->render_preview_modal(); ?>
		<?php
	}

	// -----------------------------------------------------------------------
	// Tab: Ad mail
	// -----------------------------------------------------------------------

	private function render_ad_tab() {
		$confirmed = SW_DB::count_confirmed_ad_subscribers();
		?>
		<div class="sw-compose-panel">
			<div class="sw-panel-desc">
				<p>
					<?php
					printf(
						/* translators: %d: confirmed ad subscriber count */
						esc_html__( '광고성 메일 수신에 동의한 구독자에게만 발송합니다. 현재 확인된 구독자: %d명', 'sw-bulk-email' ),
						(int) $confirmed
					);
					?>
				</p>
				<p class="description">
					<?php esc_html_e( '발송 시 제목에 [광고] 문구가 자동으로 추가되며, 각 이메일 하단에 수신 거부 링크가 자동으로 추가됩니다.', 'sw-bulk-email' ); ?>
				</p>
			</div>

			<div id="sw-ad-status"></div>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="sw-ad-subject"><?php esc_html_e( '제목', 'sw-bulk-email' ); ?></label>
					</th>
					<td>
						<input type="text" id="sw-ad-subject" class="large-text"
							placeholder="<?php esc_attr_e( '이메일 제목을 입력하세요', 'sw-bulk-email' ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '본문', 'sw-bulk-email' ); ?></th>
					<td>
						<?php
						wp_editor( '', 'sw_ad_body', [
							'media_buttons' => false,
							'textarea_rows' => 18,
							'teeny'         => false,
						] );
						?>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="button" id="sw-ad-draft-btn" class="button button-secondary button-large">
					💾 <?php esc_html_e( '임시저장', 'sw-bulk-email' ); ?>
				</button>
				&nbsp;
				<button type="button" id="sw-ad-send-btn" class="button button-primary button-large">
					💰 <?php esc_html_e( '광고 메일 발송', 'sw-bulk-email' ); ?>
				</button>
			</p>

			<div id="sw-ad-progress-wrap" class="sw-progress-wrap" style="display:none;">
				<progress id="sw-ad-progress-bar" value="0" max="100"></progress>
				<p id="sw-ad-progress-label"></p>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Template UI
	// -----------------------------------------------------------------------

	private function render_template_ui( string $prefix, array $templates ) {
		?>
		<div class="sw-template-bar">
			<div class="sw-template-bar__group">
				<label for="sw-<?php echo esc_attr( $prefix ); ?>-templates" class="sw-label">
					<?php esc_html_e( '템플릿', 'sw-bulk-email' ); ?>
				</label>
				<select id="sw-<?php echo esc_attr( $prefix ); ?>-templates">
					<option value=""><?php esc_html_e( '— 선택 —', 'sw-bulk-email' ); ?></option>
					<?php foreach ( $templates as $template ) : ?>
						<option value="<?php echo esc_attr( $template['id'] ); ?>">
							<?php echo esc_html( $template['template_name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="button" id="sw-<?php echo esc_attr( $prefix ); ?>-load-btn" class="button button-secondary">
					<?php esc_html_e( '불러오기', 'sw-bulk-email' ); ?>
				</button>
				<button type="button" id="sw-<?php echo esc_attr( $prefix ); ?>-delete-btn" class="button button-link-delete">
					<?php esc_html_e( '삭제', 'sw-bulk-email' ); ?>
				</button>
			</div>
			<div class="sw-template-bar__group">
				<button type="button" id="sw-<?php echo esc_attr( $prefix ); ?>-save-btn" class="button button-primary">
					<?php esc_html_e( '현재 내용 템플릿으로 저장', 'sw-bulk-email' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Preview modal
	// -----------------------------------------------------------------------

	private function render_preview_modal() {
		$info = SW_Mailer::get_sender_info();
		?>
		<div id="sw-preview-modal" role="dialog" aria-modal="true"
		     aria-label="<?php esc_attr_e( '이메일 미리보기', 'sw-bulk-email' ); ?>" style="display:none;">
			<div id="sw-preview-overlay"></div>
			<div id="sw-preview-box">
				<div id="sw-preview-header">
					<h2><?php esc_html_e( '이메일 미리보기', 'sw-bulk-email' ); ?></h2>
					<button type="button" id="sw-preview-close" aria-label="<?php esc_attr_e( '닫기', 'sw-bulk-email' ); ?>">✕</button>
				</div>
				<div id="sw-preview-meta">
					<table>
						<tr>
							<th><?php esc_html_e( '발신자', 'sw-bulk-email' ); ?></th>
							<td>
								<code><?php echo esc_html( $info['from_name'] ); ?>
									&lt;<?php echo esc_html( $info['from_email'] ); ?>&gt;</code>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( '제목', 'sw-bulk-email' ); ?></th>
							<td><span id="sw-preview-subject"></span></td>
						</tr>
					</table>
				</div>
				<div id="sw-preview-body-wrap">
					<iframe id="sw-preview-iframe" sandbox="allow-same-origin" title="<?php esc_attr_e( '이메일 본문 미리보기', 'sw-bulk-email' ); ?>"></iframe>
				</div>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Subscribers list page
	// -----------------------------------------------------------------------

	public function render_subscribers_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sw-bulk-email' ) );
		}

		$per_page    = 50;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_pg  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$offset      = ( $current_pg - 1 ) * $per_page;
		$total       = SW_DB::count_confirmed();
		$subscribers = SW_DB::get_confirmed_subscribers( $per_page, $offset );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '확인된 구독자 목록', 'sw-bulk-email' ); ?></h1>
			<div id="sw-subscriber-notice"></div>

			<p>
				<?php
				printf(
					esc_html__( '총 %d명', 'sw-bulk-email' ),
					(int) $total
				);
				?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'sw-bulk-email' ); ?></th>
						<th><?php esc_html_e( '이메일', 'sw-bulk-email' ); ?></th>
						<th><?php esc_html_e( '구독 확인일', 'sw-bulk-email' ); ?></th>
						<th><?php esc_html_e( '작업', 'sw-bulk-email' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $subscribers ) ) : ?>
					<tr>
						<td colspan="4"><?php esc_html_e( '구독 확인된 회원이 없습니다.', 'sw-bulk-email' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $subscribers as $sub ) : ?>
					<tr>
						<td><?php echo esc_html( $sub['id'] ); ?></td>
						<td><?php echo esc_html( $sub['email'] ); ?></td>
						<td><?php echo esc_html( $sub['opt_in_date'] ); ?></td>
						<td>
							<button type="button"
								class="button-link-delete sw-subscriber-delete-btn"
								data-id="<?php echo (int) $sub['id']; ?>"
								style="color:#a00;cursor:pointer;border:none;background:none;padding:0;">
								<?php esc_html_e( '삭제', 'sw-bulk-email' ); ?>
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			<?php
			$total_pages = (int) ceil( $total / $per_page );
			if ( $total_pages > 1 ) {
				echo paginate_links( [
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $current_pg,
					'total'   => $total_pages,
				] );
			}
			?>
		</div>
		<script>
		jQuery(document).ready(function($){
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'sw_send_batch' ) ); ?>;

			$(document).on('click', '.sw-subscriber-delete-btn', function(){
				if ( ! confirm('<?php echo esc_js( __( '이 구독자를 삭제하시겠습니까?', 'sw-bulk-email' ) ); ?>') ) { return; }
				var id  = $(this).data('id');
				var $tr = $(this).closest('tr');
				$.post(ajaxUrl, { action: 'sw_delete_subscriber', nonce: nonce, id: id }, function(resp){
					if (resp.success) {
						$tr.fadeOut(300, function(){ $(this).remove(); });
						$('#sw-subscriber-notice').html(
							'<div class="notice notice-success is-dismissible"><p>' +
							'<?php echo esc_js( __( '구독자가 삭제되었습니다.', 'sw-bulk-email' ) ); ?>' +
							'</p></div>'
						);
					} else {
						alert( resp.data && resp.data.message ? resp.data.message : '삭제 실패' );
					}
				}).fail(function(){
					alert('오류가 발생했습니다.');
				});
			});
		});
		</script>
		<?php
	}

	// -----------------------------------------------------------------------
	// AJAX: subscriber delete
	// -----------------------------------------------------------------------

	public function ajax_delete_subscriber() {
		check_ajax_referer( 'sw_send_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( '유효하지 않은 ID입니다.', 'sw-bulk-email' ) ], 400 );
		}

		$ok = SW_DB::delete_by_id( $id );
		if ( $ok ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( [ 'message' => __( '삭제에 실패했습니다.', 'sw-bulk-email' ) ] );
		}
	}

	// -----------------------------------------------------------------------
	// AJAX: subscriber count
	// -----------------------------------------------------------------------

	public function ajax_get_count() {
		check_ajax_referer( 'sw_send_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sw-bulk-email' ) ], 403 );
		}
		wp_send_json_success( [ 'count' => SW_DB::count_confirmed() ] );
	}

	// -----------------------------------------------------------------------
	// AJAX: subscriber batch (confirmed only, with unsubscribe footer)
	// -----------------------------------------------------------------------

	public function ajax_send_batch() {
		check_ajax_referer( 'sw_send_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sw-bulk-email' ) ], 403 );
		}

		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$body    = isset( $_POST['body'] )    ? wp_kses_post( wp_unslash( $_POST['body'] ) )            : '';
		$offset  = isset( $_POST['offset'] )  ? max( 0, (int) $_POST['offset'] )                        : 0;
		$batch   = isset( $_POST['batch_size'] ) ? max( 1, min( 200, (int) $_POST['batch_size'] ) )     : 50;

		if ( empty( $subject ) || empty( $body ) ) {
			wp_send_json_error( [ 'message' => __( '제목과 본문을 모두 입력해 주세요.', 'sw-bulk-email' ) ] );
		}

		$subscribers = SW_DB::get_confirmed_subscribers( $batch, $offset );
		$sent = 0; $failed = 0;

		foreach ( $subscribers as $sub ) {
			$ok = SW_Mailer::send_subscribed( $sub['email'], $subject, $body, $sub['unsubscribe_token'] );
			$ok ? $sent++ : $failed++;
		}

		$processed = $offset + count( $subscribers );
		$total     = SW_DB::count_confirmed();

		wp_send_json_success( [
			'sent'      => $sent,
			'failed'    => $failed,
			'processed' => $processed,
			'total'     => $total,
			'done'      => ( $processed >= $total ),
		] );
	}

	public function ajax_send_ad_batch() {
		check_ajax_referer( 'sw_send_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sw-bulk-email' ) ], 403 );
		}

		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$body    = isset( $_POST['body'] )    ? wp_kses_post( wp_unslash( $_POST['body'] ) )            : '';
		$offset  = isset( $_POST['offset'] )  ? max( 0, (int) $_POST['offset'] )                        : 0;
		$batch   = isset( $_POST['batch_size'] ) ? max( 1, min( 200, (int) $_POST['batch_size'] ) )     : 50;

		if ( empty( $subject ) || empty( $body ) ) {
			wp_send_json_error( [ 'message' => __( '제목과 본문을 모두 입력해 주세요.', 'sw-bulk-email' ) ] );
		}

		$subscribers = SW_DB::get_confirmed_ad_subscribers( $batch, $offset );
		$sent = 0; $failed = 0;
		$ad_subject = '[광고] ' . $subject;

		foreach ( $subscribers as $sub ) {
			$ok = SW_Mailer::send_subscribed( $sub['email'], $ad_subject, $body, $sub['unsubscribe_token'] );
			$ok ? $sent++ : $failed++;
		}

		$processed = $offset + count( $subscribers );
		$total     = SW_DB::count_confirmed_ad_subscribers();

		wp_send_json_success( [
			'sent'      => $sent,
			'failed'    => $failed,
			'processed' => $processed,
			'total'     => $total,
			'done'      => ( $processed >= $total ),
		] );
	}

	// -----------------------------------------------------------------------
	// AJAX: system batch (all WP users + all sw_subscribers, no opt-in check)
	// -----------------------------------------------------------------------

	public function ajax_send_system_batch() {
		check_ajax_referer( 'sw_send_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sw-bulk-email' ) ], 403 );
		}

		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$body    = isset( $_POST['body'] )    ? wp_kses_post( wp_unslash( $_POST['body'] ) )            : '';
		$offset  = isset( $_POST['offset'] )  ? max( 0, (int) $_POST['offset'] )                        : 0;
		$batch   = isset( $_POST['batch_size'] ) ? max( 1, min( 200, (int) $_POST['batch_size'] ) )     : 50;

		if ( empty( $subject ) || empty( $body ) ) {
			wp_send_json_error( [ 'message' => __( '제목과 본문을 모두 입력해 주세요.', 'sw-bulk-email' ) ] );
		}

		// Cache the combined recipient list in a transient for the duration of
		// the send job (rebuilt fresh when offset=0 to pick up any new users).
		$cache_key  = 'sw_system_emails_' . get_current_user_id();
		$all_emails = ( $offset === 0 ) ? false : get_transient( $cache_key );

		if ( false === $all_emails ) {
			$wp_emails  = get_users( [ 'fields' => 'user_email' ] );
			$sw_emails  = SW_DB::get_all_subscriber_emails();
			$all_emails = array_values( array_unique( array_merge(
				is_array( $wp_emails ) ? $wp_emails : [],
				is_array( $sw_emails ) ? $sw_emails : []
			) ) );
			sort( $all_emails );
			set_transient( $cache_key, $all_emails, HOUR_IN_SECONDS );
		}

		$total       = count( $all_emails );
		$batch_slice = array_slice( $all_emails, $offset, $batch );

		$sent = 0; $failed = 0;
		foreach ( $batch_slice as $email ) {
			if ( ! is_email( $email ) ) {
				continue;
			}
			$ok = SW_Mailer::send( $email, $subject, $body );
			$ok ? $sent++ : $failed++;
		}

		$processed = $offset + count( $batch_slice );
		$done      = ( $processed >= $total );

		if ( $done ) {
			delete_transient( $cache_key );
		}

		wp_send_json_success( [
			'sent'      => $sent,
			'failed'    => $failed,
			'processed' => $processed,
			'total'     => $total,
			'done'      => $done,
		] );
	}

	// -----------------------------------------------------------------------
	// AJAX: test send to current admin user
	// -----------------------------------------------------------------------

	public function ajax_test_send() {
		check_ajax_referer( 'sw_send_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sw-bulk-email' ) ], 403 );
		}

		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$body    = isset( $_POST['body'] )    ? wp_kses_post( wp_unslash( $_POST['body'] ) )            : '';

		if ( empty( $subject ) || empty( $body ) ) {
			wp_send_json_error( [ 'message' => __( '제목과 본문을 모두 입력해 주세요.', 'sw-bulk-email' ) ] );
		}

		$current_user = wp_get_current_user();
		$test_email   = $current_user->user_email ?: get_option( 'admin_email' );

		$banner = '<div style="background:#fff3cd;border:1px solid #ffc107;padding:12px 16px;margin-bottom:16px;border-radius:4px;font-size:13px;">'
			. '<strong>[테스트 발송]</strong> 실제 전송 시에는 이 배너가 표시되지 않습니다.'
			. '</div>';

		$ok = SW_Mailer::send( $test_email, '[테스트] ' . $subject, $banner . $body );

		if ( $ok ) {
			wp_send_json_success( [
				'message' => sprintf(
					/* translators: %s: email address */
					__( '테스트 메일을 %s 로 발송했습니다.', 'sw-bulk-email' ),
					esc_html( $test_email )
				),
			] );
		} else {
			wp_send_json_error( [
				'message' => __( '테스트 메일 발송에 실패했습니다. 메일 설정을 확인해 주세요.', 'sw-bulk-email' ),
			] );
		}
	}

	// -----------------------------------------------------------------------
	// AJAX: template actions
	// -----------------------------------------------------------------------

	public function ajax_load_template() {
		check_ajax_referer( 'sw_send_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			wp_send_json_error( null, 400 );
		}

		$template = SW_DB::get_template( $id );
		if ( ! $template ) {
			wp_send_json_error( null, 404 );
		}

		wp_send_json_success( $template );
	}

	public function ajax_save_template() {
		check_ajax_referer( 'sw_send_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$body    = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '';
		$type    = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';

		if ( empty( $name ) || empty( $subject ) || empty( $body ) || ! in_array( $type, [ 'subscriber', 'system' ], true ) ) {
			wp_send_json_error( [ 'message' => __( '모든 필드를 입력해주세요.', 'sw-bulk-email' ) ], 400 );
		}

		$id = SW_DB::save_template( $name, $subject, $body, $type );

		wp_send_json_success( [
			'id'   => $id,
			'name' => $name,
		] );
	}

	public function ajax_delete_template() {
		check_ajax_referer( 'sw_send_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			wp_send_json_error( null, 400 );
		}

		SW_DB::delete_template( $id );
		wp_send_json_success();
	}

	// -----------------------------------------------------------------------
	// Archive page (delegates to SW_Archive_Page)
	// -----------------------------------------------------------------------

	public function render_archive_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sw-bulk-email' ) );
		}
		( new SW_Archive_Page() )->render();
	}

	// -----------------------------------------------------------------------
	// AJAX: archive save (called before batch starts)
	// -----------------------------------------------------------------------

	public function ajax_archive_save() {
		check_ajax_referer( 'sw_send_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sw-bulk-email' ) ], 403 );
		}

		$subject   = isset( $_POST['subject'] )   ? sanitize_text_field( wp_unslash( $_POST['subject'] ) )   : '';
		$body      = isset( $_POST['body'] )      ? wp_kses_post( wp_unslash( $_POST['body'] ) )              : '';
		$mail_type = isset( $_POST['mail_type'] ) ? sanitize_key( $_POST['mail_type'] )                       : 'subscriber';
		$status    = isset( $_POST['status'] )    ? sanitize_key( $_POST['status'] )                          : 'sent';

		if ( ! in_array( $mail_type, [ 'subscriber', 'ad', 'system' ], true ) ) {
			$mail_type = 'subscriber';
		}
		if ( ! in_array( $status, [ 'draft', 'sent' ], true ) ) {
			$status = 'sent';
		}

		if ( empty( $subject ) || empty( $body ) ) {
			wp_send_json_error( [ 'message' => __( '제목과 본문을 모두 입력해 주세요.', 'sw-bulk-email' ) ] );
		}

		$id = SW_DB::archive_save( $subject, $body, $mail_type, $status );

		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( '아카이브 저장에 실패했습니다.', 'sw-bulk-email' ) ] );
		}

		wp_send_json_success( [ 'archive_id' => $id ] );
	}

	// -----------------------------------------------------------------------
	// AJAX: archive finish (update stats after batch completes)
	// -----------------------------------------------------------------------

	public function ajax_archive_finish() {
		check_ajax_referer( 'sw_send_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sw-bulk-email' ) ], 403 );
		}

		$id     = isset( $_POST['archive_id'] )   ? (int) $_POST['archive_id']   : 0;
		$sent   = isset( $_POST['sent_count'] )   ? (int) $_POST['sent_count']   : 0;
		$failed = isset( $_POST['failed_count'] ) ? (int) $_POST['failed_count'] : 0;

		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( '유효하지 않은 archive_id입니다.', 'sw-bulk-email' ) ] );
		}

		$ok = SW_DB::archive_update_stats( $id, $sent, $failed );

		if ( $ok ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( [ 'message' => __( '통계 업데이트에 실패했습니다.', 'sw-bulk-email' ) ] );
		}
	}

	// -----------------------------------------------------------------------
	// AJAX: archive update content
	// -----------------------------------------------------------------------

	public function ajax_archive_update() {
		check_ajax_referer( 'sw_send_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sw-bulk-email' ) ], 403 );
		}

		$id      = isset( $_POST['id'] )      ? (int) $_POST['id']                                                 : 0;
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) )             : '';
		$body    = isset( $_POST['body'] )    ? wp_kses_post( wp_unslash( $_POST['body'] ) )                       : '';

		if ( ! $id || empty( $subject ) || empty( $body ) ) {
			wp_send_json_error( [ 'message' => __( '모든 필드를 입력해주세요.', 'sw-bulk-email' ) ] );
		}

		$ok = SW_DB::archive_update_content( $id, $subject, $body );

		if ( $ok ) {
			wp_send_json_success( [ 'message' => __( '저장되었습니다.', 'sw-bulk-email' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( '저장에 실패했습니다.', 'sw-bulk-email' ) ] );
		}
	}

	// -----------------------------------------------------------------------
	// AJAX: archive delete
	// -----------------------------------------------------------------------

	public function ajax_archive_delete() {
		check_ajax_referer( 'sw_send_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sw-bulk-email' ) ], 403 );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( '유효하지 않은 ID입니다.', 'sw-bulk-email' ) ] );
		}

		$ok = SW_DB::archive_delete( $id );

		if ( $ok ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( [ 'message' => __( '삭제에 실패했습니다.', 'sw-bulk-email' ) ] );
		}
	}

	// -----------------------------------------------------------------------
	// AJAX: archive toggle public
	// -----------------------------------------------------------------------

	public function ajax_archive_toggle_public() {
		check_ajax_referer( 'sw_send_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sw-bulk-email' ) ], 403 );
		}

		$id        = isset( $_POST['id'] )        ? (int) $_POST['id']        : 0;
		$is_public = isset( $_POST['is_public'] ) ? (int) $_POST['is_public'] : 0;

		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( '유효하지 않은 ID입니다.', 'sw-bulk-email' ) ] );
		}

		$ok = SW_DB::archive_toggle_public( $id, (bool) $is_public );

		if ( $ok ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( [ 'message' => __( '변경에 실패했습니다.', 'sw-bulk-email' ) ] );
		}
	}

	// -----------------------------------------------------------------------
	// AJAX: update archive status (draft → sent)
	// -----------------------------------------------------------------------

	public function ajax_archive_update_status() {
		check_ajax_referer( 'sw_send_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sw-bulk-email' ) ], 403 );
		}

		$id     = isset( $_POST['id'] )     ? (int) $_POST['id']                    : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] )      : '';

		if ( ! $id || ! in_array( $status, [ 'draft', 'sent' ], true ) ) {
			wp_send_json_error( [ 'message' => __( '유효하지 않은 요청입니다.', 'sw-bulk-email' ) ] );
		}

		$ok = SW_DB::archive_update_status( $id, $status );

		if ( $ok ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( [ 'message' => __( '상태 변경에 실패했습니다.', 'sw-bulk-email' ) ] );
		}
	}

	// -----------------------------------------------------------------------
	// AJAX: get archive body (nopriv – public items only)
	// -----------------------------------------------------------------------

	public function ajax_get_archive_body() {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( ! $id ) {
			wp_send_json_error( [ 'message' => '유효하지 않은 ID입니다.' ], 400 );
		}

		$item = SW_DB::archive_get( $id );

		if ( ! $item ) {
			wp_send_json_error( [ 'message' => '항목을 찾을 수 없습니다.' ], 404 );
		}

		// Non-admin users can only access public items.
		if ( ! current_user_can( 'manage_options' ) && ! (int) $item['is_public'] ) {
			wp_send_json_error( [ 'message' => '비공개 항목입니다.' ], 403 );
		}

		wp_send_json_success( [
			'subject' => $item['subject'],
			'body'    => $item['body'],
		] );
	}

	// -----------------------------------------------------------------------
	// Embed Form page
	// -----------------------------------------------------------------------

	public function render_embed_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sw-bulk-email' ) );
		}

		$embed_token = get_option( 'sw_bulk_email_embed_token' );
		if ( ! $embed_token ) {
			$embed_token = bin2hex( random_bytes( 16 ) );
			update_option( 'sw_bulk_email_embed_token', $embed_token );
		}

		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '구독 폼 외부 삽입', 'sw-bulk-email' ); ?></h1>
			<p class="description">
				<?php esc_html_e( '아래에서 스타일을 설정하면 HTML+CSS 스니펫이 실시간으로 생성됩니다. 코드를 복사해 원하는 페이지에 붙여넣기만 하면 됩니다.', 'sw-bulk-email' ); ?>
			</p>

			<div class="sw-embed-builder">

				<!-- ① 스타일 설정 패널 -->
				<div class="sw-embed-panel sw-embed-panel--settings">
					<h2><?php esc_html_e( '① 스타일 설정', 'sw-bulk-email' ); ?></h2>

					<table class="form-table sw-embed-controls" role="presentation">
						<tr>
							<th><?php esc_html_e( '주 색상 (버튼)', 'sw-bulk-email' ); ?></th>
							<td>
								<input type="color" id="ec-primary" value="#0073aa">
								<code id="ec-primary-val">#0073aa</code>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( '테두리 색상', 'sw-bulk-email' ); ?></th>
							<td>
								<input type="color" id="ec-border" value="#cccccc">
								<code id="ec-border-val">#cccccc</code>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( '입력창 배경색', 'sw-bulk-email' ); ?></th>
							<td>
								<input type="color" id="ec-bg" value="#ffffff">
								<code id="ec-bg-val">#ffffff</code>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( '모서리 둥글기', 'sw-bulk-email' ); ?></th>
							<td>
								<input type="range" id="ec-radius" min="0" max="24" value="4" style="width:140px;vertical-align:middle;">
								<code id="ec-radius-val">4px</code>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( '폼 제목', 'sw-bulk-email' ); ?></th>
							<td><input type="text" id="ec-title" value="<?php esc_attr_e( '뉴스레터 구독', 'sw-bulk-email' ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( '설명 문구', 'sw-bulk-email' ); ?></th>
							<td><input type="text" id="ec-desc" value="<?php esc_attr_e( '새 소식을 이메일로 받아보세요.', 'sw-bulk-email' ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( '레이블 텍스트', 'sw-bulk-email' ); ?></th>
							<td><input type="text" id="ec-label" value="<?php esc_attr_e( '이메일 주소', 'sw-bulk-email' ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( '플레이스홀더', 'sw-bulk-email' ); ?></th>
							<td><input type="text" id="ec-placeholder" value="<?php esc_attr_e( 'you@example.com', 'sw-bulk-email' ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( '버튼 텍스트', 'sw-bulk-email' ); ?></th>
							<td><input type="text" id="ec-btn" value="<?php esc_attr_e( '구독하기', 'sw-bulk-email' ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( '광고 수신 동의 표시', 'sw-bulk-email' ); ?></th>
							<td>
								<label>
									<input type="checkbox" id="ec-show-ad" checked>
									<?php esc_html_e( '표시', 'sw-bulk-email' ); ?>
								</label>
							</td>
						</tr>
						<tr id="ec-ad-label-row">
							<th><?php esc_html_e( '광고 동의 문구', 'sw-bulk-email' ); ?></th>
							<td><input type="text" id="ec-ad-label" value="<?php esc_attr_e( '[선택] 광고 및 프로모션 정보 수신에 동의합니다.', 'sw-bulk-email' ); ?>" class="large-text"></td>
						</tr>
					</table>
				</div>

				<!-- ② 실시간 미리보기 -->
				<div class="sw-embed-panel sw-embed-panel--preview">
					<h2><?php esc_html_e( '② 미리보기', 'sw-bulk-email' ); ?></h2>
					<div class="sw-embed-preview-box" id="sw-embed-preview-container">
						<!-- JS가 폼 HTML을 여기에 렌더링 -->
					</div>
				</div>

			</div><!-- .sw-embed-builder -->

			<!-- ③ 생성된 코드 -->
			<div class="sw-embed-code-section">
				<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
					<h2 style="margin:0;"><?php esc_html_e( '③ 생성된 HTML 코드', 'sw-bulk-email' ); ?></h2>
					<button type="button" id="sw-embed-copy-btn" class="button button-primary">
						📋 <?php esc_html_e( '코드 복사', 'sw-bulk-email' ); ?>
					</button>
				</div>
				<p class="description">
					<?php esc_html_e( '아래 코드를 복사하여 원하는 HTML 페이지에 붙여넣으세요. CSS와 JavaScript가 모두 포함되어 있습니다.', 'sw-bulk-email' ); ?>
				</p>
				<textarea id="sw-embed-snippet" readonly
					style="width:100%;font-family:monospace;font-size:12px;background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:4px;resize:vertical;"
					rows="18"></textarea>
			</div>

			<!-- ④ 보안: 토큰 관리 -->
			<div class="sw-embed-code-section" style="margin-top:24px;">
				<h2><?php esc_html_e( '④ 보안 토큰', 'sw-bulk-email' ); ?></h2>
				<p class="description">
					<?php esc_html_e( '토큰은 외부 사이트에서 구독 요청을 인증하는 데 사용됩니다. 악용이 의심되면 재생성하세요. 재생성 시 기존에 배포된 코드를 새 코드로 교체해야 합니다.', 'sw-bulk-email' ); ?>
				</p>
				<p>
					<?php esc_html_e( '현재 토큰:', 'sw-bulk-email' ); ?>
					<code id="sw-token-display"><?php echo esc_html( $embed_token ); ?></code>
					&nbsp;
					<button type="button" id="sw-embed-regen-btn" class="button button-secondary">
						🔄 <?php esc_html_e( '토큰 재생성', 'sw-bulk-email' ); ?>
					</button>
				</p>
			</div>

		</div><!-- .wrap -->

		<style>
		.sw-embed-builder {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 24px;
			margin-top: 20px;
		}
		@media (max-width: 900px) {
			.sw-embed-builder { grid-template-columns: 1fr; }
		}
		.sw-embed-panel {
			background: #fff;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			padding: 20px 24px;
		}
		.sw-embed-panel h2 { margin-top: 0; font-size: 14px; padding-bottom: 10px; border-bottom: 1px solid #eee; }
		.sw-embed-controls td input[type="color"] { vertical-align: middle; width: 40px; height: 32px; padding: 2px; cursor: pointer; border: 1px solid #ccc; border-radius: 3px; }
		.sw-embed-controls td code { margin-left: 8px; font-size: 12px; color: #555; }
		.sw-embed-preview-box {
			background: #f5f5f5;
			border: 1px dashed #ccc;
			border-radius: 4px;
			padding: 24px 20px;
			min-height: 160px;
		}
		.sw-embed-code-section {
			background: #fff;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			padding: 20px 24px;
			margin-top: 24px;
		}
		.sw-embed-code-section h2 { margin-top: 0; font-size: 14px; }
		</style>

		<script>
		(function($){
			var ajaxUrl  = <?php echo wp_json_encode( $ajax_url ); ?>;
			var nonce    = <?php echo wp_json_encode( wp_create_nonce( 'sw_send_batch' ) ); ?>;
			var embedToken = <?php echo wp_json_encode( $embed_token ); ?>;

			/* ---- helpers ---- */
			function v(id){ return document.getElementById(id).value; }
			function checked(id){ return document.getElementById(id).checked; }
			function darken(hex) {
				var r = parseInt(hex.slice(1,3),16),
				    g = parseInt(hex.slice(3,5),16),
				    b = parseInt(hex.slice(5,7),16);
				return '#' + [Math.max(0,r-30), Math.max(0,g-30), Math.max(0,b-30)]
				    .map(function(x){ return ('0'+x.toString(16)).slice(-2); }).join('');
			}

			/* ---- build the HTML+CSS+JS snippet ---- */
			function buildSnippet() {
				var primary     = v('ec-primary');
				var primaryHov  = darken(primary);
				var border      = v('ec-border');
				var bg          = v('ec-bg');
				var radius      = v('ec-radius') + 'px';
				var title       = v('ec-title');
				var desc        = v('ec-desc');
				var label       = v('ec-label');
				var placeholder = v('ec-placeholder');
				var btn         = v('ec-btn');
				var showAd      = checked('ec-show-ad');
				var adLabel     = v('ec-ad-label');

				var adHtml = showAd
					? '\n    <div class="sw-embed-checkbox">\n' +
					  '      <input type="checkbox" id="sw-embed-ad" class="sw-embed-ad-input">\n' +
					  '      <label for="sw-embed-ad">' + escHtml(adLabel) + '</label>\n' +
					  '    </div>'
					: '';

				var css =
'<style>\n' +
':root {\n' +
'  --sw-embed-primary:       ' + primary + ';\n' +
'  --sw-embed-primary-hover: ' + primaryHov + ';\n' +
'  --sw-embed-border:        ' + border + ';\n' +
'  --sw-embed-bg:            ' + bg + ';\n' +
'  --sw-embed-radius:        ' + radius + ';\n' +
'}\n' +
'.sw-embed-wrap   { max-width: 480px; margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }\n' +
'.sw-embed-title  { font-size: 1.1em; font-weight: 700; margin: 0 0 .35em; color: #111; }\n' +
'.sw-embed-desc   { font-size: .9em; color: #666; margin: 0 0 1em; }\n' +
'.sw-embed-form   { display: flex; flex-wrap: wrap; gap: .5em; align-items: flex-end; }\n' +
'.sw-embed-label  { display: block; width: 100%; font-weight: 600; font-size: .9em; margin-bottom: .2em; color: #222; }\n' +
'.sw-embed-input  { flex: 1 1 180px; padding: .55em .75em; font-size: 1em;\n' +
'                   border: 1px solid var(--sw-embed-border); border-radius: var(--sw-embed-radius);\n' +
'                   background: var(--sw-embed-bg); box-sizing: border-box; }\n' +
'.sw-embed-input:focus { outline: 2px solid var(--sw-embed-primary); outline-offset: 1px; }\n' +
'.sw-embed-btn    { padding: .55em 1.25em; font-size: 1em; cursor: pointer;\n' +
'                   background: var(--sw-embed-primary); color: #fff; border: none;\n' +
'                   border-radius: var(--sw-embed-radius); transition: background .2s; }\n' +
'.sw-embed-btn:hover    { background: var(--sw-embed-primary-hover); }\n' +
'.sw-embed-btn:disabled { opacity: .6; cursor: not-allowed; }\n' +
'.sw-embed-checkbox { display: flex; align-items: center; gap: .5em; width: 100%; font-size: .85em; margin-top: .15em; }\n' +
'.sw-embed-notice { padding: .65em 1em; border-radius: var(--sw-embed-radius); margin-bottom: .75em; font-size: .9em; }\n' +
'.sw-embed-notice--success { background: #edfaef; border-left: 3px solid #46b450; color: #245b26; }\n' +
'.sw-embed-notice--error   { background: #fbeaea; border-left: 3px solid #dc3232; color: #7a1414; }\n' +
'</style>';

				var html =
'\n<!-- SW Bulk Email 구독 폼 ↓↓↓ 이 코드 전체를 원하는 위치에 붙여넣으세요 -->\n' +
css + '\n\n' +
'<div class="sw-embed-wrap">\n' +
(title ? '  <p class="sw-embed-title">' + escHtml(title) + '</p>\n' : '') +
(desc  ? '  <p class="sw-embed-desc">'  + escHtml(desc)  + '</p>\n' : '') +
'  <div class="sw-embed-notice" id="sw-embed-msg" style="display:none;"></div>\n' +
'  <form class="sw-embed-form" id="sw-embed-form">\n' +
'    <label class="sw-embed-label" for="sw-embed-email">' + escHtml(label) + '</label>\n' +
'    <input type="email" id="sw-embed-email" class="sw-embed-input" required\n' +
'           placeholder="' + escHtml(placeholder) + '">' +
adHtml + '\n' +
'    <button type="submit" class="sw-embed-btn">' + escHtml(btn) + '</button>\n' +
'  </form>\n' +
'</div>\n\n' +
'<script>\n' +
'(function () {\n' +
'  var endpoint = \'' + ajaxUrl + '\';\n' +
'  var token    = \'' + embedToken + '\';\n' +
'  document.getElementById(\'sw-embed-form\').addEventListener(\'submit\', function (e) {\n' +
'    e.preventDefault();\n' +
'    var form  = this;\n' +
'    var btn   = form.querySelector(\'.sw-embed-btn\');\n' +
'    var msg   = document.getElementById(\'sw-embed-msg\');\n' +
'    var email = form.querySelector(\'#sw-embed-email\').value;\n' +
'    var adEl  = form.querySelector(\'.sw-embed-ad-input\');\n' +
'    var adOptIn = (adEl && adEl.checked) ? \'1\' : \'0\';\n' +
'    btn.disabled = true;\n' +
'    msg.style.display = \'none\';\n' +
'    msg.className = \'sw-embed-notice\';\n' +
'    var body = \'action=sw_embed_subscribe\'\n' +
'             + \'&email=\'      + encodeURIComponent(email)\n' +
'             + \'&ad_opt_in=\' + adOptIn\n' +
'             + \'&token=\'     + encodeURIComponent(token);\n' +
'    fetch(endpoint, {\n' +
'      method: \'POST\',\n' +
'      headers: { \'Content-Type\': \'application/x-www-form-urlencoded\' },\n' +
'      body: body\n' +
'    })\n' +
'    .then(function (r) { return r.json(); })\n' +
'    .then(function (d) {\n' +
'      btn.disabled = false;\n' +
'      msg.textContent   = d.data ? d.data.message : \'오류가 발생했습니다.\';\n' +
'      msg.className     = \'sw-embed-notice \' + (d.success ? \'sw-embed-notice--success\' : \'sw-embed-notice--error\');\n' +
'      msg.style.display = \'block\';\n' +
'      if (d.success) { form.reset(); }\n' +
'    })\n' +
'    .catch(function () {\n' +
'      btn.disabled = false;\n' +
'      msg.textContent   = \'연결 오류가 발생했습니다.\';\n' +
'      msg.className     = \'sw-embed-notice sw-embed-notice--error\';\n' +
'      msg.style.display = \'block\';\n' +
'    });\n' +
'  });\n' +
'})();\n' +
'<\/script>\n' +
'<!-- SW Bulk Email 구독 폼 ↑↑↑ -->';

				return html;
			}

			/* ---- HTML-escape helper ---- */
			function escHtml(str) {
				return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
			}

			/* ---- render live preview from snippet ---- */
			function refreshPreview() {
				var primary    = v('ec-primary');
				var primaryHov = darken(primary);
				var border     = v('ec-border');
				var bg         = v('ec-bg');
				var radius     = v('ec-radius') + 'px';
				var title      = v('ec-title');
				var desc       = v('ec-desc');
				var label      = v('ec-label');
				var placeholder= v('ec-placeholder');
				var btn        = v('ec-btn');
				var showAd     = checked('ec-show-ad');
				var adLabel    = v('ec-ad-label');

				var container  = document.getElementById('sw-embed-preview-container');
				container.style.setProperty('--sw-embed-primary',       primary);
				container.style.setProperty('--sw-embed-primary-hover', primaryHov);
				container.style.setProperty('--sw-embed-border',        border);
				container.style.setProperty('--sw-embed-bg',            bg);
				container.style.setProperty('--sw-embed-radius',        radius);

				var adHtml = showAd
					? '<div class="sw-embed-checkbox">' +
					  '<input type="checkbox" id="sw-prev-ad">' +
					  '<label for="sw-prev-ad">' + escHtml(adLabel) + '</label>' +
					  '</div>'
					: '';

				container.innerHTML =
					'<style>' +
					'#sw-embed-preview-container{' +
					  '--sw-embed-primary:'       + primary    + ';' +
					  '--sw-embed-primary-hover:' + primaryHov + ';' +
					  '--sw-embed-border:'        + border     + ';' +
					  '--sw-embed-bg:'            + bg         + ';' +
					  '--sw-embed-radius:'        + radius     + ';' +
					'}' +
					'#sw-embed-preview-container .sw-embed-wrap   { max-width:100%;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }' +
					'#sw-embed-preview-container .sw-embed-title  { font-size:1.1em;font-weight:700;margin:0 0 .35em;color:#111; }' +
					'#sw-embed-preview-container .sw-embed-desc   { font-size:.9em;color:#666;margin:0 0 1em; }' +
					'#sw-embed-preview-container .sw-embed-form   { display:flex;flex-wrap:wrap;gap:.5em;align-items:flex-end; }' +
					'#sw-embed-preview-container .sw-embed-label  { display:block;width:100%;font-weight:600;font-size:.9em;margin-bottom:.2em;color:#222; }' +
					'#sw-embed-preview-container .sw-embed-input  { flex:1 1 180px;padding:.55em .75em;font-size:1em;border:1px solid var(--sw-embed-border);border-radius:var(--sw-embed-radius);background:var(--sw-embed-bg);box-sizing:border-box; }' +
					'#sw-embed-preview-container .sw-embed-btn    { padding:.55em 1.25em;font-size:1em;cursor:pointer;background:var(--sw-embed-primary);color:#fff;border:none;border-radius:var(--sw-embed-radius);transition:background .2s; }' +
					'#sw-embed-preview-container .sw-embed-btn:hover { background:var(--sw-embed-primary-hover); }' +
					'#sw-embed-preview-container .sw-embed-checkbox { display:flex;align-items:center;gap:.5em;width:100%;font-size:.85em;margin-top:.15em; }' +
					'</style>' +
					'<div class="sw-embed-wrap">' +
					(title ? '<p class="sw-embed-title">' + escHtml(title) + '</p>' : '') +
					(desc  ? '<p class="sw-embed-desc">'  + escHtml(desc)  + '</p>' : '') +
					'<form class="sw-embed-form" onsubmit="return false;">' +
					'<label class="sw-embed-label">' + escHtml(label) + '</label>' +
					'<input type="email" class="sw-embed-input" placeholder="' + escHtml(placeholder) + '">' +
					adHtml +
					'<button type="button" class="sw-embed-btn">' + escHtml(btn) + '</button>' +
					'</form>' +
					'</div>';
			}

			/* ---- bind all control events ---- */
			function bindAll() {
				var ids = ['ec-primary','ec-border','ec-bg','ec-radius',
				           'ec-title','ec-desc','ec-label','ec-placeholder',
				           'ec-btn','ec-show-ad','ec-ad-label'];
				ids.forEach(function(id){
					var el = document.getElementById(id);
					if (!el) return;
					el.addEventListener('input',  update);
					el.addEventListener('change', update);
				});

				// Sync color hex codes
				['primary','border','bg'].forEach(function(k){
					document.getElementById('ec-'+k).addEventListener('input', function(){
						document.getElementById('ec-'+k+'-val').textContent = this.value;
					});
				});
				document.getElementById('ec-radius').addEventListener('input', function(){
					document.getElementById('ec-radius-val').textContent = this.value + 'px';
				});

				// Toggle ad label row
				document.getElementById('ec-show-ad').addEventListener('change', function(){
					document.getElementById('ec-ad-label-row').style.display = this.checked ? '' : 'none';
				});
			}

			function update() {
				refreshPreview();
				document.getElementById('sw-embed-snippet').value = buildSnippet();
			}

			/* ---- copy button ---- */
			document.getElementById('sw-embed-copy-btn').addEventListener('click', function(){
				var ta = document.getElementById('sw-embed-snippet');
				ta.select();
				try {
					document.execCommand('copy');
					this.textContent = '✓ 복사됨!';
					var self = this;
					setTimeout(function(){ self.innerHTML = '📋 <?php esc_html_e( '코드 복사', 'sw-bulk-email' ); ?>'; }, 2000);
				} catch(e) {
					navigator.clipboard && navigator.clipboard.writeText(ta.value);
				}
			});

			/* ---- token regeneration ---- */
			document.getElementById('sw-embed-regen-btn').addEventListener('click', function(){
				if (!confirm('<?php esc_html_e( '토큰을 재생성하면 기존 코드가 작동하지 않습니다. 계속하시겠습니까?', 'sw-bulk-email' ); ?>')) return;
				var self = this;
				self.disabled = true;
				$.post(ajaxUrl, { action:'sw_embed_regen_token', nonce:nonce }, function(resp){
					self.disabled = false;
					if (resp.success) {
						embedToken = resp.data.token;
						document.getElementById('sw-token-display').textContent = embedToken;
						update();
						alert('<?php esc_html_e( '토큰이 재생성되었습니다. 코드를 다시 복사하여 교체해 주세요.', 'sw-bulk-email' ); ?>');
					}
				});
			});

			/* ---- init ---- */
			bindAll();
			update();

		})(jQuery);
		</script>
		<?php
	}

	// -----------------------------------------------------------------------
	// AJAX: embed token regeneration
	// -----------------------------------------------------------------------

	public function ajax_embed_regen_token() {
		check_ajax_referer( 'sw_send_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$token = bin2hex( random_bytes( 16 ) );
		update_option( 'sw_bulk_email_embed_token', $token );
		wp_send_json_success( [ 'token' => $token ] );
	}

	// -----------------------------------------------------------------------
	// Footer settings page (delegates to SW_Footer_Settings)
	// -----------------------------------------------------------------------

	public function render_footer_settings_page() {
		( new SW_Footer_Settings() )->render();
	}

	// -----------------------------------------------------------------------
	// Manual page
	// -----------------------------------------------------------------------

	public function render_manual_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sw-bulk-email' ) );
		}
		?>
		<div class="wrap sw-manual-page">
			<h1><?php esc_html_e( 'SW Bulk Email 사용 안내', 'sw-bulk-email' ); ?></h1>

			<div class="sw-manual-card">
				<h2><?php esc_html_e( '숏코드(Shortcode) 안내', 'sw-bulk-email' ); ?></h2>
				<p><?php esc_html_e( '글, 페이지, 위젯 등에 아래의 숏코드를 삽입하여 구독 폼 또는 발송 메일 목록을 표시할 수 있습니다.', 'sw-bulk-email' ); ?></p>

				<!-- ① 구독 폼 -->
				<h3><code>[sw_optin_form]</code></h3>
				<p><?php esc_html_e( '뉴스레터 구독 폼을 삽입합니다.', 'sw-bulk-email' ); ?></p>

				<h4><?php esc_html_e( '속성 (Attributes)', 'sw-bulk-email' ); ?></h4>
				<ul>
					<li>
						<strong><code>button</code></strong><br>
						<?php esc_html_e( '구독 버튼의 텍스트를 변경합니다.', 'sw-bulk-email' ); ?><br>
						<em><?php esc_html_e( '기본값:', 'sw-bulk-email' ); ?> "Subscribe"</em>
					</li>
				</ul>

				<h4><?php esc_html_e( '사용 예시', 'sw-bulk-email' ); ?></h4>
				<pre><code>[sw_optin_form]</code></pre>
				<pre><code>[sw_optin_form button="<?php esc_attr_e( '뉴스레터 구독하기', 'sw-bulk-email' ); ?>"]</code></pre>

				<hr style="margin:24px 0;">

				<!-- ② 발송 아카이브 -->
				<h3><code>[sw_email_archive]</code></h3>
				<p><?php esc_html_e( '관리자가 공개(is_public=1)로 설정한 발송 메일 목록을 표시합니다. 방문자가 제목을 클릭하면 본문을 모달로 확인할 수 있습니다.', 'sw-bulk-email' ); ?></p>

				<h4><?php esc_html_e( '속성 (Attributes)', 'sw-bulk-email' ); ?></h4>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th style="width:20%;"><?php esc_html_e( '속성', 'sw-bulk-email' ); ?></th>
							<th style="width:20%;"><?php esc_html_e( '기본값', 'sw-bulk-email' ); ?></th>
							<th><?php esc_html_e( '설명', 'sw-bulk-email' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>per_page</code></td>
							<td><code>10</code></td>
							<td><?php esc_html_e( '페이지당 표시할 메일 수를 지정합니다.', 'sw-bulk-email' ); ?></td>
						</tr>
						<tr>
							<td><code>type</code></td>
							<td><?php esc_html_e( '(전체)', 'sw-bulk-email' ); ?></td>
							<td>
								<?php esc_html_e( '특정 발송 유형만 필터링합니다.', 'sw-bulk-email' ); ?><br>
								<code>subscriber</code> — <?php esc_html_e( '구독자 메일만 표시', 'sw-bulk-email' ); ?><br>
								<code>ad</code> — <?php esc_html_e( '광고 메일만 표시', 'sw-bulk-email' ); ?><br>
								<code>system</code> — <?php esc_html_e( '전체 발송 메일만 표시', 'sw-bulk-email' ); ?>
							</td>
						</tr>
					</tbody>
				</table>

				<h4><?php esc_html_e( '사용 예시', 'sw-bulk-email' ); ?></h4>
				<pre><code>[sw_email_archive]</code></pre>
				<pre><code>[sw_email_archive per_page="5"]</code></pre>
				<pre><code>[sw_email_archive type="subscriber" per_page="10"]</code></pre>

				<h4><?php esc_html_e( '공개 설정 방법', 'sw-bulk-email' ); ?></h4>
				<p>
					<?php
					printf(
						wp_kses_post( __( '<strong>SW Bulk Email → 발송 내역</strong> 페이지에서 각 메일의 <strong>공개여부</strong> 버튼을 클릭하면 공개/비공개를 전환할 수 있습니다. 공개로 설정된 메일만 숏코드에 노출됩니다.', 'sw-bulk-email' ) )
					);
					?>
				</p>
			</div>

			<div class="sw-manual-card">
				<h2><?php esc_html_e( 'WP-Members 연동 안내', 'sw-bulk-email' ); ?></h2>
				<p><?php esc_html_e( 'WP-Members 플러그인이 활성화되어 있으면, 회원가입 폼에 자동으로 뉴스레터 구독 관련 필드가 추가됩니다.', 'sw-bulk-email' ); ?></p>
				<p><em><?php esc_html_e( '이 필드들은 WP-Members 필드 설정 화면에는 표시되지 않으며, 본 플러그인에 의해 자동으로 제어됩니다.', 'sw-bulk-email' ); ?></em></p>

				<h4><?php esc_html_e( '추가되는 필드', 'sw-bulk-email' ); ?></h4>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th style="width: 25%;"><?php esc_html_e( '필드 이름 (Field Name)', 'sw-bulk-email' ); ?></th>
						<th><?php esc_html_e( '라벨 (Label)', 'sw-bulk-email' ); ?></th>
						<th><?php esc_html_e( '설명', 'sw-bulk-email' ); ?></th>
					</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>sw_subscribe_newsletter</code></td>
							<td><?php esc_html_e( '뉴스레터 구독 동의', 'sw-bulk-email' ); ?></td>
							<td><?php esc_html_e( '회원가입 시 뉴스레터 구독에 동의하는지 여부를 결정합니다. 이 필드에 동의해야 구독자로 추가됩니다.', 'sw-bulk-email' ); ?></td>
						</tr>
						<tr>
							<td><code>sw_subscribe_ad</code></td>
							<td><?php esc_html_e( '광고성 정보 수신 동의 (선택)', 'sw-bulk-email' ); ?></td>
							<td><?php esc_html_e( '뉴스레터 구독에 동의한 사용자 중, 광고 및 프로모션 메일 수신에 추가로 동의하는지 여부를 결정합니다.', 'sw-bulk-email' ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>

		</div>
		<?php
	}
}