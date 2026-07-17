<?php
/**
 * SEO Meta — Meta Box Controller
 *
 * Registers the "SEO" meta box on all supported post types.
 * Handles save with:
 *  - Primary write to scos_seo_* keys
 *  - Dual-write to SEOPress keys for backward compatibility:
 *      scos_seo_title       → _seopress_titles_title
 *      scos_seo_description → _seopress_titles_desc
 *      scos_seo_canonical   → _seopress_robots_canonical
 *      scos_seo_robots (noindex) → _seopress_robots_index = 'yes' | 'no'
 *      scos_seo_breadcrumb_title → _seopress_robots_breadcrumbs
 *
 *  - bw_tldr dual-write removed — all consumers read scos_seo_tldr only
 *
 * Reads existing scos_seo_* values; bw_ legacy fallbacks removed (migration confirmed complete).
 *
 * @package    SiteEssentials
 * @subpackage Modules\SeoMeta
 * @since      1.0.0
 *
 * v1.1 | 2026-06-24 — Register scos-seo-meta ability category; load Suggest_Seo_Meta
 *                      and Suggest_Tldr abilities; extend enqueue_assets() with
 *                      scos-seo-suggest.js and ScosSeoSuggest localization data.
 * v1.2 | 2026-06-29 — Remove bw_tldr dual-write; consumers now read scos_seo_tldr first.
 * v1.3 | 2026-07-01 — Register scos-media ability category; load Fill_Image_Meta ability.
 * v1.4 | 2026-07-15 — Remove bw_tldr fallback read; migration confirmed complete on all sites.
 */

namespace SiteEssentials\Modules\SeoMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meta_Box {

	public static function init() {
		add_action( 'add_meta_boxes',                   [ __CLASS__, 'register' ] );
		add_action( 'add_meta_boxes',                   [ __CLASS__, 'remove_legacy_meta_boxes' ], 999, 1 );
		add_action( 'save_post',                        [ __CLASS__, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts',            [ __CLASS__, 'enqueue_assets' ] );
		add_filter( 'wp_insert_post_data',              [ __CLASS__, 'maybe_freeze_modified_date' ], 10, 2 );
		add_action( 'wp_abilities_api_categories_init', [ __CLASS__, 'register_ability_category' ] );

		if ( class_exists( 'WordPress\AI\Abstracts\Abstract_Ability' ) ) {
			require_once __DIR__ . '/Abilities/Suggest_Seo_Meta/Suggest_Seo_Meta.php';
			require_once __DIR__ . '/Abilities/Suggest_Tldr/Suggest_Tldr.php';
			require_once __DIR__ . '/Abilities/Fill_Image_Meta/Fill_Image_Meta.php';
		}
	}

	/**
	 * Register the scos-seo-meta and scos-media ability categories.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public static function register_ability_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category( 'scos-seo-meta', [
			'label'       => __( 'SCOS: SEO Meta', 'site-essentials' ),
			'description' => __( 'AI-assisted suggestions for SEO meta fields.', 'site-essentials' ),
		] );
		wp_register_ability_category( 'scos-media', [
			'label'       => __( 'SCOS: Media', 'site-essentials' ),
			'description' => __( 'AI-assisted media metadata: alt text, titles, and taxonomy assignment.', 'site-essentials' ),
		] );
	}

	// -------------------------------------------------------------------------

	public static function register() {
		foreach ( Meta_Fields::get_post_types() as $post_type ) {
			add_meta_box(
				'scos_seo_meta',
				__( 'SEO', 'site-essentials' ),
				[ __CLASS__, 'render' ],
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Remove legacy "Breadcrumb (Short Title)" box when SEO Meta owns breadcrumb label.
	 *
	 * @since 1.0.0
	 * @param string $post_type Current post type on the edit screen.
	 */
	public static function remove_legacy_meta_boxes( string $post_type ): void {
		if ( ! defined( 'SCOS_SEO_ACTIVE' ) ) {
			return;
		}
		foreach ( [ 'normal', 'side', 'advanced' ] as $ctx ) {
			remove_meta_box( 'bw_breadcrumb_meta', $post_type, $ctx );
		}
	}

	// -------------------------------------------------------------------------

	public static function render( $post ) {
		wp_nonce_field( 'scos_seo_meta_box', 'scos_seo_nonce' );

		// ---- Read primary scos_seo_* values first, fall back to legacy ----

		$breadcrumb_title = get_post_meta( $post->ID, 'scos_seo_breadcrumb_title', true );
		if ( empty( $breadcrumb_title ) ) {
			// Fallback: SEOPress stores the breadcrumb label in _seopress_robots_breadcrumbs.
			$breadcrumb_title = get_post_meta( $post->ID, '_seopress_robots_breadcrumbs', true );
		}

		$tldr = get_post_meta( $post->ID, 'scos_seo_tldr', true );

		$title = get_post_meta( $post->ID, 'scos_seo_title', true );
		if ( empty( $title ) ) {
			$title = get_post_meta( $post->ID, '_seopress_titles_title', true );
		}

		$description = get_post_meta( $post->ID, 'scos_seo_description', true );
		if ( empty( $description ) ) {
			$description = get_post_meta( $post->ID, '_seopress_titles_desc', true );
		}

		$canonical = get_post_meta( $post->ID, 'scos_seo_canonical', true );
		if ( empty( $canonical ) ) {
			$canonical = get_post_meta( $post->ID, '_seopress_robots_canonical', true );
		}

		// Robots: scos_seo_robots (array), or detect legacy noindex
		$robots = (array) get_post_meta( $post->ID, 'scos_seo_robots', true );
		if ( empty( $robots ) ) {
			// Migrate from SEOPress noindex value
			$seopress_noindex = get_post_meta( $post->ID, '_seopress_robots_index', true );
			if ( 'yes' === $seopress_noindex ) {
				$robots = [ 'noindex' ];
			}
		}

		$sitemap_exclude          = (array) get_post_meta( $post->ID, 'scos_seo_sitemap_exclude', true );
		$sitemap_noindex_override = (bool) get_post_meta( $post->ID, 'scos_seo_sitemap_noindex_override', true );
		$sitemap_noindex_auto     = (bool) get_post_meta( $post->ID, 'scos_seo_sitemap_noindex_auto', true );

		$freeze_date        = (bool) get_post_meta( $post->ID, 'scos_seo_freeze_og_date', true );
		$global_freeze_date = (bool) get_option( 'scos_seo_freeze_modified_date', false );

		include __DIR__ . '/views/meta-box.php';
	}

	// -------------------------------------------------------------------------

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['scos_seo_nonce'] )
			|| ! wp_verify_nonce( $_POST['scos_seo_nonce'], 'scos_seo_meta_box' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( wp_is_post_revision( $post_id ) ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
		if ( ! in_array( $post->post_type, Meta_Fields::get_post_types(), true ) ) { return; }

		// ---- Breadcrumb title (human-readable label for nav, NOT the YOURLS slug) ----
		if ( isset( $_POST['scos_seo_breadcrumb_title'] ) ) {
			$val = sanitize_text_field( $_POST['scos_seo_breadcrumb_title'] );
			self::update_or_delete( $post_id, 'scos_seo_breadcrumb_title', $val );
			// Dual-write: SEOPress reads _seopress_robots_breadcrumbs for breadcrumb display.
			// _bw_breadcrumb (YOURLS shortlink slug) is managed by the Social Amplification module.
			self::update_or_delete( $post_id, '_seopress_robots_breadcrumbs', $val );
		}

		// ---- TLDR ----
		if ( isset( $_POST['scos_seo_tldr'] ) ) {
			$val = wp_kses_post( $_POST['scos_seo_tldr'] );
			self::update_or_delete( $post_id, 'scos_seo_tldr', $val );
		}

		// ---- Meta title ----
		if ( isset( $_POST['scos_seo_title'] ) ) {
			$val = sanitize_text_field( $_POST['scos_seo_title'] );
			self::update_or_delete( $post_id, 'scos_seo_title', $val );
			// Dual-write: SEOPress reads this for frontend <title> and og:title
			self::update_or_delete( $post_id, '_seopress_titles_title', $val );
		}

		// ---- Meta description ----
		if ( isset( $_POST['scos_seo_description'] ) ) {
			$val = sanitize_textarea_field( $_POST['scos_seo_description'] );
			self::update_or_delete( $post_id, 'scos_seo_description', $val );
			// Dual-write: SEOPress reads this for frontend meta description
			self::update_or_delete( $post_id, '_seopress_titles_desc', $val );
		}

		// ---- Canonical URL ----
		if ( isset( $_POST['scos_seo_canonical'] ) ) {
			$val = esc_url_raw( trim( $_POST['scos_seo_canonical'] ) );
			self::update_or_delete( $post_id, 'scos_seo_canonical', $val );
			// Dual-write: SEOPress / Airtable reads _seopress_robots_canonical
			self::update_or_delete( $post_id, '_seopress_robots_canonical', $val );
		}

		// ---- Robots directives (multi-check) ----
		$valid_robots = array_keys( Meta_Fields::robots_options() );
		if ( isset( $_POST['scos_seo_robots'] ) ) {
			$robots = array_values( array_filter(
				(array) $_POST['scos_seo_robots'],
				fn( $v ) => in_array( $v, $valid_robots, true )
			) );
		} else {
			$robots = [];
		}
		update_post_meta( $post_id, 'scos_seo_robots', $robots );
		// Dual-write: Airtable / Seo_Module sitemap reads _seopress_robots_index
		update_post_meta(
			$post_id,
			'_seopress_robots_index',
			in_array( 'noindex', $robots, true ) ? 'yes' : 'no'
		);

		// ---- Freeze modified date (per-post) ----
		// Always write so unchecking a previously-checked box is captured.
		update_post_meta(
			$post_id,
			'scos_seo_freeze_og_date',
			! empty( $_POST['scos_seo_freeze_og_date'] ) ? '1' : '0'
		);

		// ---- Sitemap exclusions (multi-check) ----
		$valid_sitemap = array_keys( Meta_Fields::sitemap_options() );
		if ( isset( $_POST['scos_seo_sitemap_exclude'] ) ) {
			$exclude = array_values( array_filter(
				(array) $_POST['scos_seo_sitemap_exclude'],
				fn( $v ) => in_array( $v, $valid_sitemap, true )
			) );
		} else {
			$exclude = [];
		}

		// ---- Noindex ↔ sitemap auto-sync ----
		// If noindex is set and the "include despite noindex" override is not checked,
		// silently add 'xml' to sitemap_exclude and record that we auto-set it.
		// If noindex is cleared and we previously auto-set the xml exclusion, remove it.
		$noindex_now      = in_array( 'noindex', $robots, true );
		$sitemap_override = ! empty( $_POST['scos_seo_sitemap_noindex_override'] );
		$was_auto_set     = (bool) get_post_meta( $post_id, 'scos_seo_sitemap_noindex_auto', true );

		if ( $noindex_now && ! $sitemap_override ) {
			if ( ! in_array( 'xml', $exclude, true ) ) {
				$exclude[] = 'xml';
			}
			update_post_meta( $post_id, 'scos_seo_sitemap_noindex_auto', '1' );
		} else {
			// noindex cleared OR override checked — undo the auto-exclusion if we set it.
			if ( $was_auto_set ) {
				$exclude = array_values( array_diff( $exclude, [ 'xml' ] ) );
			}
			delete_post_meta( $post_id, 'scos_seo_sitemap_noindex_auto' );
		}

		update_post_meta( $post_id, 'scos_seo_sitemap_noindex_override', $sitemap_override ? '1' : '0' );
		update_post_meta( $post_id, 'scos_seo_sitemap_exclude', $exclude );
	}

	// -------------------------------------------------------------------------

	/**
	 * Prevent WP from bumping post_modified when:
	 *   - The global "freeze all modified dates" option is on, OR
	 *   - The per-post scos_seo_freeze_og_date flag is checked.
	 *
	 * Per-post value '0' can override the global freeze for that one save.
	 */
	public static function maybe_freeze_modified_date( array $data, array $postarr ): array {
		$post_id = (int) ( $postarr['ID'] ?? 0 );
		if ( ! $post_id ) {
			return $data;
		}

		// Skip revisions, auto-drafts, trash
		$status = $data['post_status'] ?? '';
		if ( in_array( $status, [ 'auto-draft', 'inherit', 'trash' ], true ) ) {
			return $data;
		}

		// Skip during autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}

		$global_freeze = (bool) get_option( 'scos_seo_freeze_modified_date', false );
		$per_post      = get_post_meta( $post_id, 'scos_seo_freeze_og_date', true );

		// Resolve freeze intent:
		// Global ON  → freeze, unless per-post is explicitly '0' (user override)
		// Global OFF → freeze only if per-post is explicitly '1'
		if ( $global_freeze ) {
			$should_freeze = ( '0' !== (string) $per_post );
		} else {
			$should_freeze = ( '1' === (string) $per_post );
		}

		if ( ! $should_freeze ) {
			return $data;
		}

		// Preserve current modified date — on brand-new posts (no prior date) skip.
		$original     = get_post_field( 'post_modified',     $post_id );
		$original_gmt = get_post_field( 'post_modified_gmt', $post_id );

		if ( $original && '0000-00-00 00:00:00' !== $original ) {
			$data['post_modified']     = $original;
			$data['post_modified_gmt'] = $original_gmt;
		}

		return $data;
	}

	// -------------------------------------------------------------------------

	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) { return; }
		global $post;
		if ( ! $post || ! in_array( $post->post_type, Meta_Fields::get_post_types(), true ) ) { return; }

		$css_path = SITE_ESSENTIALS_PATH . 'Modules/SeoMeta/assets/meta-box.css';
		$js_path  = SITE_ESSENTIALS_PATH . 'Modules/SeoMeta/assets/meta-box.js';

		wp_enqueue_style(
			'scos-seo-meta-box',
			SITE_ESSENTIALS_URL . 'Modules/SeoMeta/assets/meta-box.css',
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1.0.0'
		);
		wp_enqueue_script(
			'scos-seo-meta-box',
			SITE_ESSENTIALS_URL . 'Modules/SeoMeta/assets/meta-box.js',
			[ 'jquery' ],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : '1.0.0',
			true
		);
		wp_localize_script( 'scos-seo-meta-box', 'scosSeoMeta', [
			'noindexSitemapMsg' => esc_html__( 'This page has been removed from the sitemap because noindex is set.', 'site-essentials' ),
		] );

		// AI suggest script — only when the Abilities API is available.
		if ( class_exists( 'WordPress\AI\Abstracts\Abstract_Ability' ) ) {
			$suggest_js_path = SITE_ESSENTIALS_PATH . 'Modules/SeoMeta/assets/scos-seo-suggest.js';
			wp_enqueue_script(
				'scos-seo-suggest',
				SITE_ESSENTIALS_URL . 'Modules/SeoMeta/assets/scos-seo-suggest.js',
				[ 'jquery' ],
				file_exists( $suggest_js_path ) ? (string) filemtime( $suggest_js_path ) : '1.0.0',
				true
			);

			// Resolve search intent goal server-side: FAQ title first, freetext fallback.
			// Inline resolution avoids a direct class dependency on the CA module.
			$intent_goal = '';
			$faq_id      = (int) get_post_meta( $post->ID, 'scos_ca_intent_goal_faq_id', true );
			if ( $faq_id > 0 ) {
				$faq         = get_post( $faq_id );
				$intent_goal = $faq instanceof \WP_Post ? $faq->post_title : '';
			}
			if ( empty( $intent_goal ) ) {
				$intent_goal = (string) get_post_meta( $post->ID, 'scos_ca_intent_goal', true );
			}

			wp_localize_script( 'scos-seo-suggest', 'ScosSeoSuggest', [
				'endpointSeoMeta' => rest_url( 'wp-abilities/v1/abilities/scos/suggest-seo-meta/run' ),
				'endpointTldr'    => rest_url( 'wp-abilities/v1/abilities/scos/suggest-tldr/run' ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'postId'          => $post->ID,
				'intentGoalText'  => sanitize_text_field( $intent_goal ),
			] );
		}
	}

	// ---- Helpers ----

	private static function update_or_delete( $post_id, $key, $value ) {
		if ( ! empty( $value ) ) {
			update_post_meta( $post_id, $key, $value );
		} else {
			delete_post_meta( $post_id, $key );
		}
	}
}
