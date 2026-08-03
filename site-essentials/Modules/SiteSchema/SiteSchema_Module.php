<?php
/**
 * Site Schema Module
 *
 * Manages site-wide JSON-LD schema templates (Local Business, Success Stories,
 * Product, Service), replacing the removed brighter-core schema admin screen.
 *
 * When active:
 * - Defines SCOS_SITE_SCHEMA_ACTIVE (legacy brighter-core bw-schema-admin removed)
 * - Stores schemas under scos_site_schema_* option keys
 * - Migrates existing bw_*_schema options on first admin_init
 * - Updates scos-schema-output.php reads via fallback in that file
 *
 * @package    SiteEssentials
 * @subpackage Modules\SiteSchema
 * @since      1.0.0
 * v1.2 | 2026-08-03 — WooCommerce schema tokens (price, sku, category, availability, currency, offers)
 */

namespace SiteEssentials\Modules\SiteSchema;

use SiteEssentials\Core\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SiteSchema_Module implements Module_Interface {

	public static function get_id() {
		return 'site_schema';
	}

	public static function get_name() {
		return __( 'Schema', 'site-essentials' );
	}

	public static function get_description() {
		return __( 'Per-post JSON-LD schema input + site-wide schema templates (Local Business, Success Stories, Product, Service).', 'site-essentials' );
	}

	public static function get_tier() {
		return 'basic';
	}

	public static function get_dependencies() {
		return [];
	}

	public static function get_version() {
		return '1.2.0';
	}

	public function init() {
		if ( ! defined( 'SCOS_SITE_SCHEMA_ACTIVE' ) ) {
			define( 'SCOS_SITE_SCHEMA_ACTIVE', true );
		}
		// Also covers per-post schema (previously SeoSchema_Module)
		if ( ! defined( 'SCOS_SCHEMA_ACTIVE' ) ) {
			define( 'SCOS_SCHEMA_ACTIVE', true );
		}

		// Boot per-post schema meta box
		$meta_box_file = dirname( __DIR__ ) . '/SeoSchema/Meta_Box.php';
		if ( file_exists( $meta_box_file ) ) {
			require_once $meta_box_file;
			\SiteEssentials\Modules\SeoSchema\Meta_Box::init();
		}

		require_once __DIR__ . '/Woo_Schema_Tokens.php';
		Woo_Schema_Tokens::register();

		add_action( 'admin_init', [ __CLASS__, 'run_migration' ], 5 );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_save' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	/**
	 * One-time migration: copy bw_*_schema options to scos_site_schema_*.
	 */
	public static function run_migration() {
		if ( get_option( 'scos_site_schema_migration_done' ) ) {
			return;
		}
		$map = [
			'bw_local_business_schema'  => 'scos_site_schema_local_business',
			'bw_success_stories_schema' => 'scos_site_schema_success_stories',
			'bw_product_schema'         => 'scos_site_schema_product',
			'bw_service_schema'         => 'scos_site_schema_service',
			'bw_product_post_ids'       => 'scos_site_schema_product_ids',
			'bw_service_post_ids'       => 'scos_site_schema_service_ids',
		];
		foreach ( $map as $old => $new ) {
			$old_val = get_option( $old );
			if ( $old_val !== false && $old_val !== '' ) {
				if ( get_option( $new ) === false || get_option( $new ) === '' ) {
					update_option( $new, $old_val );
				}
			}
		}
		update_option( 'scos_site_schema_migration_done', '1' );
	}

	/**
	 * Register schema options.
	 */
	public static function register_settings() {
		$options = [
			'scos_site_schema_local_business',
			'scos_site_schema_success_stories',
			'scos_site_schema_product',
			'scos_site_schema_service',
		];
		foreach ( $options as $option ) {
			register_setting( 'scos_site_schema_group', $option, [
				'type'              => 'string',
				'sanitize_callback' => function( $v ) { return is_string( $v ) ? $v : ''; },
				'default'           => '',
			] );
		}
		register_setting( 'scos_site_schema_group', 'scos_site_schema_product_ids', [
			'type'              => 'string',
			'sanitize_callback' => [ __CLASS__, 'sanitize_post_ids' ],
			'default'           => '',
		] );
		register_setting( 'scos_site_schema_group', 'scos_site_schema_woo_product_auto', [
			'type'              => 'string',
			'sanitize_callback' => function( $v ) { return $v === '1' ? '1' : ''; },
			'default'           => '',
		] );
		register_setting( 'scos_site_schema_group', 'scos_site_schema_product_purpose_auto', [
			'type'              => 'string',
			'sanitize_callback' => function( $v ) { return $v === '1' ? '1' : ''; },
			'default'           => '',
		] );
		register_setting( 'scos_site_schema_group', 'scos_site_schema_service_purpose_auto', [
			'type'              => 'string',
			'sanitize_callback' => function( $v ) { return $v === '1' ? '1' : ''; },
			'default'           => '',
		] );
		register_setting( 'scos_site_schema_group', 'scos_site_schema_service_ids', [
			'type'              => 'string',
			'sanitize_callback' => [ __CLASS__, 'sanitize_post_ids' ],
			'default'           => '',
		] );
	}

	public static function sanitize_post_ids( $value ) {
		$value = wp_strip_all_tags( $value );
		$ids   = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY ) ) );
		return implode( ',', $ids );
	}

	/**
	 * Handle direct form saves (nonce-verified POST, not via Settings API).
	 *
	 * Toggle options (checkboxes) are only updated for the tab that was submitted.
	 * Saving from the Product tab must not wipe Service toggles and vice versa,
	 * because absent checkboxes in POST are indistinguishable from "unchecked"
	 * without knowing which tab was active.
	 */
	public static function handle_save() {
		if ( ! isset( $_POST['scos_site_schema_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scos_site_schema_nonce'] ) ), 'scos_site_schema_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current_tab = isset( $_POST['current_tab_hidden'] ) ? sanitize_key( wp_unslash( $_POST['current_tab_hidden'] ) ) : '';

		$schema_keys = [
			'scos_site_schema_local_business',
			'scos_site_schema_success_stories',
			'scos_site_schema_product',
			'scos_site_schema_service',
		];
		foreach ( $schema_keys as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_option( $key, wp_unslash( trim( $_POST[ $key ] ) ) );
			}
		}

		if ( isset( $_POST['scos_site_schema_product_ids'] ) ) {
			update_option( 'scos_site_schema_product_ids', self::sanitize_post_ids( wp_unslash( $_POST['scos_site_schema_product_ids'] ) ) );
		}
		if ( isset( $_POST['scos_site_schema_service_ids'] ) ) {
			update_option( 'scos_site_schema_service_ids', self::sanitize_post_ids( wp_unslash( $_POST['scos_site_schema_service_ids'] ) ) );
		}

		// Only update tab-specific toggles when the owning tab was submitted,
		// to prevent wiping the other tab's values (unchecked checkboxes are absent from POST).
		if ( $current_tab === 'product' ) {
			update_option( 'scos_site_schema_woo_product_auto', isset( $_POST['scos_site_schema_woo_product_auto'] ) ? '1' : '' );
			update_option( 'scos_site_schema_product_purpose_auto', isset( $_POST['scos_site_schema_product_purpose_auto'] ) ? '1' : '' );
		}
		if ( $current_tab === 'service' ) {
			update_option( 'scos_site_schema_service_purpose_auto', isset( $_POST['scos_site_schema_service_purpose_auto'] ) ? '1' : '' );
		}

		wp_safe_redirect( add_query_arg( [ 'page' => 'site-essentials-schema', 'scos_schema_saved' => '1', 'tab' => $current_tab ?: 'local-business' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Enqueue JSON validation JS on our schema page.
	 */
	public static function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'site-essentials-schema' ) === false ) {
			return;
		}
		$asset_dir = plugin_dir_path( __FILE__ ) . 'assets/';
		$asset_url = plugin_dir_url( __FILE__ ) . 'assets/';
		wp_enqueue_script(
			'scos-site-schema-js',
			$asset_url . 'site-schema.js',
			[],
			file_exists( $asset_dir . 'site-schema.js' ) ? filemtime( $asset_dir . 'site-schema.js' ) : '1.0.0',
			true
		);
		// Pass business info data for the Generate button
		$biz_fields = function_exists( 'brighter_get_business_info_fields' ) ? brighter_get_business_info_fields() : [];
		$biz_data   = [];
		foreach ( $biz_fields as $field ) {
			$biz_data[ $field ] = function_exists( 'brighter_get_option' ) ? brighter_get_option( $field ) : get_option( 'scos_biz_' . $field, '' );
		}
		wp_localize_script( 'scos-site-schema-js', 'scosBizData', $biz_data );
		wp_localize_script( 'scos-site-schema-js', 'scosSiteSchema', [
			'homeUrl' => home_url( '/' ),
		] );
	}

	public function render_settings() {
		include __DIR__ . '/views/settings.php';
	}
}
