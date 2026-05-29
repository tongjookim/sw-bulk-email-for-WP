<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [sw_email_archive] shortcode – displays publicly archived emails.
 */
class SW_Archive_Shortcode {

	public function __construct() {
		add_shortcode( 'sw_email_archive', [ $this, 'render_shortcode' ] );

		// Public AJAX for fetching email body (nopriv).
		// Note: the admin handler also covers wp_ajax_sw_get_archive_body.
		// The nopriv hook registration here is a fallback for contexts where
		// SW_Admin is not loaded (e.g. non-admin AJAX requests on the front-end).
		// We guard with has_action() to avoid duplicate registration.
		if ( ! has_action( 'wp_ajax_nopriv_sw_get_archive_body' ) ) {
			add_action( 'wp_ajax_nopriv_sw_get_archive_body', [ $this, 'ajax_get_body' ] );
		}

		// Enqueue public styles on shortcode pages.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_public_assets' ] );
	}

	// -----------------------------------------------------------------------
	// Assets
	// -----------------------------------------------------------------------

	public function enqueue_public_assets(): void {
		wp_enqueue_style(
			'sw-public-css',
			SW_BULK_EMAIL_URL . 'public/css/sw-public.css',
			[],
			SW_BULK_EMAIL_VERSION
		);
	}

	// -----------------------------------------------------------------------
	// Shortcode renderer
	// -----------------------------------------------------------------------

	/**
	 * @param array|string $atts
	 * @return string HTML
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			[
				'per_page' => 10,
				'type'     => '',   // '' = all types; or 'subscriber', 'ad', 'system'
			],
			$atts,
			'sw_email_archive'
		);

		$per_page = max( 1, (int) $atts['per_page'] );
		$type     = sanitize_key( $atts['type'] );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page = isset( $_GET['archive_page'] ) ? max( 1, (int) $_GET['archive_page'] ) : 1;
		$offset       = ( $current_page - 1 ) * $per_page;

		$total = SW_DB::archive_count_public();
		$items = SW_DB::archive_list_public( $per_page, $offset );

		// Filter by type if specified.
		if ( $type ) {
			$items = array_filter( $items, function( $item ) use ( $type ) {
				return $item['mail_type'] === $type;
			} );
		}

		$type_labels = [
			'subscriber' => '구독자',
			'ad'         => '광고',
			'system'     => '전체',
		];

		$type_colors = [
			'subscriber' => '#0073aa',
			'ad'         => '#d63638',
			'system'     => '#607d8b',
		];

		ob_start();
		?>
		<div class="sw-archive-wrap">
			<?php if ( empty( $items ) ) : ?>
				<p class="sw-archive-empty"><?php esc_html_e( '공개된 발송 내역이 없습니다.', 'sw-bulk-email' ); ?></p>
			<?php else : ?>
			<ul class="sw-archive-list">
				<?php foreach ( $items as $item ) :
					$mail_type  = $item['mail_type'];
					$badge_text = $type_labels[ $mail_type ] ?? $mail_type;
					$badge_color = $type_colors[ $mail_type ] ?? '#607d8b';
					$date_fmt   = date_i18n( get_option( 'date_format' ), strtotime( $item['created_at'] ) );
				?>
				<li class="sw-archive-item" data-id="<?php echo (int) $item['id']; ?>">
					<div class="sw-archive-item-header">
						<span class="sw-archive-badge" style="background:<?php echo esc_attr( $badge_color ); ?>;">
							<?php echo esc_html( $badge_text ); ?>
						</span>
						<span class="sw-archive-date"><?php echo esc_html( $date_fmt ); ?></span>
					</div>
					<div class="sw-archive-item-title">
						<?php echo esc_html( $item['subject'] ); ?>
					</div>
					<div class="sw-archive-item-actions">
						<button type="button" class="sw-archive-view-btn"
							data-id="<?php echo (int) $item['id']; ?>">
							<?php esc_html_e( '내용 보기', 'sw-bulk-email' ); ?>
						</button>
					</div>
				</li>
				<?php endforeach; ?>
			</ul>

			<?php
			// Pagination.
			$total_pages = (int) ceil( $total / $per_page );
			if ( $total_pages > 1 ) :
				$base = add_query_arg( 'archive_page', '%#%', get_permalink() );
			?>
			<div class="sw-archive-pagination">
				<?php
				echo paginate_links( [
					'base'    => $base,
					'format'  => '',
					'current' => $current_page,
					'total'   => $total_pages,
					'type'    => 'plain',
				] );
				?>
			</div>
			<?php endif; ?>
			<?php endif; ?>
		</div>

		<!-- Archive modal -->
		<div id="sw-archive-modal" class="sw-archive-modal" role="dialog" aria-modal="true"
		     aria-label="<?php esc_attr_e( '이메일 내용 보기', 'sw-bulk-email' ); ?>"
		     style="display:none;">
			<div class="sw-archive-modal-overlay" id="sw-archive-modal-overlay"></div>
			<div class="sw-archive-modal-inner">
				<div class="sw-archive-modal-header">
					<h3 id="sw-archive-modal-title"></h3>
					<button type="button" id="sw-archive-modal-close"
						aria-label="<?php esc_attr_e( '닫기', 'sw-bulk-email' ); ?>">✕</button>
				</div>
				<div class="sw-archive-modal-body">
					<iframe id="sw-archive-iframe" class="sw-archive-iframe"
						sandbox="allow-same-origin"
						title="<?php esc_attr_e( '이메일 내용', 'sw-bulk-email' ); ?>"></iframe>
				</div>
			</div>
		</div>

		<script>
		(function($){
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			$('.sw-archive-view-btn').on('click', function(){
				var id = $(this).data('id');
				$.post(ajaxUrl, {
					action: 'sw_get_archive_body',
					id:     id
				}, function(resp){
					if (!resp.success) {
						alert(resp.data ? resp.data.message : '불러오기 실패');
						return;
					}
					var d = resp.data;
					$('#sw-archive-modal-title').text(d.subject);

					var iframe = document.getElementById('sw-archive-iframe');
					var doc    = iframe.contentDocument || iframe.contentWindow.document;
					doc.open();
					doc.write(
						'<!DOCTYPE html><html><head><meta charset="utf-8">' +
						'<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;' +
						'max-width:680px;margin:0 auto;padding:24px 20px;color:#333;line-height:1.7;}' +
						'img{max-width:100%;}</style></head><body>' +
						d.body +
						'</body></html>'
					);
					doc.close();

					$('#sw-archive-modal').fadeIn(180);
					$('#sw-archive-modal-close').trigger('focus');
				});
			});

			$('#sw-archive-modal-close, #sw-archive-modal-overlay').on('click', function(){
				$('#sw-archive-modal').fadeOut(150);
			});

			$(document).on('keydown', function(e){
				if (e.key === 'Escape' && $('#sw-archive-modal').is(':visible')) {
					$('#sw-archive-modal').fadeOut(150);
				}
			});
		}(jQuery));
		</script>
		<?php
		return ob_get_clean();
	}

	// -----------------------------------------------------------------------
	// AJAX: get archive body (front-end, public items only)
	// -----------------------------------------------------------------------

	public function ajax_get_body(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( ! $id ) {
			wp_send_json_error( [ 'message' => '유효하지 않은 ID입니다.' ], 400 );
		}

		$item = SW_DB::archive_get( $id );

		if ( ! $item ) {
			wp_send_json_error( [ 'message' => '항목을 찾을 수 없습니다.' ], 404 );
		}

		if ( ! (int) $item['is_public'] ) {
			wp_send_json_error( [ 'message' => '비공개 항목입니다.' ], 403 );
		}

		wp_send_json_success( [
			'subject' => $item['subject'],
			'body'    => $item['body'],
		] );
	}
}
