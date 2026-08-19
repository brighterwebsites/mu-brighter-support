<?php
/**
 * Agentic — WebMCP Widget
 *
 * v1.0 | 2026-08-04
 *
 * Conditionally loads the jasonjmcghee/WebMCP widget and registers the four
 * SCOS content tools on the designated /mcp/ page only — never site-wide.
 *
 * Tools registered (executors call the scos/v1 REST endpoints from Phase 1):
 *   search_content  — keyword + optional purpose/intent filters
 *   list_content    — optional purpose/intent filters, full list
 *   get_content     — full body for one item by slug
 *   get_site_info   — static site framing
 *
 * Activation:
 *   1. Enable the toggle: scos_agentic_webmcp_enabled = 1
 *   2. Set the page ID:  scos_agentic_webmcp_page_id = <page ID of /mcp/>
 *
 * webmcp.js source: jasonjmcghee/WebMCP v0.1.13 (src/webmcp.js, MIT licence)
 * Bundled at: Modules/Agentic/assets/webmcp.js
 *
 * @package    SiteEssentials
 * @subpackage Modules\Agentic
 */

namespace SiteEssentials\Modules\Agentic;

defined( 'ABSPATH' ) || exit;

class WebMCP_Widget {

	// ── Boot ─────────────────────────────────────────────────────────────────

	public static function init(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'wp_footer',          [ __CLASS__, 'output_tool_registration' ], 20 );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function is_enabled(): bool {
		return (bool) get_option( 'scos_agentic_webmcp_enabled', false );
	}

	private static function is_mcp_page(): bool {
		$page_id = absint( get_option( 'scos_agentic_webmcp_page_id', 0 ) );

		if ( $page_id > 0 ) {
			return is_page( $page_id );
		}

		// Fallback: match slug /mcp/ if no page ID is set yet.
		return is_page( 'mcp' );
	}

	// ── Asset enqueue ─────────────────────────────────────────────────────────

	public static function enqueue(): void {
		if ( ! self::is_mcp_page() ) {
			return;
		}

		wp_enqueue_script(
			'scos-webmcp',
			plugin_dir_url( __FILE__ ) . 'assets/webmcp.js',
			[],
			'0.1.13',
			true
		);

		// Inject site URL so the inline tool registration JS can build endpoint URLs.
		wp_localize_script( 'scos-webmcp', 'scosWebMCP', [
			'restUrl' => esc_url_raw( rest_url( 'scos/v1' ) ),
			'nonce'   => '', // Public GET endpoints — no nonce needed.
		] );
	}

	// ── Tool registration ─────────────────────────────────────────────────────

	/**
	 * Output the inline JS that initialises the WebMCP widget and registers
	 * the four SCOS content tools. Only fires on the /mcp/ page.
	 */
	public static function output_tool_registration(): void {
		if ( ! self::is_mcp_page() ) {
			return;
		}

		// Widget colour — falls back to the standard BW link colour.
		$color = apply_filters( 'scos_webmcp_widget_color', '#4f46e5' );
		?>
<script>
(function () {
    var base = scosWebMCP.restUrl;

    var mcp = new WebMCP({
        color:    <?php echo wp_json_encode( $color ); ?>,
        position: 'bottom-right',
        size:     '28px',
        padding:  '16px'
    });

    // ── search_content ────────────────────────────────────────────────────────
    mcp.registerTool(
        'search_content',
        "Search Brighter Websites' service pages, case studies, and articles by keyword, optionally filtered by content type (purpose) or search intent. Returns lightweight results: title, slug, excerpt, url, purpose, and intent for each match.",
        {
            type: 'object',
            properties: {
                query: {
                    type: 'string',
                    description: 'Keyword to search for.'
                },
                purpose: {
                    type: 'string',
                    description: 'Optional. Filter by content type. Common values: service-page, pillar, case-study, supporting, authority-page, resource-guide.'
                },
                intent: {
                    type: 'string',
                    description: 'Optional. Filter by search intent. Common values: informational, commercial, transact_lead, trust.'
                }
            },
            required: ['query']
        },
        function (args) {
            var url = base + '/search?query=' + encodeURIComponent(args.query || '');
            if (args.purpose) url += '&purpose=' + encodeURIComponent(args.purpose);
            if (args.intent)  url += '&intent='  + encodeURIComponent(args.intent);
            return fetch(url).then(function (r) { return r.json(); });
        }
    );

    // ── list_content ──────────────────────────────────────────────────────────
    mcp.registerTool(
        'list_content',
        "List all published content from Brighter Websites, optionally filtered by content type (purpose) or search intent. Use this to get a full picture of available topics before drilling into a specific piece.",
        {
            type: 'object',
            properties: {
                purpose: {
                    type: 'string',
                    description: 'Optional. Filter by content type: service-page, pillar, case-study, supporting, authority-page, resource-guide, conversion-hub, etc.'
                },
                intent: {
                    type: 'string',
                    description: 'Optional. Filter by search intent: informational, commercial, transact_lead, trust, navigational.'
                }
            }
        },
        function (args) {
            var url = base + '/list';
            var params = [];
            if (args.purpose) params.push('purpose=' + encodeURIComponent(args.purpose));
            if (args.intent)  params.push('intent='  + encodeURIComponent(args.intent));
            if (params.length) url += '?' + params.join('&');
            return fetch(url).then(function (r) { return r.json(); });
        }
    );

    // ── get_content ───────────────────────────────────────────────────────────
    mcp.registerTool(
        'get_content',
        'Retrieve the full text content of a specific Brighter Websites page or article by its slug. Use search_content or list_content first to find the slug you need.',
        {
            type: 'object',
            properties: {
                slug: {
                    type: 'string',
                    description: 'The URL slug of the page or post (e.g. "website-design-ballarat" or "what-is-altc").'
                }
            },
            required: ['slug']
        },
        function (args) {
            return fetch(base + '/content/' + encodeURIComponent(args.slug))
                .then(function (r) { return r.json(); });
        }
    );

    // ── get_site_info ─────────────────────────────────────────────────────────
    mcp.registerTool(
        'get_site_info',
        'Get context about Brighter Websites — who we are, what we do, who we serve, our services and topic areas. Call this first to understand the site before using other tools.',
        {
            type: 'object',
            properties: {}
        },
        function () {
            return fetch(base + '/site-info').then(function (r) { return r.json(); });
        }
    );

}());
</script>
		<?php
	}
}
