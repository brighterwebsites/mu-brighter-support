<?php
/**
 * SEO Meta — Admin List Table Columns
 *
 * Adds two opt-in columns to all supported post type list tables:
 *   scos_seo_title — SEO Title (truncated preview)
 *   scos_seo_desc  — SEO Description (truncated preview)
 *
 * Quick Edit adds inline editing for:
 *   scos_seo_title, scos_seo_description, scos_seo_breadcrumb_title
 *
 * Save writes to scos_seo_* keys only.
 *
 * Also enqueues CSS to hide the redundant scos_topic and scos_content_cluster
 * taxonomy checklists in Quick Edit (the hidden inputs WP always renders are
 * enough to preserve the existing terms on save).
 *
 * @package    SiteEssentials
 * @subpackage Modules\SeoMeta
 * @since      1.0.0
 *
 * v1.0 | 2026-06-30
 */

namespace SiteEssentials\Modules\SeoMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Columns {

	/** Column IDs managed here — hidden by default, opt-in via Screen Options. */
	const COLUMN_IDS = [
		'scos_seo_title',
		'scos_seo_desc',
	];

	public static function init() {
		add_action( 'admin_init',            [ __CLASS__, 'register_hooks' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'save_post',             [ __CLASS__, 'handle_edit_save' ], 20, 2 );
	}

	// =========================================================================
	// Hook registration
	// =========================================================================

	public static function register_hooks() {
		foreach ( Meta_Fields::get_post_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns",       [ __CLASS__, 'add_columns' ] );
			add_action( "manage_{$post_type}_posts_custom_column", [ __CLASS__, 'render_column' ], 10, 2 );
		}
		add_filter( 'default_hidden_columns', [ __CLASS__, 'default_hidden' ], 10, 2 );
		add_action( 'quick_edit_custom_box',  [ __CLASS__, 'quick_edit_box' ], 10, 2 );
	}

	// =========================================================================
	// Column definitions
	// =========================================================================

	public static function add_columns( $columns ) {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['scos_seo_title'] = __( 'SEO Title', 'site-essentials' );
				$new['scos_seo_desc']  = __( 'SEO Desc', 'site-essentials' );
			}
		}
		return $new;
	}

	/** Hide both SEO columns by default — users opt in via Screen Options. */
	public static function default_hidden( $hidden, $screen ) {
		if ( ! isset( $screen->base ) || 'edit' !== $screen->base ) {
			return $hidden;
		}
		return array_unique( array_merge( $hidden, self::COLUMN_IDS ) );
	}

	// =========================================================================
	// Column rendering
	// =========================================================================

	public static function render_column( $column, $post_id ) {
		switch ( $column ) {

			case 'scos_seo_title':
				$title      = get_post_meta( $post_id, 'scos_seo_title', true );
				$desc       = get_post_meta( $post_id, 'scos_seo_description', true );
				$breadcrumb = get_post_meta( $post_id, 'scos_seo_breadcrumb_title', true );

				// Hidden container — read by Quick Edit JS for pre-population.
				printf(
					'<span class="scos-seo-qe-data" id="scos-seo-data-%d" data-title="%s" data-desc="%s" data-breadcrumb="%s" style="display:none"></span>',
					$post_id,
					esc_attr( (string) $title ),
					esc_attr( (string) $desc ),
					esc_attr( (string) $breadcrumb )
				);

				if ( $title ) {
					printf(
						'<span class="scos-col-text" title="%s">%s</span>',
						esc_attr( $title ),
						esc_html( wp_trim_words( $title, 8, '…' ) )
					);
				} else {
					echo '<span class="scos-col-empty">—</span>';
				}
				break;

			case 'scos_seo_desc':
				$desc = get_post_meta( $post_id, 'scos_seo_description', true );
				if ( $desc ) {
					printf(
						'<span class="scos-col-text" title="%s">%s</span>',
						esc_attr( $desc ),
						esc_html( wp_trim_words( $desc, 12, '…' ) )
					);
				} else {
					echo '<span class="scos-col-empty">—</span>';
				}
				break;
		}
	}

	// =========================================================================
	// Quick Edit box
	// =========================================================================

	/**
	 * Renders once — triggered by the scos_seo_title column.
	 * No custom nonce needed: WP verifies _inline_edit before save_post fires.
	 */
	public static function quick_edit_box( $column_name, $post_type ) {
		if ( 'scos_seo_title' !== $column_name ) { return; }
		if ( ! in_array( $post_type, Meta_Fields::get_post_types(), true ) ) { return; }
		?>
		<fieldset class="scos-qe-fieldset inline-edit-col">
			<div class="inline-edit-col">
				<h4 class="scos-qe-title"><?php esc_html_e( 'SEO Meta', 'site-essentials' ); ?></h4>
				<div class="scos-seo-qe-fields">

					<label class="scos-qe-label scos-seo-qe-label--wide">
						<span class="title"><?php esc_html_e( 'SEO Title', 'site-essentials' ); ?></span>
						<input type="text" name="scos_seo_qe_title" class="scos-seo-qe-input" style="width:100%">
					</label>

					<label class="scos-qe-label scos-seo-qe-label--wide">
						<span class="title"><?php esc_html_e( 'SEO Description', 'site-essentials' ); ?></span>
						<textarea name="scos_seo_qe_description" class="scos-seo-qe-textarea" rows="3" style="width:100%"></textarea>
					</label>

					<label class="scos-qe-label scos-seo-qe-label--wide">
						<span class="title"><?php esc_html_e( 'Breadcrumb Label', 'site-essentials' ); ?></span>
						<input type="text" name="scos_seo_qe_breadcrumb" class="scos-seo-qe-input" style="width:100%">
					</label>

				</div>
			</div>
		</fieldset>
		<?php
	}

	// =========================================================================
	// Save — Quick Edit fires save_post with _inline_edit in the request
	// =========================================================================

	public static function handle_edit_save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( wp_is_post_revision( $post_id ) ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
		if ( ! in_array( $post->post_type, Meta_Fields::get_post_types(), true ) ) { return; }

		// Only process Quick Edit saves — not normal post editor saves (Meta_Box handles those).
		if ( ! isset( $_REQUEST['_inline_edit'] ) ) { return; }

		if ( isset( $_POST['scos_seo_qe_title'] ) ) {
			$val = sanitize_text_field( wp_unslash( $_POST['scos_seo_qe_title'] ) );
			self::update_or_delete( $post_id, 'scos_seo_title', $val );
		}

		if ( isset( $_POST['scos_seo_qe_description'] ) ) {
			$val = sanitize_textarea_field( wp_unslash( $_POST['scos_seo_qe_description'] ) );
			self::update_or_delete( $post_id, 'scos_seo_description', $val );
		}

		if ( isset( $_POST['scos_seo_qe_breadcrumb'] ) ) {
			$val = sanitize_text_field( wp_unslash( $_POST['scos_seo_qe_breadcrumb'] ) );
			self::update_or_delete( $post_id, 'scos_seo_breadcrumb_title', $val );
		}
	}

	// =========================================================================
	// Assets
	// =========================================================================

	public static function enqueue_assets( $hook ) {
		if ( 'edit.php' !== $hook ) { return; }

		$css_path = SITE_ESSENTIALS_PATH . 'Modules/SeoMeta/assets/admin-columns.css';
		$js_path  = SITE_ESSENTIALS_PATH . 'Modules/SeoMeta/assets/admin-columns.js';

		wp_enqueue_style(
			'scos-seo-admin-columns',
			SITE_ESSENTIALS_URL . 'Modules/SeoMeta/assets/admin-columns.css',
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1.0.0'
		);

		wp_enqueue_script(
			'scos-seo-admin-columns',
			SITE_ESSENTIALS_URL . 'Modules/SeoMeta/assets/admin-columns.js',
			[ 'jquery', 'inline-edit-post' ],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : '1.0.0',
			true
		);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private static function update_or_delete( $post_id, $key, $value ) {
		if ( ! empty( $value ) ) {
			update_post_meta( $post_id, $key, $value );
		} else {
			delete_post_meta( $post_id, $key );
		}
	}
}
