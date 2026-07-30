<?php
/**
 * Agency white label settings page (se_agency_* options).
 *
 * @package SiteEssentials
 * @version 1.1
 *
 * v1.1 | 2026-07-30 — Access tab login redirect fields show role defaults as
 *                     placeholders instead of pre-filled values.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SiteEssentials\Core\Admin_UI;

$saved = isset( $_GET['updated'] ) && '1' === sanitize_key( wp_unslash( $_GET['updated'] ) );
$page_url = admin_url( 'admin.php' );
?>
<div class="wrap scos">

	<form id="se-agency-form" method="post"
		action="<?php echo esc_url( add_query_arg( [ 'page' => Admin_UI::AGENCY_PAGE_SLUG, 'tab' => $active_tab ], $page_url ) ); ?>">
		<?php if ( 'support-settings' === $active_tab ) : // SCOS-SUPPORT-PASS2 — switch nonce/trigger for support-settings tab ?>
			<?php wp_nonce_field( 'se_support_save', 'se_support_nonce' ); ?>
			<input type="hidden" name="se_support_save" value="1" />
		<?php elseif ( 'onboarding' === $active_tab ) : // Client Onboarding — separate nonce/trigger ?>
			<?php wp_nonce_field( 'se_onboarding_save', 'se_onboarding_nonce' ); ?>
			<input type="hidden" name="se_onboarding_save" value="1" />
		<?php else : ?>
			<?php wp_nonce_field( 'se_agency_save', 'se_agency_nonce' ); ?>
			<input type="hidden" name="se_agency_save" value="1" />
			<input type="hidden" name="se_agency_tab" value="<?php echo esc_attr( $active_tab ); ?>" />
		<?php endif; ?>

		<header class="scos__header">
			<div>
				<h1 class="scos__title"><?php esc_html_e( 'Agency', 'site-essentials' ); ?></h1>
				<p class="scos__subtitle"><?php esc_html_e( 'Site Essentials › Agency', 'site-essentials' ); ?></p>
			</div>
			<div class="scos__header-actions">
				<?php if ( 'support-settings' !== $active_tab && 'onboarding' !== $active_tab ) : ?>
				<button type="submit" class="scos-btn scos-btn--primary">
					<?php esc_html_e( 'Save changes', 'site-essentials' ); ?>
				</button>
				<?php endif; ?>
			</div>
		</header>

		<?php if ( $saved ) : ?>
		<div class="scos-notice scos-notice--success">
			<p><?php esc_html_e( 'Settings saved.', 'site-essentials' ); ?></p>
		</div>
		<?php endif; ?>

		<nav class="scos__tabs">
			<a href="<?php echo esc_url( add_query_arg( [ 'page' => Admin_UI::AGENCY_PAGE_SLUG, 'tab' => 'agency-settings' ], $page_url ) ); ?>"
			   class="scos__tab <?php echo 'agency-settings' === $active_tab ? 'scos__tab--active' : ''; ?>">
				<?php esc_html_e( 'Agency Settings', 'site-essentials' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( [ 'page' => Admin_UI::AGENCY_PAGE_SLUG, 'tab' => 'support-settings' ], $page_url ) ); ?>"
			   class="scos__tab <?php echo 'support-settings' === $active_tab ? 'scos__tab--active' : ''; ?>">
				<?php esc_html_e( 'Support Settings', 'site-essentials' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( [ 'page' => Admin_UI::AGENCY_PAGE_SLUG, 'tab' => 'access' ], $page_url ) ); ?>"
			   class="scos__tab <?php echo 'access' === $active_tab ? 'scos__tab--active' : ''; ?>">
				<?php esc_html_e( 'Access', 'site-essentials' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( [ 'page' => Admin_UI::AGENCY_PAGE_SLUG, 'tab' => 'onboarding' ], $page_url ) ); ?>"
			   class="scos__tab <?php echo 'onboarding' === $active_tab ? 'scos__tab--active' : ''; ?>">
				<?php esc_html_e( 'Onboarding', 'site-essentials' ); ?>
			</a>
		</nav>

		<?php if ( 'agency-settings' === $active_tab ) : ?>

			<?php /* ── Agency identity ─────────────────────────────────────────── */ ?>
			<div class="scos-card">
				<div class="scos-card__header">
					<h2 class="scos-card__title"><?php esc_html_e( 'Agency identity', 'site-essentials' ); ?></h2>
					<p class="scos-card__desc"><?php esc_html_e( 'Shown in client dashboards, site credits, and the Support hub.', 'site-essentials' ); ?></p>
				</div>
				<div class="scos-card__body">
					<table class="scos-form">
						<tbody>
							<tr>
								<th>
									<label for="se_agency_name"><?php esc_html_e( 'Agency name', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_name</div>
								</th>
								<td>
									<input type="text" id="se_agency_name" name="se_agency_name" class="scos-input"
										value="<?php echo esc_attr( get_option( 'se_agency_name', '' ) ); ?>" />
								</td>
							</tr>
							<tr>
								<th>
									<label for="se_agency_contact"><?php esc_html_e( 'Agency contact name', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_contact</div>
								</th>
								<td>
									<input type="text" id="se_agency_contact" name="se_agency_contact" class="scos-input"
										value="<?php echo esc_attr( get_option( 'se_agency_contact', '' ) ); ?>" />
								</td>
							</tr>
							<tr>
								<th>
									<label for="se_agency_url"><?php esc_html_e( 'Agency / support base URL', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_url</div>
								</th>
								<td>
									<input type="url" id="se_agency_url" name="se_agency_url" class="scos-input"
										value="<?php echo esc_url( get_option( 'se_agency_url', '' ) ); ?>" />
									<p class="description"><?php esc_html_e( 'Root URL for support portal. Used to resolve relative manual paths and the floating Support link.', 'site-essentials' ); ?></p>
								</td>
							</tr>
							<tr>
								<th>
									<label for="se_agency_email"><?php esc_html_e( 'Support email', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_email</div>
								</th>
								<td>
									<input type="email" id="se_agency_email" name="se_agency_email" class="scos-input"
										value="<?php echo esc_attr( get_option( 'se_agency_email', '' ) ); ?>" />
								</td>
							</tr>
							<tr>
								<th>
									<label for="se_agency_phone"><?php esc_html_e( 'Support phone', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_phone</div>
								</th>
								<td>
									<input type="tel" id="se_agency_phone" name="se_agency_phone" class="scos-input"
										value="<?php echo esc_attr( get_option( 'se_agency_phone', '' ) ); ?>" />
								</td>
							</tr>
							<tr>
								<th>
									<label for="se_agency_logo"><?php esc_html_e( 'Agency logo', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_logo</div>
								</th>
								<td>
									<input type="number" id="se_agency_logo" name="se_agency_logo" class="scos-input"
										value="<?php echo esc_attr( (int) get_option( 'se_agency_logo', 0 ) ); ?>" min="0" />
									<p class="description"><?php esc_html_e( 'Enter the WordPress attachment ID of the logo image.', 'site-essentials' ); ?></p>
								</td>
							</tr>
							<tr>
								<th>
									<label for="se_agency_location"><?php esc_html_e( 'Agency location', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_location</div>
								</th>
								<td>
									<input type="text" id="se_agency_location" name="se_agency_location" class="scos-input"
										value="<?php echo esc_attr( get_option( 'se_agency_location', '' ) ); ?>" />
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<?php /* ── Head meta tags ──────────────────────────────────────────── */ ?>
			<div class="scos-card">
				<div class="scos-card__header">
					<h2 class="scos-card__title"><?php esc_html_e( 'Head meta tags', 'site-essentials' ); ?></h2>
				</div>
				<div class="scos-card__body">
					<table class="scos-form">
						<tbody>
							<tr>
								<th>
									<label for="se_agency_meta_designer"><?php esc_html_e( 'Meta designer', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_meta_designer</div>
								</th>
								<td>
									<input type="text" id="se_agency_meta_designer" name="se_agency_meta_designer" class="scos-input"
										value="<?php echo esc_attr( get_option( 'se_agency_meta_designer', '' ) ); ?>" />
									<p class="description"><?php esc_html_e( 'Output in <meta name="designer">', 'site-essentials' ); ?></p>
								</td>
							</tr>
							<tr>
								<th>
									<label for="se_agency_meta_author"><?php esc_html_e( 'Meta web author', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_meta_author</div>
								</th>
								<td>
									<input type="text" id="se_agency_meta_author" name="se_agency_meta_author" class="scos-input"
										value="<?php echo esc_attr( get_option( 'se_agency_meta_author', '' ) ); ?>" />
									<p class="description"><?php esc_html_e( 'Output in <meta name="author">', 'site-essentials' ); ?></p>
								</td>
							</tr>
							<tr>
								<th>
									<label for="se_agency_generator"><?php esc_html_e( 'Generator tag', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_generator</div>
								</th>
								<td>
									<input type="text" id="se_agency_generator" name="se_agency_generator" class="scos-input"
										value="<?php echo esc_attr( get_option( 'se_agency_generator', '' ) ); ?>" />
									<p class="description"><?php esc_html_e( 'Replaces the WP default generator tag.', 'site-essentials' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<?php /* ── Site credit ───────────────────────────────────────────────── */ ?>
			<div class="scos-card">
				<div class="scos-card__header">
					<h2 class="scos-card__title"><?php esc_html_e( 'Site credit', 'site-essentials' ); ?></h2>
				</div>
				<div class="scos-card__body">
					<table class="scos-form">
						<tbody>
							<tr>
								<th>
									<label for="se_agency_credit_prefix"><?php esc_html_e( 'Credit prefix', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_credit_prefix</div>
								</th>
								<td>
									<input type="text" id="se_agency_credit_prefix" name="se_agency_credit_prefix" class="scos-input"
										value="<?php echo esc_attr( get_option( 'se_agency_credit_prefix', 'Proudly Built by' ) ); ?>" />
								</td>
							</tr>
							<tr>
								<th>
									<label for="se_agency_credit_anchor"><?php esc_html_e( 'Credit anchor text', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_credit_anchor</div>
								</th>
								<td>
									<input type="text" id="se_agency_credit_anchor" name="se_agency_credit_anchor" class="scos-input"
										value="<?php echo esc_attr( get_option( 'se_agency_credit_anchor', '' ) ); ?>" />
									<p class="description"><?php esc_html_e( 'Fallback: se_agency_name', 'site-essentials' ); ?></p>
								</td>
							</tr>
							<tr>
								<th>
									<label for="se_agency_credit_utm"><?php esc_html_e( 'UTM suffix', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_credit_utm</div>
								</th>
								<td>
									<input type="text" id="se_agency_credit_utm" name="se_agency_credit_utm" class="scos-input"
										value="<?php echo esc_attr( get_option( 'se_agency_credit_utm', '' ) ); ?>" />
									<p class="description"><?php esc_html_e( 'Appended to credit URL.', 'site-essentials' ); ?></p>
								</td>
							</tr>
							<tr>
								<th>
									<label for="se_agency_credit_target"><?php esc_html_e( 'Link target', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_credit_target</div>
								</th>
								<td>
									<input type="text" id="se_agency_credit_target" name="se_agency_credit_target" class="scos-input"
										value="<?php echo esc_attr( get_option( 'se_agency_credit_target', '_blank' ) ); ?>" />
								</td>
							</tr>
							<tr>
								<th>
									<label for="se_agency_credit_rel"><?php esc_html_e( 'Link rel', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_credit_rel</div>
								</th>
								<td>
									<input type="text" id="se_agency_credit_rel" name="se_agency_credit_rel" class="scos-input"
										value="<?php echo esc_attr( get_option( 'se_agency_credit_rel', 'noopener designer' ) ); ?>" />
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<?php /* ── Humans.txt ───────────────────────────────────────────────── */ ?>
			<div class="scos-card">
				<div class="scos-card__header">
					<h2 class="scos-card__title"><?php esc_html_e( 'Humans.txt', 'site-essentials' ); ?></h2>
				</div>
				<div class="scos-card__body">
					<table class="scos-form">
						<tbody>
							<tr>
								<th>
									<label for="se_agency_humans_txt_enabled"><?php esc_html_e( 'Enable humans.txt', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_humans_txt_enabled</div>
								</th>
								<td>
									<label class="scos-checkbox-row">
										<input type="checkbox" id="se_agency_humans_txt_enabled" name="se_agency_humans_txt_enabled"
											value="1" <?php checked( get_option( 'se_agency_humans_txt_enabled', '' ), '1' ); ?> />
										<?php esc_html_e( 'Publish /humans.txt', 'site-essentials' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th>
									<label for="se_agency_humans_txt"><?php esc_html_e( 'Humans.txt content', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_humans_txt</div>
								</th>
								<td>
									<textarea id="se_agency_humans_txt" name="se_agency_humans_txt" class="scos-textarea" rows="8"
									><?php echo esc_textarea( get_option( 'se_agency_humans_txt', '' ) ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Published at /humans.txt when enabled.', 'site-essentials' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<div class="scos-card__footer">
					<button type="submit" class="scos-btn scos-btn--primary">
						<?php esc_html_e( 'Save changes', 'site-essentials' ); ?>
					</button>
				</div>
			</div>

		<?php elseif ( 'support-settings' === $active_tab ) : // SCOS-SUPPORT-PASS2 — replaced empty state with working form ?>

				<?php /* ── Card 0 — Site notification ───────────────────────── */ ?>
				<div class="scos-card">
					<div class="scos-card__header">
						<h2 class="scos-card__title"><?php esc_html_e( 'Site notification', 'site-essentials' ); ?></h2>
						<p class="scos-card__desc"><?php esc_html_e( 'Shown as a banner above the Support page content. Use for time-sensitive messages — maintenance windows, update holds, known issues, etc. Leave message empty to hide.', 'site-essentials' ); ?></p>
					</div>
					<div class="scos-card__body">
						<table class="scos-form">
							<tbody>
								<tr>
									<th>
										<label for="se_support_notification_type"><?php esc_html_e( 'Type', 'site-essentials' ); ?></label>
										<div class="scos-form__slug">se_support_notification_type</div>
									</th>
									<td>
										<?php
										$saved_notif_type = get_option( 'se_support_notification_type', 'warning' );
										$notif_types = [
											'info'    => __( 'ℹ Info (blue)', 'site-essentials' ),
											'warning' => __( '⚠ Warning (yellow)', 'site-essentials' ),
											'urgent'  => __( '🔴 Urgent (red)', 'site-essentials' ),
										];
										?>
										<select id="se_support_notification_type" name="se_support_notification_type" class="scos-select">
											<?php foreach ( $notif_types as $val => $label ) : ?>
												<option value="<?php echo esc_attr( $val ); ?>"<?php selected( $saved_notif_type, $val ); ?>>
													<?php echo esc_html( $label ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<tr>
									<th>
										<label for="se_support_notification"><?php esc_html_e( 'Message', 'site-essentials' ); ?></label>
										<div class="scos-form__slug">se_support_notification</div>
										<p class="scos-metabox__hint"><?php esc_html_e( 'Plain text. Leave empty to hide the banner entirely.', 'site-essentials' ); ?></p>
									</th>
									<td>
										<textarea id="se_support_notification" name="se_support_notification"
											class="scos-textarea" rows="3"
											placeholder="<?php esc_attr_e( 'e.g. WordPress 7.0 has just been released. We will update your site once compatibility testing is complete — please hold off updating until you hear from us.', 'site-essentials' ); ?>"><?php echo esc_textarea( get_option( 'se_support_notification', '' ) ); ?></textarea>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>

				<?php /* ── Card 1 — Support tools ─────────────────────────── */ ?>
				<div class="scos-card">
					<div class="scos-card__header">
						<h2 class="scos-card__title"><?php esc_html_e( 'Support tools', 'site-essentials' ); ?></h2>
						<p class="scos-card__desc"><?php esc_html_e( 'Links shown on the client Support landing page. Leave title or URL empty to hide.', 'site-essentials' ); ?></p>
					</div>
					<div class="scos-card__body">
						<?php
						$tool_placeholders = [
							1 => [ 'title' => 'Quick Guide',              'url' => 'https://' ],
							2 => [ 'title' => 'Website Manual',           'url' => 'https://' ],
							3 => [ 'title' => 'Website Ranking Report',   'url' => 'https://' ],
							4 => [ 'title' => 'Maps Ranking Report',      'url' => 'https://' ],
							5 => [ 'title' => 'Client Portal',            'url' => 'https://' ],
							6 => [ 'title' => '',                         'url' => 'https://' ],
						];
						for ( $i = 1; $i <= 6; $i++ ) :
						?>
						<div class="scos-support-slot">
							<div class="scos-metabox__row scos-metabox__row--inline">
								<div>
									<label for="se_support_tool_<?php echo esc_attr( $i ); ?>_title">
										<?php /* translators: %d slot number */ ?>
										<?php echo esc_html( sprintf( __( 'Tool %d — Title', 'site-essentials' ), $i ) ); ?>
									</label>
									<div class="scos-form__slug">se_support_tool_<?php echo esc_attr( $i ); ?>_title</div>
									<input type="text"
										id="se_support_tool_<?php echo esc_attr( $i ); ?>_title"
										name="se_support_tool_<?php echo esc_attr( $i ); ?>_title"
										class="scos-input"
										value="<?php echo esc_attr( get_option( "se_support_tool_{$i}_title", '' ) ); ?>"
										placeholder="<?php echo esc_attr( $tool_placeholders[ $i ]['title'] ); ?>" />
								</div>
								<div>
									<label for="se_support_tool_<?php echo esc_attr( $i ); ?>_url">
										<?php echo esc_html( sprintf( __( 'Tool %d — URL', 'site-essentials' ), $i ) ); ?>
									</label>
									<div class="scos-form__slug">se_support_tool_<?php echo esc_attr( $i ); ?>_url</div>
									<input type="url"
										id="se_support_tool_<?php echo esc_attr( $i ); ?>_url"
										name="se_support_tool_<?php echo esc_attr( $i ); ?>_url"
										class="scos-input"
										value="<?php echo esc_url( get_option( "se_support_tool_{$i}_url", '' ) ); ?>"
										placeholder="<?php echo esc_attr( $tool_placeholders[ $i ]['url'] ); ?>" />
								</div>
							</div>
							<div class="scos-metabox__row">
								<label for="se_support_tool_<?php echo esc_attr( $i ); ?>_description">
									<?php echo esc_html( sprintf( __( 'Tool %d — Description', 'site-essentials' ), $i ) ); ?>
								</label>
								<div class="scos-form__slug">se_support_tool_<?php echo esc_attr( $i ); ?>_description</div>
								<p class="scos-metabox__hint"><?php esc_html_e( 'Shown below the card heading on the Support page.', 'site-essentials' ); ?></p>
								<textarea
									id="se_support_tool_<?php echo esc_attr( $i ); ?>_description"
									name="se_support_tool_<?php echo esc_attr( $i ); ?>_description"
									class="scos-textarea"
									rows="2"><?php echo esc_textarea( get_option( "se_support_tool_{$i}_description", '' ) ); ?></textarea>
							</div>
							<label class="scos-checkbox-row">
								<input type="checkbox"
									id="se_support_tool_<?php echo esc_attr( $i ); ?>_highlight"
									name="se_support_tool_<?php echo esc_attr( $i ); ?>_highlight"
									value="1"
									<?php checked( get_option( "se_support_tool_{$i}_highlight", 0 ), 1 ); ?> />
								<span>
									<?php esc_html_e( 'Highlight', 'site-essentials' ); ?>
									<span class="scos-form__slug" style="display:inline;margin-left:var(--scos-s-2)">se_support_tool_<?php echo esc_attr( $i ); ?>_highlight</span>
								</span>
							</label>
							<p class="scos-metabox__hint"><?php esc_html_e( 'Adds a purple accent border on the Support page card.', 'site-essentials' ); ?></p>
						</div>
						<?php endfor; ?>
					</div>
				</div>

				<?php /* ── Card 2 — AI tools ──────────────────────────────── */ ?>
				<div class="scos-card">
					<div class="scos-card__header">
						<h2 class="scos-card__title"><?php esc_html_e( 'AI tools', 'site-essentials' ); ?></h2>
						<p class="scos-card__desc"><?php esc_html_e( 'AI assistant links shown on the Support landing page. Leave title or URL empty to hide.', 'site-essentials' ); ?></p>
					</div>
					<div class="scos-card__body">
						<?php
						$ai_placeholders = [
							1 => 'Content Writing Assistant',
							2 => 'Research Assistant',
							3 => 'Social Media Assistant',
							4 => 'Competitor & Market Research',
						];
						for ( $i = 1; $i <= 4; $i++ ) :
						?>
						<div class="scos-support-slot">
							<div class="scos-metabox__row scos-metabox__row--inline">
								<div>
									<label for="se_support_ai_<?php echo esc_attr( $i ); ?>_title">
										<?php echo esc_html( sprintf( __( 'AI tool %d — Title', 'site-essentials' ), $i ) ); ?>
									</label>
									<div class="scos-form__slug">se_support_ai_<?php echo esc_attr( $i ); ?>_title</div>
									<input type="text"
										id="se_support_ai_<?php echo esc_attr( $i ); ?>_title"
										name="se_support_ai_<?php echo esc_attr( $i ); ?>_title"
										class="scos-input"
										value="<?php echo esc_attr( get_option( "se_support_ai_{$i}_title", '' ) ); ?>"
										placeholder="<?php echo esc_attr( $ai_placeholders[ $i ] ); ?>" />
								</div>
								<div>
									<label for="se_support_ai_<?php echo esc_attr( $i ); ?>_url">
										<?php echo esc_html( sprintf( __( 'AI tool %d — URL', 'site-essentials' ), $i ) ); ?>
									</label>
									<div class="scos-form__slug">se_support_ai_<?php echo esc_attr( $i ); ?>_url</div>
									<input type="url"
										id="se_support_ai_<?php echo esc_attr( $i ); ?>_url"
										name="se_support_ai_<?php echo esc_attr( $i ); ?>_url"
										class="scos-input"
										value="<?php echo esc_url( get_option( "se_support_ai_{$i}_url", '' ) ); ?>"
										placeholder="https://" />
								</div>
							</div>
							<div class="scos-metabox__row">
								<label for="se_support_ai_<?php echo esc_attr( $i ); ?>_description">
									<?php echo esc_html( sprintf( __( 'AI tool %d — Description', 'site-essentials' ), $i ) ); ?>
								</label>
								<div class="scos-form__slug">se_support_ai_<?php echo esc_attr( $i ); ?>_description</div>
								<p class="scos-metabox__hint"><?php esc_html_e( 'Shown below the card heading on the Support page.', 'site-essentials' ); ?></p>
								<textarea
									id="se_support_ai_<?php echo esc_attr( $i ); ?>_description"
									name="se_support_ai_<?php echo esc_attr( $i ); ?>_description"
									class="scos-textarea"
									rows="2"><?php echo esc_textarea( get_option( "se_support_ai_{$i}_description", '' ) ); ?></textarea>
							</div>
							<label class="scos-checkbox-row">
								<input type="checkbox"
									id="se_support_ai_<?php echo esc_attr( $i ); ?>_highlight"
									name="se_support_ai_<?php echo esc_attr( $i ); ?>_highlight"
									value="1"
									<?php checked( get_option( "se_support_ai_{$i}_highlight", 0 ), 1 ); ?> />
								<span>
									<?php esc_html_e( 'Highlight', 'site-essentials' ); ?>
									<span class="scos-form__slug" style="display:inline;margin-left:var(--scos-s-2)">se_support_ai_<?php echo esc_attr( $i ); ?>_highlight</span>
								</span>
							</label>
							<p class="scos-metabox__hint"><?php esc_html_e( 'Adds a purple accent border on the Support page card.', 'site-essentials' ); ?></p>
						</div>
						<?php endfor; ?>
					</div>
				</div>

				<?php /* ── Card 3 — Third-party scripts ───────────────────── */ ?>
				<div class="scos-card">
					<div class="scos-card__header">
						<h2 class="scos-card__title"><?php esc_html_e( 'Third-party scripts', 'site-essentials' ); ?></h2>
						<p class="scos-card__desc"><?php esc_html_e( 'Injected into the public &lt;head&gt; on every page via wp_head. Use for analytics, chat widgets, or similar third-party tags.', 'site-essentials' ); ?></p>
					</div>
					<div class="scos-card__body">
						<table class="scos-form">
							<tbody>
								<tr>
									<th>
										<label for="se_support_script_commenter"><?php esc_html_e( 'Commenter script', 'site-essentials' ); ?></label>
										<div class="scos-form__slug">se_support_script_commenter</div>
									</th>
									<td>
										<textarea id="se_support_script_commenter" name="se_support_script_commenter"
											class="scos-textarea" rows="4"><?php echo esc_textarea( get_option( 'se_support_script_commenter', '' ) ); ?></textarea>
										<p class="description"><?php esc_html_e( 'Paste the full script tag.', 'site-essentials' ); ?></p>
									</td>
								</tr>
								<tr>
									<th>
										<label for="se_support_script_ahrefs"><?php esc_html_e( 'Ahrefs analytics script', 'site-essentials' ); ?></label>
										<div class="scos-form__slug">se_support_script_ahrefs</div>
									</th>
									<td>
										<textarea id="se_support_script_ahrefs" name="se_support_script_ahrefs"
											class="scos-textarea" rows="4"><?php echo esc_textarea( get_option( 'se_support_script_ahrefs', '' ) ); ?></textarea>
										<p class="description"><?php esc_html_e( 'Paste the full script tag.', 'site-essentials' ); ?></p>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
					<div class="scos-card__footer">
						<button type="submit" class="scos-btn scos-btn--primary">
							<?php esc_html_e( 'Save changes', 'site-essentials' ); ?>
						</button>
					</div>
				</div>

		<?php elseif ( 'onboarding' === $active_tab ) : ?>

			<?php include SITE_ESSENTIALS_PATH . 'Views/agency-onboarding-tab.php'; ?>

		<?php elseif ( 'access' === $active_tab ) : ?>

			<?php
			// Leaving a field empty falls back to these, so show them as placeholders
			// rather than pre-filled values — a pre-filled default is indistinguishable
			// from a saved choice and gets persisted on the next save.
			$support_default   = admin_url( 'admin.php?page=site-essentials-support' );
			$dashboard_default = admin_url();
			?>
			<div class="scos-card">
				<div class="scos-card__header">
					<h2 class="scos-card__title"><?php esc_html_e( 'Login redirects', 'site-essentials' ); ?></h2>
					<p class="scos-card__desc"><?php esc_html_e( 'Where users land after logging in. Must be a URL on this site — off-site URLs are discarded.', 'site-essentials' ); ?></p>
				</div>
				<div class="scos-card__body">
					<table class="scos-form">
						<tbody>
							<tr>
								<th>
									<label for="se_agency_login_redirect_admin"><?php esc_html_e( 'Admin redirect', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_login_redirect_admin</div>
								</th>
								<td>
									<input type="url" id="se_agency_login_redirect_admin" name="se_agency_login_redirect_admin" class="scos-input"
										value="<?php echo esc_url( get_option( 'se_agency_login_redirect_admin', '' ) ); ?>"
										placeholder="<?php echo esc_attr( $support_default ); ?>" />
									<p class="description"><?php esc_html_e( 'Leave empty to use the Support hub.', 'site-essentials' ); ?></p>
								</td>
							</tr>
							<tr>
								<th>
									<label for="se_agency_login_redirect_editor"><?php esc_html_e( 'Editor redirect', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_login_redirect_editor</div>
								</th>
								<td>
									<input type="url" id="se_agency_login_redirect_editor" name="se_agency_login_redirect_editor" class="scos-input"
										value="<?php echo esc_url( get_option( 'se_agency_login_redirect_editor', '' ) ); ?>"
										placeholder="<?php echo esc_attr( $dashboard_default ); ?>" />
									<p class="description"><?php esc_html_e( 'Leave empty to use the standard WordPress dashboard.', 'site-essentials' ); ?></p>
								</td>
							</tr>
							<tr> <?php // SCOS-SUPPORT-PASS2 — shop_manager redirect field added ?>
								<th>
									<label for="se_agency_login_redirect_shop_manager"><?php esc_html_e( 'Shop Manager redirect', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">se_agency_login_redirect_shop_manager</div>
								</th>
								<td>
									<input type="url" id="se_agency_login_redirect_shop_manager" name="se_agency_login_redirect_shop_manager" class="scos-input"
										value="<?php echo esc_url( get_option( 'se_agency_login_redirect_shop_manager', '' ) ); ?>"
										placeholder="<?php echo esc_attr( $support_default ); ?>" />
									<p class="description"><?php esc_html_e( 'Leave empty to use the Support hub.', 'site-essentials' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<div class="scos-card__footer">
					<button type="submit" class="scos-btn scos-btn--primary">
						<?php esc_html_e( 'Save changes', 'site-essentials' ); ?>
					</button>
				</div>
			</div>

		<?php endif; ?>

	</form>

</div>
