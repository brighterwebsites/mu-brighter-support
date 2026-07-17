<?php
/**
 * SEO Meta box view — 3-tab UI.
 *
 * Variables available from Meta_Box::render():
 *   $post                      WP_Post
 *   $breadcrumb_title          string
 *   $tldr                      string
 *   $title                     string
 *   $description               string
 *   $canonical                 string
 *   $robots                    string[]
 *   $sitemap_exclude           string[]
 *   $sitemap_noindex_override  bool   — user override: include in sitemap despite noindex
 *   $sitemap_noindex_auto      bool   — internal: xml exclusion was auto-set by noindex
 *   $freeze_date               bool   — per-post freeze flag
 *   $global_freeze_date        bool   — site-wide freeze option
 *
 * @package SiteEssentials
 *
 * v1.0 | 2026-06-24
 * v1.1 | 2026-07-17 — TLDR field switched from plain textarea to a teeny
 *                      wp_editor() instance (bold/lists/link, no images).
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="scos-seo-wrap">

	<!-- ── TAB NAV ── -->
	<nav class="scos-seo-tabs" role="tablist">
		<button type="button" class="scos-seo-tab-btn is-active" data-tab="core"
			role="tab" aria-selected="true" aria-controls="scos-seo-tab-core">
			<?php esc_html_e( 'Core SEO', 'site-essentials' ); ?>
		</button>
		<button type="button" class="scos-seo-tab-btn" data-tab="advanced"
			role="tab" aria-selected="false" aria-controls="scos-seo-tab-advanced">
			<?php esc_html_e( 'Advanced', 'site-essentials' ); ?>
		</button>
		<button type="button" class="scos-seo-tab-btn" data-tab="og"
			role="tab" aria-selected="false" aria-controls="scos-seo-tab-og">
			<?php esc_html_e( 'OG Social', 'site-essentials' ); ?>
		</button>
	</nav>

	<!-- ══ CORE SEO TAB ══ -->
	<div class="scos-seo-tab-panel is-active" id="scos-seo-tab-core" role="tabpanel">

		<!-- AI suggest trigger — breadcrumb + title + description in one call -->
		<?php if ( class_exists( 'WordPress\AI\Abstracts\Abstract_Ability' ) ) : ?>
		<div class="scos-seo-ai-row">
			<button type="button" id="scos-seo-suggest-btn" class="button">
				<?php esc_html_e( 'Suggest with AI', 'site-essentials' ); ?>
			</button>
			<span class="scos-seo-ai-hint"><?php esc_html_e( 'Suggest breadcrumb label, meta title and description from page content.', 'site-essentials' ); ?></span>
		</div>
		<?php endif; ?>

		<!-- Breadcrumb Title -->
		<div class="scos-seo-field">
			<label for="scos_seo_breadcrumb_title">
				<?php esc_html_e( 'Breadcrumb Label', 'site-essentials' ); ?>
			</label>
			<input type="text"
				name="scos_seo_breadcrumb_title"
				id="scos_seo_breadcrumb_title"
				value="<?php echo esc_attr( $breadcrumb_title ); ?>"
				placeholder="<?php esc_attr_e( 'e.g. SEO Signals', 'site-essentials' ); ?>">
			<p class="scos-seo-help"><?php esc_html_e( 'Short label for breadcrumb navigation. Plain text, can include spaces — e.g. "SEO Signals". (YOURLS shortlink slug is separate, set in the Social Amplification metabox.)', 'site-essentials' ); ?></p>
		</div>

		<!-- TLDR Summary -->
		<div class="scos-seo-field">
			<label for="scos_seo_tldr" class="scos-seo-label-row">
				<?php esc_html_e( 'TLDR / Article Summary', 'site-essentials' ); ?>
				<?php if ( class_exists( 'WordPress\AI\Abstracts\Abstract_Ability' ) ) : ?>
				<button type="button" id="scos-tldr-suggest-btn" class="button button-small scos-seo-label-btn">
					<?php esc_html_e( 'Suggest TLDR', 'site-essentials' ); ?>
				</button>
				<?php endif; ?>
			</label>
			<div class="scos-seo-tldr-editor">
				<?php
				wp_editor(
					$tldr,
					'scos_seo_tldr',
					[
						'textarea_name'    => 'scos_seo_tldr',
						'textarea_rows'    => 4,
						'teeny'            => true,
						'media_buttons'    => false,
						'drag_drop_upload' => false,
						'quicktags'        => false,
						'tinymce'          => [
							'toolbar1'          => 'bold,italic,bullist,numlist,link,unlink,undo,redo',
							'toolbar2'          => '',
							'statusbar'         => false,
							'resize'            => false,
							'wpautop'           => true,
							'menubar'           => false,
						],
					]
				);
				?>
			</div>
			<p class="scos-seo-help"><?php esc_html_e( 'Bold and lists are supported (no images). Shown via [tldr] shortcode. Also used for Google Speakable.', 'site-essentials' ); ?></p>
		</div>

		<!-- Meta Title -->
		<div class="scos-seo-field scos-seo-field--counted">
			<label for="scos_seo_title">
				<?php esc_html_e( 'Meta Title', 'site-essentials' ); ?>
				<span class="scos-seo-counter" data-target="scos_seo_title" data-max="60">
					<span class="scos-seo-count">0</span>/60
				</span>
			</label>
			<input type="text"
				name="scos_seo_title"
				id="scos_seo_title"
				value="<?php echo esc_attr( $title ); ?>"
				maxlength="100"
				placeholder="<?php esc_attr_e( 'Leave blank to use post title', 'site-essentials' ); ?>">
			<div class="scos-seo-bar" data-target="scos_seo_title" data-max="60">
				<div class="scos-seo-bar__fill"></div>
			</div>
		</div>

		<!-- Meta Description -->
		<div class="scos-seo-field scos-seo-field--counted">
			<label for="scos_seo_description">
				<?php esc_html_e( 'Meta Description', 'site-essentials' ); ?>
				<span class="scos-seo-counter" data-target="scos_seo_description" data-max="160">
					<span class="scos-seo-count">0</span>/160
				</span>
			</label>
			<textarea name="scos_seo_description" id="scos_seo_description" rows="3"
				maxlength="320"
				placeholder="<?php esc_attr_e( 'Leave blank to use post excerpt or auto-generated snippet', 'site-essentials' ); ?>"><?php echo esc_textarea( $description ); ?></textarea>
			<div class="scos-seo-bar" data-target="scos_seo_description" data-max="160">
				<div class="scos-seo-bar__fill"></div>
			</div>
		</div>

	</div><!-- /core -->

	<!-- ══ ADVANCED TAB ══ -->
	<div class="scos-seo-tab-panel" id="scos-seo-tab-advanced" role="tabpanel" hidden>

		<!-- Canonical URL -->
		<div class="scos-seo-field">
			<label for="scos_seo_canonical">
				<?php esc_html_e( 'Canonical URL', 'site-essentials' ); ?>
			</label>
			<input type="url"
				name="scos_seo_canonical"
				id="scos_seo_canonical"
				value="<?php echo esc_url( $canonical ); ?>"
				placeholder="<?php echo esc_attr( get_permalink( $post->ID ) ?: '' ); ?>">
			<p class="scos-seo-help">
				<?php esc_html_e( 'Leave blank to use the default page URL. Set only if this content is syndicated or has duplicate URLs.', 'site-essentials' ); ?>
				<span class="scos-seo-help scos-seo-help--canonical-hint" style="display:none;color:#b45309">
					<?php esc_html_e( 'Setting a canonical to another URL will remove this page from the sitemap. Check "Include in sitemap" in Sitemap Visibility below to override.', 'site-essentials' ); ?>
				</span>
			</p>
		</div>

		<!-- Meta Robots -->
		<div class="scos-seo-field">
			<label><?php esc_html_e( 'Meta Robots', 'site-essentials' ); ?></label>
			<div class="scos-seo-checks">
				<?php foreach ( \SiteEssentials\Modules\SeoMeta\Meta_Fields::robots_options() as $val => $label ) : ?>
					<label class="scos-seo-check-label<?php echo 'noindex' === $val ? ' scos-seo-check-label--danger' : ''; ?>">
						<input type="checkbox"
							name="scos_seo_robots[]"
							value="<?php echo esc_attr( $val ); ?>"
							id="scos_seo_robots_<?php echo esc_attr( $val ); ?>"
							<?php checked( in_array( $val, $robots, true ) ); ?>>
						<code><?php echo esc_html( $val ); ?></code>
						<span><?php echo esc_html( preg_replace( '/^[a-z]+\s—\s/i', '', $label ) ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</div>

		<!-- Freeze Modified Date -->
		<div class="scos-seo-field">
			<label><?php esc_html_e( 'Modified Date', 'site-essentials' ); ?></label>
			<label class="scos-seo-check-label">
				<input type="checkbox"
					name="scos_seo_freeze_og_date"
					value="1"
					<?php
					// When global freeze is ON and per-post is not explicitly set to '0' → show as checked.
					// When global freeze is OFF → show checked only if per-post is explicitly '1'.
					$per_post_raw = get_post_meta( $post->ID, 'scos_seo_freeze_og_date', true );
					if ( $global_freeze_date ) {
						$show_checked = ( '0' !== (string) $per_post_raw );
					} else {
						$show_checked = $freeze_date;
					}
					checked( $show_checked );
					?>>
				<?php esc_html_e( 'Freeze modified date', 'site-essentials' ); ?>
			</label>
			<?php if ( $global_freeze_date ) : ?>
				<p class="scos-seo-help" style="color:#b45309;">
					<?php esc_html_e( 'Global freeze is active — all posts are frozen by default. Uncheck here to allow this save to update the modified date.', 'site-essentials' ); ?>
				</p>
			<?php else : ?>
				<p class="scos-seo-help">
					<?php esc_html_e( 'Prevent this post\'s "Last Modified" timestamp from changing on each save. Useful when making minor edits. Uncheck when publishing a major update.', 'site-essentials' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<!-- Sitemap Visibility -->
		<div class="scos-seo-field">
			<label><?php esc_html_e( 'Sitemap Visibility', 'site-essentials' ); ?></label>

			<?php
			$noindex_active   = in_array( 'noindex', $robots, true );
			$non_self_canon   = ! empty( $canonical ) && $canonical !== get_permalink( $post->ID );
			$auto_excluded    = $sitemap_noindex_auto || $non_self_canon;
			?>

			<?php if ( $noindex_active && ! $sitemap_noindex_override ) : ?>
				<div class="scos-seo-notice scos-seo-notice--warn" id="scos-noindex-sitemap-notice">
					<?php esc_html_e( 'This page has been removed from the sitemap because noindex is set.', 'site-essentials' ); ?>
				</div>
			<?php elseif ( $non_self_canon && ! $sitemap_noindex_override ) : ?>
				<div class="scos-seo-notice scos-seo-notice--warn" id="scos-canonical-sitemap-notice">
					<?php esc_html_e( 'This page has been removed from the sitemap because a non-self canonical is set.', 'site-essentials' ); ?>
				</div>
			<?php endif; ?>

			<div class="scos-seo-checks">
				<?php foreach ( \SiteEssentials\Modules\SeoMeta\Meta_Fields::sitemap_options() as $val => $label ) : ?>
					<label class="scos-seo-check-label">
						<input type="checkbox"
							name="scos_seo_sitemap_exclude[]"
							value="<?php echo esc_attr( $val ); ?>"
							<?php checked( in_array( $val, $sitemap_exclude, true ) ); ?>>
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</div>

			<?php if ( $noindex_active || $non_self_canon || $sitemap_noindex_override ) : ?>
				<label class="scos-seo-check-label scos-seo-check-label--override" style="margin-top:6px">
					<input type="checkbox"
						name="scos_seo_sitemap_noindex_override"
						id="scos_seo_sitemap_noindex_override"
						value="1"
						<?php checked( $sitemap_noindex_override ); ?>>
					<span><?php esc_html_e( 'Include in XML sitemap anyway', 'site-essentials' ); ?></span>
				</label>
				<p class="scos-seo-help">
					<?php esc_html_e( 'Edge case override — e.g. staging-style noindex pages you still want crawled, or deliberate cross-domain canonicals (syndicated content).', 'site-essentials' ); ?>
				</p>
			<?php endif; ?>
		</div>

	</div><!-- /advanced -->

	<!-- ══ OG SOCIAL TAB (Phase 2) ══ -->
	<div class="scos-seo-tab-panel" id="scos-seo-tab-og" role="tabpanel" hidden>
		<div class="scos-seo-phase2-notice">
			<strong><?php esc_html_e( 'Phase 2 — Coming Soon', 'site-essentials' ); ?></strong>
			<p><?php esc_html_e( 'Custom Open Graph title, description, and image for Facebook / LinkedIn. Twitter/X card override (falls back to OG settings).', 'site-essentials' ); ?></p>
			<p class="scos-seo-help"><?php esc_html_e( 'Currently handled by existing OG meta output in brighter-og-meta.php.', 'site-essentials' ); ?></p>
		</div>
	</div><!-- /og -->

</div><!-- /scos-seo-wrap -->
