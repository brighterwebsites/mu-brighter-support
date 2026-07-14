<?php
/**
 * Postly.ai API Client
 *
 * Wraps the two Postly endpoints needed by the amplification pipeline:
 *  - POST /v1/files/upload-from-url  → returns hosted CDN URL
 *  - POST /v1/posts                  → schedules a post across all workspace socials
 *
 * Auth: X-API-KEY header.
 * Base: https://openapi.postly.ai/v1/
 *
 * @package    SiteEssentials
 * @subpackage Modules\SocialAmplification\Amplification
 * v1.1 | 2026-07-02
 */

namespace SiteEssentials\Modules\SocialAmplification\Amplification;

defined( 'ABSPATH' ) || exit;

class Postly_Client {

	const BASE_URL = 'https://openapi.postly.ai/v1';

	/** @var string */
	private string $api_key;

	/** @var string */
	private string $workspace_id;

	/** @var string[] Optional: specific social channel IDs to target. Empty = all workspace channels. */
	private array $channel_ids;

	public function __construct( string $api_key, string $workspace_id, array $channel_ids = [] ) {
		$this->api_key      = $api_key;
		$this->workspace_id = $workspace_id;
		$this->channel_ids  = $channel_ids;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Public API
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Upload an image to Postly from a public URL.
	 *
	 * @param  string $url       Publicly accessible image URL.
	 * @param  string $file_name Optional filename hint.
	 * @return string            Postly-hosted CDN URL.
	 * @throws \RuntimeException on failure.
	 */
	public function upload_image( string $url, string $file_name = '' ): string {
		$body = [ 'url' => $url ];
		if ( $file_name ) {
			$body['file_name'] = $file_name;
		}

		$data = $this->request( 'POST', '/files/upload-from-url', $body );

		// Response: {"success": true, "data": {"id": "...", "url": "...", "file_name": "..."}}
		$hosted = $data['data']['url'] ?? '';
		if ( ! $hosted ) {
			throw new \RuntimeException( 'Postly upload-from-url did not return a URL. Response: ' . wp_json_encode( $data ) );
		}

		return $hosted;
	}

	/**
	 * Create a scheduled post on Postly.
	 *
	 * @param  array{
	 *     text:        string,
	 *     media_urls:  string[],
	 *     schedule_at: \DateTimeImmutable,
	 *     timezone:    string,
	 * } $params
	 * @return array  Raw Postly post response data.
	 * @throws \RuntimeException on failure.
	 */
	public function create_post( array $params ): array {
		$text      = $params['text']        ?? '';
		$media_raw = $params['media_urls']  ?? [];
		$schedule  = $params['schedule_at'] ?? null;
		$timezone  = $params['timezone']    ?? 'UTC';

		// Build media array — Postly requires [{url, type}]
		$media = array_map( static function ( string $url ) {
			return [ 'url' => $url, 'type' => 'image/jpeg' ];
		}, array_values( array_filter( $media_raw ) ) );

		$body = [
			'text'      => $text,
			'workspace' => $this->workspace_id,
		];

		if ( ! empty( $media ) ) {
			$body['media'] = $media;
		}

		// target_platforms: Postly expects a STRING ("all", platform names, or comma-separated channel IDs).
		// Use configured channel IDs directly; fallback to "all".
		if ( ! empty( $this->channel_ids ) ) {
			$ids = array_values( array_unique( array_filter( array_map( 'strval', $this->channel_ids ) ) ) );
			$body['target_platforms'] = ! empty( $ids ) ? implode( ',', $ids ) : 'all';
		} else {
			$body['target_platforms'] = 'all';
		}

		error_log( '[SCOS SMA Postly] Sending target_platforms (string): ' . $body['target_platforms'] );

		if ( $schedule instanceof \DateTimeImmutable ) {
			$body['one_off_schedule'] = [
				'one_off_date' => $schedule->format( 'Y-m-d' ),
				// Send local wall-clock time so Postly applies the provided timezone
				// without double-converting from an offset ISO timestamp.
				'time'         => $schedule->format( 'H:i' ),
				'timezone'     => $timezone,
			];
		}

		return $this->request( 'POST', '/posts', $body );
	}

	/**
	 * Create a GMB post with platform-specific settings.
	 *
	 * @param array{
	 *   gmb_caption:string,
	 *   cta_url:string,
	 *   image_url:string,
	 *   schedule_at:\DateTimeImmutable,
	 *   timezone:string,
	 *   gmb_channel_id:string
	 * } $params
	 */
	public function create_gmb_post( array $params ): array {
		$caption        = (string) ( $params['gmb_caption'] ?? '' );
		$cta_url        = (string) ( $params['cta_url'] ?? '' );
		$image_url      = (string) ( $params['image_url'] ?? '' );
		$timezone       = (string) ( $params['timezone'] ?? 'UTC' );
		$gmb_channel_id = (string) ( $params['gmb_channel_id'] ?? '' );
		$schedule       = $params['schedule_at'] ?? null;

		// Postly stores GMB fields under platform_posts[].settings (not flat platform_settings).
		// Verified via GET /posts/{id} on a UI-created golden post vs API-created post.
		$gmb_settings = [
			'identifier'         => 'googleMyBusiness',
			'type'               => 'update',
			'language_code'      => 'en-AU',
			'summary'            => $caption,
			'call_to_action'     => 'learn_more',
			'call_to_action_url' => $cta_url,
		];

		$body = [
			'text'             => '',
			'workspace'        => $this->workspace_id,
			'target_platforms' => $gmb_channel_id,
			'platform_posts'   => [
				[
					'identifier' => 'googleMyBusiness',
					'settings'   => $gmb_settings,
				],
			],
		];

		if ( $image_url !== '' ) {
			$body['media'] = [
				[
					'url'  => $image_url,
					'type' => 'image/*',
				],
			];
		}

		if ( $schedule instanceof \DateTimeImmutable ) {
			$body['one_off_schedule'] = [
				'one_off_date' => $schedule->format( 'Y-m-d' ),
				'time'         => $schedule->format( 'H:i' ),
				'timezone'     => $timezone,
			];
		}

		error_log( '[SCOS SMA Postly] Sending GMB payload: ' . wp_json_encode( $body ) );
		return $this->request( 'POST', '/posts', $body );
	}

	/**
	 * Fetch connected social accounts for the workspace.
	 * Results are cached per request to avoid redundant calls when scheduling 3 posts.
	 *
	 * @return array[]
	 */
	public function get_socials(): array {
		static $cache = [];
		$key = $this->workspace_id;

		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		try {
			$raw = $this->request( 'GET', "/workspaces/{$this->workspace_id}/socials" );
			error_log( '[SCOS SMA Postly] Raw socials response: ' . wp_json_encode( $raw ) );
			$cache[ $key ] = $raw['data'] ?? ( is_array( $raw ) && isset( $raw[0] ) ? $raw : [] );
		} catch ( \RuntimeException $e ) {
			error_log( '[SCOS SMA Postly] Failed to fetch socials: ' . $e->getMessage() );
			$cache[ $key ] = [];
		}

		return $cache[ $key ];
	}

	/**
	 * Fetch scheduled posts for the workspace (used by the CLI backfill to find free slots).
	 *
	 * @param  int $skip Pagination offset.
	 * @return array[]   Array of post objects.
	 * @throws \RuntimeException on failure.
	 */
	public function fetch_posts( int $skip = 0 ): array {
		$data = $this->request( 'GET', '/posts', [], [
			'workspaceId' => $this->workspace_id,
			'skip'        => $skip,
		] );
		return is_array( $data ) ? $data : [];
	}

	// ──────────────────────────────────────────────────────────────────────────
	// HTTP helper
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * @throws \RuntimeException
	 */
	private function request( string $method, string $endpoint, array $body = [], array $query = [] ): array {
		$url = self::BASE_URL . $endpoint;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = [
			'method'  => strtoupper( $method ),
			'timeout' => 30,
			'headers' => [
				'X-API-KEY'    => $this->api_key,
				'Content-Type' => 'application/json',
			],
		];

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Postly API request failed: ' . $response->get_error_message() );
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $decoded ) ? ( $decoded['message'] ?? $raw_body ) : $raw_body;
			throw new \RuntimeException( "Postly API error ({$code}) on {$method} {$endpoint}: {$msg}" );
		}

		return is_array( $decoded ) ? $decoded : [];
	}
}
