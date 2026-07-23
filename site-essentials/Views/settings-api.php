<?php
/**
 * Settings — API Settings tab
 *
 * v1.1 | 2026-05-19
 *
 * SCOS design system: scos-card, scos-form, scos-btn, scos-input--mono.
 * Inline styles and .card wrappers removed.
 * No functional changes — nonce, actions, option keys unchanged.
 *
 * @package    SiteEssentials
 * @subpackage Views
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

// Handle save / generate
$notice = '';
if ( isset( $_POST['scos_api_action'] ) && check_admin_referer( 'scos_api_settings' ) ) {
	if ( $_POST['scos_api_action'] === 'generate' ) {
		$new_token = wp_generate_password( 40, false );
		update_option( 'brighter_api_token', $new_token );
		$notice = 'generated';
	} elseif ( $_POST['scos_api_action'] === 'delete' && isset( $_POST['scos_api_confirm_delete'] ) ) {
		delete_option( 'brighter_api_token' );
		$notice = 'deleted';
	}
}

$token     = (string) get_option( 'brighter_api_token', '' );
$has_token = ! empty( $token );
$rest_base = esc_url( rest_url( 'brighter-core/v1/' ) );
?>

<?php if ( 'generated' === $notice ) : ?>
	<div class="scos-notice scos-notice--success" style="margin-bottom:var(--scos-s-4)">
		<p><?php esc_html_e( 'API token generated.', 'site-essentials' ); ?></p>
	</div>
<?php elseif ( 'deleted' === $notice ) : ?>
	<div class="scos-notice scos-notice--warning" style="margin-bottom:var(--scos-s-4)">
		<p><?php esc_html_e( 'API token deleted.', 'site-essentials' ); ?></p>
	</div>
<?php endif; ?>

<!-- ── Brighter X Token ─────────────────────────────────────────── -->
<div class="scos-card" style="margin-bottom:var(--scos-s-4)">
	<div class="scos-card__header">
		<div>
			<h2 class="scos-card__title"><?php esc_html_e( 'Brighter X API Token', 'site-essentials' ); ?></h2>
			<p class="scos-card__desc"><?php esc_html_e( 'Authenticates inbound requests to this site\'s REST API endpoints. Used by Custom GPT, MCP servers, and any tool reading structured data from this install.', 'site-essentials' ); ?></p>
		</div>
	</div>
	<div class="scos-card__body">

		<p class="description" style="margin-bottom:var(--scos-s-3)">
			<?php esc_html_e( 'Pass it as the', 'site-essentials' ); ?>
			<code>X-Brighter-Token</code>
			<?php esc_html_e( 'header on every request.', 'site-essentials' ); ?>
		</p>

		<?php if ( $has_token ) : ?>
			<div style="display:flex;align-items:center;gap:var(--scos-s-2);margin-bottom:var(--scos-s-4);flex-wrap:wrap">
				<input type="password" id="scos-api-token-field"
				       value="<?php echo esc_attr( $token ); ?>"
				       readonly
				       class="scos-input scos-input--mono" style="max-width:360px">
				<button type="button" class="scos-btn scos-btn--ghost" id="scos-api-reveal">
					<?php esc_html_e( 'Show', 'site-essentials' ); ?>
				</button>
				<button type="button" class="scos-btn scos-btn--ghost" id="scos-api-copy">
					<?php esc_html_e( 'Copy', 'site-essentials' ); ?>
				</button>
			</div>
			<script>
			( function() {
				var field  = document.getElementById( 'scos-api-token-field' );
				var reveal = document.getElementById( 'scos-api-reveal' );
				var copy   = document.getElementById( 'scos-api-copy' );
				if ( reveal ) {
					reveal.addEventListener( 'click', function() {
						if ( field.type === 'password' ) {
							field.type = 'text';
							reveal.textContent = '<?php echo esc_js( __( 'Hide', 'site-essentials' ) ); ?>';
						} else {
							field.type = 'password';
							reveal.textContent = '<?php echo esc_js( __( 'Show', 'site-essentials' ) ); ?>';
						}
					} );
				}
				if ( copy ) {
					copy.addEventListener( 'click', function() {
						navigator.clipboard.writeText( field.value ).then( function() {
							copy.textContent = '<?php echo esc_js( __( 'Copied!', 'site-essentials' ) ); ?>';
							setTimeout( function() { copy.textContent = '<?php echo esc_js( __( 'Copy', 'site-essentials' ) ); ?>'; }, 2000 );
						} );
					} );
				}
			} )();
			</script>
		<?php else : ?>
			<div class="scos-notice scos-notice--warning" style="margin-bottom:var(--scos-s-3)">
				<p>
					<strong><?php esc_html_e( 'No token set.', 'site-essentials' ); ?></strong>
					<?php esc_html_e( 'Generate one below to enable API access.', 'site-essentials' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" style="display:inline-flex;gap:var(--scos-s-2);align-items:center;flex-wrap:wrap;margin-bottom:<?php echo $has_token ? 'var(--scos-s-4)' : '0'; ?>">
			<?php wp_nonce_field( 'scos_api_settings' ); ?>
			<input type="hidden" name="scos_api_action" value="generate">
			<button type="submit" class="scos-btn <?php echo $has_token ? 'scos-btn--ghost' : 'scos-btn--primary'; ?>">
				<?php echo $has_token
					? esc_html__( 'Regenerate Token', 'site-essentials' )
					: esc_html__( 'Generate Token', 'site-essentials' ); ?>
			</button>
			<?php if ( $has_token ) : ?>
				<span class="description"><?php esc_html_e( 'Regenerating invalidates the existing token immediately.', 'site-essentials' ); ?></span>
			<?php endif; ?>
		</form>

		<?php if ( $has_token ) : ?>
		<form method="post" style="display:flex;gap:var(--scos-s-2);align-items:center;flex-wrap:wrap">
			<?php wp_nonce_field( 'scos_api_settings' ); ?>
			<input type="hidden" name="scos_api_action" value="delete">
			<label class="scos-checkbox-row">
				<input type="checkbox" name="scos_api_confirm_delete" value="1">
				<span class="description"><?php esc_html_e( 'Yes, delete the token and block API access', 'site-essentials' ); ?></span>
			</label>
			<button type="submit" class="scos-btn scos-btn--danger">
				<?php esc_html_e( 'Delete Token', 'site-essentials' ); ?>
			</button>
		</form>
		<?php endif; ?>

	</div>
</div>

<!-- ── Usage ────────────────────────────────────────────────────── -->
<div class="scos-card" style="margin-bottom:var(--scos-s-4)">
	<div class="scos-card__header scos-card__header--plain">
		<h2 class="scos-card__title"><?php esc_html_e( 'Usage', 'site-essentials' ); ?></h2>
	</div>
	<div class="scos-card__body">
		<table class="scos-form">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'REST Base URL', 'site-essentials' ); ?></th>
					<td><code><?php echo $rest_base; // phpcs:ignore WordPress.Security.EscapeOutput ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Auth Header', 'site-essentials' ); ?></th>
					<td><code>X-Brighter-Token: your_token_here</code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Example', 'site-essentials' ); ?></th>
					<td>
						<pre class="scos-input scos-input--mono" style="padding:var(--scos-s-2) var(--scos-s-3);white-space:pre-wrap;height:auto;max-width:520px">GET <?php echo $rest_base; // phpcs:ignore WordPress.Security.EscapeOutput ?>posts?per_page=15
X-Brighter-Token: <?php echo $has_token ? esc_html( substr( $token, 0, 8 ) ) . '…' : 'your_token_here'; ?></pre>
					</td>
				</tr>
			</tbody>
		</table>

		<h3 class="scos__section-label" style="margin-top:var(--scos-s-4);margin-bottom:var(--scos-s-2)"><?php esc_html_e( 'Available Endpoints', 'site-essentials' ); ?></h3>
		<table class="scos-form">
			<tbody>
				<tr><th><code>GET /posts</code></th><td><?php esc_html_e( 'All public post types with content data', 'site-essentials' ); ?></td></tr>
				<tr><th><code>GET /faqs</code></th><td><?php esc_html_e( 'All FAQ entries', 'site-essentials' ); ?></td></tr>
				<tr><th><code>GET /faqs/search?q=</code></th><td><?php esc_html_e( 'Search FAQs by keyword', 'site-essentials' ); ?></td></tr>
				<tr><th><code>GET /faqs/export</code></th><td><?php esc_html_e( 'Export FAQs (admin only)', 'site-essentials' ); ?></td></tr>
			</tbody>
		</table>
	</div>
</div>

<!-- ── Third-Party Keys ─────────────────────────────────────────── -->
<div class="scos-card">
	<div class="scos-card__header scos-card__header--plain">
		<h2 class="scos-card__title"><?php esc_html_e( 'Third-Party Integration Keys', 'site-essentials' ); ?></h2>
	</div>
	<div class="scos-card__body">
		<p class="description" style="margin-bottom:var(--scos-s-3)"><?php esc_html_e( 'These use their own dedicated keys stored in their respective modules — not the Brighter X token above.', 'site-essentials' ); ?></p>
		<table class="scos-form">
			<tbody>
				<tr>
					<th>YOURLS</th>
					<td>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=site-essentials-social-amplification&tab=yourls' ) ); ?>">
							<?php esc_html_e( 'Social Amplification → YOURLS Settings', 'site-essentials' ); ?>
						</a>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</div>
