<?php
/**
 * SEO Meta — Post Meta Registration
 *
 * @package    SiteEssentials
 * @subpackage Modules\SeoMeta
 * @since      1.0.0
 * v1.2 | 2026-06-04
 * v1.3 | 2026-07-17 — scos_seo_tldr sanitize_callback: sanitize_textarea_field →
 *                      wp_kses_post, to match Meta_Box::save()'s own sanitizer now
 *                      that the field supports bold/lists via wp_editor().
 */

namespace SiteEssentials\Modules\SeoMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meta_Fields {

	public static function init() {
		self::register();
	}

	public static function register() {
		$string = [
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => 'sanitize_text_field',
			'show_in_rest'      => false,
			'auth_callback'     => function () { return current_user_can( 'edit_posts' ); },
		];

		$textarea = array_merge( $string, [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
		$url      = array_merge( $string, [ 'sanitize_callback' => 'esc_url_raw' ] );

		// TLDR allows a small set of HTML tags (bold, lists, links) via wp_editor() —
		// must match Meta_Box::save()'s wp_kses_post() sanitizer, not the plain-text one.
		$tldr_field = array_merge( $string, [ 'sanitize_callback' => 'wp_kses_post' ] );

		// Core SEO
		register_post_meta( '', 'scos_seo_breadcrumb_title', $string );
		register_post_meta( '', 'scos_seo_tldr',             $tldr_field );
		register_post_meta( '', 'scos_seo_title',            $string );
		register_post_meta( '', 'scos_seo_description',      $textarea );

		// Advanced SEO
		register_post_meta( '', 'scos_seo_canonical',        $url );
		register_post_meta( '', 'scos_seo_robots', [
			'type'         => 'array',
			'single'       => true,
			'show_in_rest' => false,
			'auth_callback' => function () { return current_user_can( 'edit_posts' ); },
		] );
		register_post_meta( '', 'scos_seo_sitemap_exclude', [
			'type'         => 'array',
			'single'       => true,
			'show_in_rest' => false,
			'auth_callback' => function () { return current_user_can( 'edit_posts' ); },
		] );

		// Sitemap: user override — include in XML sitemap even when noindex is set.
		register_post_meta( '', 'scos_seo_sitemap_noindex_override', [
			'type'              => 'boolean',
			'single'            => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
			'show_in_rest'      => false,
			'auth_callback'     => function () { return current_user_can( 'edit_posts' ); },
		] );

		// Sitemap: internal flag — xml exclusion was auto-set by noindex (not manually set).
		register_post_meta( '', 'scos_seo_sitemap_noindex_auto', [
			'type'              => 'boolean',
			'single'            => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
			'show_in_rest'      => false,
			'auth_callback'     => function () { return current_user_can( 'edit_posts' ); },
		] );

		// Per-post flag: freeze modified date / og:updated_time on save.
		register_post_meta( '', 'scos_seo_freeze_og_date', [
			'type'              => 'boolean',
			'single'            => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
			'show_in_rest'      => false,
			'auth_callback'     => function () { return current_user_can( 'edit_posts' ); },
		] );
	}

	// ---- Robots options ----

	public static function robots_options() {
		return [
			'noindex'      => __( 'noindex — Do not index this page', 'site-essentials' ),
			'nofollow'     => __( 'nofollow — Do not follow links on this page', 'site-essentials' ),
			'noimageindex' => __( 'noimageindex — Do not index images', 'site-essentials' ),
			'nosnippet'    => __( 'nosnippet — No snippet / preview in results', 'site-essentials' ),
		];
	}

	// ---- Sitemap exclusion options ----

	public static function sitemap_options() {
		return [
			'xml'  => __( 'Exclude from XML Sitemap', 'site-essentials' ),
			'news' => __( 'Exclude from Google News Sitemap', 'site-essentials' ),
			'html' => __( 'Exclude from HTML Sitemap', 'site-essentials' ),
		];
	}

	/**
	 * Get supported post types for the SEO metabox.
	 * Reuses ContentArchitecture list when available; falls back to basics.
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
			'product', 'product_variation', 'shop_order', 'shop_coupon', 'shop_webhook',
		];
		return array_values( array_diff( get_post_types( [ 'public' => true ], 'names' ), $exclude ) );
	}
}
