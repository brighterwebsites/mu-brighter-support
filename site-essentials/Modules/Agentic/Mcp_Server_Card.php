<?php
/**
 * Agentic — MCP Server Card (SEP-1649)
 *
 * v1.0 | 2026-08-04
 *
 * WHAT THIS IS FOR
 * ----------------
 * An MCP Server Card at /.well-known/mcp/server-card.json is MCP-specific
 * discovery: "this origin offers MCP tools, here is how to reach them and what
 * they do." Where the API Catalog covers HTTP APIs generally, this covers the
 * MCP transport specifically.
 *
 * HONEST CAVEAT — READ BEFORE TRUSTING THIS FILE
 * ----------------------------------------------
 * The card's natural shape assumes a *hosted* MCP server: a URL a client can
 * connect to directly. This site does not have one. Its WebMCP implementation
 * is the browser-relay pattern — tools are bridged out of an open browser tab
 * through a WebSocket relay running on the user's own machine.
 *
 * That means there is no endpoint to advertise. Writing one in would be a lie,
 * and a card that lies is worse than no card, because a client will attempt the
 * connection and fail rather than choosing a path that works.
 *
 * So this card declares transport type "browser-relay", omits any endpoint, and
 * points at the /mcp/ page plus the REST API as the two things a consumer can
 * actually use. A client that only understands hosted transports will correctly
 * conclude it cannot connect directly — which is the truth.
 *
 * 2. SPEC IS UNSTABLE. SEP-1649 is an open PR against the MCP specification
 *    (modelcontextprotocol PR #2127), not a ratified standard. The schema will
 *    change. Field names here reflect the PR as of 2026-08 and should be
 *    re-checked before relying on them. This is a deliberate bet on an
 *    in-flight spec, not a stable integration.
 *
 * 3. Registry discovery does not exist yet. Nothing crawls the open web for
 *    these files today. Publishing it is positioning for when something does,
 *    not a traffic source now.
 *
 * @package    SiteEssentials
 * @subpackage Modules\Agentic
 */

namespace SiteEssentials\Modules\Agentic;

defined( 'ABSPATH' ) || exit;

class Mcp_Server_Card {

	const PATH = 'mcp/server-card.json';

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
				self::build_card(),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			),
			'type'    => 'application/json',
		];
	}

	// ── Document ──────────────────────────────────────────────────────────────

	public static function get_url(): string {
		return home_url( '/.well-known/' . self::PATH );
	}

	/**
	 * Build the server card.
	 *
	 * @return array
	 */
	public static function build_card(): array {
		$card = [
			'serverInfo' => [
				'name'        => sanitize_title( get_bloginfo( 'name' ) ) . '-webmcp',
				'title'       => get_bloginfo( 'name' ) . ' Content Tools',
				'version'     => Agentic_Module::get_version(),
				'description' => 'Read-only content tools for this site: site context, content listing, keyword search, and full-record retrieval. Filterable by content purpose and search intent.',
			],

			// No endpoint: tools are bridged from a browser tab via a local relay,
			// so there is nothing for a client to connect to directly.
			'transport'  => [
				'type'          => 'browser-relay',
				'implementation' => 'jasonjmcghee/WebMCP',
				'note'          => 'Tools are exposed from an open browser session through a WebSocket relay on the client machine. There is no publicly reachable MCP endpoint. See instructions at the documentation URL, or use the REST API for direct programmatic access.',
			],

			'capabilities' => [
				'tools'     => self::tool_declarations(),
				'resources' => new \stdClass(), // none
				'prompts'   => new \stdClass(), // none
			],

			// Where a consumer should actually go.
			'links' => array_values( array_filter( [
				self::link( 'documentation', self::get_doc_url() ),
				self::link( 'rest-api', rest_url( Content_API::NAMESPACE ) ),
				self::link( 'openapi', OpenAPI_Spec::get_spec_url() ),
				self::link( 'agent-skills', Agent_Skills::get_index_url() ),
			] ) ),
		];

		return $card;
	}

	/**
	 * Tool declarations mirroring what WebMCP_Widget registers in the browser.
	 *
	 * Kept descriptive rather than exhaustive — the OpenAPI document is the
	 * authoritative parameter contract, and this card links to it.
	 *
	 * @return array
	 */
	private static function tool_declarations(): array {
		return [
			[
				'name'        => 'get_site_info',
				'description' => 'Site context: what this business does, who it serves, services and topic areas. Call first.',
			],
			[
				'name'        => 'list_content',
				'description' => 'List published content, optionally filtered by purpose or search intent.',
			],
			[
				'name'        => 'search_content',
				'description' => 'Keyword search across published content, optionally filtered by purpose or search intent.',
			],
			[
				'name'        => 'get_content',
				'description' => 'Full record for one item by slug.',
			],
		];
	}

	/**
	 * Build a link entry, or null when the target does not exist.
	 *
	 * @param  string $rel
	 * @param  string $href
	 * @return array|null
	 */
	private static function link( string $rel, string $href ) {
		if ( '' === $href ) {
			return null;
		}

		return [
			'rel'  => $rel,
			'href' => $href,
		];
	}

	/**
	 * The /mcp/ page URL, or empty string when unset.
	 */
	private static function get_doc_url(): string {
		$page_id = absint( get_option( 'scos_agentic_webmcp_page_id', 0 ) );

		if ( $page_id < 1 ) {
			return '';
		}

		$url = get_permalink( $page_id );

		return $url ? $url : '';
	}
}
