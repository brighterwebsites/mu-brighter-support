<?php
// v1.0 | 2026-08-24

/**
 * Project Reviews Renderer
 *
 * Renders every bw_reviews post linked to a projects post, for use on the
 * project single template. Falls back to the aggregate rating when the
 * project has no linked reviews.
 *
 * Deliberately does NOT use the ACF Extended bidirectional bw_reviews_related
 * field. bw_related_project (Review → Project) is always written as plain
 * postmeta — by Cpt_Module::save_reviews_meta()'s native fallback when ACF/ACFE
 * isn't active, or by ACF's own save routine under the same meta key when it
 * is — see Review_Card_Renderer::get_review_data(), which already reads it the
 * same way. So the reverse lookup (Project → Reviews) here is a plain
 * meta_query against bw_related_project, which works with no ACF and no ACF
 * Extended installed at all.
 *
 * Shortcode usage:
 *   [bw_project_reviews]           — current post in loop (project ID)
 *   [bw_project_reviews id="42"]   — specific project
 *
 * @package    SiteEssentials
 * @subpackage Modules\CustomPosts
 */

namespace SiteEssentials\Modules\CustomPosts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Project_Reviews_Renderer {

	/**
	 * Shortcode handler — maps [bw_project_reviews] atts to render().
	 */
	public function shortcode( array $atts ): string {
		$atts = shortcode_atts( [
			'id' => '',
		], $atts, 'bw_project_reviews' );

		$project_id = ! empty( $atts['id'] ) ? absint( $atts['id'] ) : get_the_ID();
		if ( ! $project_id ) {
			return '';
		}

		$review_ids = self::get_review_ids_for_project( $project_id );

		return empty( $review_ids ) ? $this->render_fallback() : $this->render_reviews( $review_ids );
	}

	/**
	 * Get published review IDs linked to a project via bw_related_project.
	 *
	 * Plain postmeta lookup — no ACF or ACF Extended dependency (see class
	 * docblock). Ordered newest review first.
	 *
	 * @since 1.0.0
	 * @param int $project_id Project post ID.
	 * @return int[]
	 */
	public static function get_review_ids_for_project( int $project_id ): array {
		if ( ! $project_id ) {
			return [];
		}

		return get_posts( [
			'post_type'      => Cpt_Module::POST_TYPE_REVIEWS,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'meta_value',
			'meta_key'       => 'bw_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'          => 'DESC',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'   => 'bw_related_project',
					'value' => $project_id,
				],
			],
		] );
	}

	/**
	 * Render linked reviews as a single-column stacked grid.
	 *
	 * @param int[] $review_ids Review post IDs.
	 * @return string
	 */
	private function render_reviews( array $review_ids ): string {
		ob_start();
		?>
		<div class="bw-project-reviews">
			<?php foreach ( $review_ids as $review_id ) : ?>
				<?php $this->render_review( $review_id ); ?>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render one review card.
	 *
	 * Order: platform icon + star rating (inline, one line) → full review
	 * text → client name → date → verify link. Every text field is a bare
	 * <p> — no blockquote/span/time/strong — the verify link needs an <a>
	 * (unavoidable for a link) but stays wrapped in its own <p>.
	 *
	 * @param int $review_id Review post ID.
	 */
	private function render_review( int $review_id ): void {
		$rating         = (int) get_post_meta( $review_id, 'bw_rating', true );
		$customer_name  = get_the_title( $review_id );
		$review_post    = get_post( $review_id );
		$full_text      = $review_post ? (string) $review_post->post_content : '';
		$raw_date       = (string) get_post_meta( $review_id, 'bw_date', true );
		$precision      = (string) get_post_meta( $review_id, 'bw_date_precision', true ) ?: 'full';
		$date_formatted = $this->format_date( $raw_date, $precision );
		$verify_url     = (string) get_post_meta( $review_id, 'bw_verify_url', true );

		$platforms         = get_the_terms( $review_id, 'bw_review_platform' );
		$platform          = ( $platforms && ! is_wp_error( $platforms ) ) ? $platforms[0] : null;
		$platform_logo_id  = $platform ? absint( get_term_meta( $platform->term_id, 'bw_platform_logo_id', true ) ) : 0;
		?>
		<div class="bw-project-review">

			<?php if ( $platform_logo_id || $rating ) : ?>
			<p class="bw-project-review__meta">
				<?php if ( $platform_logo_id ) : ?>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo wp_get_attachment_image( $platform_logo_id, 'thumbnail', false, [
						'class'   => 'bw-project-review__platform-icon',
						'loading' => 'lazy',
						'alt'     => $platform ? esc_attr( $platform->name ) : '',
					] );
					?>
				<?php endif; ?>
				<?php if ( $rating ) :
					$rating = max( 1, min( 5, $rating ) );
					?>
					<span class="bw-project-review__stars" aria-label="<?php echo esc_attr( $rating . ' out of 5 stars' ); ?>"><?php echo esc_html( str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ) ); ?></span>
				<?php endif; ?>
			</p>
			<?php endif; ?>

			<?php if ( '' !== $full_text ) : ?>
			<p class="bw-project-review__text"><?php echo esc_html( wp_strip_all_tags( do_shortcode( $full_text ) ) ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== $customer_name ) : ?>
			<p class="bw-project-review__name"><?php echo esc_html( $customer_name ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== $date_formatted ) : ?>
			<p class="bw-project-review__date"><?php echo esc_html( $date_formatted ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== $verify_url ) : ?>
			<p class="bw-project-review__verify"><a href="<?php echo esc_url( $verify_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Verify review', 'site-essentials' ); ?></a></p>
			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Fallback when the project has no linked reviews — aggregate stars + count.
	 *
	 * "{stars} {average} from {count} Reviews"
	 *
	 * Computed directly rather than via [bw_review_average]/[bw_review_count] —
	 * those shortcodes return HTML-wrapped output
	 * (`<div class="bw-review-average">5.0</div>`), not a plain number, so piping
	 * that through esc_html() displayed the raw div tags as text instead of
	 * rendering them. Mirrors Aggregate_Review_Renderer::get_aggregate_data().
	 */
	private function render_fallback(): string {
		$stats = $this->get_aggregate_stats();

		// Whole-star rounding — same convention as Aggregate_Review_Renderer
		// (no partial/half-star fill exists anywhere in this codebase).
		$stars = max( 0, min( 5, (int) round( $stats['average_raw'] ) ) );

		ob_start();
		?>
		<div class="bw-project-reviews bw-project-reviews--fallback">
			<p class="bw-project-review__stars"><?php echo esc_html( str_repeat( '★', $stars ) . str_repeat( '☆', 5 - $stars ) ); ?></p>
			<p class="bw-project-review__summary">
				<?php
				printf(
					/* translators: 1: average rating, 2: review count */
					esc_html__( '%1$s from %2$s Reviews', 'site-essentials' ),
					esc_html( $stats['average'] ),
					esc_html( (string) $stats['count'] )
				);
				?>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Site-wide review average + count, all published bw_reviews (matches the
	 * default, unfiltered [bw_review_average]/[bw_review_count] shortcode scope).
	 *
	 * @return array{average: string, average_raw: float, count: int}
	 */
	private function get_aggregate_stats(): array {
		$ids = get_posts( [
			'post_type'      => Cpt_Module::POST_TYPE_REVIEWS,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		$total = 0.0;
		$count = 0;
		foreach ( $ids as $review_id ) {
			$rating = get_post_meta( (int) $review_id, 'bw_rating', true );
			if ( '' !== $rating && is_numeric( $rating ) ) {
				$total += (float) $rating;
				$count++;
			}
		}

		$average_raw = $count > 0 ? $total / $count : 0.0;

		return [
			'average'     => number_format( $average_raw, 1 ),
			'average_raw' => $average_raw,
			'count'       => $count,
		];
	}

	/**
	 * Format date respecting bw_date_precision. Mirrors Review_Card_Renderer.
	 */
	private function format_date( string $raw, string $precision ): string {
		if ( '' === $raw ) {
			return '';
		}
		$ts = strtotime( $raw );
		if ( ! $ts ) {
			return esc_html( $raw );
		}
		switch ( $precision ) {
			case 'year':       return date_i18n( 'Y', $ts );
			case 'month-year': return date_i18n( 'F Y', $ts );
			default:           return date_i18n( get_option( 'date_format' ), $ts );
		}
	}
}
