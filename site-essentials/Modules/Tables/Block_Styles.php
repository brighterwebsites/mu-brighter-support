<?php
/**
 * Table block styles
 *
 * Registers the layout choices that appear in the block sidebar for a
 * Gutenberg table, and translates the class Gutenberg emits into the
 * behaviour classes the stylesheet is written against.
 *
 * Gutenberg emits `is-style-{name}` and we cannot rename that, so rather than
 * duplicating every rule for both selectors, one render_block pass appends the
 * plain classes. The stylesheet stays written against `.bw-stack` alone.
 *
 * @package    SiteEssentials
 * @subpackage Modules\Tables
 * @since      1.0.0
 *
 * v1.0 | 2026-08-28
 */

namespace SiteEssentials\Modules\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Block_Styles {

	/** Registered style name => behaviour classes it stands for. */
	const STYLES = [
		'bw-stack'        => 'bw-stack',
		'bw-stack-labels' => 'bw-stack bw-labels',
		'bw-cards'        => 'bw-cards',
		'bw-cards-labels' => 'bw-cards bw-labels',
	];

	public static function init() {
		add_action( 'init',                 [ self::class, 'register' ] );
		add_filter( 'render_block_core/table', [ self::class, 'add_behaviour_classes' ], 10, 2 );
	}

	/**
	 * Register the four named layouts. Scroll is the default and needs no
	 * style — it is what a table with no style selected already does.
	 */
	public static function register() {
		if ( ! function_exists( 'register_block_style' ) ) {
			return;
		}

		$labels = [
			'bw-stack'        => __( 'Stacked', 'site-essentials' ),
			'bw-stack-labels' => __( 'Stacked + labels', 'site-essentials' ),
			'bw-cards'        => __( 'Cards', 'site-essentials' ),
			'bw-cards-labels' => __( 'Cards + labels', 'site-essentials' ),
		];

		foreach ( $labels as $name => $label ) {
			register_block_style( 'core/table', [
				'name'  => $name,
				'label' => $label,
			] );
		}
	}

	/**
	 * Append the behaviour classes alongside the is-style-* class Gutenberg
	 * wrote, on the figure that carries it.
	 *
	 * @param string $content Rendered block HTML.
	 * @param array  $block   Parsed block.
	 * @return string
	 */
	public static function add_behaviour_classes( $content, $block ) {
		$class_attr = isset( $block['attrs']['className'] ) ? $block['attrs']['className'] : '';
		if ( '' === $class_attr || false === strpos( $class_attr, 'is-style-bw-' ) ) {
			return $content;
		}

		$add = [];
		foreach ( self::STYLES as $name => $classes ) {
			// Trailing (?![\w-]) rather than \b: a plain word boundary matches
			// inside is-style-bw-stack-labels when testing for is-style-bw-stack.
			if ( preg_match( '/(?<![\w-])is-style-' . preg_quote( $name, '/' ) . '(?![\w-])/', $class_attr ) ) {
				$add = array_merge( $add, explode( ' ', $classes ) );
			}
		}

		if ( ! $add ) {
			return $content;
		}

		// Only touch the opening figure tag — the first tag in a table block's
		// markup, and the element Gutenberg puts the class on.
		return preg_replace_callback(
			'/^(\s*<figure\b[^>]*\bclass=")([^"]*)(")/',
			static function ( $m ) use ( $add ) {
				$existing = preg_split( '/\s+/', trim( $m[2] ), -1, PREG_SPLIT_NO_EMPTY );
				$merged   = array_unique( array_merge( $existing, $add ) );
				return $m[1] . esc_attr( implode( ' ', $merged ) ) . $m[3];
			},
			$content,
			1
		);
	}
}
