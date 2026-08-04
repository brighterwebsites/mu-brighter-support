<?php
/**
 * SEO Schema — Meta Box Controller
 *
 * Registers the "Schema" meta box and handles save.
 * bw_custom_schema dual-write and fallback read removed — scos_schema_custom is the only key.
 *
 * @package    SiteEssentials
 * @subpackage Modules\SeoSchema
 * @since      1.0.0
 *
 * v1.0 | 2026-05-01
 * v1.1 | 2026-06-29 — Remove bw_custom_schema dual-write; scos-schema-output.php now reads scos_schema_custom first.
 * v1.2 | 2026-07-15 — Remove bw_custom_schema fallback read; migration confirmed complete on all sites.
 * v1.3 | 2026-07-24 — Stop excluding 'product' from fallback post type list (only
 *                      used when ContentArchitecture module is inactive).
 */

namespace SiteEssentials\Modules\SeoSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meta_Box {

	public static function init() {
		add_action( 'add_meta_boxes',        [ __CLASS__, 'register' ] );
		add_action( 'save_post',             [ __CLASS__, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		// Register post meta
		register_post_meta( '', 'scos_schema_custom', [
			'type'         => 'string',
			'single'       => true,
			'show_in_rest' => false,
			'auth_callback' => function () { return current_user_can( 'edit_posts' ); },
		] );
	}

	// -------------------------------------------------------------------------

	public static function get_post_types() {
		if ( class_exists( '\SiteEssentials\Modules\ContentArchitecture\Taxonomies' ) ) {
			return \SiteEssentials\Modules\ContentArchitecture\Taxonomies::get_post_types();
		}
		$exclude = [
			'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
			'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part',
			// 'product' intentionally included — tracked as editorial content.
			'product_variation', 'shop_order', 'shop_coupon',
		];
		return array_values( array_diff( get_post_types( [ 'public' => true ], 'names' ), $exclude ) );
	}

	// -------------------------------------------------------------------------

	public static function register() {
		foreach ( self::get_post_types() as $post_type ) {
			add_meta_box(
				'scos_seo_schema',
				__( 'Schema', 'site-essentials' ),
				[ __CLASS__, 'render' ],
				$post_type,
				'normal',
				'low'
			);
		}
	}

	// -------------------------------------------------------------------------

	public static function render( $post ) {
		wp_nonce_field( 'scos_schema_meta_box', 'scos_schema_nonce' );

		$custom_schema = get_post_meta( $post->ID, 'scos_schema_custom', true );

		// Pretty-print existing JSON so it's readable in the textarea
		if ( ! empty( $custom_schema ) ) {
			$decoded = json_decode( $custom_schema, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$custom_schema = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
		}

		include __DIR__ . '/views/meta-box.php';
	}

	// -------------------------------------------------------------------------

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['scos_schema_nonce'] )
			|| ! wp_verify_nonce( $_POST['scos_schema_nonce'], 'scos_schema_meta_box' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( wp_is_post_revision( $post_id ) ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
		if ( ! in_array( $post->post_type, self::get_post_types(), true ) ) { return; }

		if ( ! isset( $_POST['scos_schema_custom'] ) ) {
			return;
		}

		$raw = wp_unslash( $_POST['scos_schema_custom'] );
		$raw = trim( $raw );

		if ( empty( $raw ) ) {
			delete_post_meta( $post_id, 'scos_schema_custom' );
			return;
		}

		// Validate: must be parseable JSON
		json_decode( $raw );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			// Store the raw value anyway (user may be mid-edit); frontend validation
			// already warned them. Don't silently drop their work.
			update_post_meta( $post_id, 'scos_schema_custom', $raw );
			// Don't dual-write invalid JSON to the output key
			return;
		}

		// Normalise to compact JSON for storage
		$clean = wp_json_encode( json_decode( $raw, true ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		update_post_meta( $post_id, 'scos_schema_custom', $clean );
	}

	// -------------------------------------------------------------------------

	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) { return; }
		global $post;
		if ( ! $post || ! in_array( $post->post_type, self::get_post_types(), true ) ) { return; }

		$css_path = SITE_ESSENTIALS_PATH . 'Modules/SeoSchema/assets/meta-box.css';
		$js_path  = SITE_ESSENTIALS_PATH . 'Modules/SeoSchema/assets/meta-box.js';

		wp_enqueue_style(
			'scos-schema-meta-box',
			SITE_ESSENTIALS_URL . 'Modules/SeoSchema/assets/meta-box.css',
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1.0.0'
		);
		wp_enqueue_script(
			'scos-schema-meta-box',
			SITE_ESSENTIALS_URL . 'Modules/SeoSchema/assets/meta-box.js',
			[ 'jquery' ],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : '1.0.0',
			true
		);
	}
}
