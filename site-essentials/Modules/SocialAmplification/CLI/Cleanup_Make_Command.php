<?php
/**
 * WP-CLI Cleanup Make.com Command
 *
 * Usage:
 *   wp scos-social cleanup-make --dry-run
 *   wp scos-social cleanup-make
 *
 * Deletes leftover data from the deprecated Make.com social webhook workflow:
 *  - All scos_social_framing posts (+ postmeta) — the current Post Framing CPT
 *  - All bw_talking_point posts (+ postmeta) — legacy pre-migration CPT, if any remain
 *  - All bw_content_type terms — taxonomy shared by both of the above
 *  - Make.com-only options: scos_sma_webhook_url, scos_sma_webhook_enabled,
 *    bw_social_webhook_url, bw_social_webhook_enabled, scos_pf_post_type_migrated_v1
 *
 * Does NOT touch: bw_social_webhook_secret, bw_yourls_*, bw_postly_*, or any
 * Postly.ai amplification data — those belong to the unrelated Postly pipeline.
 *
 * @package    SiteEssentials
 * @subpackage Modules\SocialAmplification\CLI
 * v1.0 | 2026-07-21
 */

namespace SiteEssentials\Modules\SocialAmplification\CLI;

defined( 'ABSPATH' ) || exit;

class Cleanup_Make_Command {

	/** Post types to purge. */
	private const POST_TYPES = [ 'scos_social_framing', 'bw_talking_point' ];

	/** Options to delete (Make.com webhook trigger only). */
	private const OPTIONS = [
		'scos_sma_webhook_url',
		'scos_sma_webhook_enabled',
		'bw_social_webhook_url',
		'bw_social_webhook_enabled',
		'scos_pf_post_type_migrated_v1',
	];

	/**
	 * Delete deprecated Make.com Post Framing / Talking Points data.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be deleted without deleting anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp scos-social cleanup-make --dry-run
	 *     wp scos-social cleanup-make
	 *
	 * @when after_wp_load
	 *
	 * @param  array $args       Positional args (unused).
	 * @param  array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$post_ids = get_posts( [
			'post_type'      => self::POST_TYPES,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		\WP_CLI::log( sprintf( 'Found %d post(s) across: %s', count( $post_ids ), implode( ', ', self::POST_TYPES ) ) );

		$terms = get_terms( [ 'taxonomy' => 'bw_content_type', 'hide_empty' => false ] );
		$terms = is_wp_error( $terms ) ? [] : $terms;
		\WP_CLI::log( sprintf( 'Found %d bw_content_type term(s).', count( $terms ) ) );

		$existing_options = array_values( array_filter( self::OPTIONS, static function ( $key ) {
			return false !== get_option( $key, false ) && '' !== get_option( $key, '' );
		} ) );
		\WP_CLI::log( sprintf( 'Found %d Make.com option(s) set: %s', count( $existing_options ), implode( ', ', $existing_options ) ?: '(none)' ) );

		if ( $dry_run ) {
			\WP_CLI::success( 'Dry run — nothing deleted.' );
			return;
		}

		$deleted_posts = 0;
		foreach ( $post_ids as $post_id ) {
			if ( wp_delete_post( $post_id, true ) ) {
				++$deleted_posts;
			}
		}

		$deleted_terms = 0;
		foreach ( $terms as $term ) {
			if ( ! is_wp_error( wp_delete_term( $term->term_id, 'bw_content_type' ) ) ) {
				++$deleted_terms;
			}
		}

		foreach ( self::OPTIONS as $key ) {
			delete_option( $key );
		}

		\WP_CLI::success( sprintf(
			'Deleted %d post(s), %d bw_content_type term(s), and cleared %d option(s).',
			$deleted_posts,
			$deleted_terms,
			count( self::OPTIONS )
		) );
	}
}
