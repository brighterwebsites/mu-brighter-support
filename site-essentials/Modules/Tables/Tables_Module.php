<?php
/**
 * Tables Module
 *
 * Responsive styling for Gutenberg tables — the BW Universal Table System.
 * Ships the shared component stylesheet, the label-stamping script, and the
 * layout choices that appear in the block sidebar.
 *
 * A site skins it by mapping three CSS custom properties in Breakdance global
 * CSS; the component itself contains no site-specific values. See SPEC.md.
 *
 * @package    SiteEssentials
 * @subpackage Modules\Tables
 * @since      1.0.0
 *
 * v1.0 | 2026-08-28
 */

namespace SiteEssentials\Modules\Tables;

use SiteEssentials\Core\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tables_Module implements Module_Interface {

	/** Shared handle for both the stylesheet and the script. */
	const HANDLE = 'bw-tables';

	/** Classes that require the label-stamping script. */
	const JS_CLASSES = [ 'bw-labels', 'bw-cards', 'is-style-bw-stack-labels', 'is-style-bw-cards', 'is-style-bw-cards-labels' ];

	public static function get_id() {
		return 'tables';
	}

	public static function get_name() {
		return __( 'Tables', 'site-essentials' );
	}

	public static function get_description() {
		return __( 'Responsive styling for Gutenberg tables. Wide tables scroll instead of breaking the layout, and each table can be set to stack into cards on mobile from the block sidebar.', 'site-essentials' );
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

	public function init() {
		require_once __DIR__ . '/Block_Styles.php';
		Block_Styles::init();

		add_action( 'wp_enqueue_scripts',          [ $this, 'enqueue_frontend' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor' ] );
	}

	// -------------------------------------------------------------------------
	// Enqueue
	// -------------------------------------------------------------------------

	/**
	 * Front end: nothing loads on a page with no table.
	 *
	 * has_block() reads post_content only, so Breakdance-built pages never match
	 * — correct for now, since tables live in articles. Sites needing another
	 * rule (a table inside a synced pattern, say) can override the decision via
	 * the scos_tables_should_enqueue filter rather than losing the gate.
	 */
	public function enqueue_frontend() {
		$post  = is_singular() ? get_post() : null;
		$needs = $post instanceof \WP_Post && has_block( 'core/table', $post );

		/**
		 * Filter whether the table assets load on this request.
		 *
		 * @param bool          $needs Whether a Gutenberg table was found.
		 * @param \WP_Post|null $post  The post being rendered, if singular.
		 */
		if ( ! apply_filters( 'scos_tables_should_enqueue', $needs, $post ) ) {
			return;
		}

		self::register_assets();
		wp_enqueue_style( self::HANDLE );

		// The script only stamps labels for card layouts. Skip it entirely when
		// no table on this page asks for one.
		if ( $post instanceof \WP_Post && self::content_needs_script( $post->post_content ) ) {
			wp_enqueue_script( self::HANDLE );
		}
	}

	/**
	 * Block editor: the stylesheet loads unconditionally so table previews are
	 * styled. The site's token mapping lives in Breakdance global CSS, which the
	 * editor does not load, so previews render in the component's own neutral
	 * defaults rather than the site's colours.
	 */
	public function enqueue_editor() {
		self::register_assets();
		wp_enqueue_style( self::HANDLE );
	}

	/**
	 * Register both assets against filemtime so a deploy cache-busts itself.
	 */
	private static function register_assets() {
		if ( wp_style_is( self::HANDLE, 'registered' ) ) {
			return;
		}

		$dir = plugin_dir_path( __FILE__ ) . 'assets/';
		$url = plugin_dir_url( __FILE__ ) . 'assets/';

		wp_register_style(
			self::HANDLE,
			$url . 'bw-tables.css',
			[],
			file_exists( $dir . 'bw-tables.css' ) ? (string) filemtime( $dir . 'bw-tables.css' ) : self::get_version()
		);

		wp_register_script(
			self::HANDLE,
			$url . 'bw-tables.js',
			[],
			file_exists( $dir . 'bw-tables.js' ) ? (string) filemtime( $dir . 'bw-tables.js' ) : self::get_version(),
			true
		);

		// WP 6.3+: defer rather than a hand-rolled script_loader_tag filter.
		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( self::HANDLE, 'strategy', 'defer' );
		}
	}

	/**
	 * Does any table on this page use a layout that needs the script?
	 *
	 * @param string $content Raw post content.
	 * @return bool
	 */
	private static function content_needs_script( $content ) {
		foreach ( self::JS_CLASSES as $class ) {
			if ( false !== strpos( $content, $class ) ) {
				return true;
			}
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	/**
	 * Reference and verification panel. This module stores no options: the
	 * tokens live in Breakdance, the layout choice lives on each block.
	 */
	public function render_settings() {
		$tokens = [
			'--bw-t-accent'   => [ __( 'Header gradient, featured column, accents', 'site-essentials' ), true ],
			'--bw-t-surface'  => [ __( 'Table and card background', 'site-essentials' ), true ],
			'--bw-t-ink'      => [ __( 'Body text colour', 'site-essentials' ), true ],
			'--bw-t-head-ink' => [ __( 'Header text — override only if the accent is light', 'site-essentials' ), false ],
			'--bw-t-radius'   => [ __( 'Corner radius', 'site-essentials' ), false ],
			'--bw-t-border'   => [ __( 'Cell rules', 'site-essentials' ), false ],
			'--bw-t-shadow'   => [ __( 'Frame and card shadow', 'site-essentials' ), false ],
		];

		$behaviours = [
			__( '(no class)', 'site-essentials' ) => __( 'Scrolls sideways below 680px, with a fade at each edge.', 'site-essentials' ),
			'bw-stack'                            => __( 'Each row becomes a card below 680px.', 'site-essentials' ),
			'bw-cards'                            => __( 'Each column becomes a card below 680px.', 'site-essentials' ),
		];

		$modifiers = [
			'bw-labels'     => __( 'Print the column heading beside each value when stacked.', 'site-essentials' ),
			'bw-hide-first' => __( 'Omit the first column entirely (cards only).', 'site-essentials' ),
			'bw-compare'    => __( 'Highlight the last column as the featured option.', 'site-essentials' ),
			'bw-pricing'    => __( 'SKU, configuration and price column widths.', 'site-essentials' ),
			'bw-compact'    => __( 'Smaller type, tighter rows.', 'site-essentials' ),
		];

		include __DIR__ . '/views/settings.php';
	}
}
