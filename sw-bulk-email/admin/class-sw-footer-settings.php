<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page for the email footer (business info, links, social).
 */
class SW_Footer_Settings {

	const NONCE_ACTION = 'sw_footer_settings_save';
	const NONCE_FIELD  = 'sw_footer_nonce';

	public function __construct() {
		add_action( 'admin_post_sw_save_footer', [ $this, 'handle_save' ] );
	}

	// -----------------------------------------------------------------------
	// Save handler
	// -----------------------------------------------------------------------

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sw-bulk-email' ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		SW_Email_Footer::save_settings( [
			'business'     => $_POST['sw_footer']['business']      ?? [],
			'links'        => $_POST['sw_footer']['links']         ?? [],
			'social'       => $_POST['sw_footer']['social']        ?? [],
			'custom_social'=> $_POST['sw_footer']['custom_social'] ?? [],
		] );
		// phpcs:enable

		wp_safe_redirect( add_query_arg( [
			'page'    => 'sw-bulk-email-footer',
			'updated' => '1',
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	// -----------------------------------------------------------------------
	// Render
	// -----------------------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sw-bulk-email' ) );
		}

		$s       = SW_Email_Footer::get_settings();
		$updated = ! empty( $_GET['updated'] ); // phpcs:ignore
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '메일 푸터 설정', 'sw-bulk-email' ); ?></h1>
			<p class="description">
				<?php esc_html_e( '모든 발송 메일 하단에 자동으로 추가되는 공시 정보를 설정합니다.', 'sw-bulk-email' ); ?>
			</p>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( '설정이 저장되었습니다.', 'sw-bulk-email' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sw_save_footer">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>

				<?php $this->section_business( $s['business'] ); ?>
				<?php $this->section_links( $s['links'] ); ?>
				<?php $this->section_social( $s['social'] ); ?>
				<?php $this->section_custom_social( $s['custom_social'] ); ?>

				<?php submit_button( __( '설정 저장', 'sw-bulk-email' ) ); ?>
			</form>

			<?php $this->section_preview( $s ); ?>
		</div>

		<style>
		.sw-footer-section { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:20px 24px; margin-top:24px; }
		.sw-footer-section h2 { margin-top:0; padding-bottom:12px; border-bottom:1px solid #eee; font-size:14px; }
		.sw-footer-row-list { margin:0; padding:0; list-style:none; }
		.sw-footer-row-item { display:flex; gap:8px; align-items:center; margin-bottom:8px; }
		.sw-footer-row-item input[type=text],
		.sw-footer-row-item input[type=url] { flex:1; }
		.sw-footer-row-item .sw-icon-preview { width:32px;height:32px;border-radius:50%;background:#555;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:16px; flex-shrink:0; }
		.sw-social-platform { display:grid; grid-template-columns:140px 1fr; align-items:center; gap:8px; margin-bottom:10px; }
		.sw-social-platform label { font-weight:600; display:flex; align-items:center; gap:8px; }
		.sw-preview-box { background:#f9f9f9; border:1px solid #ddd; border-radius:4px; padding:24px; margin-top:24px; }
		</style>

		<script>
		(function(){
			// Add website link row
			document.getElementById('sw-add-link').addEventListener('click', function(){
				const tpl = document.getElementById('sw-link-tpl').content.cloneNode(true);
				const idx = document.querySelectorAll('#sw-links-list .sw-footer-row-item').length;
				tpl.querySelectorAll('[name*="__IDX__"]').forEach(function(el){
					el.name = el.name.replace('__IDX__', idx);
				});
				document.getElementById('sw-links-list').appendChild(tpl);
			});

			// Add custom social row
			document.getElementById('sw-add-custom-social').addEventListener('click', function(){
				const tpl = document.getElementById('sw-custom-social-tpl').content.cloneNode(true);
				const idx = document.querySelectorAll('#sw-custom-social-list .sw-footer-row-item').length;
				tpl.querySelectorAll('[name*="__IDX__"]').forEach(function(el){
					el.name = el.name.replace('__IDX__', idx);
				});
				document.getElementById('sw-custom-social-list').appendChild(tpl);
				bindMediaPicker();
			});

			// Remove row
			document.addEventListener('click', function(e){
				if( e.target.matches('.sw-remove-row') ){
					e.target.closest('.sw-footer-row-item').remove();
				}
			});

			// WP 미디어 라이브러리 이미지 선택
			function bindMediaPicker(){
				document.querySelectorAll('.sw-select-media').forEach(function(btn){
					if(btn._mediaBound) return;
					btn._mediaBound = true;
					btn.addEventListener('click', function(){
						const row       = btn.closest('.sw-footer-row-item');
						const urlInput  = row.querySelector('.sw-custom-icon-url');
						const imgEl     = row.querySelector('.sw-custom-icon-img');
						const removeBtn = row.querySelector('.sw-remove-media');

						const frame = wp.media({
							title:    '<?php esc_html_e( '아이콘 이미지 선택', 'sw-bulk-email' ); ?>',
							button:   { text: '<?php esc_html_e( '선택', 'sw-bulk-email' ); ?>' },
							multiple: false,
							library:  { type: 'image' }
						});

						frame.on('select', function(){
							const attachment = frame.state().get('selection').first().toJSON();
							urlInput.value        = attachment.url;
							imgEl.src             = attachment.url;
							imgEl.style.display   = '';
							if(removeBtn) removeBtn.style.display = '';
						});

						frame.open();
					});
				});

				document.querySelectorAll('.sw-remove-media').forEach(function(btn){
					if(btn._removeBound) return;
					btn._removeBound = true;
					btn.addEventListener('click', function(){
						const row      = btn.closest('.sw-footer-row-item');
						const urlInput = row.querySelector('.sw-custom-icon-url');
						const imgEl    = row.querySelector('.sw-custom-icon-img');
						urlInput.value      = '';
						imgEl.src           = '';
						imgEl.style.display = 'none';
						btn.style.display   = 'none';
					});
				});
			}
			bindMediaPicker();
		})();
		</script>
		<?php
	}

	// -----------------------------------------------------------------------
	// Section: Business info
	// -----------------------------------------------------------------------

	private function section_business( array $b ): void {
		$fields = [
			'ceo'     => __( '대표자명', 'sw-bulk-email' ),
			'address' => __( '주소', 'sw-bulk-email' ),
			'reg_no'  => __( '사업자번호', 'sw-bulk-email' ),
			'phone'   => __( '대표번호', 'sw-bulk-email' ),
		];
		?>
		<div class="sw-footer-section">
			<h2><?php esc_html_e( '① 사업자 정보', 'sw-bulk-email' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php foreach ( $fields as $key => $label ) : ?>
				<tr>
					<th scope="row">
						<label for="sw_footer_business_<?php echo esc_attr( $key ); ?>">
							<?php echo esc_html( $label ); ?>
						</label>
					</th>
					<td>
						<input type="text"
							id="sw_footer_business_<?php echo esc_attr( $key ); ?>"
							name="sw_footer[business][<?php echo esc_attr( $key ); ?>]"
							value="<?php echo esc_attr( $b[ $key ] ?? '' ); ?>"
							class="regular-text">
					</td>
				</tr>
				<?php endforeach; ?>
			</table>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Section: Website links
	// -----------------------------------------------------------------------

	private function section_links( array $links ): void {
		?>
		<div class="sw-footer-section">
			<h2><?php esc_html_e( '② 웹사이트 링크', 'sw-bulk-email' ); ?></h2>
			<p class="description">
				<?php esc_html_e( '메일 하단에 표시할 링크를 추가하세요. 여러 개 추가 가능합니다.', 'sw-bulk-email' ); ?>
			</p>

			<ul id="sw-links-list" class="sw-footer-row-list">
				<?php foreach ( $links as $i => $link ) : ?>
				<li class="sw-footer-row-item">
					<input type="text"
						name="sw_footer[links][<?php echo (int) $i; ?>][label]"
						value="<?php echo esc_attr( $link['label'] ); ?>"
						placeholder="<?php esc_attr_e( '링크 이름', 'sw-bulk-email' ); ?>"
						class="regular-text">
					<input type="url"
						name="sw_footer[links][<?php echo (int) $i; ?>][url]"
						value="<?php echo esc_attr( $link['url'] ); ?>"
						placeholder="https://example.com"
						class="regular-text">
					<button type="button" class="sw-remove-row button button-link-delete">
						<?php esc_html_e( '삭제', 'sw-bulk-email' ); ?>
					</button>
				</li>
				<?php endforeach; ?>
			</ul>

			<template id="sw-link-tpl">
				<li class="sw-footer-row-item">
					<input type="text"
						name="sw_footer[links][__IDX__][label]"
						placeholder="<?php esc_attr_e( '링크 이름', 'sw-bulk-email' ); ?>"
						class="regular-text">
					<input type="url"
						name="sw_footer[links][__IDX__][url]"
						placeholder="https://example.com"
						class="regular-text">
					<button type="button" class="sw-remove-row button button-link-delete">
						<?php esc_html_e( '삭제', 'sw-bulk-email' ); ?>
					</button>
				</li>
			</template>

			<p style="margin-top:12px;">
				<button type="button" id="sw-add-link" class="button">
					+ <?php esc_html_e( '링크 추가', 'sw-bulk-email' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Section: Social media (presets)
	// -----------------------------------------------------------------------

	private function section_social( array $social ): void {
		$icon_base_url = SW_BULK_EMAIL_URL . 'public/assets/icon/';
		$platforms = [
			'facebook'  => [ 'label' => 'Facebook',    'color' => '#1877F2', 'img' => $icon_base_url . 'facebook-brands-solid.png' ],
			'twitter'   => [ 'label' => 'X (Twitter)', 'color' => '#000000', 'img' => $icon_base_url . 'x-twitter-brands-solid.png' ],
			'instagram' => [ 'label' => 'Instagram',   'color' => '#E1306C', 'img' => $icon_base_url . 'instagram-brands-solid.png' ],
			'linkedin'  => [ 'label' => 'LinkedIn',    'color' => '#0A66C2', 'img' => $icon_base_url . 'linkedin-brands-solid.png' ],
		];
		?>
		<div class="sw-footer-section">
			<h2><?php esc_html_e( '③ 소셜 미디어', 'sw-bulk-email' ); ?></h2>
			<p class="description">
				<?php esc_html_e( '각 플랫폼의 프로필 URL을 입력하세요. 비워두면 해당 아이콘이 표시되지 않습니다.', 'sw-bulk-email' ); ?>
			</p>

			<?php foreach ( $platforms as $key => $meta ) : ?>
			<div class="sw-social-platform">
				<label for="sw_footer_social_<?php echo esc_attr( $key ); ?>">
					<span class="sw-icon-preview" style="background:<?php echo esc_attr( $meta['color'] ); ?>;">
						<img src="<?php echo esc_url( $meta['img'] ); ?>" width="18" height="18"
							 alt="<?php echo esc_attr( $meta['label'] ); ?>">
					</span>
					<?php echo esc_html( $meta['label'] ); ?>
				</label>
				<input type="url"
					id="sw_footer_social_<?php echo esc_attr( $key ); ?>"
					name="sw_footer[social][<?php echo esc_attr( $key ); ?>]"
					value="<?php echo esc_attr( $social[ $key ] ?? '' ); ?>"
					placeholder="https://..."
					class="regular-text">
			</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Section: Custom social (Font Awesome)
	// -----------------------------------------------------------------------

	private function section_custom_social( array $items ): void {
		?>
		<div class="sw-footer-section">
			<h2><?php esc_html_e( '④ 커스텀 소셜 링크', 'sw-bulk-email' ); ?></h2>
			<p class="description">
				<?php esc_html_e( '미디어 라이브러리에서 아이콘 이미지를 선택하고, 링크 이름과 URL을 입력하세요.', 'sw-bulk-email' ); ?>
			</p>

			<ul id="sw-custom-social-list" class="sw-footer-row-list">
				<?php foreach ( $items as $i => $item ) :
					$has_icon = ! empty( $item['icon'] );
				?>
				<li class="sw-footer-row-item">
					<span class="sw-icon-preview">
						<img class="sw-custom-icon-img"
							src="<?php echo $has_icon ? esc_url( $item['icon'] ) : ''; ?>"
							width="18" height="18" alt=""
							style="<?php echo $has_icon ? '' : 'display:none;'; ?>">
					</span>
					<input type="hidden"
						name="sw_footer[custom_social][<?php echo (int) $i; ?>][icon]"
						class="sw-custom-icon-url"
						value="<?php echo esc_attr( $item['icon'] ); ?>">
					<button type="button" class="sw-select-media button">
						<?php esc_html_e( '이미지 선택', 'sw-bulk-email' ); ?>
					</button>
					<button type="button" class="sw-remove-media button button-link-delete"
						style="<?php echo ! $has_icon ? 'display:none;' : ''; ?>">
						<?php esc_html_e( '이미지 제거', 'sw-bulk-email' ); ?>
					</button>
					<input type="text"
						name="sw_footer[custom_social][<?php echo (int) $i; ?>][label]"
						value="<?php echo esc_attr( $item['label'] ); ?>"
						placeholder="<?php esc_attr_e( '이름 (예: YouTube)', 'sw-bulk-email' ); ?>"
						style="width:160px;">
					<input type="url"
						name="sw_footer[custom_social][<?php echo (int) $i; ?>][url]"
						value="<?php echo esc_attr( $item['url'] ); ?>"
						placeholder="https://..."
						class="regular-text">
					<button type="button" class="sw-remove-row button button-link-delete">
						<?php esc_html_e( '행 삭제', 'sw-bulk-email' ); ?>
					</button>
				</li>
				<?php endforeach; ?>
			</ul>

			<template id="sw-custom-social-tpl">
				<li class="sw-footer-row-item">
					<span class="sw-icon-preview">
						<img class="sw-custom-icon-img" src="" width="18" height="18" alt="" style="display:none;">
					</span>
					<input type="hidden"
						name="sw_footer[custom_social][__IDX__][icon]"
						class="sw-custom-icon-url"
						value="">
					<button type="button" class="sw-select-media button">
						<?php esc_html_e( '이미지 선택', 'sw-bulk-email' ); ?>
					</button>
					<button type="button" class="sw-remove-media button button-link-delete" style="display:none;">
						<?php esc_html_e( '이미지 제거', 'sw-bulk-email' ); ?>
					</button>
					<input type="text"
						name="sw_footer[custom_social][__IDX__][label]"
						placeholder="<?php esc_attr_e( '이름 (예: YouTube)', 'sw-bulk-email' ); ?>"
						style="width:160px;">
					<input type="url"
						name="sw_footer[custom_social][__IDX__][url]"
						placeholder="https://..."
						class="regular-text">
					<button type="button" class="sw-remove-row button button-link-delete">
						<?php esc_html_e( '행 삭제', 'sw-bulk-email' ); ?>
					</button>
				</li>
			</template>

			<p style="margin-top:12px;">
				<button type="button" id="sw-add-custom-social" class="button">
					+ <?php esc_html_e( '커스텀 링크 추가', 'sw-bulk-email' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Section: Preview
	// -----------------------------------------------------------------------

	private function section_preview( array $s ): void {
		$preview_html = SW_Email_Footer::get_html( '#' );
		if ( ! $preview_html ) {
			return;
		}
		?>
		<div class="sw-preview-box">
			<h2 style="margin-top:0;"><?php esc_html_e( '푸터 미리보기', 'sw-bulk-email' ); ?></h2>
			<div style="background:#fff;border:1px solid #e0e0e0;border-radius:4px;padding:16px;">
				<p style="color:#ccc;font-size:12px;text-align:center;">— <?php esc_html_e( '이메일 본문', 'sw-bulk-email' ); ?> —</p>
				<?php
				// Allowed tags for footer preview (already sanitized by SW_Email_Footer)
				echo wp_kses( $preview_html, [
					'div' => [ 'style' => true ],
					'p'   => [ 'style' => true ],
					'a'   => [ 'href' => true, 'style' => true, 'title' => true ],
					'img' => [ 'src' => true, 'width' => true, 'height' => true, 'alt' => true, 'style' => true, 'border' => true ],
				] );
				?>
			</div>
		</div>
		<?php
	}
}
