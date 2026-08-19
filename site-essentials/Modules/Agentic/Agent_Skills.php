<?php
/**
 * Agentic — Agent Skills Discovery (Cloudflare RFC v0.2.0)
 *
 * v1.0 | 2026-08-04
 *
 * WHAT THIS IS FOR
 * ----------------
 * Everything else in the discovery layer describes *capabilities* — here are my
 * endpoints, here are their parameters. Agent Skills describes *procedure*:
 * given those capabilities, here is how you should actually use them, in what
 * order, and what to watch out for.
 *
 * That distinction matters. An OpenAPI document tells an agent that /search
 * accepts a purpose parameter. It does not tell the agent that calling
 * /site-info first will make the purpose values interpretable, or that
 * Breakdance pages need a different request entirely. Skills carry the
 * procedural knowledge a competent human would pass to a colleague.
 *
 * Structure:
 *   /.well-known/agent-skills/index.json          the discovery index
 *   /.well-known/agent-skills/{slug}/SKILL.md     each skill's instructions
 *
 * THE SHA256 DIGEST — WHY IT EXISTS
 * ---------------------------------
 * Each index entry carries a sha256 of its SKILL.md body. This is a supply
 * chain control, not a cache key. Skills are instructions an agent may follow
 * with real consequences, so a consumer can pin a digest it has reviewed and
 * refuse to act on content that has since changed. Treat a digest change as a
 * meaningful event: it means the instructions an agent will follow have been
 * altered.
 *
 * Digests are computed at request time from the same string that gets served,
 * so they cannot drift. Skill bodies here are a few KB, so hashing per request
 * is cheaper than the cache invalidation logic would be.
 *
 * GOTCHAS
 * -------
 * 1. The digest must cover the exact served bytes. Any transformation applied
 *    on output but not before hashing (trimming, newline conversion) breaks
 *    verification. Both paths call get_skill_body() and neither post-processes.
 *
 * 2. Slug validation before path use. The router hands over whatever was in the
 *    URL, so the slug is matched against the registered set rather than used to
 *    build a filesystem path. Skills live in PHP for exactly this reason.
 *
 * 3. Content type for SKILL.md is text/markdown, and for the index
 *    application/json.
 *
 * @package    SiteEssentials
 * @subpackage Modules\Agentic
 */

namespace SiteEssentials\Modules\Agentic;

defined( 'ABSPATH' ) || exit;

class Agent_Skills {

	const BASE_PATH = 'agent-skills';
	const SCHEMA    = 'https://agentskills.io/schema/v0.2.0/index.json';

	// ── Boot ─────────────────────────────────────────────────────────────────

	public static function init(): void {
		add_filter( 'scos_well_known_response', [ __CLASS__, 'maybe_serve' ], 10, 2 );
	}

	/**
	 * Handles both the index and individual skill files.
	 *
	 * @param  array|null $response Response from an earlier handler, if any.
	 * @param  string     $path     Requested path minus the /.well-known/ prefix.
	 * @return array|null
	 */
	public static function maybe_serve( $response, string $path ) {
		// The discovery index.
		if ( self::BASE_PATH . '/index.json' === $path ) {
			return [
				'content' => wp_json_encode(
					self::build_index(),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				),
				'type'    => 'application/json',
			];
		}

		// An individual skill: agent-skills/{slug}/SKILL.md
		$pattern = '#^' . preg_quote( self::BASE_PATH, '#' ) . '/([a-z0-9-]+)/SKILL\.md$#';

		if ( preg_match( $pattern, $path, $m ) ) {
			$slug   = $m[1];
			$skills = self::get_skills();

			// Match against registered slugs — never build a path from input.
			if ( ! isset( $skills[ $slug ] ) ) {
				return $response; // Unknown slug — fall through to 404.
			}

			return [
				'content' => self::get_skill_body( $slug ),
				'type'    => 'text/markdown',
			];
		}

		return $response;
	}

	// ── Index ─────────────────────────────────────────────────────────────────

	public static function get_index_url(): string {
		return home_url( '/.well-known/' . self::BASE_PATH . '/index.json' );
	}

	private static function get_skill_url( string $slug ): string {
		return home_url( '/.well-known/' . self::BASE_PATH . '/' . $slug . '/SKILL.md' );
	}

	/**
	 * Build the discovery index per Agent Skills Discovery RFC v0.2.0.
	 *
	 * @return array
	 */
	public static function build_index(): array {
		$entries = [];

		foreach ( self::get_skills() as $slug => $skill ) {
			$body = self::get_skill_body( $slug );

			$entries[] = [
				'name'        => $slug,
				'type'        => 'skill',
				'description' => $skill['description'],
				'url'         => self::get_skill_url( $slug ),
				'sha256'      => hash( 'sha256', $body ),
			];
		}

		return [
			'$schema' => self::SCHEMA,
			'skills'  => $entries,
		];
	}

	// ── Skill definitions ─────────────────────────────────────────────────────

	/**
	 * Registered skills, keyed by slug.
	 *
	 * Descriptions appear in the index and are what an agent reads when deciding
	 * whether a skill is relevant — so they are written as capability statements,
	 * not titles.
	 *
	 * @return array<string, array{description: string}>
	 */
	private static function get_skills(): array {
		return [
			'content-api'           => [
				'description' => 'Query this site\'s published content programmatically via its REST API, including filtering by content purpose and search intent.',
			],
			'markdown-negotiation'  => [
				'description' => 'Retrieve any page on this site as clean Markdown instead of HTML, using HTTP content negotiation.',
			],
			'webmcp-connect'        => [
				'description' => 'Connect to this site\'s WebMCP tools from an MCP client to search and read content through tool calls rather than HTTP requests.',
			],
		];
	}

	/**
	 * Return the SKILL.md body for a slug.
	 *
	 * Both the index (for hashing) and the file route call this, guaranteeing the
	 * digest always matches the served bytes.
	 */
	private static function get_skill_body( string $slug ): string {
		switch ( $slug ) {
			case 'content-api':
				return self::skill_content_api();

			case 'markdown-negotiation':
				return self::skill_markdown_negotiation();

			case 'webmcp-connect':
				return self::skill_webmcp_connect();
		}

		return '';
	}

	// ── Skill bodies ──────────────────────────────────────────────────────────

	private static function skill_content_api(): string {
		$base      = rest_url( Content_API::NAMESPACE );
		$spec      = OpenAPI_Spec::get_spec_url();
		$site_name = get_bloginfo( 'name' );

		return <<<MD
---
name: content-api
description: Query this site's published content programmatically via its REST API, including filtering by content purpose and search intent.
---

# Querying {$site_name} content

Read-only REST API. No authentication. No write operations exist.

Base URL: `{$base}`
OpenAPI description: `{$spec}`

## Call this first

`GET /site-info`

Returns what this business does, who it serves, and which topics it covers.
Do this before searching — it tells you which vocabulary is likely to match
and how to interpret the `purpose` and `intent` values you will see on results.

## Finding content

`GET /list` — everything published, newest first. Use when you want to survey
what exists rather than search for something specific.

`GET /search?query={keyword}` — full-text search.

Both accept two optional filters that are the useful part of this API:

- `purpose` — the role a page plays. Values include `service-page`, `pillar`,
  `case-study`, `authority-page`, `supporting`, `resource-guide`.
- `intent` — what a visitor wants. Values include `informational`,
  `commercial`, `transact_lead`, `trust`.

So `GET /list?purpose=service-page` returns only service offerings, and
`GET /search?query=seo&purpose=case-study` finds proof rather than pitch.
Prefer filtering over retrieving everything and sorting it yourself.

An empty `purpose` or `intent` on a result means the field is unset on that
post, not that it is uncategorised by design.

## Reading one item

`GET /content/{slug}` — full record. Take `slug` from a search or list result.

**Important:** this site is built with the Breakdance page builder, which stores
layout outside WordPress's `post_content`. Those pages return an empty `content`
field and a `_note` explaining so. When you see that, fetch the page's `url`
with an `Accept: text/markdown` header instead — see the
`markdown-negotiation` skill. Do not conclude the page is empty.

## Health

`GET /health` — returns `{"status":"ok"}` without touching the database.

## Limits

`/search` returns up to 20 results, `/list` up to 100. Neither paginates.
Narrow with filters rather than expecting to page through.
MD;
	}

	private static function skill_markdown_negotiation(): string {
		$home      = home_url( '/' );
		$site_name = get_bloginfo( 'name' );

		return <<<MD
---
name: markdown-negotiation
description: Retrieve any page on this site as clean Markdown instead of HTML, using HTTP content negotiation.
---

# Reading {$site_name} pages as Markdown

Any page here will return Markdown instead of HTML if you ask for it. This is
real HTTP content negotiation — same URL, different representation.

## How

Send an `Accept: text/markdown` header:

```
curl -H "Accept: text/markdown" {$home}
```

Nothing else changes. The URL stays the same. Browsers never send that header,
so they keep receiving HTML.

## What you get

- YAML frontmatter with `title`, `description`, and `image` where available
- Body content converted to Markdown: headings, links, lists, bold, italic,
  images, code blocks
- No navigation, footer, cookie banner, or other theme chrome

Response headers worth reading:

- `Content-Type: text/markdown; charset=utf-8`
- `x-markdown-tokens` — estimated token count, so you can decide whether to
  fetch the body before spending the context on it
- `Vary: Accept` — confirms the server negotiates rather than guessing

## When to use this instead of the REST API

Use this for **page text**. Use the REST API (see the `content-api` skill) for
**finding pages and reading their metadata**.

The two are complementary, and specifically: pages built with Breakdance return
an empty `content` field from `/content/{slug}` because the builder stores
layout outside `post_content`. Markdown negotiation renders the actual page, so
it works on every page regardless of how it was built. If the REST API hands you
an empty `content` and a `_note`, this is what it is pointing you at.

## Manual testing

`?format=md` on any URL does the same thing, for when setting a header is
inconvenient:

```
{$home}?format=md
```
MD;
	}

	private static function skill_webmcp_connect(): string {
		$site_name = get_bloginfo( 'name' );
		$mcp_page  = absint( get_option( 'scos_agentic_webmcp_page_id', 0 ) );
		$mcp_url   = $mcp_page > 0 ? ( get_permalink( $mcp_page ) ?: home_url( '/mcp/' ) ) : home_url( '/mcp/' );

		return <<<MD
---
name: webmcp-connect
description: Connect to this site's WebMCP tools from an MCP client to search and read content through tool calls rather than HTTP requests.
---

# Connecting to {$site_name} via WebMCP

This site exposes its content as MCP tools through WebMCP, so an MCP client can
call them as tools instead of making HTTP requests.

Connection page: {$mcp_url}

## Which WebMCP this is

The browser-relay implementation (`jasonjmcghee/WebMCP`), not the W3C
`navigator.modelContext` browser API. That matters for how you connect: tools
are bridged from an open browser tab through a local WebSocket relay, so a
browser session and a running relay are both required. There is no publicly
reachable MCP endpoint to connect to directly.

If you want to consume this site's content without a browser in the loop, use
the REST API instead — see the `content-api` skill. It exposes the same data.

## Connecting

1. Start the relay locally:
   `npx -y "@jason.today/webmcp@latest" --config claude`
   Replace `claude` with `cursor`, `cline`, or `windsurf` as appropriate.

2. Generate a token: `npx "@jason.today/webmcp" --new`

3. Open {$mcp_url}, click the widget, paste the token.

4. Restart your MCP client so it picks up the server.

## Available tools

- `get_site_info` — site context. Call first.
- `list_content` — all published content, optional `purpose` / `intent` filters.
- `search_content` — keyword search, same optional filters.
- `get_content` — full record for one item by `slug`.

These map onto the REST endpoints described in the `content-api` skill, and the
same caveats apply — notably that Breakdance-built pages return empty content
and should be read via Markdown negotiation instead.

## Known platform issue

On Windows, the relay's daemon mode crashes on startup: it calls
`process.stdin.setRawMode()` unguarded, which throws when stdin is not a TTY,
and the forked daemon runs with `stdio: 'ignore'`. It reports a PID and exits,
so the failure is silent — the symptom is that nothing is listening on port
4797 despite an apparently successful start.

Workarounds: run with `--foreground` in an interactive terminal, or guard the
call with `process.stdin.isTTY &&` in the installed package.
MD;
	}
}
