<?php
/**
 * Content Architecture — Content Analysis Engine
 *
 * Runs on save_post and writes scos_ca_* analysis meta (word count, H2s,
 * images, reading time, internal/external link counts + detail lists).
 *
 * Skips re-analysis if post content hasn't changed since the last run
 * (checked via scos_ca_last_analyzed vs post_modified).
 *
 * Reuses BW_Content_Analysis::get_aggregated_content() when available so
 * Breakdance + ACF field content is included — same source the legacy module
 * uses. Falls back to post_content only if the legacy class isn't loaded.
 *
 * Runs at save_post priority 25 (after legacy BW_Content_Analysis at 20) so
 * both sets of meta keys are written without conflicting.
 *
 * v1.6.0 | 2026-05-19 — Hook onto updated/added_post_meta for _breakdance_data so analysis always uses fresh BD content.
 * v1.7.0 | 2026-06-07 — Rendered-first content via Rendered_Content_Extractor; analysis
 *   debounced onto the shared scos_ca_render_analyze cron event (editor stays fast);
 *   stores rendered markdown in scos_ca_content_md; drafts skipped entirely.
 *
 * @package    SiteEssentials
 * @subpackage Modules\ContentArchitecture
 * @since      1.0.0
 */

namespace SiteEssentials\Modules\ContentArchitecture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Content_Analysis {

	/**
	 * Shared debounced-analysis cron event (same string used by the legacy
	 * BW_Content_Analysis class so both write from one loopback render).
	 */
	const CRON_EVENT = 'scos_ca_render_analyze';

	public static function init() {
		// On save we only schedule — the heavy render/analysis runs in cron so
		// the editor stays fast.
		add_action( 'save_post', [ __CLASS__, 'on_save_post' ], 25, 3 );
		add_action( 'wp_ajax_scos_run_analysis_batch',    [ __CLASS__, 'ajax_run_batch' ] );
		add_action( 'wp_ajax_scos_analysis_status',       [ __CLASS__, 'ajax_status' ] );
		add_action( 'wp_ajax_scos_clear_analysis_cache',  [ __CLASS__, 'ajax_clear_cache' ] );

		// Debounced analysis worker (priority 20 so it runs after the legacy
		// handler at 10 — the rendered HTML is cached, so order is harmless).
		add_action( self::CRON_EVENT, [ __CLASS__, 'run_scheduled' ], 20, 1 );

		// Breakdance Builder saves _breakdance_data via its own REST API — this may fire
		// before save_post runs (causing analysis to read stale data) or may not trigger
		// save_post at all. Hook directly onto the meta write so we always re-analyze with
		// the freshly saved Breakdance content.
		add_action( 'updated_post_meta', [ __CLASS__, 'on_breakdance_data_saved' ], 10, 3 );
		add_action( 'added_post_meta',   [ __CLASS__, 'on_breakdance_data_saved' ], 10, 3 );
	}

	/**
	 * save_post handler — validate then schedule a debounced analysis run.
	 *
	 * Capability / autosave / revision checks live here (request context); the
	 * cron worker has no current user.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 */
	public static function on_save_post( $post_id, $post, $update ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, Taxonomies::get_post_types(), true ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// Rendered analysis only applies to published content (drafts skipped).
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		self::schedule_analysis( $post_id );
	}

	/**
	 * Schedule a single debounced analysis run for a post (deduped).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function schedule_analysis( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ! wp_next_scheduled( self::CRON_EVENT, [ $post_id ] ) ) {
			wp_schedule_single_event( time() + 15, self::CRON_EVENT, [ $post_id ] );
		}
	}

	/**
	 * WP-Cron worker — load the post and run the analysis.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function run_scheduled( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post ) {
			self::analyze( $post_id, $post, true );
		}
	}

	// ──────────────────────────────────────────────────────────────────────
	// AJAX: batch analysis for the CA overview page
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * Return per-post-type analysis status (total / analyzed / unanalyzed).
	 */
	public static function ajax_status(): void {
		check_ajax_referer( 'scos_analysis', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$types  = Taxonomies::get_post_types();
		$rows   = [];
		$totals = [ 'total' => 0, 'analyzed' => 0 ];

		foreach ( $types as $type ) {
			$obj = get_post_type_object( $type );
			if ( ! $obj ) continue;

			$all = (int) wp_count_posts( $type )->publish;
			$analyzed = (int) ( new \WP_Query( [
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => [ [ 'key' => 'scos_ca_last_analyzed', 'compare' => 'EXISTS' ] ],
			] ) )->found_posts;

			$rows[] = [
				'type'       => $type,
				'label'      => $obj->labels->name,
				'total'      => $all,
				'analyzed'   => $analyzed,
				'unanalyzed' => max( 0, $all - $analyzed ),
			];
			$totals['total']    += $all;
			$totals['analyzed'] += $analyzed;
		}

		wp_send_json_success( [ 'rows' => $rows, 'totals' => $totals ] );
	}

	/**
	 * Bulk-delete scos_ca_last_analyzed for all posts so a full re-analysis can run.
	 *
	 * Used by the "Force Re-analyze All" button on the CA Overview. Clears the cache
	 * in one fast DELETE query; the regular batch runner then processes all posts.
	 *
	 * @since 1.5.0
	 */
	public static function ajax_clear_cache(): void {
		check_ajax_referer( 'scos_clear_analysis', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		global $wpdb;
		$deleted = $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => 'scos_ca_last_analyzed' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key

		wp_send_json_success( [ 'deleted' => absint( $deleted ) ] );
	}

	/**
	 * Re-analyze a post whenever Breakdance writes new builder data.
	 *
	 * Breakdance Builder saves `_breakdance_data` via its own REST endpoint. Depending on
	 * version it may write the meta AFTER `save_post` fires (so the save_post-triggered
	 * analysis sees stale content) or may not fire `save_post` at all. Hooking here — onto
	 * the meta write itself — guarantees analysis always uses the freshly committed content.
	 *
	 * Works for both `updated_post_meta` (existing pages) and `added_post_meta` (first
	 * time Breakdance content is ever saved to a page). Fires only for `_breakdance_data`;
	 * all other meta writes are ignored with an early return.
	 *
	 * @since 1.6.0
	 * @param int    $meta_id  Meta row ID (unused).
	 * @param int    $post_id  Post being saved.
	 * @param string $meta_key Meta key just written.
	 */
	public static function on_breakdance_data_saved( int $meta_id, int $post_id, string $meta_key ): void {
		if ( '_breakdance_data' !== $meta_key ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, Taxonomies::get_post_types(), true ) ) {
			return;
		}
		// Rendered analysis only applies to published content (drafts skipped).
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		// Clear the timestamp so the skip-condition in analyze() doesn't block this run.
		delete_post_meta( $post_id, 'scos_ca_last_analyzed' );
		self::schedule_analysis( $post_id );
	}

	/**
	 * Analyze a batch of posts that haven't been analyzed yet.
	 * Accepts optional $_POST['post_type'] to limit to one type.
	 *
	 * Each post is analyzed via a live page render (loopback fetch), so the call
	 * is bounded by a wall-clock budget rather than a fixed count — this keeps the
	 * AJAX request under PHP's max_execution_time when renders are slow. Every
	 * post that is touched is guaranteed to be stamped (even if its render throws)
	 * so the queue always drains and the front-end progress loop converges.
	 */
	public static function ajax_run_batch(): void {
		check_ajax_referer( 'scos_analysis', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$type_filter = isset( $_POST['post_type'] ) ? sanitize_key( $_POST['post_type'] ) : '';
		$post_types  = $type_filter ? [ $type_filter ] : Taxonomies::get_post_types();
		$batch_size  = (int) apply_filters( 'scos_ca_batch_size', 10 );
		$deadline    = microtime( true ) + (float) apply_filters( 'scos_ca_batch_time_budget', 12.0 );

		$ids = get_posts( [
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				'relation' => 'OR',
				[ 'key' => 'scos_ca_last_analyzed', 'compare' => 'NOT EXISTS' ],
				[ 'key' => 'scos_ca_last_analyzed', 'value' => '', 'compare' => '=' ],
			],
		] );

		$processed = 0;
		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				try {
					self::analyze( $post_id, $post, true );
				} catch ( \Throwable $e ) {
					// Never let one bad post stall the queue — stamp it so it can't
					// be re-selected, and log for follow-up.
					update_post_meta( $post_id, 'scos_ca_last_analyzed', $post->post_modified );
					error_log( 'SCOS CA batch: analyze() failed for post ' . $post_id . ' — ' . $e->getMessage() );
				}
				$processed++;
			}
			// Stop once the time budget is spent; the JS loop will call again.
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		// Count remaining unanalyzed across all requested post types.
		$remaining = (int) ( new \WP_Query( [
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'meta_query'     => [
				'relation' => 'OR',
				[ 'key' => 'scos_ca_last_analyzed', 'compare' => 'NOT EXISTS' ],
				[ 'key' => 'scos_ca_last_analyzed', 'value' => '', 'compare' => '=' ],
			],
		] ) )->found_posts;

		wp_send_json_success( [
			'processed' => $processed,
			'remaining' => $remaining,
		] );
	}

	/**
	 * Main entry point — triggered on save_post.
	 *
	 * @since 1.0.0
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 * @return void
	 */
	public static function analyze( $post_id, $post, $update ) {
		// No capability check here: analyze() also runs from WP-Cron (run_scheduled)
		// where there is no current user. on_save_post / ajax_run_batch gate access.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, Taxonomies::get_post_types(), true ) ) {
			return;
		}
		// Rendered analysis only applies to published content (drafts skipped).
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		// Skip if content hasn't changed since last analysis.
		$last = get_post_meta( $post_id, 'scos_ca_last_analyzed', true );
		if ( $last && $last === $post->post_modified ) {
			return;
		}

		$raw   = self::get_content( $post_id, $post );
		$clean = self::clean_content( $raw );
		$links = self::analyze_links( $clean );
		$stats = self::calculate_stats( $clean );

		// Rendered markdown — primary "extractable text/md" deliverable, consumed
		// by AI prompts and the content inventory without re-rendering live.
		if ( class_exists( Rendered_Content_Extractor::class ) ) {
			update_post_meta( $post_id, 'scos_ca_content_md', Rendered_Content_Extractor::to_markdown( $clean ) );
		}

		$reading_time = $stats['word_count'] > 0
			? max( 1, (int) ceil( $stats['word_count'] / 200 ) )
			: 0;

		update_post_meta( $post_id, 'scos_ca_word_count',             $stats['word_count'] );
		update_post_meta( $post_id, 'scos_ca_h2_count',               $stats['h2_count'] );
		update_post_meta( $post_id, 'scos_ca_image_count',            $stats['image_count'] );
		update_post_meta( $post_id, 'scos_ca_reading_time',           $reading_time );
		update_post_meta( $post_id, 'scos_ca_reading_time_iso',       $reading_time ? 'PT' . $reading_time . 'M' : '' );
		update_post_meta( $post_id, 'scos_ca_links_to_internal',      $links['internal_count'] );
		update_post_meta( $post_id, 'scos_ca_links_to_external',      $links['external_count'] );
		update_post_meta( $post_id, 'scos_ca_links_to_internal_list', $links['internal_links'] );
		update_post_meta( $post_id, 'scos_ca_links_to_external_list', $links['external_links'] );
		update_post_meta( $post_id, 'scos_ca_last_analyzed',          $post->post_modified );

		// Schema type tracker — detect which schema types this page contributes.
		$schema_types = self::detect_schema_types( $post_id, $post );
		update_post_meta( $post_id, 'scos_ca_schema_track', $schema_types );
	}

	/**
	 * Get full post content from all sources (Breakdance, ACF, post_content).
	 * Delegates to BW_Content_Analysis if available.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return string Raw HTML/text.
	 */
	private static function get_content( $post_id, $post ) {
		if ( class_exists( '\BW_Content_Analysis' )
			&& method_exists( '\BW_Content_Analysis', 'get_aggregated_content' ) ) {
			$content = \BW_Content_Analysis::get_aggregated_content( $post_id );
			if ( $content ) {
				return $content;
			}
		}
		return $post->post_content;
	}

	/**
	 * Strip nav / header / footer noise from content HTML.
	 *
	 * @param string $html Raw HTML.
	 * @return string Cleaned HTML.
	 */
	private static function clean_content( $html ) {
		if ( empty( $html ) ) {
			return '';
		}
		// Remove header, footer, nav blocks (common Breakdance/builder patterns).
		$html = preg_replace( '/<(header|footer|nav)[^>]*>.*?<\/\1>/is', '', $html );
		return $html;
	}

	/**
	 * Extract word count, H2 count, and image count from cleaned HTML.
	 *
	 * @param string $html Cleaned HTML.
	 * @return array{word_count: int, h2_count: int, image_count: int}
	 */
	private static function calculate_stats( $html ) {
		$text = wp_strip_all_tags( $html );
		preg_match_all( '/<h2[^>]*>/i', $html, $h2_matches );
		preg_match_all( '/<img[^>]*>/i', $html, $img_matches );

		return [
			'word_count'  => $text ? str_word_count( $text ) : 0,
			'h2_count'    => count( $h2_matches[0] ),
			'image_count' => count( $img_matches[0] ),
		];
	}

	/**
	 * Detect which schema @type values this post contributes to the page graph.
	 *
	 * Scans two sources:
	 *  1. Gutenberg blocks in post_content (e.g. brighter/faq-selector).
	 *  2. The `bw_custom_schema` post meta (raw JSON-LD string / array).
	 *
	 * Returns a deduplicated, sorted array of @type strings such as
	 * ['FAQPage', 'HowTo', 'Product']. Empty array = no schema detected.
	 *
	 * Extensible via the `scos_schema_track_types` filter:
	 *   apply_filters( 'scos_schema_track_types', array $types, int $post_id, \WP_Post $post )
	 *
	 * @since 1.1.0
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return string[]
	 */
	private static function detect_schema_types( int $post_id, \WP_Post $post ): array {
		$types = [];

		// ── 1. Gutenberg block scan ───────────────────────────────────────────
		if ( ! empty( $post->post_content ) && function_exists( 'parse_blocks' ) ) {
			$blocks = parse_blocks( $post->post_content );
			self::scan_blocks_for_schema( $blocks, $types );
		}

		// ── 2. Breakdance tree scan ──────────────────────────────────────────
		// Mirrors the Gutenberg detection so BD-rendered pages with a
		// Scos_Faqs element get FAQPage recorded too. The walker is local
		// to this method (no shared state with FAQ_Schema_Graph) so this
		// module stays usable even if the FAQ submodule is off.
		self::scan_breakdance_for_schema( $post_id, $types );

		// ── 3. Custom schema post meta ──
		$custom_json = get_post_meta( $post_id, 'scos_schema_custom', true );
		if ( ! empty( $custom_json ) ) {
			$decoded = json_decode( $custom_json, true );
			if ( is_array( $decoded ) ) {
				// May be a single object or an array of objects.
				$items = isset( $decoded[0] ) ? $decoded : [ $decoded ];
				foreach ( $items as $item ) {
					if ( isset( $item['@type'] ) && is_string( $item['@type'] ) ) {
						$types[] = $item['@type'];
					}
				}
			}
		}

		// ── 4. Allow other modules to contribute schema type signals ──────────
		$types = (array) apply_filters( 'scos_schema_track_types', $types, $post_id, $post );

		// Deduplicate and sort for stable storage.
		$types = array_values( array_unique( array_filter( $types ) ) );
		sort( $types );

		return $types;
	}

	/**
	 * Scan `_breakdance_data` post meta for known schema-contributing elements.
	 *
	 * Currently detects:
	 *  - Scos_Faqs (BreakdanceCustomElements\ScosFaqs) → FAQPage
	 *
	 * Uses a cheap haystack pre-check before decoding the tree so posts that
	 * don't use the element pay only a string-search cost.
	 *
	 * @since 1.2.0
	 * @param int      $post_id Post ID.
	 * @param string[] $types   Reference — schema types are appended.
	 * @return void
	 */
	private static function scan_breakdance_for_schema( int $post_id, array &$types ): void {
		$raw = get_post_meta( $post_id, '_breakdance_data', true );
		if ( empty( $raw ) || ! is_string( $raw ) ) {
			$raw = get_post_meta( $post_id, 'breakdance_data', true );
		}
		if ( empty( $raw ) ) {
			return;
		}

		$haystack = is_string( $raw ) ? $raw : wp_json_encode( $raw );
		if ( false === strpos( (string) $haystack, 'ScosFaqs' ) ) {
			return;
		}

		$tree = self::decode_bd_tree( $raw );
		if ( null === $tree ) {
			return;
		}

		if ( self::bd_tree_has_scos_faqs( $tree ) ) {
			$types[] = 'FAQPage';
		}
	}

	/**
	 * Unwrap `_breakdance_data` to the root node of the tree.
	 *
	 * Same parse pattern as BW_Content_Analysis::parse_breakdance_structure()
	 * and FAQ_Schema_Graph::decode_bd_tree(): outer JSON has a
	 * `tree_json_string` field that's itself a JSON string, decoded to give
	 * a tree with `root` at the top.
	 *
	 * @since 1.3.0
	 * @param mixed $raw Raw meta value (string or pre-decoded array).
	 * @return array|null Root node, or null when the data can't be parsed.
	 */
	private static function decode_bd_tree( $raw ) {
		$outer = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( ! is_array( $outer ) ) {
			return null;
		}

		if ( isset( $outer['tree_json_string'] ) && is_string( $outer['tree_json_string'] ) ) {
			$tree = json_decode( $outer['tree_json_string'], true );
		} else {
			$tree = $outer;
		}
		if ( ! is_array( $tree ) ) {
			return null;
		}

		if ( isset( $tree['root'] ) && is_array( $tree['root'] ) ) {
			return $tree['root'];
		}

		return $tree;
	}

	/**
	 * Recursive existence check for a Scos_Faqs element with schema enabled.
	 *
	 * Mirrors the resilient walker in FAQ_Schema_Graph::walk_bd_tree(): we
	 * recurse into every nested array (not just `$node['children']`) and
	 * accept both fully-qualified and short class-name `type` values, plus
	 * both `data.properties.content` and `properties.content` shapes.
	 *
	 * Returns true on first matched element with schema_enabled — we don't
	 * need to count or collect IDs here, just record that the page
	 * contributes FAQPage.
	 *
	 * @since 1.4.0
	 * @param array $node Tree fragment.
	 * @return bool
	 */
	private static function bd_tree_has_scos_faqs( array $node ): bool {
		$type = self::resolve_node_type( $node );

		if ( 'BreakdanceCustomElements\\ScosFaqs' === $type || 'ScosFaqs' === $type ) {
			$properties = [];
			if ( isset( $node['data']['properties'] ) && is_array( $node['data']['properties'] ) ) {
				$properties = $node['data']['properties'];
			} elseif ( isset( $node['properties'] ) && is_array( $node['properties'] ) ) {
				$properties = $node['properties'];
			}
			$content = isset( $properties['content'] ) && is_array( $properties['content'] ) ? $properties['content'] : [];
			$display = isset( $content['display'] )   && is_array( $content['display'] )   ? $content['display']   : [];

			$schema_enabled = array_key_exists( 'schema_enabled', $display )
				? (bool) $display['schema_enabled']
				: ( array_key_exists( 'schema_enabled', $content ) ? (bool) $content['schema_enabled'] : true );
			if ( $schema_enabled ) {
				return true;
			}
		}

		// Recurse into every nested array — tolerates unexpected wrappers.
		foreach ( $node as $value ) {
			if ( is_array( $value ) && self::bd_tree_has_scos_faqs( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve `type` from a BD node, regardless of nesting depth or shape.
	 *
	 * @since 1.4.0
	 * @param array $node
	 * @return string
	 */
	private static function resolve_node_type( array $node ): string {
		if ( isset( $node['data']['type'] ) ) {
			$t = $node['data']['type'];
		} elseif ( isset( $node['type'] ) ) {
			$t = $node['type'];
		} else {
			return '';
		}
		if ( is_string( $t ) ) {
			return $t;
		}
		if ( is_array( $t ) && isset( $t['name'] ) && is_string( $t['name'] ) ) {
			return $t['name'];
		}
		return '';
	}

	/**
	 * Recursively walk a parsed block tree and collect schema type strings.
	 *
	 * Current detections:
	 *  - `brighter/faq-selector` with enableSchema !== false → FAQPage
	 *
	 * @since 1.1.0
	 * @param array    $blocks Parsed block array.
	 * @param string[] $types  Reference — schema types are appended.
	 * @return void
	 */
	private static function scan_blocks_for_schema( array $blocks, array &$types ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name  = $block['blockName'] ?? '';
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];

			if ( 'brighter/faq-selector' === $name ) {
				// Block defaults to schema enabled; only skip when explicitly set false.
				$schema_enabled = array_key_exists( 'enableSchema', $attrs )
					? (bool) $attrs['enableSchema']
					: true;
				if ( $schema_enabled && ! empty( $attrs['selectedFaqs'] ) ) {
					$types[] = 'FAQPage';
				}
			}

			// Recurse into inner blocks (e.g. blocks nested inside Group blocks).
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::scan_blocks_for_schema( $block['innerBlocks'], $types );
			}
		}
	}

	/**
	 * Categorise links in the content as internal or external.
	 *
	 * "Internal" = same host (or relative URL).
	 * "External" = different host.
	 *
	 * @param string $html Cleaned HTML.
	 * @return array{
	 *   internal_count: int,
	 *   external_count: int,
	 *   internal_links: array<array{url:string,text:string}>,
	 *   external_links: array<array{url:string,text:string}>
	 * }
	 */
	private static function analyze_links( $html ) {
		$site_host = (string) parse_url( home_url(), PHP_URL_HOST );
		$internal  = [];
		$external  = [];

		preg_match_all(
			'/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
			$html,
			$matches,
			PREG_SET_ORDER
		);

		foreach ( $matches as $match ) {
			$url  = esc_url_raw( $match[1] );
			$text = wp_strip_all_tags( $match[2] );

			if ( empty( $url ) ) {
				continue;
			}

			// Skip anchors and javascript: links.
			if ( 0 === strpos( $url, '#' ) || 0 === strpos( $url, 'javascript' ) ) {
				continue;
			}

			$host = (string) parse_url( $url, PHP_URL_HOST );

			// Empty host = relative URL = internal.
			if ( empty( $host )
				|| $host === $site_host
				|| ( strlen( $host ) > strlen( $site_host ) && substr( $host, -( strlen( $site_host ) + 1 ) ) === '.' . $site_host )
			) {
				$internal[] = [ 'url' => $url, 'text' => $text ];
			} else {
				$external[] = [ 'url' => $url, 'text' => $text ];
			}
		}

		return [
			'internal_count' => count( $internal ),
			'external_count' => count( $external ),
			'internal_links' => $internal,
			'external_links' => $external,
		];
	}
}
