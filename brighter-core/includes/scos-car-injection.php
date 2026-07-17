<?php
/**
 * SCOS Content Architecture Record (CAR) Injection
 *
 * Outputs window.brighterSCOS into <head> on every page.
 * Reads from scos_ca_* meta keys and scos_* taxonomies.
 * altc_strategic_lens / altc_topic taxonomy fallbacks retained (term relationships still valid).
 *
 * Structure:
 *   car     — semantic intent, topical authority, metrics
 *   meta    — post ID, type, version, timestamp
 *
 * The legacy `tracking` block has been removed — GA4 config is managed
 * entirely by the GA4 scripts and does not belong in the CAR.
 *
 * @package BrighterCore
 * @subpackage Analytics
 *
 * v1.1 | 2026-05-22 — search-intent now resolved via Intent_Goal_Resolver when available,
 *                      so FAQ-linked posts output the FAQ title rather than the raw meta value.
 * v1.2 | 2026-06-24 — restructured CAR keys: cluster → known-for-goal (title + description),
 *                      maturity → topic-maturity, search-intent → answers, pillar removed,
 *                      service_pathway (id/title) → commercial-end-target (url/title).
 * v1.3 | 2026-07-15 — Remove all bw_* meta-key fallbacks; migration confirmed complete.
 *                      altc_strategic_lens and altc_topic taxonomy fallbacks retained (term data still valid).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Inject SCOS CAR into <head>.
 * Priority 5 — loads before GA4 tracking scripts (priority 99).
 */
add_action( 'wp_head', function () {

	// Only output CAR when Content Architecture module is active.
	// This constant is defined at init priority 5 by ContentArchitecture_Module.
	if ( ! defined( 'SCOS_CA_ACTIVE' ) ) { return; }

	// ── Resolve post ID ──────────────────────────────────────────────────────
	$post_id = null;
	if ( is_singular() ) {
		$post_id = get_the_ID();
	} elseif ( is_front_page() ) {
		$front_page_id = (int) get_option( 'page_on_front' );
		if ( $front_page_id ) {
			$post_id = $front_page_id;
		}
	}

	// ── Minimal CAR for archives / blog home ─────────────────────────────────
	if ( ! $post_id ) {
		$scos = [
			'car'  => [
				'known-for-goal'        => [ 'title' => 'not_set', 'description' => 'not_set' ],
				'topic'                 => 'not_set',
				'topic-maturity'        => 'not_set',
				'intent'                => 'not_set',
				'answers'               => 'not_set',
				'purpose'               => 'not_set',
				'commercial-end-target' => null,
			],
			'meta' => [
				'post_id'       => 0,
				'post_type'     => get_post_type() ?: 'archive',
				'scos_version'  => defined( 'SCOS_VERSION' ) ? SCOS_VERSION : '4.4.0',
				'car_generated' => current_time( 'c' ),
			],
		];
		scos_output_car( $scos );
		return;
	}

	// ── Helper: scos_ key first, bw_ key fallback ────────────────────────────
	$val = function ( $scos_key, $legacy_key = null ) use ( $post_id ) {
		$v = get_post_meta( $post_id, $scos_key, true );
		if ( $v !== '' && $v !== null && $v !== false ) {
			return $v;
		}
		if ( $legacy_key ) {
			$v = get_post_meta( $post_id, $legacy_key, true );
			return ( $v !== '' && $v !== null && $v !== false ) ? $v : 'not_set';
		}
		return 'not_set';
	};

	// ── Cluster (scos_content_cluster taxonomy → legacy altc_strategic_lens) ─
	$cluster_name        = 'not_set';
	$cluster_description = 'not_set';
	$cluster_terms = wp_get_post_terms( $post_id, 'scos_content_cluster' );
	if ( ! is_wp_error( $cluster_terms ) && ! empty( $cluster_terms ) ) {
		$cluster_name        = $cluster_terms[0]->name;
		$cluster_description = $cluster_terms[0]->description ?: 'not_set';
	} else {
		// Taxonomy-based legacy fallback (altc_strategic_lens term relationships are still valid)
		$legacy_terms = wp_get_post_terms( $post_id, 'altc_strategic_lens' );
		if ( ! is_wp_error( $legacy_terms ) && ! empty( $legacy_terms ) ) {
			$cluster_name        = $legacy_terms[0]->name;
			$cluster_description = $legacy_terms[0]->description ?: 'not_set';
		}
	}

	// ── Topic (scos_topic taxonomy → legacy altc_topic) ──────────────────────
	$topic_name = 'not_set';
	$topic_terms = wp_get_post_terms( $post_id, 'scos_topic', [ 'fields' => 'names' ] );
	if ( ! is_wp_error( $topic_terms ) && ! empty( $topic_terms ) ) {
		$topic_name = $topic_terms[0];
	} else {
		// Taxonomy-based legacy fallback (altc_topic term relationships are still valid)
		$legacy_terms = wp_get_post_terms( $post_id, 'altc_topic', [ 'fields' => 'names' ] );
		if ( ! is_wp_error( $legacy_terms ) && ! empty( $legacy_terms ) ) {
			$topic_name = $legacy_terms[0];
		}
	}

	// ── Commercial end target (formerly service_pathway) ─────────────────────
	$commercial_end_target = null;
	$service_pathway_id = (int) get_post_meta( $post_id, 'scos_ca_service_pathway_id', true );
	if ( $service_pathway_id > 0 ) {
		$commercial_end_target = [
			'url'   => get_permalink( $service_pathway_id ),
			'title' => get_the_title( $service_pathway_id ),
		];
	}

	// ── Content metrics ───────────────────────────────────────────────────────
	$metrics = [
		'word_count'     => (int) get_post_meta( $post_id, 'scos_ca_word_count',        true ),
		'reading_time'   => (int) get_post_meta( $post_id, 'scos_ca_reading_time',      true ),
		'internal_links' => (int) get_post_meta( $post_id, 'scos_ca_links_to_internal', true ),
		'external_links' => (int) get_post_meta( $post_id, 'scos_ca_links_to_external', true ),
		'last_updated'   => get_the_modified_date( 'Y-m-d', $post_id ),
	];

	// ── Assemble CAR ─────────────────────────────────────────────────────────
	// ── Search intent resolution ─────────────────────────────────────────────
	// Use Intent_Goal_Resolver when available (site-essentials CA module active).
	if ( class_exists( 'SiteEssentials\\Modules\\ContentArchitecture\\Intent_Goal_Resolver' ) ) {
		$search_intent = SiteEssentials\Modules\ContentArchitecture\Intent_Goal_Resolver::resolve_question( $post_id );
		if ( '' === $search_intent ) {
			$search_intent = 'not_set';
		}
	} else {
		$search_intent = $val( 'scos_ca_intent_goal' );
	}

	$scos = [
		'car' => [
			'known-for-goal'        => [
				'title'       => $cluster_name,
				'description' => $cluster_description,
			],
			'topic'                 => $topic_name,
		'topic-maturity'        => $val( 'scos_ca_maturity' ),
		'intent'                => $val( 'scos_ca_intent' ),
		'answers'               => $search_intent,
		'purpose'               => $val( 'scos_ca_purpose' ),
			'commercial-end-target' => $commercial_end_target,
			'metrics'               => $metrics,
		],
		'meta' => [
			'post_id'       => $post_id,
			'post_type'     => get_post_type( $post_id ),
			'scos_version'  => defined( 'SCOS_VERSION' ) ? SCOS_VERSION : '4.4.0',
			'car_generated' => current_time( 'c' ),
		],
	];

	scos_output_car( $scos );

}, 5 );

/**
 * Output the window.brighterSCOS script tag.
 *
 * @param array $scos Data structure to JSON-encode.
 */
if ( ! function_exists( 'scos_output_car' ) ) :
function scos_output_car( array $scos ) {
	echo "\n" . '<script data-no-optimize="1" data-cfasync="false" data-litespeed-no-optimize="1">' . "\n";
	echo '// SCOS Content Architecture Record — semantic intent and topical authority mapping.' . "\n";
	echo 'window.scosCAR = ' . wp_json_encode( $scos, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . ';' . "\n";
	echo '</script>' . "\n";
}
endif;
