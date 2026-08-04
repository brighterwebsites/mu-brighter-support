<?php
/**
 * Agentic — OpenAPI 3.1 Description + Health Endpoint
 *
 * v1.0 | 2026-08-04
 *
 * WHAT THIS IS FOR
 * ----------------
 * An OpenAPI document is a machine-readable contract for an HTTP API: paths,
 * parameters, response shapes, types. Agents and tooling read it to work out
 * how to call an API without a human reading docs first.
 *
 * This is the piece that makes /.well-known/api-catalog worth publishing. A
 * catalog whose service-desc link points at nothing is just an announcement
 * that an API exists. Pointing it at a real OpenAPI document means an agent
 * can go from "this site has an API" to "here is exactly how to query it"
 * in two requests.
 *
 * Routes added:
 *   GET /wp-json/scos/v1/openapi.json  — the OpenAPI 3.1 document
 *   GET /wp-json/scos/v1/health        — liveness probe (the catalog's status rel)
 *
 * WHY HAND-AUTHORED RATHER THAN GENERATED
 * ---------------------------------------
 * WordPress can partially self-describe: rest_get_server()->get_routes()
 * exposes registered args, and an OPTIONS request returns a schema. But that
 * output describes *parameters* well and *response bodies* not at all, and
 * it carries none of the prose that makes a spec useful to an agent deciding
 * whether to call something.
 *
 * So the document is authored here as a PHP array. The trade-off is real: it
 * can drift from Content_API if routes change and this file doesn't. The
 * mitigation is that both live in the same module, and the namespace is read
 * from Content_API::NAMESPACE rather than repeated as a literal.
 *
 * NOTE ON VERSIONS
 * ----------------
 * OpenAPI 3.1 is used because it aligns with JSON Schema 2020-12, so the
 * schema objects here are valid JSON Schema. 3.0 used a divergent subset and
 * would need "nullable" instead of type arrays. If a consumer rejects this
 * document, check whether it only speaks 3.0.
 *
 * @package    SiteEssentials
 * @subpackage Modules\Agentic
 */

namespace SiteEssentials\Modules\Agentic;

defined( 'ABSPATH' ) || exit;

class OpenAPI_Spec {

	// ── Boot ─────────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route( Content_API::NAMESPACE, '/openapi.json', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'handle_spec' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( Content_API::NAMESPACE, '/health', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'handle_health' ],
			'permission_callback' => '__return_true',
		] );
	}

	// ── Handlers ──────────────────────────────────────────────────────────────

	public static function handle_spec( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( self::build_spec(), 200 );
	}

	/**
	 * Liveness probe. Deliberately minimal — it must not touch the database,
	 * or it stops being a useful signal about whether the API layer is up.
	 */
	public static function handle_health( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( [
			'status'  => 'ok',
			'version' => Agentic_Module::get_version(),
			'time'    => gmdate( 'c' ),
		], 200 );
	}

	// ── Document ──────────────────────────────────────────────────────────────

	/**
	 * Public so the API Catalog can read the served URL without duplicating it.
	 */
	public static function get_spec_url(): string {
		return rest_url( Content_API::NAMESPACE . '/openapi.json' );
	}

	public static function get_health_url(): string {
		return rest_url( Content_API::NAMESPACE . '/health' );
	}

	/**
	 * Build the OpenAPI 3.1 document.
	 *
	 * @return array
	 */
	public static function build_spec(): array {
		$site_name = get_bloginfo( 'name' );

		return [
			'openapi' => '3.1.0',
			'info'    => [
				'title'       => $site_name . ' — SCOS Content API',
				'version'     => Agentic_Module::get_version(),
				'description' => 'Read-only access to published content, filterable by the SCOS content architecture fields (purpose and search intent). Intended for AI agents and automated consumers. No authentication required; no write operations exist.',
				'contact'     => [
					'url' => home_url(),
				],
			],
			'servers' => [
				[
					'url'         => rest_url( Content_API::NAMESPACE ),
					'description' => 'Production',
				],
			],
			'paths'   => [
				'/search'         => self::path_search(),
				'/list'           => self::path_list(),
				'/content/{slug}' => self::path_content(),
				'/site-info'      => self::path_site_info(),
				'/health'         => self::path_health(),
			],
			'components' => [
				'schemas'    => [
					'ContentSummary' => self::schema_content_summary(),
					'ContentFull'    => self::schema_content_full(),
					'SiteInfo'       => self::schema_site_info(),
				],
				'parameters' => [
					'purpose' => self::param_purpose(),
					'intent'  => self::param_intent(),
				],
			],
		];
	}

	// ── Paths ─────────────────────────────────────────────────────────────────

	private static function path_search(): array {
		return [
			'get' => [
				'operationId' => 'searchContent',
				'summary'     => 'Search content by keyword',
				'description' => 'Full-text search across published posts and pages, optionally narrowed by content purpose or search intent. Returns lightweight summaries without body content — call /content/{slug} for a full record.',
				'parameters'  => [
					[
						'name'        => 'query',
						'in'          => 'query',
						'required'    => true,
						'description' => 'Keyword to search for.',
						'schema'      => [ 'type' => 'string' ],
					],
					[ '$ref' => '#/components/parameters/purpose' ],
					[ '$ref' => '#/components/parameters/intent' ],
				],
				'responses'   => [
					'200' => [
						'description' => 'Matching content, up to 20 results.',
						'content'     => [
							'application/json' => [
								'schema' => [
									'type'  => 'array',
									'items' => [ '$ref' => '#/components/schemas/ContentSummary' ],
								],
							],
						],
					],
				],
			],
		];
	}

	private static function path_list(): array {
		return [
			'get' => [
				'operationId' => 'listContent',
				'summary'     => 'List published content',
				'description' => 'All published posts and pages, newest first, optionally filtered by purpose or intent. Useful for surveying what exists before searching.',
				'parameters'  => [
					[ '$ref' => '#/components/parameters/purpose' ],
					[ '$ref' => '#/components/parameters/intent' ],
				],
				'responses'   => [
					'200' => [
						'description' => 'Content list, up to 100 results.',
						'content'     => [
							'application/json' => [
								'schema' => [
									'type'  => 'array',
									'items' => [ '$ref' => '#/components/schemas/ContentSummary' ],
								],
							],
						],
					],
				],
			],
		];
	}

	private static function path_content(): array {
		return [
			'get' => [
				'operationId' => 'getContent',
				'summary'     => 'Get one item by slug',
				'description' => 'Full record for a single post or page. Note that pages built with the Breakdance page builder store layout outside post_content and will return an empty content field plus an explanatory _note — request the page URL with an Accept: text/markdown header to get readable text for those.',
				'parameters'  => [
					[
						'name'        => 'slug',
						'in'          => 'path',
						'required'    => true,
						'description' => 'URL slug of the post or page.',
						'schema'      => [ 'type' => 'string' ],
					],
				],
				'responses'   => [
					'200' => [
						'description' => 'The content record.',
						'content'     => [
							'application/json' => [
								'schema' => [ '$ref' => '#/components/schemas/ContentFull' ],
							],
						],
					],
					'404' => [
						'description' => 'No published item matches that slug.',
					],
				],
			],
		];
	}

	private static function path_site_info(): array {
		return [
			'get' => [
				'operationId' => 'getSiteInfo',
				'summary'     => 'Get site context',
				'description' => 'Who this business is, what it does, who it serves, and which topics it covers. Worth calling first — it frames how to interpret the rest of the content.',
				'responses'   => [
					'200' => [
						'description' => 'Site context.',
						'content'     => [
							'application/json' => [
								'schema' => [ '$ref' => '#/components/schemas/SiteInfo' ],
							],
						],
					],
				],
			],
		];
	}

	private static function path_health(): array {
		return [
			'get' => [
				'operationId' => 'getHealth',
				'summary'     => 'Liveness probe',
				'description' => 'Returns ok when the API layer is responding. Does not touch the database.',
				'responses'   => [
					'200' => [
						'description' => 'API is up.',
						'content'     => [
							'application/json' => [
								'schema' => [
									'type'       => 'object',
									'properties' => [
										'status'  => [ 'type' => 'string', 'const' => 'ok' ],
										'version' => [ 'type' => 'string' ],
										'time'    => [ 'type' => 'string', 'format' => 'date-time' ],
									],
								],
							],
						],
					],
				],
			],
		];
	}

	// ── Reusable parameters ───────────────────────────────────────────────────

	/**
	 * Enum values are pulled from Meta_Fields so the spec cannot drift from the
	 * options the admin UI actually offers.
	 */
	private static function param_purpose(): array {
		return [
			'name'        => 'purpose',
			'in'          => 'query',
			'required'    => false,
			'description' => 'Filter by content purpose (scos_ca_purpose) — the role a page plays in the content architecture.',
			'schema'      => [
				'type' => 'string',
				'enum' => self::purpose_values(),
			],
		];
	}

	private static function param_intent(): array {
		return [
			'name'        => 'intent',
			'in'          => 'query',
			'required'    => false,
			'description' => 'Filter by search intent (scos_ca_intent) — what a visitor is trying to accomplish.',
			'schema'      => [
				'type' => 'string',
				'enum' => self::intent_values(),
			],
		];
	}

	/**
	 * Read purpose slugs from the Content Architecture module when available,
	 * so this stays in sync with the dropdown in the editor. Falls back to a
	 * static list if that module is not loaded.
	 *
	 * @return string[]
	 */
	private static function purpose_values(): array {
		$class = '\SiteEssentials\Modules\ContentArchitecture\Meta_Fields';

		if ( class_exists( $class ) && method_exists( $class, 'purpose_options' ) ) {
			$keys = array_keys( $class::purpose_options() );
			return array_values( array_filter( $keys, static fn( $k ) => '' !== $k ) );
		}

		return [ 'pillar', 'service-page', 'case-study', 'authority-page', 'supporting' ];
	}

	/**
	 * @return string[]
	 */
	private static function intent_values(): array {
		$class = '\SiteEssentials\Modules\ContentArchitecture\Meta_Fields';

		if ( class_exists( $class ) && method_exists( $class, 'intent_options' ) ) {
			$keys = array_keys( $class::intent_options() );
			return array_values( array_filter( $keys, static fn( $k ) => '' !== $k ) );
		}

		return [ 'informational', 'commercial', 'transactional', 'trust', 'navigational' ];
	}

	// ── Schemas ───────────────────────────────────────────────────────────────

	private static function schema_content_summary(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'title'   => [ 'type' => 'string' ],
				'slug'    => [ 'type' => 'string', 'description' => 'Pass to /content/{slug}.' ],
				'url'     => [ 'type' => 'string', 'format' => 'uri' ],
				'excerpt' => [ 'type' => 'string', 'description' => 'Trimmed to roughly 30 words.' ],
				'purpose' => [ 'type' => 'string', 'description' => 'Empty when unset on the post.' ],
				'intent'  => [ 'type' => 'string', 'description' => 'Empty when unset on the post.' ],
			],
		];
	}

	private static function schema_content_full(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'title'          => [ 'type' => 'string' ],
				'slug'           => [ 'type' => 'string' ],
				'url'            => [ 'type' => 'string', 'format' => 'uri' ],
				'published_date' => [ 'type' => 'string', 'format' => 'date' ],
				'purpose'        => [ 'type' => 'string' ],
				'intent'         => [ 'type' => 'string' ],
				'content'        => [
					'type'        => 'string',
					'description' => 'Plain text with HTML and shortcodes stripped. Empty for Breakdance-built pages.',
				],
				'_note'          => [
					'type'        => 'string',
					'description' => 'Present only when content is empty, explaining why and what to request instead.',
				],
			],
		];
	}

	private static function schema_site_info(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'name'        => [ 'type' => 'string' ],
				'tagline'     => [ 'type' => 'string' ],
				'description' => [ 'type' => 'string' ],
				'author'      => [ 'type' => 'string' ],
				'location'    => [ 'type' => 'string' ],
				'services'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'topics'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'url'         => [ 'type' => 'string', 'format' => 'uri' ],
			],
		];
	}
}
