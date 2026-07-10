<?php
/**
 * Performance Page Template
 *
 * Tabs: WP Tweaks | Image Optimisation | Asset Preloading | Monitoring
 *
 * @package    SiteEssentials
 * @subpackage Views
 * @version    2.1.0
 *
 * Changelog:
 * 2.1.0 - Preserve bw_preloads_map on Card 1/2 submit so options.php no longer wipes
 *         Per-Page Preloads data when saving Google Fonts or Featured Image settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$performance_slug = \SiteEssentials\Core\Admin_UI::ESSENTIALS_PAGE_SLUG;
$base_url         = admin_url( 'admin.php?page=' . $performance_slug );
?>

<div class="wrap scos">

<header class="scos__header">
	<div>
		<h1 class="scos__title"><?php esc_html_e( 'Performance', 'site-essentials' ); ?></h1>
		<p class="scos__subtitle">Site Essentials &rsaquo; Performance</p>
	</div>
	<div class="scos__header-actions">
	</div>
</header>

<nav class="scos__tabs">
	<a href="<?php echo esc_url( $base_url . '&tab=tweaks' ); ?>"
	   class="scos__tab <?php echo $active_tab === 'tweaks' ? 'scos__tab--active' : ''; ?>">
		<?php esc_html_e( 'WP Tweaks', 'site-essentials' ); ?>
	</a>
	<a href="<?php echo esc_url( $base_url . '&tab=image-optimization' ); ?>"
	   class="scos__tab <?php echo $active_tab === 'image-optimization' ? 'scos__tab--active' : ''; ?>">
		<?php esc_html_e( 'Image Optimisation', 'site-essentials' ); ?>
	</a>
	<a href="<?php echo esc_url( $base_url . '&tab=asset-preloading' ); ?>"
	   class="scos__tab <?php echo $active_tab === 'asset-preloading' ? 'scos__tab--active' : ''; ?>">
		<?php esc_html_e( 'Asset Preloading', 'site-essentials' ); ?>
	</a>
	<a href="<?php echo esc_url( $base_url . '&tab=monitoring' ); ?>"
	   class="scos__tab <?php echo $active_tab === 'monitoring' ? 'scos__tab--active' : ''; ?>">
		<?php esc_html_e( 'Monitoring', 'site-essentials' ); ?>
	</a>
</nav>

<?php
// ── WP Tweaks ─────────────────────────────────────────────────────────────
if ( $active_tab === 'tweaks' ) :
?>

	<?php if ( ! $tweaks_module || ! is_object( $tweaks_module ) ) : ?>
		<div class="scos-notice scos-notice--warning">
			<p>
				<?php esc_html_e( 'WordPress Tweaks module is not enabled.', 'site-essentials' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \SiteEssentials\Core\Admin_UI::SETTINGS_PAGE_SLUG ) ); ?>">
					<?php esc_html_e( 'Enable it in Settings → Modules', 'site-essentials' ); ?>
				</a>
			</p>
		</div>
	<?php else : ?>
		<?php $tweaks_module->render_settings(); ?>
	<?php endif; ?>

<?php
// ── Image Optimisation ────────────────────────────────────────────────────
elseif ( $active_tab === 'image-optimization' ) :
?>

	<?php if ( ! $image_settings_available ) : ?>
		<div class="scos-notice scos-notice--warning">
			<p><?php esc_html_e( 'Image optimization settings require brighter-core (Brighter Tools) to be active.', 'site-essentials' ); ?></p>
		</div>
	<?php else : ?>

		<!-- Card 1: Image Settings -->
		<div class="scos-card">
			<div class="scos-card__header">
				<h2 class="scos-card__title"><?php esc_html_e( 'Image Settings', 'site-essentials' ); ?></h2>
			</div>
			<form method="post" action="options.php">
				<?php settings_fields( 'brighter_optimisation_settings' ); ?>
				<?php
				// Preserve Card 2 (Thumbnails) fields so options.php does not clear them on Card 1 submit.
				$_img_sizes = function_exists( 'brighter_get_image_sizes_config' ) ? array_keys( brighter_get_image_sizes_config() ) : [];
				foreach ( $_img_sizes as $_size ) {
					if ( get_option( 'enable_size_' . $_size, 1 ) ) {
						echo '<input type="hidden" name="enable_size_' . esc_attr( $_size ) . '" value="1">';
					}
				}
				?>
				<input type="hidden" name="brighter_enable_custom_hero"  value="<?php echo esc_attr( get_option( 'brighter_enable_custom_hero', 0 ) ? '1' : '0' ); ?>">
				<input type="hidden" name="brighter_custom_hero_width"   value="<?php echo esc_attr( get_option( 'brighter_custom_hero_width', 0 ) ); ?>">
				<input type="hidden" name="brighter_custom_hero_height"  value="<?php echo esc_attr( get_option( 'brighter_custom_hero_height', 0 ) ); ?>">
				<input type="hidden" name="jpeg_quality"                 value="<?php echo esc_attr( get_option( 'jpeg_quality', 75 ) ); ?>">
				<div class="scos-card__body">
					<table class="form-table" role="presentation">
						<tbody>
							<?php do_settings_fields( 'brighter_optimisation_page', 'image_settings_section' ); ?>
						</tbody>
					</table>
				</div>
				<div class="scos-card__footer">
					<button type="submit" class="scos-btn scos-btn--primary">
						<?php esc_html_e( 'Save Image Settings', 'site-essentials' ); ?>
					</button>
					<a href="https://brighterwebsites.com.au/software/image-optimisation/#image-settings"
					   target="_blank" rel="noopener" class="scos-btn scos-btn--ghost">
						<?php esc_html_e( 'View guide', 'site-essentials' ); ?>
					</a>
				</div>
			</form>
		</div>

		<!-- Card 2: Manage Image Thumbnails & Sizes -->
		<div class="scos-card">
			<div class="scos-card__header">
				<h2 class="scos-card__title"><?php esc_html_e( 'Manage Image Thumbnails &amp; Sizes', 'site-essentials' ); ?></h2>
			</div>
			<form method="post" action="options.php">
				<?php settings_fields( 'brighter_optimisation_settings' ); ?>
				<?php
				// Preserve Card 1 (Image Settings) fields so options.php does not clear them on Card 2 submit.
				if ( get_option( 'enable_image_resize', 'yes' ) === 'yes' ) {
					echo '<input type="hidden" name="enable_image_resize" value="yes">';
				}
				if ( get_option( 'disable_big_image_threshold', 0 ) ) {
					echo '<input type="hidden" name="disable_big_image_threshold" value="1">';
				}
				?>
				<input type="hidden" name="image_max_dimension" value="<?php echo esc_attr( get_option( 'image_max_dimension', 2480 ) ); ?>">
				<div class="scos-card__body">
					<table class="form-table" role="presentation">
						<tbody>
							<?php do_settings_fields( 'brighter_optimisation_page', 'image_thumbnails_section' ); ?>
						</tbody>
					</table>
				</div>
			<div class="scos-card__footer">
				<button type="submit" class="scos-btn scos-btn--primary">
					<?php esc_html_e( 'Save Thumbnail Settings', 'site-essentials' ); ?>
				</button>
				<a href="https://brighterwebsites.com.au/software/image-optimisation/#image-thumbnails"
				   target="_blank" rel="noopener" class="scos-btn scos-btn--ghost">
					<?php esc_html_e( 'View guide', 'site-essentials' ); ?>
				</a>
			</div>
		</form>
	</div>

		<!-- Card 3: Registered Image Sizes (info only — no form inputs) -->
		<div class="scos-card">
			<div class="scos-card__header">
				<h2 class="scos-card__title"><?php esc_html_e( 'Registered Image Sizes', 'site-essentials' ); ?></h2>
			</div>
			<div class="scos-card__body">
				<?php
				global $wp_settings_sections;
				$reg_section = $wp_settings_sections['brighter_optimisation_page']['registered_sizes_section'] ?? null;
				if ( $reg_section && isset( $reg_section['callback'] ) ) {
					call_user_func( $reg_section['callback'], $reg_section );
				}
				?>
			</div>
			<div class="scos-card__footer">
				<a href="https://brighterwebsites.com.au/software/image-optimisation/#registered-sizes"
				   target="_blank" rel="noopener" class="scos-btn scos-btn--ghost">
					<?php esc_html_e( 'View guide', 'site-essentials' ); ?>
				</a>
			</div>
		</div>

	<?php endif; ?>

<?php
// ── Asset Preloading ──────────────────────────────────────────────────────
elseif ( $active_tab === 'asset-preloading' ) :
?>

	<?php if ( ! class_exists( 'Brighter_Tweaks' ) ) : ?>
		<div class="scos-notice scos-notice--warning">
			<p><?php esc_html_e( 'Asset preloading requires brighter-core (Brighter Tweaks) to be active.', 'site-essentials' ); ?></p>
		</div>
	<?php else : ?>

		<?php if ( isset( $_GET['tweaks_saved'] ) && $_GET['tweaks_saved'] === '1' ) : ?>
			<div class="scos-notice scos-notice--success">
				<p><?php esc_html_e( 'Settings saved.', 'site-essentials' ); ?></p>
			</div>
		<?php endif; ?>

		<?php $asset_preload_url = admin_url( 'admin.php?page=site-essentials-essentials&tab=asset-preloading' ); ?>

		<!-- Card 1: Google Fonts Preload -->
		<div class="scos-card">
			<div class="scos-card__header">
				<h2 class="scos-card__title"><?php esc_html_e( 'Google Fonts Preload', 'site-essentials' ); ?></h2>
			</div>
			<form method="post" action="options.php">
				<?php settings_fields( 'brighter_tweaks' ); ?>
				<?php
				// Preserve other brighter_tweaks options so options.php does not overwrite them.
				$pt = (array) get_option( 'brighter_preload_post_types', [] );
				foreach ( $pt as $t ) {
					echo '<input type="hidden" name="brighter_preload_post_types[]" value="' . esc_attr( $t ) . '">';
				}
				?>
				<input type="hidden" name="brighter_preload_webp_append"  value="<?php echo get_option( 'brighter_preload_webp_append', 0 ) ? '1' : '0'; ?>">
				<input type="hidden" name="brighter_preload_webp_replace" value="<?php echo get_option( 'brighter_preload_webp_replace', 0 ) ? '1' : '0'; ?>">
				<input type="hidden" name="brighter_preload_use_og_image" value="<?php echo get_option( 'brighter_preload_use_og_image', 1 ) ? '1' : '0'; ?>">
				<input type="hidden" name="theme_colour"                   value="<?php echo esc_attr( get_option( 'theme_colour', '' ) ); ?>">
				<?php
				// Preserve Card 3 (Per-Page Preloads) map so options.php does not clear it on Card 1 submit.
				$_preload_map = get_option( 'bw_preloads_map', [] );
				if ( is_array( $_preload_map ) ) {
					foreach ( $_preload_map as $_pid => $_urls ) {
						foreach ( (array) $_urls as $_url ) {
							echo '<input type="hidden" name="bw_preloads_map[' . (int) $_pid . '][]" value="' . esc_attr( $_url ) . '">';
						}
					}
				}
				?>
				<div class="scos-card__body">
					<table class="form-table" role="presentation">
						<tbody>
							<?php do_settings_fields( 'brighter_tweaks', 'google_fonts_preload' ); ?>
						</tbody>
					</table>
				</div>
				<div class="scos-card__footer">
					<button type="submit" class="scos-btn scos-btn--primary">
						<?php esc_html_e( 'Save', 'site-essentials' ); ?>
					</button>
					<a href="https://brighterwebsites.com.au/software/performance/font-optimisation/google-fonts-preload"
					   target="_blank" rel="noopener" class="scos-btn scos-btn--ghost">
						<?php esc_html_e( 'View guide', 'site-essentials' ); ?>
					</a>
				</div>
			</form>
		</div>

		<!-- Card 2: Preload Featured Images on Singles -->
		<div class="scos-card">
			<div class="scos-card__header">
				<h2 class="scos-card__title"><?php esc_html_e( 'Preload Featured Images on Singles', 'site-essentials' ); ?></h2>
			</div>
			<form method="post" action="options.php">
				<?php settings_fields( 'brighter_tweaks' ); ?>
				<?php // Preserve Google Fonts value so options.php does not clear it. ?>
				<input type="hidden" name="bw_google_fonts_preload" value="<?php echo esc_attr( get_option( 'bw_google_fonts_preload', '' ) ); ?>">
				<input type="hidden" name="theme_colour" value="<?php echo esc_attr( get_option( 'theme_colour', '' ) ); ?>">
				<?php
				// Preserve Card 3 (Per-Page Preloads) map so options.php does not clear it on Card 2 submit.
				$_preload_map = get_option( 'bw_preloads_map', [] );
				if ( is_array( $_preload_map ) ) {
					foreach ( $_preload_map as $_pid => $_urls ) {
						foreach ( (array) $_urls as $_url ) {
							echo '<input type="hidden" name="bw_preloads_map[' . (int) $_pid . '][]" value="' . esc_attr( $_url ) . '">';
						}
					}
				}
				?>
				<div class="scos-card__body">
					<table class="form-table" role="presentation">
						<tbody>
							<?php do_settings_fields( 'brighter_tweaks', 'preload_on_singles' ); ?>
						</tbody>
					</table>
				</div>
				<div class="scos-card__footer">
					<button type="submit" class="scos-btn scos-btn--primary">
						<?php esc_html_e( 'Save', 'site-essentials' ); ?>
					</button>
					<a href="https://brighterwebsites.com.au/software/asset-preloading/#featured-images"
					   target="_blank" rel="noopener" class="scos-btn scos-btn--ghost">
						<?php esc_html_e( 'View guide', 'site-essentials' ); ?>
					</a>
				</div>
			</form>
		</div>

		<!-- Card 3: Per-Page Preloads
		     Note: render_preload_form outputs its own form (POST). The search is a
		     separate GET form placed first in the card body so it sits above the list. -->
		<div class="scos-card">
			<div class="scos-card__header">
				<h2 class="scos-card__title"><?php esc_html_e( 'Per-Page Preloads', 'site-essentials' ); ?></h2>
			</div>
			<div class="scos-card__body">
				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( $performance_slug ); ?>">
					<input type="hidden" name="tab" value="asset-preloading">
					<input type="search" name="s"
					       value="<?php echo esc_attr( isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '' ); ?>"
					       placeholder="<?php esc_attr_e( 'Search pages…', 'site-essentials' ); ?>">
					<button class="scos-btn"><?php esc_html_e( 'Search', 'site-essentials' ); ?></button>
				</form>
				<?php Brighter_Tweaks::render_preload_form( true, $asset_preload_url ); ?>
			</div>
			<div class="scos-card__footer">
				<a href="https://brighterwebsites.com.au/software/asset-preloading/#per-page"
				   target="_blank" rel="noopener" class="scos-btn scos-btn--ghost">
					<?php esc_html_e( 'View guide', 'site-essentials' ); ?>
				</a>
			</div>
		</div>

	<?php endif; ?>

<?php
// ── Monitoring ────────────────────────────────────────────────────────────
elseif ( $active_tab === 'monitoring' ) :

	include SITE_ESSENTIALS_PATH . 'Views/performance-monitoring.php';

endif;
?>

</div>
