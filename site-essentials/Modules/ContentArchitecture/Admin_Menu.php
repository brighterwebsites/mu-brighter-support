<?php
/**
 * Content Architecture — Admin Menu
 *
 * Registers a "Content Architecture" top-level admin menu with submenus for:
 *  - Content Clusters taxonomy management (edit-tags.php)
 *  - Topics taxonomy management (edit-tags.php)
 *  - Strategy Overview dashboard (simple stats page)
 *
 * v1.3.0 | 2026-05-29 — Strategy Configuration section added to Overview (read-only display of scos_ca_strategy_* options).
 * v1.4.0 | 2026-07-18 — Retire Airtable CAR sync integration; remove Integrations submenu, render/save methods.
 *
 * @package    SiteEssentials
 * @subpackage Modules\ContentArchitecture
 * @since      1.0.0
 */

namespace SiteEssentials\Modules\ContentArchitecture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Menu {

	const MENU_SLUG     = 'scos-content-architecture';
	const OVERVIEW_SLUG = 'scos-content-architecture';

	public static function init() {
		add_action( 'admin_menu',            [ __CLASS__, 'register' ] );
		add_action( 'admin_menu',            [ __CLASS__, 'suppress_legacy_integrations' ], 99 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		// Fix submenu highlight for taxonomy management pages.
		add_filter( 'parent_file',  [ __CLASS__, 'fix_parent_file' ] );
		add_filter( 'submenu_file', [ __CLASS__, 'fix_submenu_file' ] );
	}

	/**
	 * Remove legacy bw-integration-car submenu from brighter_support once CA is active.
	 */
	public static function suppress_legacy_integrations(): void {
		remove_submenu_page( 'brighter_support', 'bw-integration-car' );
	}

	public static function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'scos-content-architecture' ) === false ) {
			return;
		}

		wp_enqueue_style( 'scos-tokens', SITE_ESSENTIALS_URL . 'assets/css/tokens.css', [], SITE_ESSENTIALS_VERSION );
		wp_enqueue_style( 'scos-ui',     SITE_ESSENTIALS_URL . 'assets/css/scos-ui.css', [ 'scos-tokens' ], SITE_ESSENTIALS_VERSION );

		$asset_file = __DIR__ . '/assets/ca-overview.js';
		wp_enqueue_script(
			'scos-ca-overview',
			SITE_ESSENTIALS_URL . 'Modules/ContentArchitecture/assets/ca-overview.js',
			[ 'jquery' ],
			file_exists( $asset_file ) ? (string) filemtime( $asset_file ) : '1.0.0',
			true
		);
		wp_localize_script( 'scos-ca-overview', 'scosCA', [
			'nonce'      => wp_create_nonce( 'scos_analysis' ),
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'clearNonce' => wp_create_nonce( 'scos_clear_analysis' ),
		] );
	}

	/**
	 * Register top-level menu and submenus.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register() {
		// Top-level menu (points to the overview/dashboard page).
		add_menu_page(
			__( 'Content Architecture', 'site-essentials' ),
			__( 'Content Architecture', 'site-essentials' ),
			'edit_posts',
			self::MENU_SLUG,
			[ __CLASS__, 'render_overview' ],
			'dashicons-analytics',
			25
		);

		// Rename first auto-generated submenu from menu title to "Overview".
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Strategy Overview', 'site-essentials' ),
			__( 'Overview', 'site-essentials' ),
			'edit_posts',
			self::OVERVIEW_SLUG,
			[ __CLASS__, 'render_overview' ]
		);

		// Content Clusters — links to native WP taxonomy management.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Content Clusters', 'site-essentials' ),
			__( 'Content Clusters', 'site-essentials' ),
			'manage_categories',
			'edit-tags.php?taxonomy=scos_content_cluster'
		);

		// Topics — links to native WP taxonomy management.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Topics', 'site-essentials' ),
			__( 'Topics', 'site-essentials' ),
			'manage_categories',
			'edit-tags.php?taxonomy=scos_topic'
		);
	}

	/**
	 * Keep the Content Architecture top-level menu open when managing terms.
	 *
	 * @since 1.0.0
	 * @param string $file Current parent file.
	 * @return string
	 */
	public static function fix_parent_file( $file ) {
		global $current_screen;
		if ( $current_screen && in_array( $current_screen->taxonomy, [ 'scos_content_cluster', 'scos_topic' ], true ) ) {
			return self::MENU_SLUG;
		}
		return $file;
	}

	/**
	 * Highlight the correct submenu item when managing terms.
	 *
	 * @since 1.0.0
	 * @param string $file Current submenu file.
	 * @return string
	 */
	public static function fix_submenu_file( $file ) {
		global $current_screen;
		if ( ! $current_screen ) {
			return $file;
		}
		if ( 'scos_content_cluster' === $current_screen->taxonomy ) {
			return 'edit-tags.php?taxonomy=scos_content_cluster';
		}
		if ( 'scos_topic' === $current_screen->taxonomy ) {
			return 'edit-tags.php?taxonomy=scos_topic';
		}
		return $file;
	}

	/**
	 * Render the Strategy Overview / dashboard page.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render_overview() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'site-essentials' ) );
		}

		$clusters      = get_terms( [ 'taxonomy' => 'scos_content_cluster', 'hide_empty' => false ] );
		$topics        = get_terms( [ 'taxonomy' => 'scos_topic',           'hide_empty' => false ] );
		$cluster_count = is_wp_error( $clusters ) ? 0 : count( $clusters );
		$topic_count   = is_wp_error( $topics )   ? 0 : count( $topics );

		// Strategy Configuration — eight scos_ca_strategy_* options (MCP-only writes, read-only here).
		$s_known_for  = (string) get_option( 'scos_ca_strategy_known_for_position', '' );
		$s_mat_start  = (string) get_option( 'scos_ca_strategy_maturity_start', '' );
		$s_mat_goal   = (string) get_option( 'scos_ca_strategy_maturity_goal', '' );
		$s_geo        = (string) get_option( 'scos_ca_strategy_geographic_scope', '' );
		$s_market     = (string) get_option( 'scos_ca_strategy_target_market', '' );
		$s_gaps       = (string) get_option( 'scos_ca_strategy_content_gaps', '' );
		$s_rec        = (string) get_option( 'scos_ca_strategy_recommendation', '' );
		$s_outcome    = (string) get_option( 'scos_ca_strategy_outcome_goal', '' );
		$has_strategy = $s_known_for || $s_mat_start || $s_mat_goal || $s_geo || $s_market || $s_gaps || $s_rec || $s_outcome;

		// Maturity label lookup — handles both underscore and hyphen slug variants.
		$mat_options = Meta_Fields::maturity_options();
		$mat_label   = static function( string $slug ) use ( $mat_options ): string {
			if ( isset( $mat_options[ $slug ] ) ) {
				return $mat_options[ $slug ];
			}
			$alt = str_replace( '-', '_', $slug );
			if ( isset( $mat_options[ $alt ] ) ) {
				return $mat_options[ $alt ];
			}
			return $slug ? ucwords( str_replace( [ '_', '-' ], ' ', $slug ) ) : '—';
		};
		?>
		<div class="wrap scos">

			<header class="scos__header">
				<div>
					<h1 class="scos__title"><?php esc_html_e( 'Content Architecture', 'site-essentials' ); ?></h1>
					<p class="scos__subtitle">Site Essentials &rsaquo; Content Architecture</p>
				</div>
			</header>

			<?php // ── Strategy Configuration ─────────────────────────────────── ?>
		<div class="scos-card" style="margin-bottom:var(--scos-s-6)">
			<div class="scos-card__header">
				<div>
					<span class="scos-card__title"><?php esc_html_e( 'Strategy Configuration', 'site-essentials' ); ?></span>
					<span class="scos-card__desc">
						<?php esc_html_e( 'Read-only — populate via MCP or WP-CLI.', 'site-essentials' ); ?>
						&nbsp;&middot;&nbsp;
						<a href="<?php echo esc_url( home_url( '/wp-content/ai-knowledge/202-authority-content-strategy.md' ) ); ?>" target="_blank" rel="noopener">202 Authority Strategy &#8599;</a>
						&nbsp;&middot;&nbsp;
						<a href="<?php echo esc_url( home_url( '/wp-content/ai-knowledge/105-competitive-positioning.md' ) ); ?>" target="_blank" rel="noopener">105 Competitive Positioning &#8599;</a>
					</span>
				</div>
			</div>
			<div class="scos-card__body">
			<?php if ( ! $has_strategy ) : ?>
				<p style="color:var(--scos-ink-subtle);margin:0"><?php esc_html_e( 'Not synced — populate via MCP.', 'site-essentials' ); ?></p>
			<?php else : ?>

				<?php // Primary Authority Positioning — highlighted. ?>
				<?php if ( $s_known_for ) : ?>
				<div style="border:2px solid var(--scos-accent);background:var(--scos-accent-soft);border-radius:var(--scos-r-lg);padding:var(--scos-s-5);margin-bottom:var(--scos-s-5)">
					<p class="scos__section-label" style="color:var(--scos-accent);margin:0 0 var(--scos-s-2)"><?php esc_html_e( 'Primary Authority Positioning', 'site-essentials' ); ?></p>
					<p style="font-size:1.05rem;font-weight:700;color:var(--scos-ink);margin:0;line-height:1.6"><?php echo esc_html( $s_known_for ); ?></p>
				</div>
				<?php endif; ?>

				<?php // Maturity Targets. ?>
				<?php if ( $s_mat_start || $s_mat_goal ) : ?>
				<div style="margin-bottom:var(--scos-s-5)">
					<p class="scos__section-label" style="margin:0 0 var(--scos-s-3)"><?php esc_html_e( 'Maturity Targets', 'site-essentials' ); ?></p>
					<div style="display:flex;align-items:center;gap:var(--scos-s-4)">
						<div>
							<div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--scos-ink-subtle);margin-bottom:2px"><?php esc_html_e( 'Start', 'site-essentials' ); ?></div>
							<div style="font-weight:600;color:var(--scos-ink)"><?php echo esc_html( $s_mat_start ? $mat_label( $s_mat_start ) : '—' ); ?></div>
						</div>
						<span style="color:var(--scos-ink-subtle);font-size:1.2rem">&rarr;</span>
						<div>
							<div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--scos-ink-subtle);margin-bottom:2px"><?php esc_html_e( 'Goal', 'site-essentials' ); ?></div>
							<div style="font-weight:600;color:var(--scos-ink)"><?php echo esc_html( $s_mat_goal ? $mat_label( $s_mat_goal ) : '—' ); ?></div>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<?php // Geographic Scope + Target Market — two columns. ?>
				<?php if ( $s_geo || $s_market ) : ?>
				<div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--scos-s-5);margin-bottom:var(--scos-s-5)">
					<?php if ( $s_geo ) : ?>
					<div>
						<p class="scos__section-label" style="margin:0 0 var(--scos-s-2)"><?php esc_html_e( 'Geographic Scope', 'site-essentials' ); ?></p>
						<p style="color:var(--scos-ink);margin:0;line-height:1.6"><?php echo esc_html( $s_geo ); ?></p>
					</div>
					<?php endif; ?>
					<?php if ( $s_market ) : ?>
					<div>
						<p class="scos__section-label" style="margin:0 0 var(--scos-s-2)"><?php esc_html_e( 'Target Market', 'site-essentials' ); ?></p>
						<p style="color:var(--scos-ink);margin:0;line-height:1.6"><?php echo esc_html( $s_market ); ?></p>
					</div>
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<?php // Biggest Content Gaps — full width. ?>
				<?php if ( $s_gaps ) : ?>
				<div style="margin-bottom:var(--scos-s-5)">
					<p class="scos__section-label" style="margin:0 0 var(--scos-s-2)"><?php esc_html_e( 'Biggest Content Gaps', 'site-essentials' ); ?></p>
					<p style="color:var(--scos-ink);margin:0;line-height:1.6"><?php echo esc_html( $s_gaps ); ?></p>
				</div>
				<?php endif; ?>

				<?php // Strategic Recommendation + Strategy Outcome Goal — two columns. ?>
				<?php if ( $s_rec || $s_outcome ) : ?>
				<div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--scos-s-5)">
					<?php if ( $s_rec ) : ?>
					<div>
						<p class="scos__section-label" style="margin:0 0 var(--scos-s-2)"><?php esc_html_e( 'Strategic Recommendation', 'site-essentials' ); ?></p>
						<p style="color:var(--scos-ink);margin:0;line-height:1.6"><?php echo esc_html( $s_rec ); ?></p>
					</div>
					<?php endif; ?>
					<?php if ( $s_outcome ) : ?>
					<div>
						<p class="scos__section-label" style="margin:0 0 var(--scos-s-2)"><?php esc_html_e( 'Strategy Outcome Goal', 'site-essentials' ); ?></p>
						<p style="color:var(--scos-ink);margin:0;line-height:1.6"><?php echo esc_html( $s_outcome ); ?></p>
					</div>
					<?php endif; ?>
				</div>
				<?php endif; ?>

			<?php endif; ?>
			</div>
		</div>

		<?php // ── Taxonomy summary cards ──────────────────────────────────── ?>
			<div style="display:flex;gap:var(--scos-s-4);flex-wrap:wrap;margin-bottom:var(--scos-s-6)">

				<div class="scos-card" style="flex:1;min-width:200px">
					<div class="scos-card__header scos-card__header--plain">
						<span class="scos-card__title"><?php esc_html_e( 'Content Clusters', 'site-essentials' ); ?></span>
					</div>
					<div class="scos-card__body">
						<p style="font-size:2rem;font-weight:700;color:var(--scos-accent);margin:0 0 var(--scos-s-3)">
							<?php echo absint( $cluster_count ); ?>
						</p>
						<p style="color:var(--scos-ink-subtle);margin:0 0 var(--scos-s-4)"><?php esc_html_e( 'defined', 'site-essentials' ); ?></p>
					</div>
					<div class="scos-card__footer">
						<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=scos_content_cluster' ) ); ?>" class="scos-btn scos-btn--ghost">
							<?php esc_html_e( 'Manage Clusters', 'site-essentials' ); ?>
						</a>
					</div>
				</div>

				<div class="scos-card" style="flex:1;min-width:200px">
					<div class="scos-card__header scos-card__header--plain">
						<span class="scos-card__title"><?php esc_html_e( 'Topics', 'site-essentials' ); ?></span>
					</div>
					<div class="scos-card__body">
						<p style="font-size:2rem;font-weight:700;color:var(--scos-accent);margin:0 0 var(--scos-s-3)">
							<?php echo absint( $topic_count ); ?>
						</p>
						<p style="color:var(--scos-ink-subtle);margin:0 0 var(--scos-s-4)"><?php esc_html_e( 'defined', 'site-essentials' ); ?></p>
					</div>
					<div class="scos-card__footer">
						<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=scos_topic' ) ); ?>" class="scos-btn scos-btn--ghost">
							<?php esc_html_e( 'Manage Topics', 'site-essentials' ); ?>
						</a>
					</div>
				</div>

			</div>

			<?php if ( ! empty( $clusters ) && ! is_wp_error( $clusters ) ) : ?>
			<div class="scos-card" style="margin-bottom:var(--scos-s-6)">
				<div class="scos-card__header">
					<span class="scos-card__title"><?php esc_html_e( 'Cluster Breakdown', 'site-essentials' ); ?></span>
				</div>
				<div class="scos-card__body" style="padding:0">
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Cluster', 'site-essentials' ); ?></th>
								<th style="width:80px;text-align:center"><?php esc_html_e( 'Posts', 'site-essentials' ); ?></th>
								<th style="width:80px"></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $clusters as $cluster ) : ?>
								<tr>
									<td><?php echo esc_html( $cluster->name ); ?></td>
									<td style="text-align:center"><?php echo esc_html( $cluster->count ); ?></td>
									<td>
										<a href="<?php echo esc_url( admin_url( 'edit-tags.php?action=edit&taxonomy=scos_content_cluster&tag_ID=' . $cluster->term_id ) ); ?>">
											<?php esc_html_e( 'Edit', 'site-essentials' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php endif; ?>

			<?php // ── Content Analysis Section ──────────────────────────────── ?>
			<div class="scos-card">
				<div class="scos-card__header">
					<span class="scos-card__title"><?php esc_html_e( 'Content Analysis', 'site-essentials' ); ?></span>
					<span class="scos-card__desc"><?php esc_html_e( 'Word count, H2s, images, and links per post. Runs on save; use the buttons to backfill or force a full re-analysis.', 'site-essentials' ); ?></span>
				</div>
				<div class="scos-card__body" style="padding:0">
					<table class="wp-list-table widefat fixed striped" id="scos-analysis-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Post Type', 'site-essentials' ); ?></th>
								<th style="width:80px;text-align:center"><?php esc_html_e( 'Total', 'site-essentials' ); ?></th>
								<th style="width:80px;text-align:center"><?php esc_html_e( 'Analysed', 'site-essentials' ); ?></th>
								<th style="width:80px;text-align:center"><?php esc_html_e( 'Pending', 'site-essentials' ); ?></th>
								<th style="width:140px;text-align:center"><?php esc_html_e( 'Coverage', 'site-essentials' ); ?></th>
								<th style="width:120px"></th>
							</tr>
						</thead>
						<tbody id="scos-analysis-rows">
							<tr><td colspan="6" style="color:var(--scos-ink-subtle);text-align:center;padding:var(--scos-s-5)">
								<?php esc_html_e( 'Loading…', 'site-essentials' ); ?>
							</td></tr>
						</tbody>
						<tfoot id="scos-analysis-foot" style="display:none">
							<tr style="font-weight:600">
								<td><?php esc_html_e( 'Total', 'site-essentials' ); ?></td>
								<td id="scos-ft-total"  style="text-align:center">—</td>
								<td id="scos-ft-done"   style="text-align:center">—</td>
								<td id="scos-ft-pend"   style="text-align:center">—</td>
								<td id="scos-ft-bar"    style="text-align:center">—</td>
								<td></td>
							</tr>
						</tfoot>
					</table>
				</div>
				<div class="scos-card__footer" style="flex-wrap:wrap;gap:var(--scos-s-3)">
					<button id="scos-run-all" class="scos-btn scos-btn--primary">
						&#9654; <?php esc_html_e( 'Run Analysis (pending only)', 'site-essentials' ); ?>
					</button>
					<button id="scos-force-all" class="scos-btn scos-btn--ghost" title="<?php esc_attr_e( 'Clears stored analysis and re-runs on all posts — use when Breakdance content was not being read correctly.', 'site-essentials' ); ?>">
						&#8635; <?php esc_html_e( 'Force Re-analyze All', 'site-essentials' ); ?>
					</button>
					<span id="scos-analysis-msg" style="color:var(--scos-ink-subtle);font-size:var(--scos-fs-sm);align-self:center"></span>
				</div>
				<div id="scos-analysis-progress" style="display:none;padding:0 var(--scos-s-5) var(--scos-s-5)">
					<div style="background:var(--scos-border);border-radius:var(--scos-r-sm);height:8px;overflow:hidden">
						<div id="scos-analysis-bar" style="background:var(--scos-accent);height:100%;width:0;transition:width .3s"></div>
					</div>
					<div id="scos-analysis-progress-label" style="font-size:var(--scos-fs-sm);color:var(--scos-ink-subtle);margin-top:var(--scos-s-2)"></div>
				</div>
			</div>

		</div>
		<?php
	}

	/**
	 * @deprecated Integrations page removed — Airtable CAR sync retired 2026-07-18.
	 */
	public static function render_integrations(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'site-essentials' ) );
		}
		?>
		<div class="wrap scos">
			<header class="scos__header">
				<div>
					<h1 class="scos__title"><?php esc_html_e( 'Integrations', 'site-essentials' ); ?></h1>
					<p class="scos__subtitle">Site Essentials &rsaquo; Content Architecture</p>
				</div>
			</header>
			<div class="scos-notice scos-notice--info">
				<p><?php esc_html_e( 'The Airtable CAR sync integration has been retired. This page is no longer active.', 'site-essentials' ); ?></p>
			</div>
		</div>
		<?php
	}

}
