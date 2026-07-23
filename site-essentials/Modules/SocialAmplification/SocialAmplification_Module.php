<?php
/**
 * Social Amplification Module
 *
 * Per-page social amplification panel:
 *  - YOURLS shortlink slug (scos_sa_shortlink_slug)
 *  - Postly.ai amplification pipeline (AI captions, scheduling, backfill)
 *
 * Options are saved under the scos_sma_ prefix; YOURLS values are dual-written to
 * legacy bw_yourls_* keys so BW_YOURLS_Helper continues to work without changes.
 *
 * @package    SiteEssentials
 * @subpackage Modules\SocialAmplification
 * @version    1.4 | 2026-07-21
 *
 * v1.4 | 2026-07-21 — Removed Post Framing CPT + Make.com webhook trigger
 *                      (deprecated, unused on all sites). Postly pipeline unaffected.
 */

namespace SiteEssentials\Modules\SocialAmplification;

use SiteEssentials\Core\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SocialAmplification_Module implements Module_Interface {

	public static function get_id() {
		return 'social_amplification';
	}

	public static function get_name() {
		return __( 'Social Amplification', 'site-essentials' );
	}

	public static function get_description() {
		return __( 'YOURLS shortlink slug and Postly.ai amplification pipeline for automated social post creation.', 'site-essentials' );
	}

	public static function get_tier() {
		return 'pro';
	}

	public static function get_dependencies() {
		return [];
	}

	public static function get_version() {
		return '1.2.0';
	}

	public function init() {
		if ( ! defined( 'SCOS_SA_ACTIVE' ) ) {
			define( 'SCOS_SA_ACTIVE', true );
		}

		require_once __DIR__ . '/Post_Type_Config.php';
		require_once __DIR__ . '/Meta_Fields.php';
		require_once __DIR__ . '/Meta_Box.php';
		require_once __DIR__ . '/Admin_Columns.php';

		// ── Postly.ai amplification pipeline ──────────────────────────────
		require_once __DIR__ . '/Amplification/Anthropic_Client.php';
		require_once __DIR__ . '/Amplification/Postly_Client.php';
		require_once __DIR__ . '/Amplification/Amplification_Engine.php';
		require_once __DIR__ . '/Amplification/REST_Endpoint.php';
		require_once __DIR__ . '/Amplification/Backfill_Endpoint.php';
		require_once __DIR__ . '/Publish_Hook.php';

		Meta_Fields::init();
		Meta_Box::init();
		Admin_Columns::init();
		Amplification\REST_Endpoint::init();
		Amplification\Backfill_Endpoint::init();
		Publish_Hook::init();
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_settings_assets' ] );

		// AJAX: fetch Postly channel IDs (admin-only)
		add_action( 'wp_ajax_scos_sa_fetch_postly_channels', [ $this, 'ajax_fetch_postly_channels' ] );

		// WP-CLI commands (scos-social namespace)
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once __DIR__ . '/CLI/Backfill_Command.php';
			require_once __DIR__ . '/CLI/Send_Post_Command.php';
			require_once __DIR__ . '/CLI/Cleanup_Make_Command.php';
			\WP_CLI::add_command( 'scos-social backfill', CLI\Backfill_Command::class );
			\WP_CLI::add_command( 'scos-social sendpost', CLI\Send_Post_Command::class );
			\WP_CLI::add_command( 'scos-social cleanup-make', CLI\Cleanup_Make_Command::class );
		}

		// WP Abilities API — category + send-social-post ability
		add_action( 'wp_abilities_api_categories_init', [ __CLASS__, 'register_ability_category' ] );
		if ( class_exists( 'WordPress\AI\Abstracts\Abstract_Ability' ) ) {
			require_once __DIR__ . '/Abilities/Send_Social_Post/Send_Social_Post.php';
		}

		// One-time migration: copy bw_* option values → scos_sma_* on first load
		add_action( 'admin_init', [ $this, 'maybe_migrate_options' ] );
	}

	/**
	 * Register the Social Amplification ability category.
	 */
	public static function register_ability_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category( 'scos-social-amplification', [
			'label'       => __( 'SCOS: Social Amplification', 'site-essentials' ),
			'description' => __( 'Abilities for generating and scheduling social media posts from WordPress content.', 'site-essentials' ),
		] );
	}

	/**
	 * Migrate legacy bw_* social options → scos_sma_* (runs once, non-destructive).
	 */
	public function maybe_migrate_options(): void {
		if ( get_option( 'scos_sma_options_migrated' ) ) {
			return;
		}

		$map = [
			'bw_yourls_api_url'           => 'scos_sma_yourls_url',
			'bw_yourls_signature'         => 'scos_sma_yourls_signature',
			'bw_yourls_username'          => 'scos_sma_yourls_username',
			'bw_yourls_password'          => 'scos_sma_yourls_password',
		];

		foreach ( $map as $old => $new ) {
			$existing = get_option( $old );
			if ( false !== $existing && '' !== $existing ) {
				// Only set if the new key doesn't already have a value
				if ( false === get_option( $new ) || '' === get_option( $new ) ) {
					update_option( $new, $existing, false );
				}
			}
		}

		update_option( 'scos_sma_options_migrated', '1', false );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Option helper: reads scos_sma_* with bw_* fallback
	// Used by Meta_Box and views/settings.php
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Get a Social Amplification option, preferring scos_sma_* over legacy bw_* keys.
	 *
	 * @param string $scos_key   e.g. 'scos_sma_yourls_url'
	 * @param string $legacy_key e.g. 'bw_yourls_api_url'
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function get_option( string $scos_key, string $legacy_key = '', $default = '' ) {
		$val = get_option( $scos_key, '' );
		if ( '' !== $val ) {
			return $val;
		}
		if ( $legacy_key ) {
			return get_option( $legacy_key, $default );
		}
		return $default;
	}

	/**
	 * AJAX handler: fetch connected Postly social channels for the workspace.
	 *
	 * Returns [{target, name, channel_id}] using the saved API key + workspace ID.
	 * Nonce: scos_sa_channels (printed by settings.php via wp_create_nonce).
	 */
	public function ajax_fetch_postly_channels(): void {
		check_ajax_referer( 'scos_sa_channels', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'site-essentials' ) ], 403 );
		}

		$api_key      = (string) get_option( 'bw_postly_api_key', '' );
		$workspace_id = (string) get_option( 'bw_postly_workspace_id', '' );

		if ( ! $api_key || ! $workspace_id ) {
			wp_send_json_error( [ 'message' => __( 'Postly API key and Workspace ID must be saved before fetching channels.', 'site-essentials' ) ] );
		}

		try {
			$client   = new Amplification\Postly_Client( $api_key, $workspace_id );
			$socials  = $client->get_socials();

			$channels = [];
			foreach ( $socials as $item ) {
				if ( empty( $item['channel_id'] ) ) {
					continue;
				}
				$channels[] = [
					'target'     => sanitize_text_field( $item['target']     ?? '' ),
					'name'       => sanitize_text_field( $item['name']       ?? '' ),
					'channel_id' => sanitize_text_field( $item['channel_id'] ?? '' ),
					'connected'  => ! empty( $item['connected'] ),
				];
			}

			wp_send_json_success( [ 'channels' => $channels ] );
		} catch ( \RuntimeException $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function render_settings() {
		include __DIR__ . '/views/settings.php';
	}

	public static function enqueue_settings_assets( string $hook ): void {
		if ( 'site-essentials_page_site-essentials-social-amplification' !== $hook ) {
			return;
		}
		$js_path = SITE_ESSENTIALS_PATH . 'Modules/SocialAmplification/assets/backfill.js';
		wp_enqueue_script(
			'scos-sa-backfill',
			SITE_ESSENTIALS_URL . 'Modules/SocialAmplification/assets/backfill.js',
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : '1.0.0',
			true
		);
	}
}
