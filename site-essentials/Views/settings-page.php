<?php
/**
 * Settings Page Template
 *
 * v1.1 | 2026-05-19
 *
 * SCOS design system: scos__header, scos__tabs, scos-card per section.
 * Modules tab calls render_modules_section() directly instead of
 * do_settings_sections() to avoid the duplicate module-name list that
 * the Settings API renders as section headings.
 *
 * @package    SiteEssentials
 * @subpackage Views
 */

defined( 'ABSPATH' ) || exit;

use SiteEssentials\Core\Admin_UI;
use SiteEssentials\Core\Module_Loader;
use SiteEssentials\Core\Cache_Helper;

$page_slug   = Admin_UI::SETTINGS_PAGE_SLUG;
$deploy_info = Admin_UI::get_deployment_info();
?>

<div class="wrap scos">

	<header class="scos__header">
		<div>
			<h1 class="scos__title"><?php esc_html_e( 'Settings', 'site-essentials' ); ?></h1>
			<p class="scos__subtitle">
				<?php esc_html_e( 'Site Essentials › Settings', 'site-essentials' ); ?>
				&nbsp;&mdash;&nbsp;v<?php echo esc_html( $deploy_info['version'] ); ?>
				&nbsp;|&nbsp;<code><?php echo esc_html( $deploy_info['commit'] ); ?></code>
				&nbsp;|&nbsp;<?php echo esc_html( $deploy_info['deployed_at'] ); ?>
			</p>
		</div>
	</header>

	<nav class="scos__tabs">
		<a href="?page=<?php echo esc_attr( $page_slug ); ?>&tab=modules"
		   class="scos__tab<?php echo 'modules' === $active_tab ? ' scos__tab--active' : ''; ?>">
			<?php esc_html_e( 'Modules', 'site-essentials' ); ?>
		</a>
		<a href="?page=<?php echo esc_attr( $page_slug ); ?>&tab=import-export"
		   class="scos__tab<?php echo 'import-export' === $active_tab ? ' scos__tab--active' : ''; ?>">
			<?php esc_html_e( 'Import / Export', 'site-essentials' ); ?>
		</a>
		<a href="?page=<?php echo esc_attr( $page_slug ); ?>&tab=api"
		   class="scos__tab<?php echo 'api' === $active_tab ? ' scos__tab--active' : ''; ?>">
			<?php esc_html_e( 'API Settings', 'site-essentials' ); ?>
		</a>
		<a href="?page=<?php echo esc_attr( $page_slug ); ?>&tab=ai-keys"
		   class="scos__tab<?php echo 'ai-keys' === $active_tab ? ' scos__tab--active' : ''; ?>">
			<?php esc_html_e( 'AI API Keys', 'site-essentials' ); ?>
		</a>
		<a href="?page=<?php echo esc_attr( $page_slug ); ?>&tab=email"
		   class="scos__tab<?php echo 'email' === $active_tab ? ' scos__tab--active' : ''; ?>">
			<?php esc_html_e( 'Email', 'site-essentials' ); ?>
		</a>
		<a href="?page=<?php echo esc_attr( $page_slug ); ?>&tab=cache"
		   class="scos__tab<?php echo 'cache' === $active_tab ? ' scos__tab--active' : ''; ?>">
			<?php esc_html_e( 'Cache', 'site-essentials' ); ?>
		</a>
		<a href="?page=<?php echo esc_attr( $page_slug ); ?>&tab=debug"
		   class="scos__tab<?php echo 'debug' === $active_tab ? ' scos__tab--active' : ''; ?>">
			<?php esc_html_e( 'Debug', 'site-essentials' ); ?>
		</a>
	</nav>

	<?php if ( 'modules' === $active_tab ) : ?>

		<p class="description" style="margin-bottom:var(--scos-s-4)">
			<?php esc_html_e( 'Enable or disable modules below. Module settings are available on their respective pages (SEO, Essentials, etc.).', 'site-essentials' ); ?>
		</p>

		<?php
		/*
		 * Call render_modules_section() directly rather than do_settings_sections().
		 * do_settings_sections() renders an <h2> for every registered module section
		 * (added via register_settings()), which produces the duplicate name list
		 * visible below the grid.
		 */
		global $wp_settings_sections;
		$section_callback = $wp_settings_sections[ Admin_UI::PAGE_SLUG ]['site_essentials_modules']['callback'] ?? null;
		if ( is_callable( $section_callback ) ) {
			call_user_func( $section_callback );
		}
		?>

	<?php elseif ( 'import-export' === $active_tab ) : ?>

		<div class="scos-card" style="margin-bottom:var(--scos-s-4)">
			<div class="scos-card__header">
				<div>
					<h2 class="scos-card__title"><?php esc_html_e( 'Export Settings', 'site-essentials' ); ?></h2>
					<p class="scos-card__desc"><?php esc_html_e( 'Export your Site Essentials settings as JSON.', 'site-essentials' ); ?></p>
				</div>
			</div>
			<div class="scos-card__body">
				<button type="button" class="scos-btn scos-btn--primary" id="se-export-settings">
					<?php esc_html_e( 'Export Settings', 'site-essentials' ); ?>
				</button>
			</div>
		</div>

		<div class="scos-card">
			<div class="scos-card__header">
				<div>
					<h2 class="scos-card__title"><?php esc_html_e( 'Import Settings', 'site-essentials' ); ?></h2>
					<p class="scos-card__desc"><?php esc_html_e( 'Import settings from a JSON file.', 'site-essentials' ); ?></p>
				</div>
			</div>
			<div class="scos-card__body">
				<textarea id="se-import-json" rows="10" class="scos-input scos-input--mono" style="max-width:100%;margin-bottom:var(--scos-s-3)"></textarea>
				<label class="scos-checkbox-row" style="margin-bottom:var(--scos-s-3)">
					<input type="checkbox" id="se-import-merge" checked>
					<span><?php esc_html_e( 'Merge with existing settings (unchecked = replace all)', 'site-essentials' ); ?></span>
				</label>
			</div>
			<div class="scos-card__footer">
				<button type="button" class="scos-btn scos-btn--primary" id="se-import-settings">
					<?php esc_html_e( 'Import Settings', 'site-essentials' ); ?>
				</button>
			</div>
		</div>

	<?php elseif ( 'api' === $active_tab ) : ?>

		<?php include SITE_ESSENTIALS_PATH . 'Views/settings-api.php'; ?>

	<?php elseif ( 'ai-keys' === $active_tab ) : ?>

		<?php
		// TODO: migrate to se_ prefix (shared across modules) — see CLAUDE.md §3.
		$anthropic_opt      = get_option( 'bw_anthropic_api_key', '' );
		$anthropic_model    = get_option( 'bw_anthropic_model', '' );
		$anthropic_constant = defined( 'SE_ANTHROPIC_API_KEY' ) && is_string( SE_ANTHROPIC_API_KEY ) && SE_ANTHROPIC_API_KEY !== '';
		// Never render the key itself. type="password" only masks it on screen —
		// the cleartext value stays in the page source, readable by anything that
		// can see the DOM of this admin page.
		$anthropic_has_key  = $anthropic_constant || ( is_string( $anthropic_opt ) && $anthropic_opt !== '' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'scos_save_ai_keys', 'scos_ai_keys_nonce' ); ?>
			<input type="hidden" name="action" value="scos_save_ai_keys">

			<div class="scos-card">
				<div class="scos-card__header">
					<div>
						<h2 class="scos-card__title"><?php esc_html_e( 'AI API Keys', 'site-essentials' ); ?></h2>
						<p class="scos-card__desc"><?php esc_html_e( 'Third-party AI provider credentials. Stored as WordPress options and never exposed to the front end.', 'site-essentials' ); ?></p>
					</div>
				</div>
				<div class="scos-card__body">
					<table class="scos-form">
						<tbody>
							<tr>
								<th>
									<label for="bw_anthropic_api_key"><?php esc_html_e( 'Anthropic API Key', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">bw_anthropic_api_key</div>
								</th>
								<td>
									<input type="password" id="bw_anthropic_api_key" name="bw_anthropic_api_key"
									       value=""
									       class="scos-input scos-input--mono"
									       autocomplete="new-password"
									       <?php disabled( $anthropic_constant ); ?>
									       placeholder="<?php echo esc_attr( $anthropic_has_key ? __( 'Saved — leave blank to keep', 'site-essentials' ) : __( 'sk-ant-...', 'site-essentials' ) ); ?>">
									<p class="description">
										<?php esc_html_e( 'Used by Social Amplification (caption generation via Claude) and future AI integrations. Obtain from', 'site-essentials' ); ?>
										<a href="https://console.anthropic.com/" target="_blank" rel="noopener">console.anthropic.com</a>.
									</p>
									<?php if ( $anthropic_constant ) : ?>
										<p class="description">
											<?php esc_html_e( 'Set via the SE_ANTHROPIC_API_KEY constant in wp-config.php. Remove the constant to manage the key here.', 'site-essentials' ); ?>
										</p>
									<?php elseif ( $anthropic_has_key ) : ?>
										<p class="description" style="color:var(--scos-success);margin-top:var(--scos-s-1)">
											✓ <?php esc_html_e( 'Key is saved. Leave blank to keep it, or enter a new value to replace it.', 'site-essentials' ); ?>
										</p>
										<label class="description" for="bw_anthropic_api_key_clear">
											<input type="checkbox" id="bw_anthropic_api_key_clear" name="bw_anthropic_api_key_clear" value="1">
											<?php esc_html_e( 'Delete the stored key', 'site-essentials' ); ?>
										</label>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th>
									<label for="bw_anthropic_model"><?php esc_html_e( 'Claude Model', 'site-essentials' ); ?></label>
									<div class="scos-form__slug">bw_anthropic_model</div>
								</th>
								<td>
									<input type="text" id="bw_anthropic_model" name="bw_anthropic_model"
									       value="<?php echo esc_attr( $anthropic_model ); ?>"
									       class="scos-input scos-input--mono"
									       placeholder="claude-haiku-4-5-20251001">
									<p class="description">
										<?php esc_html_e( 'Default:', 'site-essentials' ); ?> <code>claude-haiku-4-5-20251001</code>
										<?php esc_html_e( '(Claude Haiku 4.5). Enter the exact API model string — e.g.', 'site-essentials' ); ?>
										<code>claude-3-5-sonnet-20241022</code>.
										<?php esc_html_e( 'A 404 error means the model name is wrong or not on your plan — check', 'site-essentials' ); ?>
										<a href="https://docs.anthropic.com/en/docs/about-claude/models" target="_blank" rel="noopener">docs.anthropic.com/models</a>.
									</p>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<div class="scos-card__footer">
					<button type="submit" class="scos-btn scos-btn--primary">
						<?php esc_html_e( 'Save AI API Keys', 'site-essentials' ); ?>
					</button>
				</div>
			</div>
		</form>

	<?php elseif ( 'email' === $active_tab ) : ?>

		<?php include SITE_ESSENTIALS_PATH . 'Modules/EmailDelivery/views/settings.php'; ?>

	<?php elseif ( 'cache' === $active_tab ) : ?>

		<?php $stats = Cache_Helper::get_stats(); ?>

		<div class="scos-card" style="margin-bottom:var(--scos-s-4)">
			<div class="scos-card__header scos-card__header--plain">
				<h2 class="scos-card__title"><?php esc_html_e( 'Cache Statistics', 'site-essentials' ); ?></h2>
			</div>
			<div class="scos-card__body">
				<table class="scos-form">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Object Cache', 'site-essentials' ); ?></th>
							<td>
								<?php if ( $stats['object_cache_enabled'] ) : ?>
									<span style="color:var(--scos-success)">✓ <?php esc_html_e( 'Enabled', 'site-essentials' ); ?></span>
								<?php else : ?>
									<span style="color:var(--scos-ink-subtle)">✗ <?php esc_html_e( 'Disabled (using transients)', 'site-essentials' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Cache Group', 'site-essentials' ); ?></th>
							<td><code><?php echo esc_html( $stats['cache_group'] ); ?></code></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Default Duration', 'site-essentials' ); ?></th>
							<td><?php echo esc_html( $stats['default_duration'] ); ?> <?php esc_html_e( 'seconds', 'site-essentials' ); ?></td>
						</tr>
						<?php if ( isset( $stats['transient_count'] ) ) : ?>
						<tr>
							<th><?php esc_html_e( 'Transient Count', 'site-essentials' ); ?></th>
							<td><?php echo esc_html( $stats['transient_count'] ); ?></td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="scos-card">
			<div class="scos-card__header scos-card__header--plain">
				<h2 class="scos-card__title"><?php esc_html_e( 'Clear Cache', 'site-essentials' ); ?></h2>
			</div>
			<div class="scos-card__body">
				<p class="description" style="margin-bottom:var(--scos-s-3)"><?php esc_html_e( 'Clear all Site Essentials cache entries.', 'site-essentials' ); ?></p>
				<button type="button" class="scos-btn scos-btn--ghost" id="se-clear-cache">
					<?php esc_html_e( 'Clear All Cache', 'site-essentials' ); ?>
				</button>
			</div>
		</div>

	<?php elseif ( 'debug' === $active_tab ) : ?>

		<?php $loaded_modules = Module_Loader::get_loaded_modules(); ?>
		<div class="scos-card" style="margin-bottom:var(--scos-s-4)">
			<div class="scos-card__header scos-card__header--plain">
				<h2 class="scos-card__title"><?php esc_html_e( 'Loaded Modules', 'site-essentials' ); ?></h2>
			</div>
			<div class="scos-card__body">
				<?php if ( empty( $loaded_modules ) ) : ?>
					<p class="description"><?php esc_html_e( 'No modules loaded.', 'site-essentials' ); ?></p>
				<?php else : ?>
					<table class="scos-form">
						<tbody>
						<?php foreach ( $loaded_modules as $module_id => $module ) : ?>
							<tr>
								<th><?php echo esc_html( $module::get_name() ); ?></th>
								<td>
									<code><?php echo esc_html( $module_id ); ?></code>
									&nbsp;&mdash;&nbsp;v<?php echo esc_html( $module::get_version() ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<?php $failed_modules = Module_Loader::get_failed_modules(); ?>
		<div class="scos-card" style="margin-bottom:var(--scos-s-4)">
			<div class="scos-card__header scos-card__header--plain">
				<h2 class="scos-card__title"><?php esc_html_e( 'Failed Modules', 'site-essentials' ); ?></h2>
			</div>
			<div class="scos-card__body">
				<?php if ( empty( $failed_modules ) ) : ?>
					<p class="description" style="color:var(--scos-success)">✓ <?php esc_html_e( 'No module failures.', 'site-essentials' ); ?></p>
				<?php else : ?>
					<table class="scos-form">
						<tbody>
						<?php foreach ( $failed_modules as $module_id => $reason ) : ?>
							<tr>
								<th style="color:var(--scos-danger)"><?php echo esc_html( $module_id ); ?></th>
								<td><?php echo esc_html( $reason ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<div class="scos-card">
			<div class="scos-card__header scos-card__header--plain">
				<h2 class="scos-card__title"><?php esc_html_e( 'System Info', 'site-essentials' ); ?></h2>
			</div>
			<div class="scos-card__body">
				<table class="scos-form">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Site Essentials Version', 'site-essentials' ); ?></th>
							<td><?php echo esc_html( SITE_ESSENTIALS_VERSION ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'WordPress Version', 'site-essentials' ); ?></th>
							<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'PHP Version', 'site-essentials' ); ?></th>
							<td><?php echo esc_html( PHP_VERSION ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Object Cache', 'site-essentials' ); ?></th>
							<td>
								<?php if ( wp_using_ext_object_cache() ) : ?>
									<span style="color:var(--scos-success)">✓ <?php esc_html_e( 'Enabled', 'site-essentials' ); ?></span>
								<?php else : ?>
									<span style="color:var(--scos-ink-subtle)">✗ <?php esc_html_e( 'Disabled', 'site-essentials' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

	<?php endif; ?>

</div>
