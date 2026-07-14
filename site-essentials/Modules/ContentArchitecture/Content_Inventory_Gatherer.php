<?php
/**
 * Content Inventory Gatherer — Single-pass content export
 *
 * Gathers full WordPress content inventory for analysis and reporting.
 * Supports incremental collection via ?since= parameter.
 *
 * Performance: One server-side pass with batch meta loading, no N+1 queries.
 *
 * v1.0 | 2026-06-06
 *
 * @package    SiteEssentials
 * @subpackage Modules\ContentArchitecture
 */

namespace SiteEssentials\Modules\ContentArchitecture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Content_Inventory_Gatherer {

	/**
	 * Post types to skip (internal/system types).
	 */
	private const SKIP_POST_TYPES = [
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
	];

	/**
	 * Gather full content inventory, optionally filtered by modification time.
	 *
	 * @param string|null $since ISO 8601 timestamp. If set, only return posts where
	 *                           MAX(post_modified, scos_ca_last_analyzed) >= since.
	 * @return array Inventory structure with meta and posts array.
	 */
	public static function gather( $since = null ) {
		global $wpdb;

		// ──────────────────────────────────────────────────────────────────────
		// Step 1: Determine which post types to include
		// ──────────────────────────────────────────────────────────────────────

		$all_post_types = get_post_types( [], 'objects' );
		$found          = [];
		$included       = [];
		$excluded       = [];

		foreach ( $all_post_types as $name => $obj ) {
			$found[] = $name;
		}

		foreach ( $all_post_types as $name => $obj ) {
			// Skip internal types.
			if ( in_array( $name, self::SKIP_POST_TYPES, true ) ) {
				$excluded[] = $name;
				continue;
			}

			// Only include post, page, or public types.
			$is_public = ! empty( $obj->public );
			if ( 'post' !== $name && 'page' !== $name && ! $is_public ) {
				$excluded[] = $name;
				continue;
			}

			// Check if there are published posts of this type.
			$counts = wp_count_posts( $name );
			$cnt    = isset( $counts->publish ) ? (int) $counts->publish : 0;
			if ( $cnt > 0 ) {
				$included[] = $name;
			} else {
				$excluded[] = $name;
			}
		}

		// ──────────────────────────────────────────────────────────────────────
		// Step 2: Analysis prefix — scos_ca_ is the only canonical set
		// ──────────────────────────────────────────────────────────────────────

		$prefix = 'scos_ca_';

		// ──────────────────────────────────────────────────────────────────────
		// Step 3: Query posts with optional since filter
		// ──────────────────────────────────────────────────────────────────────

		$query_args = [
			'post_type'        => $included,
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'orderby'          => 'modified',
			'order'            => 'DESC',
			'suppress_filters' => true,
		];

		// Build where clause for since filter if provided.
		if ( ! empty( $since ) ) {
			$since_sanitized = gmdate( 'Y-m-d H:i:s', strtotime( $since ) );
			$query_args['meta_query'] = [
				'relation' => 'OR',
				[
					'key'     => $prefix . 'last_analyzed',
					'value'   => $since_sanitized,
					'compare' => '>=',
					'type'    => 'DATETIME',
				],
			];

			// We also need posts where post_modified >= since.
			// WP_Query doesn't have a built-in post_modified >= filter,
			// so we'll filter manually below after fetching all posts.
		}

		$posts = get_posts( $query_args );

		// ──────────────────────────────────────────────────────────────────────
		// Step 4: Batch-load all meta for N+1 prevention
		// ──────────────────────────────────────────────────────────────────────

		$post_ids = wp_list_pluck( $posts, 'ID' );
		if ( ! empty( $post_ids ) ) {
			update_meta_cache( 'post', $post_ids );
		}

		// ──────────────────────────────────────────────────────────────────────
		// Step 5: Build inventory for each post
		// ──────────────────────────────────────────────────────────────────────

		$posts_out = [];
		$is_bw     = 'bw_' === $prefix;

		foreach ( $posts as $post ) {
			$pid = $post->ID;

			// Apply since filter on post_modified if provided.
			if ( ! empty( $since ) ) {
				$since_sanitized = gmdate( 'Y-m-d H:i:s', strtotime( $since ) );
				$post_modified   = $post->post_modified;
				$last_analyzed   = get_post_meta( $pid, $prefix . 'last_analyzed', true );

				// Only include if post_modified >= since OR last_analyzed >= since.
				if ( $post_modified < $since_sanitized && $last_analyzed < $since_sanitized ) {
					continue;
				}
			}

			$slug            = $post->post_name;
			$last_analyzed   = get_post_meta( $pid, $prefix . 'last_analyzed', true );
			$analysis_status = $last_analyzed ? 'complete' : 'pending';

			// Get taxonomy terms.
			$cluster_terms = wp_get_object_terms( $pid, 'scos_content_cluster', [ 'fields' => 'names' ] );
			$cluster       = ( ! is_wp_error( $cluster_terms ) && ! empty( $cluster_terms ) ) ? implode( ', ', $cluster_terms ) : '';

			$topic_terms = wp_get_object_terms( $pid, 'scos_topic', [ 'fields' => 'names' ] );
			$topic       = ( ! is_wp_error( $topic_terms ) && ! empty( $topic_terms ) ) ? implode( ', ', $topic_terms ) : '';

			// Build URLs.
			$rel             = '';
			$pl              = get_permalink( $pid );
			if ( $pl && ! is_wp_error( $pl ) ) {
				$rel = wp_make_link_relative( $pl );
			}
			if ( '' === $rel && $slug ) {
				$rel = '/' . $slug . '/';
			}

			$production_domain = get_option( 'siteurl' );
			$production_url    = $rel ? ( rtrim( $production_domain, '/' ) . $rel ) : null;
			$gsc_url           = $production_url
				? 'https://search.google.com/search-console/performance/search-analytics?resource_id=' . urlencode( $production_url )
				: null;
			$ga4_path          = $rel ?: null;

			// Helper: convert empty/null values to null.
			$nn = function( $v ) {
				return ( '' === $v || null === $v ) ? null : $v;
			};

			// Prefer the pre-computed rendered markdown (scos_ca_content_md) — it is
			// the fully rendered page (resolved ACF, dynamic fields, Query Loops,
			// Post Repeaters, image URLs) and avoids an expensive live render per row.
			// Fall back to live aggregation only when the markdown hasn't been built yet.
			$content = get_post_meta( $pid, 'scos_ca_content_md', true );
			if ( '' === $content || null === $content ) {
				if ( class_exists( 'BW_Content_Analysis' ) && method_exists( 'BW_Content_Analysis', 'get_aggregated_content' ) ) {
					$content = \BW_Content_Analysis::get_aggregated_content( $pid );
				} else {
					$content = $post->post_content;
				}
			}

			// Build post record with all fields
			$post_record = [
				'id'                           => (int) $pid,
				'title'                        => $post->post_title,
				'slug'                         => $slug,
				'post_type'                    => $post->post_type,
				'post_date'                    => $post->post_date,
				'post_modified'                => $post->post_modified,
				'analysis_status'              => $analysis_status,

				// Live aggregated content (Breakdance + ACF + editor)
				'content'                      => $nn( $content ),

				// Analysis metrics
				'word_count'                   => $nn( get_post_meta( $pid, $prefix . 'word_count', true ) ),
				'h2_count'                     => $nn( get_post_meta( $pid, $prefix . 'h2_count', true ) ),
				'image_count'                  => $nn( get_post_meta( $pid, $prefix . 'image_count', true ) ),
				'reading_time'                 => $nn( get_post_meta( $pid, $prefix . 'reading_time', true ) ),
				'internal_link_count'          => $nn( get_post_meta( $pid, $prefix . 'links_to_internal', true ) ),
				'external_link_count'          => $nn( get_post_meta( $pid, $prefix . 'links_to_external', true ) ),
				'last_analyzed'                => $nn( $last_analyzed ),

				// Content Architecture — Strategy
				'scos_ca_intent'               => $is_bw ? null : $nn( get_post_meta( $pid, 'scos_ca_intent', true ) ),
				'scos_ca_intent_goal'          => $is_bw ? null : $nn( get_post_meta( $pid, 'scos_ca_intent_goal', true ) ),
				'scos_ca_purpose'              => $is_bw ? null : $nn( get_post_meta( $pid, 'scos_ca_purpose', true ) ),
				'scos_ca_maturity'             => $is_bw ? null : $nn( get_post_meta( $pid, 'scos_ca_maturity', true ) ),

				// Content Architecture — Relationships
				'scos_ca_pillar_page_id'       => $is_bw ? null : $nn( get_post_meta( $pid, 'scos_ca_pillar_page_id', true ) ),
				'scos_ca_service_pathway_id'   => $is_bw ? null : $nn( get_post_meta( $pid, 'scos_ca_service_pathway_id', true ) ),
				'scos_ca_intent_goal_faq_id'   => $is_bw ? null : $nn( get_post_meta( $pid, 'scos_ca_intent_goal_faq_id', true ) ),

				// Content Architecture — Taxonomy (supporting topics as array)
				'scos_ca_supporting_topics'    => $is_bw ? null : $nn( get_post_meta( $pid, 'scos_ca_supporting_topics', true ) ),

				// Content Architecture — Workflow
				'scos_ca_index_status'         => $is_bw ? null : $nn( get_post_meta( $pid, 'scos_ca_index_status', true ) ),
				'scos_ca_optimization_progress' => $is_bw ? null : $nn( get_post_meta( $pid, 'scos_ca_optimization_progress', true ) ),
				'scos_ca_next_step'            => $is_bw ? null : $nn( get_post_meta( $pid, 'scos_ca_next_step', true ) ),

				// Content Architecture — Advanced Analysis
				'scos_ca_links_to_internal_list' => $is_bw ? null : $nn( get_post_meta( $pid, 'scos_ca_links_to_internal_list', true ) ),
				'scos_ca_links_to_external_list' => $is_bw ? null : $nn( get_post_meta( $pid, 'scos_ca_links_to_external_list', true ) ),
				'scos_ca_reading_time_iso'    => $is_bw ? null : $nn( get_post_meta( $pid, 'scos_ca_reading_time_iso', true ) ),
				'scos_ca_schema_track'         => $is_bw ? null : $nn( get_post_meta( $pid, 'scos_ca_schema_track', true ) ),

				// SEO Meta
				'scos_seo_title'               => $nn( get_post_meta( $pid, 'scos_seo_title', true ) ),
				'scos_seo_description'         => $nn( get_post_meta( $pid, 'scos_seo_description', true ) ),
				'scos_seo_tldr'                => $nn( get_post_meta( $pid, 'scos_seo_tldr', true ) ),
				'scos_seo_robots'              => $nn( get_post_meta( $pid, 'scos_seo_robots', true ) ),
				'scos_seo_canonical'           => $nn( get_post_meta( $pid, 'scos_seo_canonical', true ) ),
				'scos_seo_breadcrumb_title'    => $nn( get_post_meta( $pid, 'scos_seo_breadcrumb_title', true ) ),
				'scos_seo_freeze_og_date'      => $nn( get_post_meta( $pid, 'scos_seo_freeze_og_date', true ) ),
				'scos_seo_sitemap_exclude'     => $nn( get_post_meta( $pid, 'scos_seo_sitemap_exclude', true ) ),
				'scos_seo_sitemap_noindex_override' => $nn( get_post_meta( $pid, 'scos_seo_sitemap_noindex_override', true ) ),
				'scos_seo_isnoindex'           => $nn( get_post_meta( $pid, 'scos_seo_isnoindex', true ) ),

				// Social Amplification
				'scos_sa_shortlink_slug'       => $nn( get_post_meta( $pid, 'scos_sa_shortlink_slug', true ) ),

				// Taxonomies (clustered)
				'cluster'                      => $nn( $cluster ),
				'topic'                        => $nn( $topic ),

				// URLs
				'production_url'               => $production_url,
				'gsc_url'                      => $gsc_url,
				'ga4_path'                     => $ga4_path,
			];

			$posts_out[] = $post_record;
		}

		// ──────────────────────────────────────────────────────────────────────
		// Step 6: Build response payload
		// ──────────────────────────────────────────────────────────────────────

		$total           = count( $posts_out );
		$pending_count   = 0;
		foreach ( $posts_out as $p ) {
			if ( 'pending' === $p['analysis_status'] ) {
				$pending_count++;
			}
		}
		$complete_count = $total - $pending_count;

		return [
			'meta' => [
				'collected_at'              => current_time( 'c' ),
				'total_posts_included'      => $total,
				'analysis_complete_count'   => $complete_count,
				'analysis_pending_count'    => $pending_count,
				'content_analysis_prefix'   => $prefix,
				'wp_post_types_found'       => $found,
				'wp_post_types_included'    => $included,
				'wp_post_types_excluded'    => $excluded,
			],
			'posts' => $posts_out,
		];
	}
}
