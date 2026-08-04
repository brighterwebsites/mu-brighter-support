<?php
/**
 * Third-party head scripts (Support hub).
 *
 * Single owner for the Commenter / Ahrefs tags injected into the public <head>.
 * Canonical options are se_support_script_commenter and se_support_script_ahrefs.
 * Every superseded key is folded into those once, then deleted, so clearing a
 * field in the Agency › Support settings tab actually removes the tag from the
 * head instead of falling back to a stale copy.
 *
 * @package    SiteEssentials
 * @subpackage Core
 * @version    1.0
 * @since      1.2
 *
 * v1.0 | 2026-08-02 — Consolidates se_support_ahrefs_script, ahrefs_analytics_script,
 *                     se_support_simple_commenter_script and simple_commenter_script,
 *                     replacing the duplicate wp_head output in brighter-core.
 */

namespace SiteEssentials\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, saves and renders the admin-managed third-party head scripts.
 */
class Support_Scripts {

	/**
	 * Consolidation flag. Bump the value to re-run against a new key map.
	 */
	const CONSOLIDATED_OPTION = 'site_essentials_support_scripts_consolidated';
	const CONSOLIDATED_VERSION = '1';

	/**
	 * Canonical option key => superseded keys, highest priority first.
	 *
	 * Superseded keys are read only until consolidation runs, then deleted.
	 *
	 * @return array<string, string[]>
	 */
	public static function key_map(): array {
		return [
			'se_support_script_commenter' => [ 'se_support_simple_commenter_script', 'simple_commenter_script' ],
			'se_support_script_ahrefs'    => [ 'se_support_ahrefs_script', 'ahrefs_analytics_script' ],
		];
	}

	/**
	 * Register hooks. Called from the plugin bootstrap on init.
	 *
	 * @return void
	 */
	public static function init(): void {
		self::maybe_consolidate();

		add_action( 'wp_head', [ __CLASS__, 'output' ], 10 );
	}

	/**
	 * Resolve a script slot: canonical option first, then any superseded key.
	 *
	 * @param string $canonical_key One of the keys in key_map().
	 * @return string Raw stored markup, or '' when unset.
	 */
	public static function get( string $canonical_key ): string {
		$map = self::key_map();
		if ( ! isset( $map[ $canonical_key ] ) ) {
			return '';
		}

		foreach ( array_merge( [ $canonical_key ], $map[ $canonical_key ] ) as $key ) {
			$value = get_option( $key, '' );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return '';
	}

	/**
	 * Persist script slots.
	 *
	 * Values are stored verbatim: these are code fields, and sanitize_textarea_field()
	 * would strip the <script> tags they exist to hold. The caller is responsible for
	 * the capability check and nonce verification.
	 *
	 * @param array<string, string> $values Canonical key => unslashed raw markup.
	 * @return void
	 */
	public static function save( array $values ): void {
		foreach ( array_keys( self::key_map() ) as $canonical_key ) {
			if ( ! array_key_exists( $canonical_key, $values ) ) {
				continue;
			}
			update_option( $canonical_key, trim( (string) $values[ $canonical_key ] ) );
		}
	}

	/**
	 * Render the stored tags into the public head.
	 *
	 * @return void
	 */
	public static function output(): void {
		if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		foreach ( array_keys( self::key_map() ) as $canonical_key ) {
			$markup = self::get( $canonical_key );
			if ( '' === $markup ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-stored trusted code field
			echo "\n" . $markup . "\n";
		}
	}

	/**
	 * One-time fold of superseded keys into the canonical options.
	 *
	 * Write-gated to admin and WP-CLI so a front-end request never triggers option
	 * writes. Until it runs, get() still resolves the superseded keys, so no site
	 * loses its tags in the interim.
	 *
	 * @return void
	 */
	public static function maybe_consolidate(): void {
		if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( self::CONSOLIDATED_VERSION === get_option( self::CONSOLIDATED_OPTION, '' ) ) {
			return;
		}

		foreach ( self::key_map() as $canonical_key => $superseded_keys ) {
			$resolved = self::get( $canonical_key );
			if ( '' !== $resolved ) {
				update_option( $canonical_key, $resolved );
			}
			foreach ( $superseded_keys as $old_key ) {
				delete_option( $old_key );
			}
		}

		update_option( self::CONSOLIDATED_OPTION, self::CONSOLIDATED_VERSION );
	}
}
