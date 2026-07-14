<?php
/**
 * Content Architecture — Intent Goal Resolver
 *
 * Single source of truth for resolving a post's Search Intent Goal.
 * Supports dual-mode: FAQ post link (scos_ca_intent_goal_faq_id) first,
 * falling back to freetext (scos_ca_intent_goal).
 *
 * Also owns stub FAQ creation and incomplete-FAQ detection so that
 * Meta_Box, Admin_Columns, REST, CAR injection, and WP-CLI/MCP
 * all read from one place.
 *
 * v1.1 | 2026-05-25 — create_stub_faq now sets scos_faq_is_intent_goal on the new FAQ.
 * v1.2 | 2026-07-15 — Remove bw_search_intent / bw_altc_notes fallbacks; migration complete.
 *
 * @package    SiteEssentials
 * @subpackage Modules\ContentArchitecture
 */

namespace SiteEssentials\Modules\ContentArchitecture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Intent_Goal_Resolver {

	// =========================================================================
	// Read helpers
	// =========================================================================

	/**
	 * Return the linked FAQ post ID for a post, or 0 if none.
	 *
	 * @param int $post_id Post ID.
	 * @return int FAQ post ID, or 0.
	 */
	public static function get_faq_id( int $post_id ): int {
		return (int) get_post_meta( $post_id, 'scos_ca_intent_goal_faq_id', true );
	}

	/**
	 * Resolve the search intent question for a post.
	 *
	 * Resolve order:
	 *   1. FAQ post title (when scos_ca_intent_goal_faq_id is set and FAQ exists)
	 *   2. scos_ca_intent_goal (freetext)
	 *
	 * @param int $post_id Post ID.
	 * @return string Question text, or empty string.
	 */
	public static function resolve_question( int $post_id ): string {
		$faq_id = self::get_faq_id( $post_id );
		if ( $faq_id > 0 ) {
			$faq = get_post( $faq_id );
			if ( $faq && 'faq' === $faq->post_type ) {
				return $faq->post_title;
			}
		}

		return (string) get_post_meta( $post_id, 'scos_ca_intent_goal', true );
	}

	// =========================================================================
	// Incomplete FAQ detection
	// =========================================================================

	/**
	 * Determine whether a FAQ post is incomplete (stub / needs answer).
	 *
	 * An FAQ is incomplete when:
	 *   - post_status is 'draft', OR
	 *   - scos_faq_schema_answer is empty AND post_content is empty or < 30 chars
	 *
	 * @param int $faq_id FAQ post ID.
	 * @return bool True when incomplete.
	 */
	public static function is_faq_incomplete( int $faq_id ): bool {
		$faq = get_post( $faq_id );
		if ( ! $faq || 'faq' !== $faq->post_type ) {
			return false;
		}

		if ( 'draft' === $faq->post_status ) {
			return true;
		}

		$schema_answer = (string) get_post_meta( $faq_id, 'scos_faq_schema_answer', true );
		if ( '' !== $schema_answer ) {
			return false;
		}

		$content_text = wp_strip_all_tags( $faq->post_content );
		return strlen( trim( $content_text ) ) < 30;
	}

	// =========================================================================
	// FAQ summary
	// =========================================================================

	/**
	 * Return a structured summary of a FAQ post for use in UI and REST.
	 *
	 * @param int $faq_id FAQ post ID.
	 * @return array{id: int, title: string, status: string, topic: string, edit_url: string, incomplete: bool}|null
	 *         Null when the post doesn't exist or isn't an faq.
	 */
	public static function get_faq_summary( int $faq_id ): ?array {
		$faq = get_post( $faq_id );
		if ( ! $faq || 'faq' !== $faq->post_type ) {
			return null;
		}

		$topic      = '';
		$topic_terms = get_the_terms( $faq_id, 'scos_topic' );
		if ( $topic_terms && ! is_wp_error( $topic_terms ) ) {
			$topic = $topic_terms[0]->name;
		}

		return [
			'id'         => $faq_id,
			'title'      => $faq->post_title,
			'status'     => $faq->post_status,
			'topic'      => $topic,
			'edit_url'   => (string) get_edit_post_link( $faq_id, 'raw' ),
			'incomplete' => self::is_faq_incomplete( $faq_id ),
		];
	}

	// =========================================================================
	// Stub creation
	// =========================================================================

	/**
	 * Create a stub FAQ in draft status.
	 *
	 * Assigns the given topic term (copied from the source page), and CA_Defaults
	 * will run automatically on first save to set intent/purpose for the faq CPT.
	 *
	 * @param string $title          FAQ title / question text.
	 * @param int    $topic_term_id  scos_topic term ID to assign (0 = none).
	 * @param int    $source_post_id Optional — post ID that triggered creation (for logging only).
	 * @return int|\WP_Error New FAQ post ID on success, WP_Error on failure.
	 */
	public static function create_stub_faq( string $title, int $topic_term_id = 0, int $source_post_id = 0 ) {
		$title = sanitize_text_field( $title );
		if ( '' === $title ) {
			return new \WP_Error( 'empty_title', __( 'FAQ title cannot be empty.', 'site-essentials' ) );
		}

		$faq_id = wp_insert_post(
			[
				'post_title'  => $title,
				'post_type'   => 'faq',
				'post_status' => 'draft',
			],
			true
		);

		if ( is_wp_error( $faq_id ) ) {
			return $faq_id;
		}

		if ( $topic_term_id > 0 && taxonomy_exists( 'scos_topic' ) ) {
			wp_set_post_terms( $faq_id, [ $topic_term_id ], 'scos_topic' );
		}

		// Mark the new FAQ as an intent goal immediately — it was created for this purpose.
		update_post_meta( $faq_id, 'scos_faq_is_intent_goal', '1' );

		return $faq_id;
	}

	// =========================================================================
	// Reverse lookup
	// =========================================================================

	/**
	 * Return IDs of posts that link to a given FAQ as their intent goal.
	 *
	 * @param int $faq_id FAQ post ID.
	 * @return int[] Array of post IDs.
	 */
	public static function get_pages_using_faq( int $faq_id ): array {
		if ( $faq_id <= 0 ) {
			return [];
		}

		$posts = get_posts( [
			'post_type'      => 'any',
			'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'   => 'scos_ca_intent_goal_faq_id',
					'value' => $faq_id,
					'type'  => 'NUMERIC',
				],
			],
		] );

		return array_map( 'intval', (array) $posts );
	}
}
