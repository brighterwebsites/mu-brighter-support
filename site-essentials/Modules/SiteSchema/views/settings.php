<?php
/**
 * Site Schema Module — settings view.
 *
 * v1.4 | 2026-08-03 — Document WooCommerce schema tokens
 *
 * Tabbed panel: Local Business | Success Stories | Product | Service
 * SCOS design system: scos__header, scos__tabs, scos-card, scos-form.
 */
defined( 'ABSPATH' ) || exit;

$tabs = [
	'local-business'  => __( 'Local Business', 'site-essentials' ),
	'success-stories' => __( 'Success Stories', 'site-essentials' ),
	'product'         => __( 'Product', 'site-essentials' ),
	'service'         => __( 'Service', 'site-essentials' ),
];

$current_tab = isset( $_GET['tab'] ) && array_key_exists( $_GET['tab'], $tabs )
	? sanitize_key( $_GET['tab'] )
	: 'local-business';

$base_url = add_query_arg( 'page', 'site-essentials-schema', admin_url( 'admin.php' ) );

$local_business  = get_option( 'scos_site_schema_local_business', '' );
$success_stories = get_option( 'scos_site_schema_success_stories', '' );
$product         = get_option( 'scos_site_schema_product', '' );
$product_ids     = get_option( 'scos_site_schema_product_ids', '' );
$woo_product_auto         = get_option( 'scos_site_schema_woo_product_auto', '' );
$product_purpose_auto     = get_option( 'scos_site_schema_product_purpose_auto', '' );
$service                  = get_option( 'scos_site_schema_service', '' );
$service_ids              = get_option( 'scos_site_schema_service_ids', '' );
$service_purpose_auto     = get_option( 'scos_site_schema_service_purpose_auto', '' );

$guide_base = 'https://brighterwebsites.com.au/software/schema/';
$guide_urls = [
	'local-business'  => $guide_base . '#local-business',
	'success-stories' => $guide_base . '#success',
	'product'         => $guide_base . '#product',
	'service'         => $guide_base . '#service',
];
$current_guide = isset( $guide_urls[ $current_tab ] ) ? $guide_urls[ $current_tab ] : $guide_base;
?>

<?php if ( isset( $_GET['scos_schema_saved'] ) ) : ?>
	<div class="scos-notice scos-notice--success" style="margin-bottom:var(--scos-s-4)">
		<p><?php esc_html_e( 'Schema settings saved.', 'site-essentials' ); ?></p>
	</div>
<?php endif; ?>

<header class="scos__header">
	<div>
		<h1 class="scos__title"><?php esc_html_e( 'Schema', 'site-essentials' ); ?></h1>
		<p class="scos__subtitle"><?php esc_html_e( 'Site Essentials › Schema', 'site-essentials' ); ?></p>
	</div>
	<div class="scos__header-actions">
		<a href="<?php echo esc_url( $current_guide ); ?>"
		   class="scos-btn scos-btn--ghost"
		   target="_blank" rel="noopener">
			<?php esc_html_e( 'Guide', 'site-essentials' ); ?> ↗
		</a>
		<button type="submit" form="scos-schema-form" class="scos-btn scos-btn--primary">
			<?php esc_html_e( 'Save Schema', 'site-essentials' ); ?>
		</button>
	</div>
</header>

<nav class="scos__tabs">
	<?php foreach ( $tabs as $tab => $label ) : ?>
		<a href="<?php echo esc_url( add_query_arg( 'tab', $tab, $base_url ) ); ?>"
		   class="scos__tab<?php echo $current_tab === $tab ? ' scos__tab--active' : ''; ?>">
			<?php echo esc_html( $label ); ?>
		</a>
	<?php endforeach; ?>
</nav>

<form method="post" id="scos-schema-form">
	<?php wp_nonce_field( 'scos_site_schema_save', 'scos_site_schema_nonce' ); ?>
	<input type="hidden" name="current_tab_hidden" value="<?php echo esc_attr( $current_tab ); ?>">

	<?php if ( $current_tab === 'local-business' ) : ?>

		<div class="scos-card">
			<div class="scos-card__header">
				<div>
					<h2 class="scos-card__title"><?php esc_html_e( 'Local Business Schema', 'site-essentials' ); ?></h2>
					<p class="scos-card__desc"><?php esc_html_e( 'Site-wide LocalBusiness JSON-LD included in your schema graph on every page.', 'site-essentials' ); ?></p>
				</div>
			</div>
			<div class="scos-card__body">

				<?php if ( function_exists( 'brighter_get_option' ) && brighter_get_option( 'business_name' ) ) : ?>
					<p style="margin-bottom:var(--scos-s-3)">
						<button type="button" id="scos-generate-local-biz" class="scos-btn scos-btn--ghost">
							<?php esc_html_e( 'Generate from Business Info', 'site-essentials' ); ?>
						</button>
						<span class="description" style="margin-left:var(--scos-s-2)"><?php esc_html_e( 'Pre-fills the textarea from your Business Info fields — review and save.', 'site-essentials' ); ?></span>
					</p>
				<?php endif; ?>

				<table class="scos-form">
					<tbody>
						<tr>
							<th>
								<label for="scos_site_schema_local_business"><?php esc_html_e( 'Local Business JSON-LD', 'site-essentials' ); ?></label>
								<div class="scos-form__slug">scos_site_schema_local_business</div>
							</th>
							<td>
								<textarea id="scos_site_schema_local_business" name="scos_site_schema_local_business"
									rows="13" class="scos-input scos-input--mono scos-schema-json"
									placeholder='{"@type": "LocalBusiness", "@id": "<?php echo esc_js( home_url( '/#organization' ) ); ?>", "name": "Your Business", "url": "<?php echo esc_js( home_url( '/' ) ); ?>"}'><?php echo esc_textarea( $local_business ); ?></textarea>
								<div id="scos_site_schema_local_business-validation" class="scos-schema-validation" aria-live="polite"></div>
								<p class="description"><?php esc_html_e( 'Leave empty to auto-generate from Business Info fields. Single block: { }. Multiple: [ { }, { } ].', 'site-essentials' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<div class="scos-card__footer">
				<button type="submit" form="scos-schema-form" class="scos-btn scos-btn--primary">
					<?php esc_html_e( 'Save Schema', 'site-essentials' ); ?>
				</button>
			</div>
		</div>

	<?php elseif ( $current_tab === 'success-stories' ) : ?>

		<div class="scos-card">
			<div class="scos-card__header">
				<div>
					<h2 class="scos-card__title"><?php esc_html_e( 'Success Stories Schema', 'site-essentials' ); ?></h2>
					<p class="scos-card__desc"><?php esc_html_e( 'Merged into the schema graph on every single Project/Success Story page (post type: projects).', 'site-essentials' ); ?></p>
				</div>
			</div>
			<div class="scos-card__body">
				<table class="scos-form">
					<tbody>
						<tr>
							<th>
								<label for="scos_site_schema_success_stories"><?php esc_html_e( 'Success Stories JSON-LD', 'site-essentials' ); ?></label>
								<div class="scos-form__slug">scos_site_schema_success_stories</div>
							</th>
							<td>
								<textarea id="scos_site_schema_success_stories" name="scos_site_schema_success_stories"
									rows="13" class="scos-input scos-input--mono scos-schema-json"
									placeholder='{"@type": "CreativeWork", "name": "Example Success Story"}'><?php echo esc_textarea( $success_stories ); ?></textarea>
								<div id="scos_site_schema_success_stories-validation" class="scos-schema-validation" aria-live="polite"></div>
								<p class="description"><?php esc_html_e( 'Single block: { }. Multiple: [ { }, { } ]. Invalid JSON is stored but not output.', 'site-essentials' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<div class="scos-card__footer">
				<button type="submit" form="scos-schema-form" class="scos-btn scos-btn--primary">
					<?php esc_html_e( 'Save Schema', 'site-essentials' ); ?>
				</button>
			</div>
		</div>

	<?php elseif ( $current_tab === 'product' ) : ?>

		<div class="scos-card">
			<div class="scos-card__header">
				<div>
					<h2 class="scos-card__title"><?php esc_html_e( 'Product Schema', 'site-essentials' ); ?></h2>
					<p class="scos-card__desc"><?php esc_html_e( 'Merged into the schema graph on product pages. Enable WooCommerce auto-apply or list specific post/page IDs below.', 'site-essentials' ); ?></p>
				</div>
			</div>
			<div class="scos-card__body">
				<table class="scos-form">
					<tbody>
						<tr>
							<th>
								<label for="scos_site_schema_woo_product_auto"><?php esc_html_e( 'WooCommerce auto-apply', 'site-essentials' ); ?></label>
								<div class="scos-form__slug">scos_site_schema_woo_product_auto</div>
							</th>
							<td>
								<label class="scos-toggle">
									<input type="checkbox" id="scos_site_schema_woo_product_auto" name="scos_site_schema_woo_product_auto" value="1"<?php checked( $woo_product_auto, '1' ); ?>>
									<span class="scos-toggle__track"></span>
								</label>
								<p class="description"><?php esc_html_e( 'Apply this Product schema to all WooCommerce product pages automatically. No post IDs needed — covers the whole catalogue.', 'site-essentials' ); ?></p>
							</td>
						</tr>
						<tr>
							<th>
								<label for="scos_site_schema_product_purpose_auto"><?php esc_html_e( 'Purpose auto-apply', 'site-essentials' ); ?></label>
								<div class="scos-form__slug">scos_site_schema_product_purpose_auto</div>
							</th>
							<td>
								<label class="scos-toggle">
									<input type="checkbox" id="scos_site_schema_product_purpose_auto" name="scos_site_schema_product_purpose_auto" value="1"<?php checked( $product_purpose_auto, '1' ); ?>>
									<span class="scos-toggle__track"></span>
								</label>
								<p class="description"><?php esc_html_e( 'Apply this Product schema to any page whose Content Architecture purpose is set to Product (product-page). No post IDs needed.', 'site-essentials' ); ?></p>
							</td>
						</tr>
						<tr>
							<th>
								<label for="scos_site_schema_product_ids"><?php esc_html_e( 'Post/Page IDs', 'site-essentials' ); ?></label>
								<div class="scos-form__slug">scos_site_schema_product_ids</div>
							</th>
							<td>
								<input type="text" id="scos_site_schema_product_ids" name="scos_site_schema_product_ids"
									value="<?php echo esc_attr( $product_ids ); ?>" class="scos-input" placeholder="123, 456, 789">
								<p class="description"><?php esc_html_e( 'Comma-separated post or page IDs that should output Product schema (non-WooCommerce or specific overrides).', 'site-essentials' ); ?></p>
							</td>
						</tr>
						<tr>
							<th>
								<label for="scos_site_schema_product"><?php esc_html_e( 'Product JSON-LD', 'site-essentials' ); ?></label>
								<div class="scos-form__slug">scos_site_schema_product</div>
							</th>
							<td>
								<textarea id="scos_site_schema_product" name="scos_site_schema_product"
									rows="13" class="scos-input scos-input--mono scos-schema-json"
									placeholder='{"@type": "Product", "name": "Product Name"}'><?php echo esc_textarea( $product ); ?></textarea>
								<div id="scos_site_schema_product-validation" class="scos-schema-validation" aria-live="polite"></div>
								<p class="description"><?php esc_html_e( 'Single block: { }. Multiple: [ { }, { } ].', 'site-essentials' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<div class="scos-card__footer">
				<button type="submit" form="scos-schema-form" class="scos-btn scos-btn--primary">
					<?php esc_html_e( 'Save Schema', 'site-essentials' ); ?>
				</button>
			</div>
		</div>

	<?php elseif ( $current_tab === 'service' ) : ?>

		<div class="scos-card">
			<div class="scos-card__header">
				<div>
					<h2 class="scos-card__title"><?php esc_html_e( 'Service Schema', 'site-essentials' ); ?></h2>
					<p class="scos-card__desc"><?php esc_html_e( 'Merged into the schema graph on single posts/pages whose IDs are in the list below, or when Content Architecture purpose auto-apply is enabled.', 'site-essentials' ); ?></p>
				</div>
			</div>
			<div class="scos-card__body">
				<table class="scos-form">
					<tbody>
						<tr>
							<th>
								<label for="scos_site_schema_service_purpose_auto"><?php esc_html_e( 'Purpose auto-apply', 'site-essentials' ); ?></label>
								<div class="scos-form__slug">scos_site_schema_service_purpose_auto</div>
							</th>
							<td>
								<label class="scos-toggle">
									<input type="checkbox" id="scos_site_schema_service_purpose_auto" name="scos_site_schema_service_purpose_auto" value="1"<?php checked( $service_purpose_auto, '1' ); ?>>
									<span class="scos-toggle__track"></span>
								</label>
								<p class="description"><?php esc_html_e( 'Apply this Service schema to any page whose Content Architecture purpose is set to Service (service-page). No post IDs needed.', 'site-essentials' ); ?></p>
							</td>
						</tr>
						<tr>
							<th>
								<label for="scos_site_schema_service_ids"><?php esc_html_e( 'Post/Page IDs', 'site-essentials' ); ?></label>
								<div class="scos-form__slug">scos_site_schema_service_ids</div>
							</th>
							<td>
								<input type="text" id="scos_site_schema_service_ids" name="scos_site_schema_service_ids"
									value="<?php echo esc_attr( $service_ids ); ?>" class="scos-input" placeholder="123, 456, 789">
								<p class="description"><?php esc_html_e( 'Comma-separated post or page IDs that should output Service schema.', 'site-essentials' ); ?></p>
							</td>
						</tr>
						<tr>
							<th>
								<label for="scos_site_schema_service"><?php esc_html_e( 'Service JSON-LD', 'site-essentials' ); ?></label>
								<div class="scos-form__slug">scos_site_schema_service</div>
							</th>
							<td>
								<textarea id="scos_site_schema_service" name="scos_site_schema_service"
									rows="13" class="scos-input scos-input--mono scos-schema-json"
									placeholder='{"@type": "Service", "name": "Service Name"}'><?php echo esc_textarea( $service ); ?></textarea>
								<div id="scos_site_schema_service-validation" class="scos-schema-validation" aria-live="polite"></div>
								<p class="description"><?php esc_html_e( 'Single block: { }. Multiple: [ { }, { } ].', 'site-essentials' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<div class="scos-card__footer">
				<button type="submit" form="scos-schema-form" class="scos-btn scos-btn--primary">
					<?php esc_html_e( 'Save Schema', 'site-essentials' ); ?>
				</button>
			</div>
		</div>

	<?php endif; ?>

</form>

<div class="scos-card" style="margin-top:var(--scos-s-4)">
	<div class="scos-card__header scos-card__header--plain">
		<h3 class="scos-card__title"><?php esc_html_e( 'Template variables', 'site-essentials' ); ?></h3>
	</div>
	<div class="scos-card__body">
		<ul style="line-height:1.9;margin:0 0 0 1em">
			<li><code>%%post_title%%</code> &mdash; <?php esc_html_e( 'Post/page title', 'site-essentials' ); ?></li>
			<li><code>%%post_excerpt%%</code> &mdash; <?php esc_html_e( 'Excerpt', 'site-essentials' ); ?></li>
			<li><code>%%post_date%%</code>, <code>%%post_modified%%</code> &mdash; <?php esc_html_e( 'Date (ISO 8601)', 'site-essentials' ); ?></li>
			<li><code>%%post_url%%</code>, <code>%%post_id%%</code>, <code>%%post_name%%</code></li>
			<li><code>%%post_author%%</code>, <code>%%post_thumbnail_url%%</code></li>
			<li><code>%%site_name%%</code>, <code>%%site_url%%</code></li>
			<li><code>%%_cmeta_meta_key%%</code> &mdash; <?php esc_html_e( 'Custom post meta', 'site-essentials' ); ?></li>
			<li><code>%%_cmeta__sku%%</code> &mdash; <?php esc_html_e( 'WooCommerce SKU via post meta (_sku)', 'site-essentials' ); ?></li>
			<li><code>%%_cmeta_options_option_key%%</code> &mdash; <?php esc_html_e( 'WordPress option value (allowed option name prefixes: se_, scos_, site_essentials_; works in site-wide schema without a post)', 'site-essentials' ); ?></li>
			<li><code>%%_acf_field_name%%</code> &mdash; <?php esc_html_e( 'ACF field', 'site-essentials' ); ?></li>
			<li><code>%%date_year_ahead%%</code> &mdash; <?php esc_html_e( 'ISO date one year from today — use for priceValidUntil', 'site-essentials' ); ?></li>
			<li><code>%%_woo_price%%</code> &mdash; <?php esc_html_e( 'WooCommerce active price (sale if on sale, else regular)', 'site-essentials' ); ?></li>
			<li><code>%%_woo_sku%%</code> &mdash; <?php esc_html_e( 'WooCommerce SKU (preferred over %%_cmeta__sku%%)', 'site-essentials' ); ?></li>
			<li><code>%%_woo_category%%</code> &mdash; <?php esc_html_e( 'First product_cat term name', 'site-essentials' ); ?></li>
			<li><code>%%_woo_availability%%</code> &mdash; <?php esc_html_e( 'Schema.org availability URL from stock status (InStock / OutOfStock / BackOrder)', 'site-essentials' ); ?></li>
			<li><code>%%_woo_currency%%</code> &mdash; <?php esc_html_e( 'Store currency code (e.g. AUD)', 'site-essentials' ); ?></li>
			<li><code>%%_woo_offers_json%%</code> &mdash; <?php esc_html_e( 'Full Offer object (price, currency, availability, url, sku)', 'site-essentials' ); ?></li>
			<li><code>%%_scos_review_cards_json%%</code> &mdash; <?php esc_html_e( 'Array of Review objects from ScosReviewCard elements on the page (specific mode only)', 'site-essentials' ); ?></li>
			<li><code>%%_scos_aggregate_rating_json%%</code> &mdash; <?php esc_html_e( 'AggregateRating object — count and average across all published reviews', 'site-essentials' ); ?></li>
		</ul>
		<p class="description" style="margin-top:var(--scos-s-2)"><?php esc_html_e( 'Multiple blocks: use a single array [ { … }, { … } ].', 'site-essentials' ); ?></p>
	</div>
</div>

<style>
.scos-schema-validation { margin-top:var(--scos-s-1);padding:var(--scos-s-2) var(--scos-s-3);border-radius:var(--scos-r-md);display:none }
.scos-schema-validation.valid   { background:var(--scos-success-soft);color:var(--scos-success);display:block!important }
.scos-schema-validation.invalid { background:var(--scos-danger-soft);color:var(--scos-danger);display:block!important }
</style>
