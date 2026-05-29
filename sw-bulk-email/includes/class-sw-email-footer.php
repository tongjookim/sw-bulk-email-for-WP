<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the HTML email footer from saved settings.
 *
 * Option key : sw_bulk_email_footer
 * Shape      : {
 *   business : { ceo, address, reg_no, phone },
 *   links    : [ { label, url }, … ],
 *   social   : { facebook, twitter, instagram, linkedin },
 *   custom_social : [ { icon, label, url }, … ],
 * }
 */
class SW_Email_Footer {

	const OPTION_KEY = 'sw_bulk_email_footer';

	// -----------------------------------------------------------------------
	// Option helpers
	// -----------------------------------------------------------------------

	public static function get_settings(): array {
		$defaults = [
			'business'     => [ 'ceo' => '', 'address' => '', 'reg_no' => '', 'phone' => '' ],
			'links'        => [],
			'social'       => [ 'facebook' => '', 'twitter' => '', 'instagram' => '', 'linkedin' => '' ],
			'custom_social'=> [],
		];
		$saved = get_option( self::OPTION_KEY, [] );
		return array_replace_recursive( $defaults, is_array( $saved ) ? $saved : [] );
	}

	public static function save_settings( array $raw ): void {
		$settings = [
			'business' => [
				'ceo'     => sanitize_text_field( $raw['business']['ceo']     ?? '' ),
				'address' => sanitize_text_field( $raw['business']['address'] ?? '' ),
				'reg_no'  => sanitize_text_field( $raw['business']['reg_no']  ?? '' ),
				'phone'   => sanitize_text_field( $raw['business']['phone']   ?? '' ),
			],
			'links'         => self::sanitize_links( $raw['links']         ?? [] ),
			'social'        => self::sanitize_social( $raw['social']       ?? [] ),
			'custom_social' => self::sanitize_custom_social( $raw['custom_social'] ?? [] ),
		];
		update_option( self::OPTION_KEY, $settings );
	}

	private static function sanitize_links( array $items ): array {
		$out = [];
		foreach ( $items as $item ) {
			$label = sanitize_text_field( $item['label'] ?? '' );
			$url   = esc_url_raw( $item['url'] ?? '' );
			if ( $label && $url ) {
				$out[] = compact( 'label', 'url' );
			}
		}
		return $out;
	}

	private static function sanitize_social( array $raw ): array {
		$platforms = [ 'facebook', 'twitter', 'instagram', 'linkedin' ];
		$out = [];
		foreach ( $platforms as $p ) {
			$out[ $p ] = esc_url_raw( $raw[ $p ] ?? '' );
		}
		return $out;
	}

	private static function sanitize_custom_social( array $items ): array {
		$out = [];
		foreach ( $items as $item ) {
			$icon  = esc_url_raw( $item['icon']  ?? '' );
			$label = sanitize_text_field( $item['label'] ?? '' );
			$url   = esc_url_raw( $item['url']   ?? '' );
			if ( $url ) {
				$out[] = compact( 'icon', 'label', 'url' );
			}
		}
		return $out;
	}

	// -----------------------------------------------------------------------
	// HTML builder
	// -----------------------------------------------------------------------

	/**
	 * Returns the full footer HTML. Pass an unsubscribe URL to append the link.
	 *
	 * @param string $unsubscribe_url  Empty string = no unsubscribe block.
	 * @return string
	 */
	public static function get_html( string $unsubscribe_url = '' ): string {
		$s = self::get_settings();

		$has_business     = array_filter( $s['business'] );
		$has_links        = ! empty( $s['links'] );
		$has_social       = array_filter( $s['social'] ) || ! empty( $s['custom_social'] );
		$has_unsubscribe  = ! empty( $unsubscribe_url );

		if ( ! $has_business && ! $has_links && ! $has_social && ! $has_unsubscribe ) {
			return '';
		}

		$wrap_style = 'margin-top:32px;padding:20px 24px;border-top:2px solid #e8e8e8;'
			. 'font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#888;'
			. 'text-align:center;line-height:1.8;';

		$html  = '<div style="' . $wrap_style . '">';

		// Site name
		$site_name = get_bloginfo( 'name' );
		if ( $site_name ) {
			$html .= '<p style="margin:0 0 8px;font-size:14px;font-weight:bold;color:#555;">'
				. esc_html( $site_name ) . '</p>';
		}

		// Social icons
		if ( $has_social ) {
			$html .= self::build_social_row( $s['social'], $s['custom_social'] );
		}

		// Website links
		if ( $has_links ) {
			$html .= self::build_links_row( $s['links'] );
		}

		// Business info
		if ( $has_business ) {
			$html .= self::build_business_row( $s['business'] );
		}

		// Unsubscribe
		if ( $has_unsubscribe ) {
			$html .= '<p style="margin:12px 0 0;font-size:11px;color:#bbb;">'
				. esc_html__( '이 메일은 수신에 동의하신 분께 발송됩니다.', 'sw-bulk-email' ) . ' '
				. '<a href="' . esc_url( $unsubscribe_url ) . '" '
				. 'style="color:#bbb;text-decoration:underline;">'
				. esc_html__( '수신 거부', 'sw-bulk-email' )
				. '</a></p>';
		}

		$html .= '</div>';
		return $html;
	}

	// -----------------------------------------------------------------------
	// Row builders
	// -----------------------------------------------------------------------

	private static function build_business_row( array $b ): string {
		$parts = [];
		if ( $b['ceo'] )     $parts[] = esc_html__( '대표자', 'sw-bulk-email' ) . ': ' . esc_html( $b['ceo'] );
		if ( $b['address'] ) $parts[] = esc_html__( '주소', 'sw-bulk-email' )   . ': ' . esc_html( $b['address'] );
		if ( $b['reg_no'] )  $parts[] = esc_html__( '사업자번호', 'sw-bulk-email' ) . ': ' . esc_html( $b['reg_no'] );
		if ( $b['phone'] )   $parts[] = esc_html__( '대표번호', 'sw-bulk-email' )   . ': ' . esc_html( $b['phone'] );

		return '<p style="margin:8px 0 0;font-size:11px;color:#aaa;">'
			. implode( ' &nbsp;|&nbsp; ', $parts )
			. '</p>';
	}

	private static function build_links_row( array $links ): string {
		$link_style = 'color:#888;text-decoration:none;';
		$parts = [];
		foreach ( $links as $l ) {
			$parts[] = '<a href="' . esc_url( $l['url'] ) . '" style="' . $link_style . '">'
				. esc_html( $l['label'] ) . '</a>';
		}
		return '<p style="margin:8px 0 0;">' . implode( ' &nbsp;|&nbsp; ', $parts ) . '</p>';
	}

	private static function build_social_row( array $social, array $custom ): string {
		// Preset platforms — hosted PNG icons (white on brand-color circle).
		$icon_base_url = SW_BULK_EMAIL_URL . 'public/assets/icon/';
		$presets = [
			'facebook'  => [ 'label' => 'Facebook',   'color' => '#1877F2', 'img' => $icon_base_url . 'facebook-brands-solid.png' ],
			'twitter'   => [ 'label' => 'X (Twitter)', 'color' => '#000000', 'img' => $icon_base_url . 'x-twitter-brands-solid.png' ],
			'instagram' => [ 'label' => 'Instagram',   'color' => '#E1306C', 'img' => $icon_base_url . 'instagram-brands-solid.png' ],
			'linkedin'  => [ 'label' => 'LinkedIn',    'color' => '#0A66C2', 'img' => $icon_base_url . 'linkedin-brands-solid.png' ],
		];

		// <a> style: 8px padding around a 16px icon = 32px circle.
		$a_img_style  = 'display:inline-block;border-radius:50%;padding:8px;margin:0 4px;vertical-align:middle;line-height:0;font-size:0;';
		$img_style    = 'display:block;width:16px;height:16px;border:0;';
		$a_text_style = 'display:inline-block;width:32px;height:32px;border-radius:50%;'
			. 'margin:0 4px;vertical-align:middle;line-height:32px;text-align:center;'
			. 'font-size:11px;font-weight:bold;color:#fff;';

		$icons = '';

		foreach ( $presets as $key => $meta ) {
			if ( empty( $social[ $key ] ) ) {
				continue;
			}
			$icons .= '<a href="' . esc_url( $social[ $key ] ) . '" '
				. 'style="' . $a_img_style . 'background:' . $meta['color'] . ';" '
				. 'title="' . esc_attr( $meta['label'] ) . '">'
				. '<img src="' . esc_url( $meta['img'] ) . '" width="16" height="16" '
				. 'alt="' . esc_attr( $meta['label'] ) . '" style="' . $img_style . '">'
				. '</a>';
		}

		// Custom social links — use uploaded image URL if set, otherwise text initials.
		foreach ( $custom as $c ) {
			if ( ! empty( $c['icon'] ) ) {
				$icons .= '<a href="' . esc_url( $c['url'] ) . '" '
					. 'style="' . $a_img_style . 'background:#555;" '
					. 'title="' . esc_attr( $c['label'] ) . '">'
					. '<img src="' . esc_url( $c['icon'] ) . '" width="16" height="16" '
					. 'alt="' . esc_attr( $c['label'] ) . '" style="' . $img_style . '">'
					. '</a>';
			} else {
				$icons .= '<a href="' . esc_url( $c['url'] ) . '" '
					. 'style="' . $a_text_style . 'background:#555;" '
					. 'title="' . esc_attr( $c['label'] ) . '">'
					. esc_html( mb_strtoupper( mb_substr( $c['label'], 0, 2 ) ) )
					. '</a>';
			}
		}

		return '<p style="margin:0 0 8px;">' . $icons . '</p>';
	}
}
