<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the admin archive pages (list and edit views).
 * AJAX handlers live in SW_Admin.
 */
class SW_Archive_Page {

	/**
	 * Route to the correct view based on $_GET['action'].
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sw-bulk-email' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		if ( 'edit' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
			$this->render_edit( $id );
		} else {
			$this->render_list();
		}
	}

	// -----------------------------------------------------------------------
	// List view
	// -----------------------------------------------------------------------

	private function render_list(): void {
		$per_page = 20;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_pg = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$offset     = ( $current_pg - 1 ) * $per_page;
		$total      = SW_DB::archive_count();
		$items      = SW_DB::archive_list( $per_page, $offset );
		$base_url   = admin_url( 'admin.php?page=sw-bulk-email-archive' );

		$type_labels = [
			'subscriber' => '구독자',
			'ad'         => '광고',
			'system'     => '전체',
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '발송 내역', 'sw-bulk-email' ); ?></h1>
			<p>
				<?php
				printf(
					esc_html__( '총 %d건', 'sw-bulk-email' ),
					(int) $total
				);
				?>
			</p>
			<div id="sw-archive-notice"></div>
			<table class="widefat striped sw-archive-table">
				<thead>
					<tr>
						<th style="width:36%;"><?php esc_html_e( '제목', 'sw-bulk-email' ); ?></th>
						<th><?php esc_html_e( '상태', 'sw-bulk-email' ); ?></th>
						<th><?php esc_html_e( '발송 유형', 'sw-bulk-email' ); ?></th>
						<th><?php esc_html_e( '성공', 'sw-bulk-email' ); ?></th>
						<th><?php esc_html_e( '실패', 'sw-bulk-email' ); ?></th>
						<th><?php esc_html_e( '공개여부', 'sw-bulk-email' ); ?></th>
						<th><?php esc_html_e( '저장일', 'sw-bulk-email' ); ?></th>
						<th><?php esc_html_e( '액션', 'sw-bulk-email' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $items ) ) : ?>
					<tr>
						<td colspan="8"><?php esc_html_e( '발송 내역이 없습니다.', 'sw-bulk-email' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $items as $item ) :
						$edit_url     = esc_url( $base_url . '&action=edit&id=' . (int) $item['id'] );
						$type_label   = $type_labels[ $item['mail_type'] ] ?? esc_html( $item['mail_type'] );
						$is_public    = (int) $item['is_public'];
						$public_label = $is_public ? '공개' : '비공개';
						$is_draft     = ( ( $item['status'] ?? 'sent' ) === 'draft' );
					?>
					<tr data-id="<?php echo (int) $item['id']; ?>">
						<td>
							<a href="<?php echo $edit_url; ?>">
								<?php echo esc_html( wp_trim_words( $item['subject'], 12, '…' ) ); ?>
							</a>
						</td>
						<td>
							<?php if ( $is_draft ) : ?>
								<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:#f0ad4e;color:#fff;font-size:11px;font-weight:600;">
									<?php esc_html_e( '임시저장', 'sw-bulk-email' ); ?>
								</span>
							<?php else : ?>
								<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:#46b450;color:#fff;font-size:11px;font-weight:600;">
									<?php esc_html_e( '발송완료', 'sw-bulk-email' ); ?>
								</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $type_label ); ?></td>
						<td><?php echo (int) $item['sent_count']; ?></td>
						<td><?php echo (int) $item['failed_count']; ?></td>
						<td>
							<?php if ( ! $is_draft ) : ?>
							<button type="button"
								class="button button-small sw-archive-toggle-public"
								data-id="<?php echo (int) $item['id']; ?>"
								data-public="<?php echo $is_public; ?>">
								<?php echo esc_html( $public_label ); ?>
							</button>
							<?php else : ?>
								<span style="color:#aaa;font-size:12px;">—</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $item['created_at'] ); ?></td>
						<td>
							<a href="<?php echo $edit_url; ?>" class="button button-small">
								<?php echo $is_draft ? esc_html__( '편집/발송', 'sw-bulk-email' ) : esc_html__( '편집', 'sw-bulk-email' ); ?>
							</a>
							&nbsp;
							<button type="button"
								class="button button-small button-link-delete sw-archive-delete-btn"
								data-id="<?php echo (int) $item['id']; ?>">삭제</button>
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
					'base'    => add_query_arg( 'paged', '%#%', $base_url ),
					'format'  => '',
					'current' => $current_pg,
					'total'   => $total_pages,
				] );
			}
			?>
		</div>
		<script>
		jQuery(document).ready(function($){
			var nonce = swBulkEmail.nonce;

			// Delete
			$('.sw-archive-delete-btn').on('click', function(){
				if (!confirm('이 발송 내역을 삭제하시겠습니까?')) { return; }
				var id  = $(this).data('id');
				var $tr = $(this).closest('tr');
				$.post(swBulkEmail.ajaxUrl, {
					action: 'sw_archive_delete',
					nonce:  nonce,
					id:     id
				}, function(resp){
					if (resp.success) {
						$tr.fadeOut(300, function(){ $(this).remove(); });
					} else {
						alert('삭제 실패');
					}
				});
			});

			// Toggle public
			$('.sw-archive-toggle-public').on('click', function(){
				var $btn     = $(this);
				var id       = $btn.data('id');
				var isPublic = parseInt($btn.data('public'), 10);
				var newState = isPublic ? 0 : 1;
				$.post(swBulkEmail.ajaxUrl, {
					action:    'sw_archive_toggle_public',
					nonce:     nonce,
					id:        id,
					is_public: newState
				}, function(resp){
					if (resp.success) {
						$btn.data('public', newState);
						$btn.text(newState ? '공개' : '비공개');
					} else {
						alert('변경 실패');
					}
				});
			});
		});
		</script>
		<?php
	}

	// -----------------------------------------------------------------------
	// Edit view
	// -----------------------------------------------------------------------

	private function render_edit( int $id ): void {
		if ( ! $id ) {
			echo '<div class="wrap"><p>' . esc_html__( '잘못된 접근입니다.', 'sw-bulk-email' ) . '</p></div>';
			return;
		}

		$item = SW_DB::archive_get( $id );
		if ( ! $item ) {
			echo '<div class="wrap"><p>' . esc_html__( '항목을 찾을 수 없습니다.', 'sw-bulk-email' ) . '</p></div>';
			return;
		}

		$list_url = admin_url( 'admin.php?page=sw-bulk-email-archive' );

		$type_labels = [
			'subscriber' => '구독자 메일',
			'ad'         => '광고 메일',
			'system'     => '전체 발송',
		];
		$type_label = $type_labels[ $item['mail_type'] ] ?? esc_html( $item['mail_type'] );
		$is_draft   = ( ( $item['status'] ?? 'sent' ) === 'draft' );
		?>
		<div class="wrap sw-bulk-email-wrap">
			<h1>
				<?php echo $is_draft
					? esc_html__( '임시저장 편집', 'sw-bulk-email' )
					: esc_html__( '발송 내역 편집', 'sw-bulk-email' ); ?>
				<?php if ( $is_draft ) : ?>
					<span style="display:inline-block;margin-left:8px;padding:3px 10px;border-radius:3px;background:#f0ad4e;color:#fff;font-size:13px;font-weight:600;vertical-align:middle;">
						<?php esc_html_e( '임시저장', 'sw-bulk-email' ); ?>
					</span>
				<?php endif; ?>
				<a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action">
					<?php esc_html_e( '목록으로', 'sw-bulk-email' ); ?>
				</a>
			</h1>

			<div id="sw-archive-edit-notice"></div>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="sw-archive-subject"><?php esc_html_e( '제목', 'sw-bulk-email' ); ?></label>
					</th>
					<td>
						<input type="text" id="sw-archive-subject" class="large-text"
							value="<?php echo esc_attr( $item['subject'] ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '본문', 'sw-bulk-email' ); ?></th>
					<td>
						<?php
						wp_editor( $item['body'], 'sw_archive_body', [
							'media_buttons' => false,
							'textarea_rows' => 20,
							'teeny'         => false,
						] );
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '발송 유형', 'sw-bulk-email' ); ?></th>
					<td><?php echo esc_html( $type_label ); ?></td>
				</tr>
				<?php if ( ! $is_draft ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( '발송 통계', 'sw-bulk-email' ); ?></th>
					<td>
						<span id="sw-archive-stat-sent"><?php echo (int) $item['sent_count']; ?></span>건 성공 /
						<span id="sw-archive-stat-failed"><?php echo (int) $item['failed_count']; ?></span>건 실패
					</td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php echo $is_draft ? esc_html__( '저장일', 'sw-bulk-email' ) : esc_html__( '발송일', 'sw-bulk-email' ); ?></th>
					<td><?php echo esc_html( $item['created_at'] ); ?></td>
				</tr>
			</table>

			<p class="submit">
				<button type="button" id="sw-archive-save-content-btn" class="button button-primary button-large"
					data-id="<?php echo (int) $id; ?>">
					<?php esc_html_e( '수정 내용 저장', 'sw-bulk-email' ); ?>
				</button>
			</p>

			<hr />
			<h2>
				<?php echo $is_draft
					? esc_html__( '발송', 'sw-bulk-email' )
					: esc_html__( '재발송', 'sw-bulk-email' ); ?>
			</h2>
			<p class="description">
				<?php echo $is_draft
					? esc_html__( '저장된 제목/본문으로 발송합니다. 발송 전 먼저 저장하세요.', 'sw-bulk-email' )
					: esc_html__( '저장된 제목/본문으로 재발송합니다. 발송 전 먼저 저장하세요.', 'sw-bulk-email' ); ?>
			</p>

			<div id="sw-archive-resend-status"></div>
			<div id="sw-archive-resend-progress-wrap" class="sw-progress-wrap" style="display:none;">
				<progress id="sw-archive-resend-progress-bar" value="0" max="100"></progress>
				<p id="sw-archive-resend-progress-label"></p>
			</div>

			<p class="submit" style="display:flex;gap:10px;flex-wrap:wrap;">
				<button type="button" id="sw-archive-send-sub-btn" class="button button-primary button-large"
					data-id="<?php echo (int) $id; ?>"
					data-status="<?php echo esc_attr( $item['status'] ?? 'sent' ); ?>"
					data-mail-type="<?php echo esc_attr( $item['mail_type'] ); ?>">
					<?php echo $is_draft
						? esc_html__( '구독자에게 발송', 'sw-bulk-email' )
						: esc_html__( '구독자에게 재발송', 'sw-bulk-email' ); ?>
				</button>
				<button type="button" id="sw-archive-send-ad-btn" class="button button-primary button-large"
					data-id="<?php echo (int) $id; ?>"
					data-status="<?php echo esc_attr( $item['status'] ?? 'sent' ); ?>"
					data-mail-type="<?php echo esc_attr( $item['mail_type'] ); ?>">
					<?php echo $is_draft
						? esc_html__( '광고 메일 발송', 'sw-bulk-email' )
						: esc_html__( '광고 메일 재발송', 'sw-bulk-email' ); ?>
				</button>
				<button type="button" id="sw-archive-send-sys-btn" class="button button-primary button-large sw-btn-danger"
					data-id="<?php echo (int) $id; ?>"
					data-status="<?php echo esc_attr( $item['status'] ?? 'sent' ); ?>"
					data-mail-type="<?php echo esc_attr( $item['mail_type'] ); ?>">
					<?php echo $is_draft
						? esc_html__( '전체 발송', 'sw-bulk-email' )
						: esc_html__( '전체 재발송', 'sw-bulk-email' ); ?>
				</button>
			</p>
		</div>
		<?php
	}
}
