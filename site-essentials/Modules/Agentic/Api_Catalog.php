<?php
/**
 * Agentic — API Catalog (RFC 9727)
 *
 * v1.0 | 2026-08-04
 *
 * WHAT THIS IS FOR
 * ----------------
 * RFC 9727 defines /.well-known/api-catalog as the answer to "what APIs does
 * this organisation publish?" It is one level up from an OpenAPI document: the
 * catalog lists APIs, and each entry links out to that API's description, docs,
 * and health endpoint.
 *
 * The chain an agent follows:
 *
 *   1. GET /.well-known/api-catalog     "what APIs exist here?"
 *   2. follow service-desc              "how do I call this one?"  (OpenAPI)
 *   3. call the API                     actual work
 *
 * Publishing this before we had a real API would have been noise. Now that
 * scos/v1 exists with a genuine OpenAPI description, the catalog has something
 * true to say.
 *
 * THE LINKSET FORMAT (RFC 9264) — THE PART THAT SURPRISES PEOPLE
 * -------------------------------------------------------------
 * The body is not a hand-designed JSON shape. It is application/linkset+json,
 * a JSON serialisation of HTTP Link headers (RFC 8288). That constrains the
 * structure in ways worth understanding:
 *
 *   - The top-level key is "linkset", an array of link contexts.
 *   - Each context has an "anchor" — the URI the links are *about*.
 *   - Every other key in a context is a link relation type, and its value is
 *     an array of target objects, each with at least an "href".
 *
 * So relation names are keys, not values, and targets are always arrays even
 * when there is exactly one. That is why this looks oddly nested for what is
 * really a short list.
 *
 * Relation types used here are IANA-registered:
 *   service-desc  machine-readable description   (our OpenAPI document)
 *   service-doc   human-readable documentation   (the /mcp/ page)
 *   status        health/status resource          (our /health route)
 *
 * GOTCHAS
 * -------
 * 1. No file extension. The path is /.well-known/api-catalog, not
 *    api-catalog.json. The content type carries the format. Well_Known_Router
 *    strips trailing slashes so both forms resolve.
 *
 * 2. Content type must be application/linkset+json, not application/json.
 *    A strict validator will reject the latter.
 *
 * 3. service-doc is only advertised when the /mcp/ page actually exists.
 *    Linking to a 404 is worse than omitting the relation.
 *
 * @package    SiteEssentials
 * @subpackage Modules\Agentic
 */

namespace SiteEssentials\Modules\Agentic;

defined( 'ABSPATH' ) || exit;

class Api_Catalog {

	const PATH         = 'api-catalog';
	const CONTENT_TYPE = 'application/linkset+json';

	// ── Boot ─────────────────────────────────────────────────────────────────

	public static function init(): void {
		add_filter( 'scos_well_known_response', [ __CLASS__, 'maybe_serve' ], 10, 2 );
	}

	/**
	 * @param  array|null $response Response from an earlier handler, if any.
	 * @param  string     $path     Requested path minus the /.well-known/ prefix.
	 * @return array|null
	 */
	public static function maybe_serve( $response, string $path ) {
		if ( self::PATH !== $path ) {
			return $response;
		}

		return [
			'content' => wp_json_encode(
				self::build_linkset(),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			),
			'type'    => self::CONTENT_TYPE,
		];
	}

	// ── Document ──────────────────────────────────────────────────────────────

	public static function get_url(): string {
		return home_url( '/.well-known/' . self::PATH );
	}

	/**
	 * Build the RFC 9264 linkset describing the scos/v1 API.
	 *
	 * @return array
	 */
	public static function build_linkset(): array {
		$context = [
			// The API this set of links describes.
			'anchor' => rest_url( Content_API::NAMESPACE ),

			'service-desc' => [
				[
					'href' => OpenAPI_Spec::get_spec_url(),
					'type' => 'application/vnd.oai.openapi+json;version=3.1',
				],
			],

			'status' => [
				[
					'href' => OpenAPI_Spec::get_health_url(),
					'type' => 'application/json',
				],
			],
		];

		// Only advertise documentation that exists.
		$doc_url = self::get_service_doc_url();
		if ( '' !== $doc_url ) {
			$context['service-doc'] = [
				[
					'href'  => $doc_url,
					'type'  => 'text/html',
					'title' => 'Connect via MCP',
				],
			];
		}

		return [ 'linkset' => [ $context ] ];
	}

	/**
	 * Resolve the /mcp/ page URL, or empty string when no such page is set.
	 *
	 * Reuses the same option WebMCP_Widget keys off, so the catalog and the
	 * widget can never disagree about where the MCP page lives.
	 */
	private static function get_service_doc_url(): string {
		$page_id = absint( get_option( 'scos_agentic_webmcp_page_id', 0 ) );

		if ( $page_id < 1 ) {
			return '';
		}

		$url = get_permalink( $page_id );

		return $url ? $url : '';
	}
}
