<?php
/**
 * Content Architecture — Admin List Table Columns
 *
 * - Cluster, Topic, Intent, Purpose, Progress, Next Step, Index, Social columns
 * - All hidden by default for new users; opt-in via Screen Options
 * - Quick Edit: pre-populated dropdowns + progress checkboxes
 * - Bulk Edit: "No Change" default, progress replace-if-any-checked
 * - Filters: Topic taxonomy + Purpose meta
 *
 * @package    SiteEssentials
 * @subpackage Modules\ContentArchitecture
 * @since      1.0.0
 *
 * v1.1 | 2026-05-22 — scos_ca_intent_goal column now shows linked FAQ title + incomplete badge.
 * v1.2 | 2026-07-21 — Remove Social column (Make.com trigger button, deprecated/unused on all sites).
 */

namespace SiteEssentials\Modules\ContentArchitecture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Columns {

	/** Column IDs managed by this class (used for default_hidden). */
	const COLUMN_IDS = [
		'scos_ca_cluster',
		'scos_ca_topic',
		'scos_ca_intent',
		'scos_ca_purpose',
		'scos_ca_maturity',
		'scos_ca_progress',
		'scos_ca_next_step',
		'scos_ca_index',
		'scos_ca_pillar',
		'scos_ca_pathway',
		'scos_ca_intent_goal',
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
		foreach ( Taxonomies::get_post_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns",         [ __CLASS__, 'add_columns' ] );
			add_action( "manage_{$post_type}_posts_custom_column",   [ __CLASS__, 'render_column' ], 10, 2 );
			add_filter( "manage_edit-{$post_type}_sortable_columns", [ __CLASS__, 'sortable_columns' ] );
		}
		add_filter( 'default_hidden_columns', [ __CLASS__, 'default_hidden' ], 10, 2 );
		add_action( 'quick_edit_custom_box',  [ __CLASS__, 'quick_edit_box' ], 10, 2 );
		add_action( 'bulk_edit_custom_box',   [ __CLASS__, 'bulk_edit_box' ], 10, 2 );
		add_action( 'restrict_manage_posts',  [ __CLASS__, 'filter_dropdowns' ] );
		add_filter( 'parse_query',            [ __CLASS__, 'filter_query' ] );
	}

	// =========================================================================
	// Column definitions
	// =========================================================================

	public static function add_columns( $columns ) {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['scos_ca_cluster']     = __( 'Cluster', 'site-essentials' );
				$new['scos_ca_topic']       = __( 'Topic', 'site-essentials' );
				$new['scos_ca_intent']      = __( 'Intent', 'site-essentials' );
				$new['scos_ca_purpose']     = __( 'Purpose', 'site-essentials' );
				$new['scos_ca_maturity']    = __( 'Maturity', 'site-essentials' );
				$new['scos_ca_progress']    = __( 'Progress', 'site-essentials' );
				$new['scos_ca_next_step']   = __( 'Next Step', 'site-essentials' );
				$new['scos_ca_index']       = __( 'Index', 'site-essentials' );
				$new['scos_ca_pillar']      = __( 'Pillar', 'site-essentials' );
				$new['scos_ca_pathway']     = __( 'Pathway', 'site-essentials' );
				$new['scos_ca_intent_goal'] = __( 'Primary Intent', 'site-essentials' );
			}
		}
		return $new;
	}

	/**
	 * Hide all CA columns by default — users opt in via Screen Options.
	 * Only applies to users who have not yet saved Screen Options.
	 */
	public static function default_hidden( $hidden, $screen ) {
		if ( ! isset( $screen->base ) || 'edit' !== $screen->base ) {
			return $hidden;
		}
		return array_unique( array_merge( $hidden, self::COLUMN_IDS ) );
	}

	public static function sortable_columns( $columns ) {
		$columns['scos_ca_intent']    = 'scos_ca_intent';
		$columns['scos_ca_purpose']   = 'scos_ca_purpose';
		$columns['scos_ca_next_step'] = 'scos_ca_next_step';
		$columns['scos_ca_index']     = 'scos_ca_index_status';
		return $columns;
	}

	// =========================================================================
	// Column rendering
	// =========================================================================

	public static function render_column( $column, $post_id ) {
		switch ( $column ) {

			case 'scos_ca_cluster':
				$terms   = wp_get_post_terms( $post_id, 'scos_content_cluster' );
				$term    = ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0] : null;
				$t_terms = wp_get_post_terms( $post_id, 'scos_topic' );
				$t_term  = ( ! is_wp_error( $t_terms ) && ! empty( $t_terms ) ) ? $t_terms[0] : null;

				// Single aggregated data container read by Quick Edit JS
				$progress = get_post_meta( $post_id, 'scos_ca_optimization_progress', true );
				if ( ! is_array( $progress ) ) { $progress = []; }
				// Helper: read new scos_ key, fall back to legacy bw_ key for unmigrated posts.
				// Only works when the slug values are identical between old and new systems.
				$scos_or_legacy = function( $scos_key, $legacy_key ) use ( $post_id ) {
					$val = get_post_meta( $post_id, $scos_key, true );
					if ( $val !== '' && $val !== null && $val !== false ) {
						return (string) $val;
					}
					return (string) get_post_meta( $post_id, $legacy_key, true );
				};

				$qe_data = [
					'cluster'      => $term   ? (int) $term->term_id   : 0,
					'topic'        => $t_term ? (int) $t_term->term_id  : 0,
					// intent: legacy system stored term IDs (not slugs) — no usable fallback
					'intent'       => (string) get_post_meta( $post_id, 'scos_ca_intent', true ),
					// purpose: legacy key bw_purpose shares the same slugs (pillar, supporting, etc.)
					'purpose'      => $scos_or_legacy( 'scos_ca_purpose',  'bw_purpose' ),
					// maturity: legacy key bw_cont_maturity shares the same slugs
					'maturity'     => $scos_or_legacy( 'scos_ca_maturity', 'bw_cont_maturity' ),
					'index-status' => (string) get_post_meta( $post_id, 'scos_ca_index_status', true ),
					'next-step'    => (string) get_post_meta( $post_id, 'scos_ca_next_step', true ),
					'pillar'       => (int) get_post_meta( $post_id, 'scos_ca_pillar_page_id', true ),
					'pathway'      => (int) get_post_meta( $post_id, 'scos_ca_service_pathway_id', true ),
					'progress'     => $progress,
				];
				printf(
					'<span class="scos-col-qe-data" id="scos-col-data-%d" data-qe="%s" style="display:none"></span>',
					$post_id,
					esc_attr( wp_json_encode( $qe_data ) )
				);
				if ( $term ) {
					echo '<span class="scos-col-badge scos-col-badge--cluster">' . esc_html( $term->name ) . '</span>';
				} else {
					echo '<span class="scos-col-empty">—</span>';
				}
				break;

			case 'scos_ca_topic':
				$terms = wp_get_post_terms( $post_id, 'scos_topic' );
				$term  = ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0] : null;
				if ( $term ) {
					echo '<span class="scos-col-badge scos-col-badge--topic">' . esc_html( $term->name ) . '</span>';
				} else {
					echo '<span class="scos-col-empty">—</span>';
				}
				break;

			case 'scos_ca_intent':
				$val    = get_post_meta( $post_id, 'scos_ca_intent', true );
				$opts   = Meta_Fields::intent_options();
				$colors = Meta_Fields::intent_colors();
				if ( $val && isset( $opts[ $val ] ) ) {
					$c = $colors[ $val ] ?? [ 'color' => '#374151', 'bg' => '#e5e7eb' ];
					printf(
						'<span class="scos-col-badge" style="color:%s;background:%s">%s</span>',
						esc_attr( $c['color'] ), esc_attr( $c['bg'] ), esc_html( $opts[ $val ] )
					);
				} else {
					echo '<span class="scos-col-empty">—</span>';
				}
				break;

			case 'scos_ca_purpose':
				$val    = get_post_meta( $post_id, 'scos_ca_purpose', true );
				$opts   = Meta_Fields::purpose_options();
				$colors = Meta_Fields::purpose_colors();
				if ( $val && isset( $opts[ $val ] ) ) {
					$c = $colors[ $val ] ?? [ 'color' => '#374151', 'bg' => '#e5e7eb' ];
					printf(
						'<span class="scos-col-badge" style="color:%s;background:%s">%s</span>',
						esc_attr( $c['color'] ), esc_attr( $c['bg'] ), esc_html( $opts[ $val ] )
					);
				} else {
					echo '<span class="scos-col-empty">—</span>';
				}
				break;

			case 'scos_ca_maturity':
				$val    = get_post_meta( $post_id, 'scos_ca_maturity', true );
				$opts   = Meta_Fields::maturity_options();
				$colors = Meta_Fields::maturity_colors();
				if ( $val && isset( $opts[ $val ] ) ) {
					$c = $colors[ $val ] ?? [ 'color' => '#374151', 'bg' => '#e5e7eb' ];
					printf(
						'<span class="scos-col-badge" style="color:%s;background:%s">%s</span>',
						esc_attr( $c['color'] ), esc_attr( $c['bg'] ), esc_html( $opts[ $val ] )
					);
				} else {
					echo '<span class="scos-col-empty">—</span>';
				}
				break;

			case 'scos_ca_pillar':
				$pillar_id = (int) get_post_meta( $post_id, 'scos_ca_pillar_page_id', true );
				if ( $pillar_id > 0 ) {
					$title = get_the_title( $pillar_id );
					printf(
						'<a href="%s" class="scos-col-link" title="%s">%s</a>',
						esc_url( get_edit_post_link( $pillar_id ) ),
						esc_attr( $title ),
						esc_html( wp_trim_words( $title, 5, '…' ) )
					);
				} else {
					echo '<span class="scos-col-empty">—</span>';
				}
				break;

			case 'scos_ca_pathway':
				$pathway_id = (int) get_post_meta( $post_id, 'scos_ca_service_pathway_id', true );
				if ( $pathway_id > 0 ) {
					$title = get_the_title( $pathway_id );
					printf(
						'<a href="%s" class="scos-col-link" title="%s">%s</a>',
						esc_url( get_edit_post_link( $pathway_id ) ),
						esc_attr( $title ),
						esc_html( wp_trim_words( $title, 5, '…' ) )
					);
				} else {
					echo '<span class="scos-col-empty">—</span>';
				}
				break;

			case 'scos_ca_intent_goal':
				$faq_id = Intent_Goal_Resolver::get_faq_id( $post_id );
				if ( $faq_id > 0 ) {
					$summary = Intent_Goal_Resolver::get_faq_summary( $faq_id );
					if ( $summary ) {
						$label = wp_trim_words( $summary['title'], 8, '…' );
						printf(
							'<a href="%s" title="%s" class="scos-col-link">%s</a>',
							esc_url( $summary['edit_url'] ),
							esc_attr( $summary['title'] ),
							esc_html( $label )
						);
						if ( $summary['incomplete'] ) {
							echo ' <span class="scos-col-badge" style="color:#92400e;background:#fef3c7">'
								. esc_html__( 'Needs answer', 'site-essentials' )
								. '</span>';
						}
					} else {
						echo '<span class="scos-col-empty">—</span>';
					}
				} else {
					$goal = Intent_Goal_Resolver::resolve_question( $post_id );
					if ( $goal ) {
						printf(
							'<span class="scos-col-text" title="%s">%s</span>',
							esc_attr( $goal ),
							esc_html( wp_trim_words( $goal, 8, '…' ) )
						);
					} else {
						echo '<span class="scos-col-empty">—</span>';
					}
				}
				break;

			case 'scos_ca_progress':
				$progress = get_post_meta( $post_id, 'scos_ca_optimization_progress', true );
				if ( ! is_array( $progress ) ) { $progress = []; }
				$opts = Meta_Fields::optimization_progress_options();
				if ( ! empty( $progress ) ) {
					foreach ( $progress as $p ) {
						if ( isset( $opts[ $p ] ) ) {
							$opt = $opts[ $p ];
							printf(
								'<span class="scos-col-progress" style="color:%s;background:%s">%s</span>',
								esc_attr( $opt['color'] ), esc_attr( $opt['bg'] ), esc_html( $opt['label'] )
							);
						}
					}
				} else {
					echo '<span class="scos-col-empty">—</span>';
				}
				break;

			case 'scos_ca_next_step':
				$val  = get_post_meta( $post_id, 'scos_ca_next_step', true );
				$opts = Meta_Fields::next_step_options();
				if ( $val && isset( $opts[ $val ] ) ) {
					$opt = $opts[ $val ];
					printf(
						'<span class="scos-col-badge" style="color:%s;background:%s">%s</span>',
						esc_attr( $opt['color'] ), esc_attr( $opt['bg'] ), esc_html( $opt['label'] )
					);
				} else {
					echo '<span class="scos-col-empty">—</span>';
				}
				break;

			case 'scos_ca_index':
				$val    = get_post_meta( $post_id, 'scos_ca_index_status', true );
				$opts   = Meta_Fields::index_status_options();
				$colors = Meta_Fields::index_status_colors();
				if ( $val && isset( $opts[ $val ] ) ) {
					$c = $colors[ $val ] ?? [ 'color' => '#6b7280', 'bg' => '#f3f4f6' ];
					printf(
						'<span class="scos-col-badge" style="color:%s;background:%s">%s</span>',
						esc_attr( $c['color'] ), esc_attr( $c['bg'] ), esc_html( $opts[ $val ] )
					);
				} else {
					echo '<span class="scos-col-empty">—</span>';
				}
				break;

		}
	}

	// =========================================================================
	// Quick Edit
	// =========================================================================

	public static function quick_edit_box( $column_name, $post_type ) {
		// Render once, triggered by the cluster column
		if ( 'scos_ca_cluster' !== $column_name ) { return; }
		if ( ! in_array( $post_type, Taxonomies::get_post_types(), true ) ) { return; }

		$clusters = get_terms( [ 'taxonomy' => 'scos_content_cluster', 'hide_empty' => false ] );
		if ( is_wp_error( $clusters ) ) { $clusters = []; }

		// No custom nonce needed — WordPress verifies _inline_edit before save_post fires.
		// The sentinel field lets PHP know progress was intentionally submitted.
		?>
		<input type="hidden" name="scos_ca_qe_progress_submitted" value="1" />
		<fieldset class="scos-qe-fieldset inline-edit-col">
			<div class="inline-edit-col">
				<h4 class="scos-qe-title"><?php esc_html_e( 'Content Architecture', 'site-essentials' ); ?></h4>
				<div class="scos-qe-grid">

					<label class="scos-qe-label">
						<span class="title"><?php esc_html_e( 'Cluster', 'site-essentials' ); ?></span>
						<select name="scos_ca_qe_cluster" data-scos-field="cluster">
							<option value="0"><?php esc_html_e( '— None —', 'site-essentials' ); ?></option>
							<?php foreach ( $clusters as $t ) : ?>
								<option value="<?php echo esc_attr( $t->term_id ); ?>"><?php echo esc_html( $t->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

				<label class="scos-qe-label">
					<span class="title"><?php esc_html_e( 'Topic', 'site-essentials' ); ?></span>
					<select name="scos_ca_qe_topic" data-scos-field="topic">
						<option value="0"><?php esc_html_e( '— None —', 'site-essentials' ); ?></option>
						<?php Taxonomies::render_hierarchical_options( 'scos_topic' ); ?>
					</select>
				</label>

					<label class="scos-qe-label">
						<span class="title"><?php esc_html_e( 'Intent', 'site-essentials' ); ?></span>
						<select name="scos_ca_qe_intent" data-scos-field="intent">
							<?php foreach ( Meta_Fields::intent_options() as $v => $l ) : ?>
								<option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="scos-qe-label">
						<span class="title"><?php esc_html_e( 'Purpose', 'site-essentials' ); ?></span>
						<select name="scos_ca_qe_purpose" data-scos-field="purpose">
							<?php foreach ( Meta_Fields::purpose_options() as $v => $l ) : ?>
								<option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="scos-qe-label">
						<span class="title"><?php esc_html_e( 'Index', 'site-essentials' ); ?></span>
						<select name="scos_ca_qe_index_status" data-scos-field="index-status">
							<?php foreach ( Meta_Fields::index_status_options() as $v => $l ) : ?>
								<option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="scos-qe-label">
						<span class="title"><?php esc_html_e( 'Next Step', 'site-essentials' ); ?></span>
						<select name="scos_ca_qe_next_step" data-scos-field="next-step">
							<?php foreach ( Meta_Fields::next_step_options() as $v => $opt ) : ?>
								<option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $opt['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="scos-qe-label">
						<span class="title"><?php esc_html_e( 'Maturity', 'site-essentials' ); ?></span>
						<select name="scos_ca_qe_maturity" data-scos-field="maturity">
							<?php foreach ( Meta_Fields::maturity_options() as $v => $l ) : ?>
								<option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<?php
					$qe_pillar_pages = get_posts( [
						'post_type'   => get_post_types( [ 'public' => true ], 'names' ),
						'numberposts' => -1,
						'orderby'     => 'title',
						'order'       => 'ASC',
						'meta_query'  => [ [ 'key' => 'scos_ca_purpose', 'value' => 'pillar' ] ],
						'fields'      => 'ids',
					] );
					$qe_pathway_pages = get_posts( [
						'post_type'   => get_post_types( [ 'public' => true ], 'names' ),
						'numberposts' => -1,
						'orderby'     => 'title',
						'order'       => 'ASC',
						'meta_query'  => [ [ 'key' => 'scos_ca_purpose', 'value' => [ 'service-page', 'product-page', 'conversion-hub' ], 'compare' => 'IN' ] ],
						'fields'      => 'ids',
					] );
					?>

					<label class="scos-qe-label">
						<span class="title"><?php esc_html_e( 'Pillar Page', 'site-essentials' ); ?></span>
						<select name="scos_ca_qe_pillar_page_id" data-scos-field="pillar">
							<option value="0"><?php esc_html_e( '— None —', 'site-essentials' ); ?></option>
							<?php foreach ( $qe_pillar_pages as $pid ) : ?>
								<option value="<?php echo esc_attr( $pid ); ?>"><?php echo esc_html( get_the_title( $pid ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="scos-qe-label">
						<span class="title"><?php esc_html_e( 'Service Pathway', 'site-essentials' ); ?></span>
						<select name="scos_ca_qe_service_pathway_id" data-scos-field="pathway">
							<option value="0"><?php esc_html_e( '— None —', 'site-essentials' ); ?></option>
							<?php foreach ( $qe_pathway_pages as $pid ) : ?>
								<option value="<?php echo esc_attr( $pid ); ?>"><?php echo esc_html( get_the_title( $pid ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

				</div><!-- .scos-qe-grid -->

				<div class="scos-qe-progress-wrap">
					<span class="scos-qe-progress-label title"><?php esc_html_e( 'Optimization Progress', 'site-essentials' ); ?></span>
					<div class="scos-qe-progress-tags">
						<?php foreach ( Meta_Fields::optimization_progress_options() as $val => $opt ) : ?>
							<label class="scos-qe-progress-tag"
								style="--tag-color:<?php echo esc_attr( $opt['color'] ); ?>;--tag-bg:<?php echo esc_attr( $opt['bg'] ); ?>"
								data-scos-progress-val="<?php echo esc_attr( $val ); ?>">
								<input type="checkbox"
									name="scos_ca_qe_progress[]"
									value="<?php echo esc_attr( $val ); ?>">
								<?php echo esc_html( $opt['label'] ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

			</div>
		</fieldset>
		<?php
	}

	// =========================================================================
	// Bulk Edit
	// =========================================================================

	public static function bulk_edit_box( $column_name, $post_type ) {
		if ( 'scos_ca_cluster' !== $column_name ) { return; }
		if ( ! in_array( $post_type, Taxonomies::get_post_types(), true ) ) { return; }

		$clusters = get_terms( [ 'taxonomy' => 'scos_content_cluster', 'hide_empty' => false ] );
		if ( is_wp_error( $clusters ) ) { $clusters = []; }
		?>
		<fieldset class="scos-qe-fieldset inline-edit-col">
			<div class="inline-edit-col">
				<h4 class="scos-qe-title"><?php esc_html_e( 'Content Architecture', 'site-essentials' ); ?></h4>
				<div class="scos-qe-grid">

					<label class="scos-qe-label">
						<span class="title"><?php esc_html_e( 'Cluster', 'site-essentials' ); ?></span>
						<select name="scos_ca_be_cluster">
							<option value=""><?php esc_html_e( '— No Change —', 'site-essentials' ); ?></option>
							<option value="0"><?php esc_html_e( '✕ Remove', 'site-essentials' ); ?></option>
							<?php foreach ( $clusters as $t ) : ?>
								<option value="<?php echo esc_attr( $t->term_id ); ?>"><?php echo esc_html( $t->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

				<label class="scos-qe-label">
					<span class="title"><?php esc_html_e( 'Topic', 'site-essentials' ); ?></span>
					<select name="scos_ca_be_topic">
						<option value=""><?php esc_html_e( '— No Change —', 'site-essentials' ); ?></option>
						<option value="0"><?php esc_html_e( '✕ Remove', 'site-essentials' ); ?></option>
						<?php Taxonomies::render_hierarchical_options( 'scos_topic' ); ?>
					</select>
				</label>

					<label class="scos-qe-label">
						<span class="title"><?php esc_html_e( 'Intent', 'site-essentials' ); ?></span>
						<select name="scos_ca_be_intent">
							<option value=""><?php esc_html_e( '— No Change —', 'site-essentials' ); ?></option>
							<?php foreach ( Meta_Fields::intent_options() as $v => $l ) : ?>
								<?php if ( '' === $v ) { continue; } ?>
								<option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="scos-qe-label">
						<span class="title"><?php esc_html_e( 'Purpose', 'site-essentials' ); ?></span>
						<select name="scos_ca_be_purpose">
							<option value=""><?php esc_html_e( '— No Change —', 'site-essentials' ); ?></option>
							<?php foreach ( Meta_Fields::purpose_options() as $v => $l ) : ?>
								<?php if ( '' === $v ) { continue; } ?>
								<option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="scos-qe-label">
						<span class="title"><?php esc_html_e( 'Index', 'site-essentials' ); ?></span>
						<select name="scos_ca_be_index_status">
							<option value=""><?php esc_html_e( '— No Change —', 'site-essentials' ); ?></option>
							<?php foreach ( Meta_Fields::index_status_options() as $v => $l ) : ?>
								<?php if ( '' === $v ) { continue; } ?>
								<option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="scos-qe-label">
						<span class="title"><?php esc_html_e( 'Next Step', 'site-essentials' ); ?></span>
						<select name="scos_ca_be_next_step">
							<option value=""><?php esc_html_e( '— No Change —', 'site-essentials' ); ?></option>
							<?php foreach ( Meta_Fields::next_step_options() as $v => $opt ) : ?>
								<?php if ( '' === $v ) { continue; } ?>
								<option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $opt['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="scos-qe-label">
						<span class="title"><?php esc_html_e( 'Maturity', 'site-essentials' ); ?></span>
						<select name="scos_ca_be_maturity">
							<option value=""><?php esc_html_e( '— No Change —', 'site-essentials' ); ?></option>
							<?php foreach ( Meta_Fields::maturity_options() as $v => $l ) : ?>
								<?php if ( '' === $v ) { continue; } ?>
								<option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<?php
					$be_pillar_pages = get_posts( [
						'post_type'   => get_post_types( [ 'public' => true ], 'names' ),
						'numberposts' => -1,
						'orderby'     => 'title',
						'order'       => 'ASC',
						'meta_query'  => [ [ 'key' => 'scos_ca_purpose', 'value' => 'pillar' ] ],
						'fields'      => 'ids',
					] );
					$be_pathway_pages = get_posts( [
						'post_type'   => get_post_types( [ 'public' => true ], 'names' ),
						'numberposts' => -1,
						'orderby'     => 'title',
						'order'       => 'ASC',
						'meta_query'  => [ [ 'key' => 'scos_ca_purpose', 'value' => [ 'service-page', 'product-page', 'conversion-hub' ], 'compare' => 'IN' ] ],
						'fields'      => 'ids',
					] );
					?>

					<label class="scos-qe-label">
						<span class="title"><?php esc_html_e( 'Pillar Page', 'site-essentials' ); ?></span>
						<select name="scos_ca_be_pillar_page_id">
							<option value=""><?php esc_html_e( '— No Change —', 'site-essentials' ); ?></option>
							<option value="0"><?php esc_html_e( '✕ Remove', 'site-essentials' ); ?></option>
							<?php foreach ( $be_pillar_pages as $pid ) : ?>
								<option value="<?php echo esc_attr( $pid ); ?>"><?php echo esc_html( get_the_title( $pid ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="scos-qe-label">
						<span class="title"><?php esc_html_e( 'Service Pathway', 'site-essentials' ); ?></span>
						<select name="scos_ca_be_service_pathway_id">
							<option value=""><?php esc_html_e( '— No Change —', 'site-essentials' ); ?></option>
							<option value="0"><?php esc_html_e( '✕ Remove', 'site-essentials' ); ?></option>
							<?php foreach ( $be_pathway_pages as $pid ) : ?>
								<option value="<?php echo esc_attr( $pid ); ?>"><?php echo esc_html( get_the_title( $pid ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

				</div><!-- .scos-qe-grid -->

				<div class="scos-qe-progress-wrap">
					<span class="scos-qe-progress-label title">
						<?php esc_html_e( 'Optimization Progress', 'site-essentials' ); ?>
						<em class="scos-qe-hint"><?php esc_html_e( 'Leave all unchecked = no change', 'site-essentials' ); ?></em>
					</span>
					<div class="scos-qe-progress-tags">
						<?php foreach ( Meta_Fields::optimization_progress_options() as $val => $opt ) : ?>
							<label class="scos-qe-progress-tag"
								style="--tag-color:<?php echo esc_attr( $opt['color'] ); ?>;--tag-bg:<?php echo esc_attr( $opt['bg'] ); ?>">
								<input type="checkbox"
									name="scos_ca_be_progress[]"
									value="<?php echo esc_attr( $val ); ?>">
								<?php echo esc_html( $opt['label'] ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

			</div>
		</fieldset>
		<?php
	}

	// =========================================================================
	// Save — Quick Edit + Bulk Edit both fire save_post
	// =========================================================================

	public static function handle_edit_save( $post_id, $post ) {
		// Log EVERYTHING at the top — before any early returns — so we can see what's happening
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$scos_keys = implode( ',', array_filter( array_keys( (array) $_REQUEST ), function( $k ) {
				return strpos( $k, 'scos_' ) === 0;
			} ) );
			error_log( sprintf(
				'[SCOS BE:entry] post_id=%d  post_type=%s  autosave=%s  revision=%s  can_edit=%s  in_types=%s  _inline=%s  bulk_edit=%s  scos_keys=[%s]',
				$post_id,
				$post->post_type ?? '?',
				( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ? 'yes' : 'no',
				wp_is_post_revision( $post_id ) ? 'yes' : 'no',
				current_user_can( 'edit_post', $post_id ) ? 'yes' : 'no',
				in_array( $post->post_type, Taxonomies::get_post_types(), true ) ? 'yes' : 'no',
				isset( $_REQUEST['_inline_edit'] ) ? 'yes' : 'no',
				isset( $_REQUEST['bulk_edit'] ) ? esc_attr( wp_unslash( $_REQUEST['bulk_edit'] ) ) : 'no',
				$scos_keys
			) );
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( wp_is_post_revision( $post_id ) ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
		if ( ! in_array( $post->post_type, Taxonomies::get_post_types(), true ) ) { return; }

		$is_inline = isset( $_REQUEST['_inline_edit'] );
		$is_bulk   = isset( $_REQUEST['bulk_edit'] );

		if ( ! $is_inline && ! $is_bulk ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[SCOS BE:skip] neither inline nor bulk — bailing' );
			}
			return;
		}

		if ( $is_bulk ) {
			self::save_bulk_edit_fields( $post_id );
		} else {
			self::save_quick_edit_fields( $post_id );
		}
	}

	private static function save_quick_edit_fields( $post_id ) {
		// Taxonomies
		if ( isset( $_POST['scos_ca_qe_cluster'] ) ) {
			$id = absint( $_POST['scos_ca_qe_cluster'] );
			wp_set_post_terms( $post_id, $id > 0 ? [ $id ] : [], 'scos_content_cluster' );
		}
		if ( isset( $_POST['scos_ca_qe_topic'] ) ) {
			$id = absint( $_POST['scos_ca_qe_topic'] );
			wp_set_post_terms( $post_id, $id > 0 ? [ $id ] : [], 'scos_topic' );
		}

		// Simple string meta
		$meta_map = [
			'scos_ca_qe_intent'       => 'scos_ca_intent',
			'scos_ca_qe_purpose'      => 'scos_ca_purpose',
			'scos_ca_qe_maturity'     => 'scos_ca_maturity',
			'scos_ca_qe_index_status' => 'scos_ca_index_status',
			'scos_ca_qe_next_step'    => 'scos_ca_next_step',
		];
		foreach ( $meta_map as $post_key => $meta_key ) {
			if ( isset( $_POST[ $post_key ] ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) );
			}
		}

		// Int meta (page links)
		if ( isset( $_POST['scos_ca_qe_pillar_page_id'] ) ) {
			update_post_meta( $post_id, 'scos_ca_pillar_page_id', absint( $_POST['scos_ca_qe_pillar_page_id'] ) );
		}
		if ( isset( $_POST['scos_ca_qe_service_pathway_id'] ) ) {
			update_post_meta( $post_id, 'scos_ca_service_pathway_id', absint( $_POST['scos_ca_qe_service_pathway_id'] ) );
		}

		// Progress — only update if the sentinel field was present (confirms our panel was rendered
		// and JS had the chance to pre-populate checkboxes; prevents silent clear on JS failure).
		if ( isset( $_POST['scos_ca_qe_progress_submitted'] ) ) {
			$progress = isset( $_POST['scos_ca_qe_progress'] ) && is_array( $_POST['scos_ca_qe_progress'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['scos_ca_qe_progress'] ) )
				: [];
			update_post_meta( $post_id, 'scos_ca_optimization_progress', $progress );
		}
	}

	private static function save_bulk_edit_fields( $post_id ) {
		// IMPORTANT: Read from $_REQUEST not $_POST.
		// WordPress's bulk_edit_posts() takes $post_data = &$_POST (by reference) and mutates
		// the array during iteration, so $_POST is unreliable by the time save_post fires.
		// $_REQUEST is computed once at request start and is never modified.
		$req = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[SCOS BE:bulk] post_id=%d  intent=%s  purpose=%s  cluster=%s  maturity=%s',
				$post_id,
				isset( $req['scos_ca_be_intent'] )   ? sanitize_text_field( wp_unslash( $req['scos_ca_be_intent'] ) )   : '(not set)',
				isset( $req['scos_ca_be_purpose'] )  ? sanitize_text_field( wp_unslash( $req['scos_ca_be_purpose'] ) )  : '(not set)',
				isset( $req['scos_ca_be_cluster'] )  ? sanitize_text_field( wp_unslash( $req['scos_ca_be_cluster'] ) )  : '(not set)',
				isset( $req['scos_ca_be_maturity'] ) ? sanitize_text_field( wp_unslash( $req['scos_ca_be_maturity'] ) ) : '(not set)'
			) );
		}

		// Taxonomy: empty string = no change, '0' = remove, positive int = set
		if ( isset( $req['scos_ca_be_cluster'] ) && '' !== $req['scos_ca_be_cluster'] ) {
			$id = absint( $req['scos_ca_be_cluster'] );
			wp_set_post_terms( $post_id, $id > 0 ? [ $id ] : [], 'scos_content_cluster' );
		}
		if ( isset( $req['scos_ca_be_topic'] ) && '' !== $req['scos_ca_be_topic'] ) {
			$id = absint( $req['scos_ca_be_topic'] );
			wp_set_post_terms( $post_id, $id > 0 ? [ $id ] : [], 'scos_topic' );
		}

		// Simple meta: empty string = no change
		$meta_map = [
			'scos_ca_be_intent'       => 'scos_ca_intent',
			'scos_ca_be_purpose'      => 'scos_ca_purpose',
			'scos_ca_be_maturity'     => 'scos_ca_maturity',
			'scos_ca_be_index_status' => 'scos_ca_index_status',
			'scos_ca_be_next_step'    => 'scos_ca_next_step',
		];
		foreach ( $meta_map as $req_key => $meta_key ) {
			if ( isset( $req[ $req_key ] ) && '' !== $req[ $req_key ] ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( wp_unslash( $req[ $req_key ] ) ) );
			}
		}

		// Int page links: empty string = no change, '0' = remove
		if ( isset( $req['scos_ca_be_pillar_page_id'] ) && '' !== $req['scos_ca_be_pillar_page_id'] ) {
			update_post_meta( $post_id, 'scos_ca_pillar_page_id', absint( $req['scos_ca_be_pillar_page_id'] ) );
		}
		if ( isset( $req['scos_ca_be_service_pathway_id'] ) && '' !== $req['scos_ca_be_service_pathway_id'] ) {
			update_post_meta( $post_id, 'scos_ca_service_pathway_id', absint( $req['scos_ca_be_service_pathway_id'] ) );
		}

		// Progress: only replace if at least one tag is checked; otherwise leave untouched
		if ( ! empty( $req['scos_ca_be_progress'] ) && is_array( $req['scos_ca_be_progress'] ) ) {
			$progress = array_map( 'sanitize_text_field', wp_unslash( $req['scos_ca_be_progress'] ) );
			update_post_meta( $post_id, 'scos_ca_optimization_progress', $progress );
		}
	}

	// =========================================================================
	// Filters
	// =========================================================================

	public static function filter_dropdowns( $post_type ) {
		if ( ! in_array( $post_type, Taxonomies::get_post_types(), true ) ) { return; }

		// Topic filter
		$current_topic = isset( $_GET['scos_filter_topic'] ) ? absint( $_GET['scos_filter_topic'] ) : 0;
		$topics = get_terms( [ 'taxonomy' => 'scos_topic', 'hide_empty' => true ] );
		if ( ! is_wp_error( $topics ) && ! empty( $topics ) ) {
			echo '<select name="scos_filter_topic" class="scos-col-filter">';
			echo '<option value="">' . esc_html__( 'All Topics', 'site-essentials' ) . '</option>';
			foreach ( $topics as $t ) {
				printf(
					'<option value="%d"%s>%s</option>',
					$t->term_id,
					selected( $current_topic, $t->term_id, false ),
					esc_html( $t->name )
				);
			}
			echo '</select>';
		}

		// Purpose filter
		$current_purpose = isset( $_GET['scos_filter_purpose'] ) ? sanitize_text_field( wp_unslash( $_GET['scos_filter_purpose'] ) ) : '';
		echo '<select name="scos_filter_purpose" class="scos-col-filter">';
		echo '<option value="">' . esc_html__( 'All Purposes', 'site-essentials' ) . '</option>';
		foreach ( Meta_Fields::purpose_options() as $v => $l ) {
			if ( '' === $v ) { continue; }
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $v ),
				selected( $current_purpose, $v, false ),
				esc_html( $l )
			);
		}
		echo '</select>';
	}

	public static function filter_query( $query ) {
		global $pagenow;
		if ( 'edit.php' !== $pagenow || ! $query->is_main_query() ) { return; }

		if ( ! empty( $_GET['scos_filter_topic'] ) ) {
			$tax_query   = (array) ( $query->get( 'tax_query' ) ?: [] );
			$tax_query[] = [
				'taxonomy' => 'scos_topic',
				'field'    => 'term_id',
				'terms'    => absint( $_GET['scos_filter_topic'] ),
			];
			$query->set( 'tax_query', $tax_query );
		}

		if ( ! empty( $_GET['scos_filter_purpose'] ) ) {
			$meta_query   = (array) ( $query->get( 'meta_query' ) ?: [] );
			$meta_query[] = [
				'key'   => 'scos_ca_purpose',
				'value' => sanitize_text_field( wp_unslash( $_GET['scos_filter_purpose'] ) ),
			];
			$query->set( 'meta_query', $meta_query );
		}
	}

	// =========================================================================
	// Assets
	// =========================================================================

	public static function enqueue_assets( $hook ) {
		if ( 'edit.php' !== $hook ) { return; }

		$css_path = SITE_ESSENTIALS_PATH . 'Modules/ContentArchitecture/assets/admin-columns.css';
		$js_path  = SITE_ESSENTIALS_PATH . 'Modules/ContentArchitecture/assets/admin-columns.js';

		wp_enqueue_style(
			'scos-ca-admin-columns',
			SITE_ESSENTIALS_URL . 'Modules/ContentArchitecture/assets/admin-columns.css',
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1.0.4'
		);

		wp_enqueue_script(
			'scos-ca-admin-columns',
			SITE_ESSENTIALS_URL . 'Modules/ContentArchitecture/assets/admin-columns.js',
			[ 'jquery', 'inline-edit-post' ],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : '1.0.4',
			true
		);

	}
}
