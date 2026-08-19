<?php
/**
 * Agentic Module
 *
 * v1.2 | 2026-08-04
 *
 * Handles AI agent discovery and content accessibility for client sites.
 *
 * Content access:
 *   - Markdown content negotiation (Accept: text/markdown)
 *   - scos/v1 REST content API (search, list, content/{slug}, site-info, health)
 *   - WebMCP widget (conditionally loaded on the /mcp/ page)
 *
 * Discovery layer — how an agent finds the above without being told:
 *   - RFC 8288 Link headers (llms.txt, /mcp/, api-catalog)
 *   - OpenAPI 3.1 description of scos/v1
 *   - RFC 9727 API Catalog at /.well-known/api-catalog
 *   - Agent Skills index at /.well-known/agent-skills/index.json
 *   - MCP Server Card at /.well-known/mcp/server-card.json
 *
 * The discovery chain is deliberately layered: Link headers point at the
 * catalog, the catalog points at the OpenAPI document, and the skills index
 * carries the procedural knowledge that neither of those can express.
 *
 * @package    SiteEssentials
 * @subpackage Modules\Agentic
 * @since      1.0.0
 */

namespace SiteEssentials\Modules\Agentic;

use SiteEssentials\Core\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agentic_Module implements Module_Interface {

	// ── Module_Interface metadata ─────────────────────────────────────────────

	public static function get_id(): string {
		return 'agentic';
	}

	public static function get_name(): string {
		return __( 'Agentic', 'site-essentials' );
	}

	public static function get_description(): string {
		return __( 'AI agent discovery and content accessibility — plain-text rendering, discovery signals, and capability exposure.', 'site-essentials' );
	}

	public static function get_tier(): string {
		return 'basic';
	}

	public static function get_dependencies(): array {
		return [];
	}

	public static function get_version(): string {
		return '1.2';
	}

	// ── Lifecycle ─────────────────────────────────────────────────────────────

	public function init(): void {
		if ( ! defined( 'SCOS_AGENTIC_ACTIVE' ) ) {
			define( 'SCOS_AGENTIC_ACTIVE', true );
		}

		Markdown_Renderer::init();
		Content_API::init();
		OpenAPI_Spec::init();
		WebMCP_Widget::init();

		// Discovery layer — the router must init before its consumers register.
		Well_Known_Router::init();
		Api_Catalog::init();
		Agent_Skills::init();
		Mcp_Server_Card::init();

		add_action( 'send_headers', [ __CLASS__, 'send_link_headers' ] );
	}

	/**
	 * Emit RFC 8288 Link headers advertising the discovery resources.
	 *
	 * These are the entry point to the whole discovery chain — an agent that
	 * fetches any page gets pointed at everything else without having to guess
	 * at well-known paths.
	 *
	 * Relations used:
	 *   describedby  llms.txt          machine-readable site description
	 *   api-catalog  api-catalog       RFC 9727 catalog, leads to the OpenAPI doc
	 *   service-doc  /mcp/             human-readable connection instructions
	 *
	 * Each is only advertised when the resource actually exists. Linking to a
	 * 404 is worse than omitting the relation, because it costs the agent a
	 * request to discover the link was wrong.
	 */
	public static function send_link_headers(): void {
		$links = [];

		$llms = get_option( 'scos_llms_txt', [] );
		if ( ! empty( $llms['enabled'] ) ) {
			$links[] = '<' . esc_url( home_url( '/llms.txt' ) ) . '>; rel="describedby"';
		}

		// Always present — the catalog is served unconditionally by Api_Catalog.
		$links[] = '<' . esc_url( Api_Catalog::get_url() ) . '>; rel="api-catalog"';

		$mcp_page_id = absint( get_option( 'scos_agentic_webmcp_page_id', 0 ) );
		if ( $mcp_page_id > 0 ) {
			$mcp_url = get_permalink( $mcp_page_id );
			if ( $mcp_url ) {
				$links[] = '<' . esc_url( $mcp_url ) . '>; rel="service-doc"';
			}
		}

		foreach ( $links as $link ) {
			header( 'Link: ' . $link, false );
		}
	}

	// ── Admin ─────────────────────────────────────────────────────────────────

	public function render_settings(): void {
		$enabled        = (bool) get_option( 'scos_agentic_markdown_enabled', false );
		$webmcp_enabled = (bool) get_option( 'scos_agentic_webmcp_enabled', false );
		$webmcp_page_id = absint( get_option( 'scos_agentic_webmcp_page_id', 0 ) );
		include __DIR__ . '/views/settings.php';
	}
}
