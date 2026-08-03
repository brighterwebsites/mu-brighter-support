<?php
// v1.0 | 2026-08-03

/**
 * WooCommerce schema token resolver.
 *
 * Registers WooCommerce product tokens via the `bw_schema_resolve_variable`
 * filter exposed by brighter-core/includes/scos-schema-output.php.
 *
 * %%_woo_price%%
 *   Active price (sale price when on sale, else regular) via WC_Product::get_price().
 *
 * %%_woo_sku%%
 *   Product SKU via WC_Product::get_sku(). Equivalent meta token: %%_cmeta__sku%%.
 *
 * %%_woo_category%%
 *   First product_cat term name for the product.
 *
 * %%_woo_availability%%
 *   Schema.org availability URL from stock status
 *   (InStock / OutOfStock / BackOrder).
 *
 * %%_woo_currency%%
 *   Store currency code (e.g. AUD) via get_woocommerce_currency().
 *
 * %%_woo_offers_json%%
 *   Full schema.org Offer object (price, currency, availability, url, optional sku).
 *   Whole-value replacement returns a PHP array — same pattern as %%post_thumbnail%%.
 *
 * Logic is public/static so WP-CLI / MCP / REST can call the same methods.
 *
 * @package    SiteEssentials
 * @subpackage Modules\SiteSchema
 */

namespace SiteEssentials\Modules\SiteSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Schema_Tokens {

	const TOKEN_PRICE        = '_woo_price';
	const TOKEN_SKU          = '_woo_sku';
	const TOKEN_CATEGORY     = '_woo_category';
	const TOKEN_AVAILABILITY = '_woo_availability';
	const TOKEN_CURRENCY     = '_woo_currency';
	const TOKEN_OFFERS       = '_woo_offers_json';

	/**
	 * Map WooCommerce stock_status → schema.org availability URL.
	 *
	 * @var array<string, string>
	 */
	const AVAILABILITY_MAP = [
		'instock'     => 'https://schema.org/InStock',
		'outofstock'  => 'https://schema.org/OutOfStock',
		'onbackorder' => 'https://schema.org/BackOrder',
	];

	/**
	 * Register the token resolver filter.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'bw_schema_resolve_variable', [ self::class, 'resolve_token' ], 10, 3 );
	}

	/**
	 * Route token resolution for WooCommerce tokens.
	 *
	 * @param mixed  $value   Current resolved value (pass-through for unhandled tokens).
	 * @param string $name    Token name without %% delimiters.
	 * @param int    $post_id Current page post ID.
	 * @return mixed
	 */
	public static function resolve_token( $value, string $name, int $post_id ) {
		switch ( $name ) {
			case self::TOKEN_PRICE:
				return self::get_price( $post_id );
			case self::TOKEN_SKU:
				return self::get_sku( $post_id );
			case self::TOKEN_CATEGORY:
				return self::get_category( $post_id );
			case self::TOKEN_AVAILABILITY:
				return self::get_availability( $post_id );
			case self::TOKEN_CURRENCY:
				return self::get_currency();
			case self::TOKEN_OFFERS:
				$offers = self::get_offers( $post_id );
				return null === $offers ? '' : $offers;
			default:
				return $value;
		}
	}

	/**
	 * Active product price (sale if on sale, else regular).
	 *
	 * @param int $post_id Product post ID.
	 * @return string Empty when WooCommerce / product unavailable or price empty.
	 */
	public static function get_price( int $post_id ): string {
		$product = self::get_product( $post_id );
		if ( ! $product ) {
			return '';
		}
		$price = $product->get_price();
		return ( $price !== '' && $price !== null ) ? (string) $price : '';
	}

	/**
	 * Product SKU.
	 *
	 * @param int $post_id Product post ID.
	 * @return string
	 */
	public static function get_sku( int $post_id ): string {
		$product = self::get_product( $post_id );
		if ( ! $product ) {
			return '';
		}
		$sku = $product->get_sku();
		return is_string( $sku ) ? $sku : '';
	}

	/**
	 * First product category name.
	 *
	 * @param int $post_id Product post ID.
	 * @return string
	 */
	public static function get_category( int $post_id ): string {
		if ( $post_id <= 0 || ! taxonomy_exists( 'product_cat' ) ) {
			return '';
		}
		$terms = get_the_terms( $post_id, 'product_cat' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}
		return (string) $terms[0]->name;
	}

	/**
	 * Schema.org availability URL from WooCommerce stock status.
	 *
	 * Uses stock_status (not qty) — products with stock management off can be
	 * in stock with empty/zero quantity.
	 *
	 * @param int $post_id Product post ID.
	 * @return string Schema.org URL or empty.
	 */
	public static function get_availability( int $post_id ): string {
		$product = self::get_product( $post_id );
		if ( ! $product ) {
			return '';
		}
		$status = (string) $product->get_stock_status();
		return self::AVAILABILITY_MAP[ $status ] ?? '';
	}

	/**
	 * Store currency code (ISO 4217).
	 *
	 * @return string
	 */
	public static function get_currency(): string {
		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			return '';
		}
		$code = get_woocommerce_currency();
		return is_string( $code ) ? $code : '';
	}

	/**
	 * Build a schema.org Offer object for the product.
	 *
	 * Public for WP-CLI / MCP reuse.
	 *
	 * @param int $post_id Product post ID.
	 * @return array<string, mixed>|null Null when product or price unavailable.
	 */
	public static function get_offers( int $post_id ): ?array {
		$product = self::get_product( $post_id );
		if ( ! $product ) {
			return null;
		}

		$price = $product->get_price();
		if ( $price === '' || $price === null ) {
			return null;
		}

		$offer = [
			'@type'         => 'Offer',
			'url'           => get_permalink( $post_id ),
			'priceCurrency' => self::get_currency(),
			'price'         => (string) $price,
			'availability'  => self::get_availability( $post_id ),
			'priceValidUntil' => gmdate( 'Y-m-d', strtotime( '+1 year' ) ),
		];

		$sku = $product->get_sku();
		if ( is_string( $sku ) && $sku !== '' ) {
			$offer['sku'] = $sku;
		}

		// Drop empty optional string fields (keep price always).
		foreach ( [ 'url', 'priceCurrency', 'availability' ] as $key ) {
			if ( empty( $offer[ $key ] ) ) {
				unset( $offer[ $key ] );
			}
		}

		return $offer;
	}

	/**
	 * Load a WC_Product for a post ID, or null if WooCommerce / product missing.
	 *
	 * @param int $post_id Post ID.
	 * @return \WC_Product|null
	 */
	private static function get_product( int $post_id ) {
		if ( $post_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return null;
		}
		return $product;
	}
}
