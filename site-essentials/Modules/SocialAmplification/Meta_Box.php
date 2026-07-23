<?php
/**
 * Social Amplification — Meta Box Controller
 *
 * Registers a "Social Amplification" meta box on all supported post types.
 * Handles:
 *  - scos_sa_shortlink_slug save (_bw_breadcrumb dual-write removed — consumers now read scos_sa_shortlink_slug first)
 *  - Enqueues JS for the Postly "Create Social Post" / "Reset & Re-amplify" trigger
 *
 * @package    SiteEssentials
 * @subpackage Modules\SocialAmplification
 * @since      1.0.0
 *
 * v1.0 | 2026-05-01
 * v1.1 | 2026-06-29 — Remove _bw_breadcrumb dual-write; consumers now read scos_sa_shortlink_slug first.
 * v1.2 | 2026-07-21 — Remove Make.com webhook trigger button/UI (deprecated, unused on all sites).
 */

namespace SiteEssentials\Modules\SocialAmplification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meta_Box {

	public static function init() {
		add_action( 'add_meta_boxes',        [ __CLASS__, 'register' ] );
		add_action( 'add_meta_boxes',        [ __CLASS__, 'remove_legacy_meta_boxes' ], 999, 1 );
		add_action( 'save_post',             [ __CLASS__, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'wp_ajax_scos_sa_amplify', [ __CLASS__, 'ajax_re_amplify' ] );
	}

	// -------------------------------------------------------------------------

	public static function register() {
		foreach ( Meta_Fields::get_post_types() as $post_type ) {
			add_meta_box(
				'scos_social_amplification',
				__( 'Social Amplification', 'site-essentials' ),
				[ __CLASS__, 'render' ],
				$post_type,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Remove legacy brighter-core breadcrumb slug meta box when superseded.
	 *
	 * @since 1.0.0
	 * @param string $post_type Current post type on the edit screen.
	 */
	public static function remove_legacy_meta_boxes( string $post_type ): void {
		if ( ! defined( 'SCOS_SA_ACTIVE' ) ) {
			return;
		}
		$contexts = [ 'normal', 'side', 'advanced' ];
		foreach ( $contexts as $ctx ) {
			remove_meta_box( 'bw_breadcrumb_meta', $post_type, $ctx );
		}
	}

	// -------------------------------------------------------------------------

	public static function render( $post ) {
		wp_nonce_field( 'scos_sa_meta_box', 'scos_sa_nonce' );

		// Primary scos key; fall back to legacy _bw_breadcrumb (YOURLS keyword)
		$shortlink_slug = get_post_meta( $post->ID, 'scos_sa_shortlink_slug', true );
		if ( empty( $shortlink_slug ) ) {
			$shortlink_slug = get_post_meta( $post->ID, '_bw_breadcrumb', true );
		}

		$is_published   = ( 'publish' === $post->post_status );
		$yourls_api_url = rtrim( SocialAmplification_Module::get_option( 'scos_sma_yourls_url', 'bw_yourls_api_url' ), '/' );
		$yourls_base    = $yourls_api_url
			? preg_replace( '#/yourls-api\.php$#', '', $yourls_api_url )
			: '';
		$amplified      = get_post_meta( $post->ID, '_scos_sa_amplified', true ) === '1';
		$log            = get_option( \SiteEssentials\Modules\SocialAmplification\Amplification\Amplification_Engine::LOG_OPTION, [] );
		$log_entry      = is_array( $log ) ? ( $log[ $post->ID ] ?? [] ) : [];
		$ran_at         = (string) ( $log_entry['ran_at'] ?? '' );
		$log_posts      = (array) ( $log_entry['posts'] ?? [] );

		include __DIR__ . '/views/meta-box.php';
	}

	// -------------------------------------------------------------------------

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['scos_sa_nonce'] )
			|| ! wp_verify_nonce( $_POST['scos_sa_nonce'], 'scos_sa_meta_box' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( wp_is_post_revision( $post_id ) ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
		if ( ! in_array( $post->post_type, Meta_Fields::get_post_types(), true ) ) { return; }

		if ( isset( $_POST['scos_sa_shortlink_slug'] ) ) {
			$slug = sanitize_title( $_POST['scos_sa_shortlink_slug'] );
			if ( ! empty( $slug ) ) {
				update_post_meta( $post_id, 'scos_sa_shortlink_slug', $slug );
			} else {
				delete_post_meta( $post_id, 'scos_sa_shortlink_slug' );
			}
		}
	}

	// -------------------------------------------------------------------------

	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) { return; }
		global $post;
		if ( ! $post || ! in_array( $post->post_type, Meta_Fields::get_post_types(), true ) ) { return; }

		$css_path = SITE_ESSENTIALS_PATH . 'Modules/SocialAmplification/assets/meta-box.css';
		$js_path  = SITE_ESSENTIALS_PATH . 'Modules/SocialAmplification/assets/meta-box.js';

		wp_enqueue_style(
			'scos-sa-meta-box',
			SITE_ESSENTIALS_URL . 'Modules/SocialAmplification/assets/meta-box.css',
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1.0.0'
		);
		wp_enqueue_script(
			'scos-sa-meta-box',
			SITE_ESSENTIALS_URL . 'Modules/SocialAmplification/assets/meta-box.js',
			[ 'jquery' ],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : '1.0.0',
			true
		);
		wp_localize_script( 'scos-sa-meta-box', 'scosSA', [
			'amplifyNonce' => wp_create_nonce( 'scos_sa_amplify' ),
			'ajaxurl'      => admin_url( 'admin-ajax.php' ),
			'settingsUrl'  => admin_url( 'admin.php?page=site-essentials-social-amplification&scos_sma_tab=postly#postly' ),
			'i18n'         => [
				'error'      => __( 'Error', 'site-essentials' ),
				'reAmplify'  => __( 'Reset & Re-amplify', 'site-essentials' ),
				'create'     => __( 'Create Social Post', 'site-essentials' ),
				'amplifying' => __( 'Running…', 'site-essentials' ),
				'configError' => __( 'AI knowledge not configured. Set up Anthropic API key and knowledge files in', 'site-essentials' ),
				'settingsLink' => __( 'Social Amplification settings', 'site-essentials' ),
			],
		] );
	}

	public static function ajax_re_amplify(): void {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'scos_sa_amplify' ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'site-essentials' ) ], 403 );
		}
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'site-essentials' ) ], 403 );
		}

		delete_post_meta( $post_id, \SiteEssentials\Modules\SocialAmplification\Publish_Hook::AMPLIFIED_META );

		try {
			$result = \SiteEssentials\Modules\SocialAmplification\Amplification\Amplification_Engine::run( $post_id );
			update_post_meta( $post_id, \SiteEssentials\Modules\SocialAmplification\Publish_Hook::AMPLIFIED_META, '1' );
			wp_send_json_success( [ 'result' => $result ] );
		} catch ( \RuntimeException $e ) {
			$message = $e->getMessage();
			// Classify config-type failures so the JS can render a targeted help message.
			$is_config_error = (
				stripos( $message, 'api key' ) !== false
				|| stripos( $message, 'anthropic' ) !== false
				|| stripos( $message, 'ai-knowledge' ) !== false
				|| stripos( $message, 'knowledge' ) !== false
			);
			wp_send_json_error( [
				'message' => $message,
				'code'    => $is_config_error ? 'config_error' : 'error',
			], 500 );
		}
	}
}
