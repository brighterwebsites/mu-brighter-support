<?php
/**
 * Social Amplification — Post Meta Registration
 *
 * @package    SiteEssentials
 * @subpackage Modules\SocialAmplification
 * @since      1.0.0
 *
 * v1.1 | 2026-07-24 — Stop excluding 'product' from fallback post type list (only
 *                      used when ContentArchitecture module is inactive).
 */

namespace SiteEssentials\Modules\SocialAmplification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meta_Fields {

	public static function init() {
		self::register();
	}

	public static function register() {
		register_post_meta(
			'',
			'scos_sa_shortlink_slug',
			[
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => 'sanitize_title',
				'show_in_rest'      => false,
				'auth_callback'     => function () { return current_user_can( 'edit_posts' ); },
			]
		);
	}

	/**
	 * Get supported post types — delegates to ContentArchitecture if loaded.
	 *
	 * @return array
	 */
	public static function get_post_types() {
		if ( class_exists( '\SiteEssentials\Modules\ContentArchitecture\Taxonomies' ) ) {
			return \SiteEssentials\Modules\ContentArchitecture\Taxonomies::get_post_types();
		}

		$exclude = [
			'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
			'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part',
			// 'product' intentionally included — tracked as editorial content.
			'product_variation', 'shop_order', 'shop_coupon', 'shop_webhook',
		];
		return array_values( array_diff( get_post_types( [ 'public' => true ], 'names' ), $exclude ) );
	}
}
