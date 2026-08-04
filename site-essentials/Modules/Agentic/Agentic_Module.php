<?php
/**
 * Agentic Module
 *
 * v1.1 | 2026-08-04
 *
 * Handles AI agent discovery and content accessibility for client sites.
 *   - Markdown content negotiation (Accept: text/markdown)
 *   - scos/v1 REST content API (search, list, content/{slug}, site-info)
 *   - RFC 8288 Link headers for agent discovery
 *   - WebMCP widget (conditionally loaded on the /mcp/ page)
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
		return '1.1';
	}

	// ── Lifecycle ─────────────────────────────────────────────────────────────

	public function init(): void {
		if ( ! defined( 'SCOS_AGENTIC_ACTIVE' ) ) {
			define( 'SCOS_AGENTIC_ACTIVE', true );
		}

		Markdown_Renderer::init();
		Content_API::init();
		WebMCP_Widget::init();
		add_action( 'send_headers', [ __CLASS__, 'send_link_headers' ] );
	}

	/**
	 * Emit RFC 8288 Link headers so agents can discover llms.txt and the /mcp/ page.
	 *
	 * llms.txt: rel="describedby" — points to the machine-readable site description.
	 * /mcp/:    rel="service-doc" — points to the human+agent-readable MCP hub page.
	 *
	 * Headers are only emitted when the respective resources are actually present:
	 * - llms.txt only when the scos_llms_txt option has it enabled.
	 * - /mcp/ only when scos_agentic_webmcp_page_id option is set to a valid page ID.
	 */
	public static function send_link_headers(): void {
		$links = [];

		$llms = get_option( 'scos_llms_txt', [] );
		if ( ! empty( $llms['enabled'] ) ) {
			$links[] = '<' . esc_url( home_url( '/llms.txt' ) ) . '>; rel="describedby"';
		}

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
