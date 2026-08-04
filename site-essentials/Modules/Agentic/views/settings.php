<?php
/**
 * Agentic Module — settings view
 *
 * v1.0 | 2026-06-22
 *
 * Template C (single-section settings). Rendered inside <div class="wrap scos">
 * by Admin_UI::render_agentic_page().
 *
 * Variables available:
 * @var bool $enabled Whether Markdown for Agents is enabled.
 *
 * @package    SiteEssentials
 * @subpackage Modules\Agentic\Views
 */

defined( 'ABSPATH' ) || exit;
?>

<header class="scos__header">
	<div>
		<h1 class="scos__title"><?php esc_html_e( 'Agentic', 'site-essentials' ); ?></h1>
		<p class="scos__subtitle"><?php esc_html_e( 'Site Essentials › Agentic', 'site-essentials' ); ?></p>
	</div>
</header>

<?php if ( isset( $_GET['updated'] ) && 'true' === $_GET['updated'] ) : ?>
	<div class="scos-notice scos-notice--success" style="margin-bottom:var(--scos-s-4)">
		<p><?php esc_html_e( 'Agentic settings saved.', 'site-essentials' ); ?></p>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="scos_save_agentic">
	<?php wp_nonce_field( 'scos_save_agentic', 'scos_agentic_nonce' ); ?>

	<!-- ── General Settings ──────────────────────────────────────────────── -->
	<div class="scos-card" style="margin-bottom:var(--scos-s-6)">
		<div class="scos-card__header scos-card__header--plain">
			<h2 class="scos-card__title"><?php esc_html_e( 'General Settings', 'site-essentials' ); ?></h2>
		</div>
		<div class="scos-card__body">
			<label class="scos-checkbox-row" style="cursor:pointer">
				<input
					type="checkbox"
					name="scos_agentic_markdown_enabled"
					id="scos_agentic_markdown_enabled"
					value="1"
					<?php checked( $enabled ); ?>>
				<span>
					<strong><?php esc_html_e( 'Enable Markdown for Agents', 'site-essentials' ); ?></strong>
					<span class="description" style="display:block;margin-top:2px">
						<?php esc_html_e( 'When enabled, AI agents that send an Accept: text/markdown request header receive a Markdown version of the page — YAML frontmatter (title, description, image) plus converted content, with no theme chrome. Normal browser requests are completely unaffected. Also responds to ?format=md or ?format=markdown query parameters for manual testing.', 'site-essentials' ); ?>
					</span>
					<span class="description" style="display:block;margin-top:4px;font-style:italic">
						<?php
						printf(
							/* translators: %s example URL with format=md parameter */
							esc_html__( 'Manual test: %s', 'site-essentials' ),
							'<code>' . esc_html( home_url( '/your-page/?format=md' ) ) . '</code>'
						);
						?>
					</span>
				</span>
			</label>
			<div class="scos-form__slug" style="margin-top:var(--scos-s-3)">scos_agentic_markdown_enabled</div>
		</div>
	</div>

	<div class="scos-save-bar">
		<button type="submit" class="scos-btn scos-btn--primary">
			<?php esc_html_e( 'Save Agentic Settings', 'site-essentials' ); ?>
		</button>
	</div>

</form>
