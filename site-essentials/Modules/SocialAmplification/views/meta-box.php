<?php
/**
 * Social Amplification meta box view.
 *
 * Variables from Meta_Box::render():
 *   $post             WP_Post
 *   $shortlink_slug   string
 *   $is_published     bool
 *   $yourls_base      string  base URL e.g. https://bweb1.com.au (or empty)
 *   $amplified        bool
 *   $ran_at           string
 *   $log_posts        array
 *
 * @package SiteEssentials
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="scos-sa-wrap">

	<!-- ── Shortlink Slug ── -->
	<div class="scos-sa-section">
		<div class="scos-sa-field">
			<label for="scos_sa_shortlink_slug">
				<?php esc_html_e( 'YOURLS Shortlink Slug', 'site-essentials' ); ?>
			</label>
			<div class="scos-sa-slug-row">
				<?php if ( $yourls_base ) : ?>
					<span class="scos-sa-slug-prefix"><?php echo esc_html( rtrim( $yourls_base, '/' ) . '/' ); ?></span>
				<?php endif; ?>
				<input type="text"
					name="scos_sa_shortlink_slug"
					id="scos_sa_shortlink_slug"
					value="<?php echo esc_attr( $shortlink_slug ); ?>"
					placeholder="<?php esc_attr_e( 'e.g. seo-signals', 'site-essentials' ); ?>"
					class="scos-sa-slug-input">
				<?php if ( $yourls_base && $shortlink_slug ) : ?>
					<a href="<?php echo esc_url( $yourls_base . '/' . $shortlink_slug ); ?>"
						target="_blank" rel="noopener noreferrer" class="scos-sa-link-out" title="<?php esc_attr_e( 'Open shortlink', 'site-essentials' ); ?>">
						<span class="dashicons dashicons-external"></span>
					</a>
				<?php endif; ?>
			</div>
			<p class="scos-sa-help"><?php esc_html_e( 'Slug format, no spaces. Saved to YOURLS as the shortlink keyword.', 'site-essentials' ); ?></p>
		</div>
	</div>

	<!-- ── Amplification Status / Re-run ── -->
	<div class="scos-sa-section scos-sa-section--amplify">
		<div class="scos-sa-amplify-header">
			<strong><?php esc_html_e( 'Postly Amplification Status', 'site-essentials' ); ?></strong>
			<span class="scos-sa-amplify-badge <?php echo $amplified ? 'is-yes' : 'is-no'; ?>">
				<?php if ( $amplified ) : ?>
					<?php esc_html_e( 'Amplified', 'site-essentials' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Not yet amplified', 'site-essentials' ); ?>
				<?php endif; ?>
			</span>
		</div>

		<?php if ( $ran_at ) : ?>
			<p class="scos-sa-help">
				<?php
				printf(
					/* translators: %s date */
					esc_html__( 'Last ran: %s', 'site-essentials' ),
					esc_html( mysql2date( 'j M Y g:i a', $ran_at ) )
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( ! empty( $log_posts ) ) : ?>
			<table class="widefat striped scos-sa-slot-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Slot', 'site-essentials' ); ?></th>
						<th><?php esc_html_e( 'Scheduled', 'site-essentials' ); ?></th>
						<th><?php esc_html_e( 'Status', 'site-essentials' ); ?></th>
						<th><?php esc_html_e( 'Postly ID', 'site-essentials' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $log_posts as $slot_row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $slot_row['slot'] ?? '—' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $slot_row['scheduled'] ?? '—' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $slot_row['status'] ?? '—' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $slot_row['postly_id'] ?? '—' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $is_published ) : ?>
			<p style="margin-top:12px;">
				<button type="button"
					id="scos-sa-reamp-btn"
					class="button <?php echo $amplified ? 'button-secondary' : 'button-primary'; ?>"
					data-post-id="<?php echo esc_attr( $post->ID ); ?>"
					data-amplified="<?php echo $amplified ? '1' : '0'; ?>">
					<?php if ( $amplified ) : ?>
						<?php esc_html_e( 'Reset & Re-amplify', 'site-essentials' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Create Social Post', 'site-essentials' ); ?>
					<?php endif; ?>
				</button>
			</p>
		<?php endif; ?>
		<div id="scos-sa-reamp-msg" class="scos-sa-result" hidden></div>
		<div id="scos-sa-reamp-results"></div>
	</div>

</div><!-- /scos-sa-wrap -->
