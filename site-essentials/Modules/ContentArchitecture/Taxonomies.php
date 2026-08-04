<?php
/**
 * Content Architecture — Taxonomy Registration
 *
 * Registers scos_content_cluster and scos_topic as proper WordPress taxonomies
 * with meta_box_cb => false so only our single Content Architecture metabox
 * manages term assignment (via wp_set_post_terms). No WP default checkboxes.
 *
 * Timing: called at WordPress init priority 5.
 * Post type association is deferred to init priority 20 so all CPTs registered
 * at the default priority 10 are included.
 *
 * @package    SiteEssentials
 * @subpackage Modules\ContentArchitecture
 * @since      1.0.0
 *
 * v1.1 | 2026-07-24 — Stop excluding 'product' from supported post types; WooCommerce
 *                      products should be tracked as editorial content like any other
 *                      public post type. Only non-editorial WooCommerce internals
 *                      (variations, orders, coupons, webhooks) remain excluded.
 */

namespace SiteEssentials\Modules\ContentArchitecture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Taxonomies {

	public static function init() {
		// Register the taxonomy objects now (priority 5).
		// We pass an empty post-type list and wire up the real post types at
		// priority 20 so all CPTs registered at the default priority (10) are
		// already available when we call register_taxonomy_for_object_type().
		self::register_taxonomies();

		// Associate with all supported public post types after they're registered.
		add_action( 'init', [ __CLASS__, 'associate_post_types' ], 20 );

		// Topic term meta — sameAs URL field on edit-tags.php
		add_action( 'init',                        [ __CLASS__, 'register_term_meta' ] );
		add_action( 'scos_topic_add_form_fields',  [ __CLASS__, 'topic_add_fields' ] );
		add_action( 'scos_topic_edit_form_fields', [ __CLASS__, 'topic_edit_fields' ] );
		add_action( 'created_scos_topic',          [ __CLASS__, 'save_topic_meta' ] );
		add_action( 'edited_scos_topic',           [ __CLASS__, 'save_topic_meta' ] );
	}

	/**
	 * Register both taxonomies with no post-type association yet.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_taxonomies() {
		register_taxonomy(
			'scos_content_cluster',
			[],
			[
				'labels'             => [
					'name'              => __( 'Content Clusters', 'site-essentials' ),
					'singular_name'     => __( 'Content Cluster', 'site-essentials' ),
					'menu_name'         => __( 'Content Clusters', 'site-essentials' ),
					'all_items'         => __( 'All Content Clusters', 'site-essentials' ),
					'edit_item'         => __( 'Edit Content Cluster', 'site-essentials' ),
					'view_item'         => __( 'View Content Cluster', 'site-essentials' ),
					'update_item'       => __( 'Update Content Cluster', 'site-essentials' ),
					'add_new_item'      => __( 'Add New Content Cluster', 'site-essentials' ),
					'new_item_name'     => __( 'New Content Cluster Name', 'site-essentials' ),
					'search_items'      => __( 'Search Content Clusters', 'site-essentials' ),
					'not_found'         => __( 'No content clusters found.', 'site-essentials' ),
				],
				'hierarchical'       => true,
				'show_ui'            => true,   // Enables edit-tags.php management pages.
				'meta_box_cb'        => false,  // No default sidebar boxes — our metabox handles this.
				'show_in_nav_menus'  => false,
				'show_tagcloud'      => false,
				'show_admin_column'  => false,  // Managed by Admin_Columns.
				'show_in_rest'       => false,
				'rewrite'            => [ 'slug' => 'content-cluster', 'with_front' => false ],
				'query_var'          => true,
			]
		);

		register_taxonomy(
			'scos_topic',
			[],
			[
				'labels'             => [
					'name'              => __( 'Topics', 'site-essentials' ),
					'singular_name'     => __( 'Topic', 'site-essentials' ),
					'menu_name'         => __( 'Topics', 'site-essentials' ),
					'all_items'         => __( 'All Topics', 'site-essentials' ),
					'edit_item'         => __( 'Edit Topic', 'site-essentials' ),
					'view_item'         => __( 'View Topic', 'site-essentials' ),
					'update_item'       => __( 'Update Topic', 'site-essentials' ),
					'add_new_item'      => __( 'Add New Topic', 'site-essentials' ),
					'new_item_name'     => __( 'New Topic Name', 'site-essentials' ),
					'parent_item'       => __( 'Parent Topic', 'site-essentials' ),
					'parent_item_colon' => __( 'Parent Topic:', 'site-essentials' ),
					'search_items'      => __( 'Search Topics', 'site-essentials' ),
					'not_found'         => __( 'No topics found.', 'site-essentials' ),
				],
				'hierarchical'       => true,
				'show_ui'            => true,
				'meta_box_cb'        => false,
				'show_in_nav_menus'  => false,
				'show_tagcloud'      => false,
				'show_admin_column'  => false,
				'show_in_rest'       => false,
				'rewrite'            => [ 'slug' => 'topic', 'with_front' => false ],
				'query_var'          => true,
			]
		);
	}

	/**
	 * Associate both taxonomies with all supported public post types.
	 * Runs at init priority 20 so all CPTs are already registered.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function associate_post_types() {
		foreach ( self::get_post_types() as $post_type ) {
			register_taxonomy_for_object_type( 'scos_content_cluster', $post_type );
			register_taxonomy_for_object_type( 'scos_topic', $post_type );
		}
	}

	/**
	 * Output <option> elements for a hierarchical taxonomy select.
	 *
	 * Renders parent terms first, then children indented with em-dashes,
	 * matching the style WordPress uses for category dropdowns.
	 *
	 * @param string $taxonomy  Taxonomy slug.
	 * @param int    $parent_id Parent term ID to start from (0 = root).
	 * @param int    $depth     Current recursion depth (used for indentation).
	 * @return void
	 */
	public static function render_hierarchical_options( $taxonomy, $parent_id = 0, $depth = 0 ) {
		$terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'parent'     => $parent_id,
			'orderby'    => 'name',
			'order'      => 'ASC',
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		$pad = $depth > 0 ? str_repeat( '&nbsp;&nbsp;&mdash;&nbsp;', $depth ) : '';

		foreach ( $terms as $term ) {
			printf(
				'<option value="%d">%s%s</option>',
				$term->term_id,
				$pad, // phpcs:ignore WordPress.Security.EscapeOutput — pad contains only safe HTML entities
				esc_html( $term->name )
			);
			// Recurse into children
			self::render_hierarchical_options( $taxonomy, $term->term_id, $depth + 1 );
		}
	}

	/**
	 * Get all public post types that Content Architecture applies to.
	 * Excludes WooCommerce, system types, and other non-editorial types.
	 *
	 * @since  1.0.0
	 * @return array Post type slugs.
	 */
	public static function get_post_types() {
		static $types = null;
		if ( null !== $types ) {
			return $types;
		}

		$exclude = [
			'attachment',
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_block',
			'wp_template',
			'wp_template_part',
			// WooCommerce — 'product' is intentionally included (tracked as editorial
			// content); only non-editorial WooCommerce internals are excluded.
			'product_variation',
			'shop_order',
			'shop_coupon',
			'shop_webhook',
		];

		$all   = get_post_types( [ 'public' => true ], 'names' );
		$types = array_values( array_diff( $all, $exclude ) );

		return $types;
	}

	// =========================================================================
	// Topic term meta — sameAs URL
	// =========================================================================

	/**
	 * Register scos_topic_same_as as proper term meta so it's available
	 * via REST, WP All Export, and get_term_meta().
	 */
	public static function register_term_meta() {
		register_term_meta( 'scos_topic', 'scos_topic_same_as', [
			'type'              => 'string',
			'description'       => __( 'External authoritative URL for this topic (used in schema sameAs).', 'site-essentials' ),
			'single'            => true,
			'sanitize_callback' => 'esc_url_raw',
			'show_in_rest'      => false,
		] );
	}

	/**
	 * Render the sameAs field on the Add New Topic form.
	 */
	public static function topic_add_fields() {
		?>
		<div class="form-field">
			<label for="scos_topic_same_as"><?php esc_html_e( 'sameAs URL', 'site-essentials' ); ?></label>
			<input type="url" id="scos_topic_same_as" name="scos_topic_same_as" value="" placeholder="https://www.wikidata.org/wiki/Q12345" />
			<p><?php esc_html_e( 'External authoritative URL for this topic (used in schema sameAs). Example: https://www.wikidata.org/wiki/Q12345', 'site-essentials' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the sameAs field on the Edit Topic form.
	 *
	 * @param \WP_Term $term Current term object.
	 */
	public static function topic_edit_fields( $term ) {
		$value = get_term_meta( $term->term_id, 'scos_topic_same_as', true );
		?>
		<tr class="form-field">
			<th scope="row">
				<label for="scos_topic_same_as"><?php esc_html_e( 'sameAs URL', 'site-essentials' ); ?></label>
			</th>
			<td>
				<input type="url" id="scos_topic_same_as" name="scos_topic_same_as"
					value="<?php echo esc_attr( $value ); ?>"
					placeholder="https://www.wikidata.org/wiki/Q12345"
					style="width:100%;max-width:500px" />
				<p class="description"><?php esc_html_e( 'External authoritative URL for this topic (used in schema sameAs). Example: https://www.wikidata.org/wiki/Q12345', 'site-essentials' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save scos_topic_same_as when a topic is created or updated.
	 *
	 * @param int $term_id Term ID being saved.
	 */
	public static function save_topic_meta( $term_id ) {
		if ( ! isset( $_POST['scos_topic_same_as'] ) ) {
			return;
		}
		$url = esc_url_raw( wp_unslash( $_POST['scos_topic_same_as'] ) );
		if ( $url ) {
			update_term_meta( $term_id, 'scos_topic_same_as', $url );
		} else {
			delete_term_meta( $term_id, 'scos_topic_same_as' );
		}
	}
}
