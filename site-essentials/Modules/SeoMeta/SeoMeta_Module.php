<?php
/**
 * SEO Meta Module
 *
 * Per-page SEO metabox with three tabs:
 *  - Core SEO   : Breadcrumb Title, TLDR, Meta Title (60 char), Meta Description (160 char)
 *  - Advanced   : Canonical URL, Meta Robots directives, Sitemap exclusions
 *  - OG Social  : Planned Phase 2 (placeholder)
 *
 * Stores authoritative data in scos_seo_* meta keys. No SEOPress dual-writes.
 *
 * Absorbs:
 *  - BW_TLDR_Meta_Box    (bw_tldr)
 *  - BW_Breadcrumbs_Meta (_bw_breadcrumb)
 *
 * @package    SiteEssentials
 * @subpackage Modules\SeoMeta
 * @version    1.1.0
 *
 * v1.1 | 2026-06-30 — Admin columns (SEO Title, SEO Desc) + Quick Edit for Title, Description, Breadcrumb.
 * v1.2 | 2026-07-01 — Register suggest-seo-meta and suggest-tldr WP-CLI commands.
 * v1.3 | 2026-07-01 — Bootstrap Media_Meta_Filler, Media_Columns; add scos fill-image-meta CLI command.
 * v1.4 | 2026-07-18 — Remove SEOPress dual-writes and fallback reads; scos_seo_* is sole contract.
 */

namespace SiteEssentials\Modules\SeoMeta;

use SiteEssentials\Core\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoMeta_Module implements Module_Interface {

	/** @var bool Prevent double init when both Seo_Module and legacy paths call bootstrap. */
	private static $features_bootstrapped = false;

	public static function get_id() {
		return 'seo_meta';
	}

	public static function get_name() {
		return __( 'SEO Meta', 'site-essentials' );
	}

	public static function get_description() {
		return __( 'Bootstrapped by the SEO Module (not a separate toggle).', 'site-essentials' );
	}

	public static function get_tier() {
		return 'basic';
	}

	public static function get_dependencies() {
		return [];
	}

	public static function get_version() {
		return '1.0.0';
	}

	/**
	 * Load SEO meta / archive / head features (invoked from Seo_Module when the SEO Module is enabled).
	 *
	 * @since 1.0.0
	 */
	public static function bootstrap_features() {
		if ( self::$features_bootstrapped ) {
			return;
		}
		self::$features_bootstrapped = true;

		require_once __DIR__ . '/Meta_Fields.php';
		require_once __DIR__ . '/Meta_Box.php';
		require_once __DIR__ . '/Head_Output.php';
		require_once __DIR__ . '/Archive_Settings.php';
		require_once __DIR__ . '/Image_SEO.php';
		require_once __DIR__ . '/Virtual_Files.php';
		require_once __DIR__ . '/Exif_Stripper.php';
		require_once __DIR__ . '/Redirections.php';
		require_once __DIR__ . '/Author_SEO.php';
		require_once __DIR__ . '/Admin_Columns.php';
		require_once __DIR__ . '/Media_Meta_Filler.php';
		require_once __DIR__ . '/Media_Columns.php';

		Meta_Fields::init();
		Meta_Box::init();
		Head_Output::init();
		Archive_Settings::init();
		Image_SEO::init();
		Virtual_Files::init();
		Exif_Stripper::init();
		Redirections::init();
		Author_SEO::init();
		Admin_Columns::init();
		Media_Meta_Filler::init();
		Media_Columns::init();

		self::register_cli_commands();
	}

	/**
	 * Register WP-CLI commands for SEO Meta.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private static function register_cli_commands(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		require_once __DIR__ . '/CLI/Suggest_Seo_Meta_Command.php';
		require_once __DIR__ . '/CLI/Suggest_Tldr_Command.php';
		require_once __DIR__ . '/CLI/Fill_Image_Meta_Command.php';

		\WP_CLI::add_command(
			'scos suggest-seo-meta',
			CLI\Suggest_Seo_Meta_Command::class,
			[
				'shortdesc' => 'Suggest breadcrumb label, meta title, and meta description for a post using AI.',
				'longdesc'  => 'Wraps the scos/suggest-seo-meta ability. All three fields are generated in a single AI call. ' .
					'Requires the WordPress AI plugin. Use --apply to auto-save the top suggestion for each field.',
			]
		);

		\WP_CLI::add_command(
			'scos suggest-tldr',
			CLI\Suggest_Tldr_Command::class,
			[
				'shortdesc' => 'Suggest TLDR article summary options for a post using AI.',
				'longdesc'  => 'Wraps the scos/suggest-tldr ability. When the post has a linked Search Intent Goal, ' .
					'the TLDR is written to directly answer that question. ' .
					'Requires the WordPress AI plugin. Use --apply to auto-save the top option to scos_seo_tldr.',
			]
		);

		\WP_CLI::add_command(
			'scos fill-image-meta',
			CLI\Fill_Image_Meta_Command::class,
			[
				'shortdesc' => 'Generate alt text and titles for images missing metadata.',
				'longdesc'  => 'Wraps the scos/fill-image-meta ability. Images are grouped by parent post — one AI call per group. ' .
					'Supports --attachment-id, --post-id, or no flag (all qualifying images). ' .
					'Use --overwrite to replace existing metadata. Use --dry-run to preview without saving. ' .
					'Requires the WordPress AI plugin.',
			]
		);
	}

	/**
	 * @since 1.0.0
	 */
	public function init() {
		self::bootstrap_features();
	}

	public function render_settings() {
		include __DIR__ . '/views/settings.php';
	}
}
