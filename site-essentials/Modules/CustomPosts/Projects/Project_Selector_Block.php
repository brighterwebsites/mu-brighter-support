<?php
/**
 * Project Selector Gutenberg block (server-rendered).
 *
 * Selects specific `projects` posts by ID and renders them as cards. Same
 * problem the FAQ Selector block (FAQ_Block.php) already solves for FAQs:
 * the default Query Loop / Latest Posts block references posts dynamically,
 * so a renamed post title/slug silently drops it from the loop. Referencing
 * by ID here means renaming a project never loses the embed.
 *
 * v1.0 | 2026-08-20
 *
 * @package    SiteEssentials
 * @subpackage Modules\CustomPosts\Projects
 */

namespace SiteEssentials\Modules\CustomPosts\Projects;

use SiteEssentials\Modules\CustomPosts\Cpt_Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Project_Selector_Block {

	const BLOCK_NAME    = 'brighter/project-selector';
	const SCRIPT_HANDLE = 'scos-project-selector-block';

	/**
	 * Hook block registration into init.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', [ self::class, 'register_block' ] );
	}

	/**
	 * Register the block server-side.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$asset_url = trailingslashit( SITE_ESSENTIALS_URL ) . 'Modules/CustomPosts/Projects/assets/project-selector-block.js';

		wp_register_script(
			self::SCRIPT_HANDLE,
			$asset_url,
			[ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch' ],
			defined( 'SITE_ESSENTIALS_VERSION' ) ? SITE_ESSENTIALS_VERSION : '1.0.0',
			true
		);

		register_block_type( self::BLOCK_NAME, [
			'api_version'     => 3,
			'editor_script'   => self::SCRIPT_HANDLE,
			'render_callback' => [ self::class, 'render' ],
			'attributes'      => [
				'selectedProjects' => [
					'type'    => 'array',
					'default' => [],
					'items'   => [ 'type' => 'number' ],
				],
				'displayFormat' => [
					'type'    => 'string',
					'default' => 'horizontal', // horizontal | stacked
				],
				'titleTag' => [
					'type'    => 'string',
					'default' => 'h3', // h2 | h3 | h4 | h5 | h6 | p
				],
			],
		] );
	}

	/**
	 * Server-side render — returns block HTML only.
	 *
	 * Markup:
	 *   .bw-project-selector                    wrapper, all selected cards
	 *     a.bw-project-card(--horizontal|--stacked)  outer link — whole card is clickable
	 *       .bw-project-card__media > img.bw-project-card__image  (og-image size, 1200x630)
	 *       .bw-project-card__body
	 *         {titleTag}.bw-project-card__title
	 *         .bw-project-card__excerpt
	 *
	 * @since 1.0.0
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( $attributes ): string {
		$attrs = wp_parse_args( (array) $attributes, [
			'selectedProjects' => [],
			'displayFormat'    => 'horizontal',
			'titleTag'         => 'h3',
		] );

		$selected_ids = array_values( array_filter( array_map( 'intval', (array) $attrs['selectedProjects'] ) ) );
		$format       = in_array( $attrs['displayFormat'], [ 'horizontal', 'stacked' ], true )
			? $attrs['displayFormat']
			: 'horizontal';
		$title_tag    = in_array( $attrs['titleTag'], [ 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ], true )
			? $attrs['titleTag']
			: 'h3';

		if ( empty( $selected_ids ) ) {
			return '<p>' . esc_html__( 'No projects selected.', 'site-essentials' ) . '</p>';
		}

		$projects = self::get_by_ids( $selected_ids );
		if ( empty( $projects ) ) {
			return '<p>' . esc_html__( 'No projects found.', 'site-essentials' ) . '</p>';
		}

		ob_start();
		?>
		<div class="bw-project-selector" data-format="<?php echo esc_attr( $format ); ?>">
			<?php foreach ( $projects as $project ) :
				$permalink = (string) get_permalink( $project );
				$thumb_id  = get_post_thumbnail_id( $project );
				$excerpt   = get_the_excerpt( $project );
			?>
				<a href="<?php echo esc_url( $permalink ); ?>" class="bw-project-card bw-project-card--<?php echo esc_attr( $format ); ?>">
					<?php if ( $thumb_id ) :
						$alt = trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) );
						if ( '' === $alt ) {
							$alt = get_the_title( $project );
						}
						?>
						<div class="bw-project-card__media">
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							echo wp_get_attachment_image( $thumb_id, 'og-image', false, [
								'class' => 'bw-project-card__image',
								'alt'   => $alt,
							] );
							?>
						</div>
					<?php endif; ?>
					<div class="bw-project-card__body">
						<<?php echo esc_attr( $title_tag ); ?> class="bw-project-card__title"><?php echo esc_html( get_the_title( $project ) ); ?></<?php echo esc_attr( $title_tag ); ?>>
						<?php if ( '' !== $excerpt ) : ?>
							<div class="bw-project-card__excerpt"><?php echo wp_kses_post( $excerpt ); ?></div>
						<?php endif; ?>
					</div>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Get published project posts by IDs, preserving selection order.
	 *
	 * @since 1.0.0
	 * @param int[] $ids Project post IDs.
	 * @return \WP_Post[]
	 */
	public static function get_by_ids( array $ids ): array {
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( empty( $ids ) ) {
			return [];
		}

		return get_posts( [
			'post_type'      => Cpt_Module::POST_TYPE_PROJECTS,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'post__in'       => $ids,
			'orderby'        => 'post__in',
			'no_found_rows'  => true,
		] );
	}
}
