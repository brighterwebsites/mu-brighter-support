<?php
/**
 * Amplification Engine
 *
 * Orchestrates the full publish-to-Postly workflow for a single post:
 *  1. Gather post data (title, excerpt, permalink, images)
 *  2. Create YOURLS shortlink (falls back to permalink)
 *  3. Call Anthropic for 3 captions
 *  4. Upload images to Postly CDN
 *  5. Schedule 3 posts (T+0, T+42d, T+84d — Mon/Wed/Fri aligned)
 *  6. Log results to wp_option `scos_sa_amplify_log`
 *
 * Image-selection rules:
 *  - Collect featured image + ACF gallery images, deduplicate.
 *  - If ≥ 5 images: Post 1 = first 4 gallery, Post 2 = last 4 gallery, Post 3 = random 4.
 *  - If < 5 images:  same set for all three posts (up to 4 images each).
 *
 * @package    SiteEssentials
 * @subpackage Modules\SocialAmplification\Amplification
 * v1.3 | 2026-07-02
 */

namespace SiteEssentials\Modules\SocialAmplification\Amplification;

defined( 'ABSPATH' ) || exit;

class Amplification_Engine {

	const IMAGES_PER_POST    = 4;
	const LOG_OPTION         = 'scos_sa_amplify_log';
	const LOG_PREFIX         = '[SCOS SMA Engine]';

	// ──────────────────────────────────────────────────────────────────────────
	// Channel resolution
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Build the channel ID list for standard social posts.
	 * Combines per-platform IDs (Facebook, Instagram) and the legacy Others field.
	 *
	 * @return string[]
	 */
	private static function get_standard_channel_ids(): array {
		$ids = [];

		$fb_id = trim( (string) get_option( 'scos_sa_postly_fb_channel_id', '' ) );
		if ( $fb_id && get_option( 'scos_sa_postly_fb_enabled', '1' ) !== '0' ) {
			$ids[] = $fb_id;
		}

		$ig_id = trim( (string) get_option( 'scos_sa_postly_ig_channel_id', '' ) );
		if ( $ig_id && get_option( 'scos_sa_postly_ig_enabled', '1' ) !== '0' ) {
			$ids[] = $ig_id;
		}

		$others_raw = (string) get_option( 'bw_postly_channel_ids', '' );
		foreach ( array_filter( array_map( 'trim', explode( ',', $others_raw ) ) ) as $id ) {
			$ids[] = $id;
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Get the configured GMB channel ID (new key with legacy fallback).
	 * Public so CLI and external callers can check without instantiating.
	 */
	public static function resolve_gmb_channel_id(): string {
		$id = trim( (string) get_option( 'scos_sa_postly_gmb_channel_id', '' ) );
		if ( '' !== $id ) {
			return $id;
		}
		return trim( (string) get_option( 'se_postly_gmb_channel_id', '' ) );
	}

	private static function get_gmb_channel_id(): string {
		return self::resolve_gmb_channel_id();
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Entry point
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Run the full amplification pipeline for a single post.
	 *
	 * @param  int   $post_id
	 * @param  array $options Override schedule etc. { schedule_at: \DateTimeImmutable }
	 * @return array          Result summary logged to `scos_sa_amplify_log`.
	 * @throws \RuntimeException on unrecoverable error.
	 */
	public static function run( int $post_id, array $options = [] ): array {
		error_log( self::LOG_PREFIX . " ── Starting amplification run for post #{$post_id} ──" );

		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== 'publish' ) {
			$msg = "Post {$post_id} is not published or does not exist.";
			error_log( self::LOG_PREFIX . ' ' . $msg );
			throw new \RuntimeException( $msg );
		}

		// ── 1. Gather post data ──────────────────────────────────────────────
		$title     = get_the_title( $post );
		$permalink = get_permalink( $post );
		$excerpt   = self::get_excerpt( $post );
		error_log( self::LOG_PREFIX . " Post #{$post_id}: \"{$title}\" | {$permalink}" );

		// ── 2. YOURLS shortlink ──────────────────────────────────────────────
		$shortlink = self::get_shortlink( $post_id, $permalink );
		error_log( self::LOG_PREFIX . " Shortlink: {$shortlink}" );

		// ── 3. Determine content type ────────────────────────────────────────
		$content_type = self::get_content_type( $post );
		error_log( self::LOG_PREFIX . " Content type: {$content_type}" );

		$context = [
			'post_id'      => $post_id,
			'title'        => $title,
			'excerpt'      => $excerpt,
			'permalink'    => $permalink,
			'shortlink'    => $shortlink,
			'content_type' => $content_type,
		];

		$timezone = get_option( 'timezone_string', 'UTC' ) ?: 'UTC';

		// ── 4. Publish time window ───────────────────────────────────────────
		// Read configured window (defaults: 09:00–17:00 site time).
		$time_min_str = (string) get_option( 'bw_social_publish_time_min', '09:00' );
		$time_max_str = (string) get_option( 'bw_social_publish_time_max', '17:00' );
		[ $min_h, $min_m ] = array_map( 'intval', explode( ':', $time_min_str . ':00' ) );
		[ $max_h, $max_m ] = array_map( 'intval', explode( ':', $time_max_str . ':00' ) );
		$min_minutes = $min_h * 60 + $min_m;
		$max_minutes = max( $max_h * 60 + $max_m, $min_minutes + 1 );

		// Returns [hour, minute] picked randomly within the window.
		$rand_in_window = static function () use ( $min_minutes, $max_minutes ): array {
			$t = mt_rand( $min_minutes, $max_minutes );
			return [ intdiv( $t, 60 ), $t % 60 ];
		};

		// ── 5. Base schedule time ────────────────────────────────────────────
		$now     = new \DateTimeImmutable( 'now', new \DateTimeZone( $timezone ) );
		$base_dt = $options['schedule_at'] ?? $options['standard_schedule_at'] ?? $now;

		[ $rand_h, $rand_m ] = $rand_in_window();
		$base_dt = $base_dt->setTime( $rand_h, $rand_m, 0 );

		// Slot 1 guard: must be ≥ 60 min from now (API processing + human approval buffer).
		// If the randomly chosen time is too soon, push to next day with a fresh random time.
		if ( $base_dt <= $now->modify( '+60 minutes' ) ) {
			[ $rand_h, $rand_m ] = $rand_in_window();
			$base_dt = $now->modify( '+1 day' )->setTime( $rand_h, $rand_m, 0 );
		}

		error_log( self::LOG_PREFIX . ' Base schedule (standard default): ' . $base_dt->format( 'Y-m-d H:i T' ) . " (window {$time_min_str}–{$time_max_str})" );

		// ── 6. Resolve per-post-type config ─────────────────────────────────
		$pt_config = \SiteEssentials\Modules\SocialAmplification\Post_Type_Config::get_config(
			$post->post_type
		);

		$run_standard = array_key_exists( 'run_standard', $options ) ? (bool) $options['run_standard'] : true;
		$run_gmb      = array_key_exists( 'run_gmb', $options ) ? (bool) $options['run_gmb'] : true;

		// Override post_count if passed explicitly via CLI/ability options.
		if ( isset( $options['post_count'] ) ) {
			$pt_config['post_count'] = max( 1, (int) $options['post_count'] );
		}

		$standard_results = [];
		$gmb_results      = [];

		if ( $run_standard ) {
			$standard_results = self::run_standard_flow(
				$post_id,
				$context,
				$pt_config,
				$timezone,
				$options['standard_schedule_at'] ?? $base_dt
			);
		}

		if ( $run_gmb ) {
			$gmb_results = self::run_gmb_flow(
				$post_id,
				$context,
				$timezone,
				$options['gmb_schedule_at'] ?? null
			);
		}

		error_log( self::LOG_PREFIX . " ── Run complete for post #{$post_id} ──" );

		// ── 7. Log results ───────────────────────────────────────────────────
		$log_entry = [
			'post_id'         => $post_id,
			'ran_at'          => current_time( 'mysql' ),
			'shortlink'       => $shortlink,
			'posts'           => $standard_results, // Back-compat with existing UI expectations.
			'standard_posts'  => $standard_results,
			'gmb_posts'       => $gmb_results,
		];

		self::write_log( $post_id, $log_entry );

		return $log_entry;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────────────────────────────────

	private static function get_excerpt( \WP_Post $post ): string {
		if ( $post->post_excerpt ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}
		return wp_trim_words( wp_strip_all_tags( $post->post_content ), 40, '...' );
	}

	/**
	 * @deprecated Replaced by per-post-type config from Post_Type_Config::get_config().
	 *             Retained for external callers that may still reference this method.
	 */
	private static function get_platform_configs(): array {
		return [
			'standard' => [
				'count'    => max( 1, (int) get_option( 'scos_sa_postly_post_count', 3 ) ),
				'gap_days' => 42,
				'adapter'  => 'standard',
			],
			'gmb'      => [
				'count'    => 1,
				'gap_days' => 0,
				'adapter'  => 'gmb',
			],
		];
	}

	/**
	 * @param array $pt_config  Resolved config from Post_Type_Config::get_config().
	 *                          Keys: post_count, frames, no_featured, no_attachments,
	 *                          acf_gallery_keys, max_images.
	 */
	private static function run_standard_flow(
		int $post_id,
		array $context,
		array $pt_config,
		string $timezone,
		\DateTimeImmutable $base_dt
	): array {
		$channel_ids = self::get_standard_channel_ids();

		if ( empty( $channel_ids ) ) {
			error_log( self::LOG_PREFIX . ' Standard not configured: no channel IDs found. Skipping standard flow.' );
			return [];
		}

		$api_key      = get_option( 'bw_postly_api_key', '' );
		$workspace_id = get_option( 'bw_postly_workspace_id', '' );
		$post_count   = max( 1, (int) ( $pt_config['post_count'] ?? 3 ) );
		$gap_days     = 42;
		$frames       = $pt_config['frames'] ?? [];
		error_log( self::LOG_PREFIX . " Standard flow workspace: {$workspace_id} | channels: " . implode( ',', $channel_ids ) . " | count: {$post_count} | tz: {$timezone}" );

		$all_images = self::collect_images( $post_id, $pt_config );
		error_log( self::LOG_PREFIX . ' Standard images collected: ' . count( $all_images ) . ' — ' . implode( ', ', array_map( 'basename', $all_images ) ) );
		$image_sets = self::build_image_sets( $all_images, $post_count, (int) ( $pt_config['max_images'] ?? self::IMAGES_PER_POST ) );

		error_log( self::LOG_PREFIX . " Calling Anthropic for {$post_count} standard captions…" );
		$captions = Anthropic_Client::generate_captions( $context, $frames, $post_count );
		$client   = new Postly_Client( $api_key, $workspace_id, $channel_ids );

		$post_results = [];

		for ( $i = 0; $i < $post_count; $i++ ) {
			$offset_days = $gap_days * $i;
			$schedule_dt = $base_dt->modify( "+{$offset_days} days" );
			$caption_key = 'post_' . ( $i + 1 );
			$caption     = $captions[ $caption_key ] ?? '';

			error_log( self::LOG_PREFIX . " Standard slot " . ( $i + 1 ) . ": scheduled {$schedule_dt->format('Y-m-d H:i')} | images from set " . count( $image_sets[ $i ] ?? [] ) );

			$uploaded_urls = self::upload_images( $client, $image_sets[ $i ] ?? [] );
			error_log( self::LOG_PREFIX . ' Standard uploaded ' . count( $uploaded_urls ) . ' of ' . count( $image_sets[ $i ] ?? [] ) . ' images to Postly CDN' );

			try {
				$result = $client->create_post( [
					'text'        => $caption,
					'media_urls'  => $uploaded_urls,
					'schedule_at' => $schedule_dt,
					'timezone'    => $timezone,
				] );

				$postly_id = $result['_id'] ?? ( $result['id'] ?? null );
				$post_results[] = [
					'platform'    => 'standard',
					'slot'        => $i + 1,
					'scheduled'   => $schedule_dt->format( 'Y-m-d H:i' ),
					'caption_key' => $caption_key,
					'status'      => 'scheduled',
					'postly_id'   => $postly_id,
					'images'      => count( $uploaded_urls ),
				];
			} catch ( \RuntimeException $e ) {
				$post_results[] = [
					'platform'    => 'standard',
					'slot'        => $i + 1,
					'scheduled'   => $schedule_dt->format( 'Y-m-d H:i' ),
					'caption_key' => $caption_key,
					'status'      => 'error',
					'error'       => $e->getMessage(),
					'images'      => count( $uploaded_urls ),
				];
			}
		}

		return $post_results;
	}

	private static function run_gmb_flow(
		int $post_id,
		array $context,
		string $timezone,
		?\DateTimeImmutable $base_dt = null
	): array {
		$gmb_channel_id = self::get_gmb_channel_id();
		if ( '' === $gmb_channel_id ) {
			error_log( self::LOG_PREFIX . ' GMB not configured: scos_sa_postly_gmb_channel_id / se_postly_gmb_channel_id is blank. Skipping GMB flow.' );
			return [];
		}

		$api_key      = get_option( 'bw_postly_api_key', '' );
		$workspace_id = get_option( 'bw_postly_workspace_id', '' );
		$client       = new Postly_Client( $api_key, $workspace_id );
		$now          = new \DateTimeImmutable( 'now', new \DateTimeZone( $timezone ) );

		$schedule_dt = $base_dt ?: $now->modify( '+60 minutes' );
		if ( $schedule_dt <= $now->modify( '+60 minutes' ) ) {
			$schedule_dt = $now->modify( '+60 minutes' );
		}

		$source_image = self::get_featured_og_image( $post_id );
		$permalink    = $context['permalink'] ?? '';
		$gmb_caption  = Anthropic_Client::generate_gmb_caption( $context );
		// GMB CTA always uses the permalink (never YOURLS shortlink).
		$cta_url      = self::build_cta_url( $permalink, '' );

		$image_url = '';
		if ( $source_image !== '' ) {
			try {
				$file_name = basename( (string) wp_parse_url( $source_image, PHP_URL_PATH ) ) ?: 'gmb-image.jpg';
				$image_url = $client->upload_image( $source_image, $file_name );
				error_log( self::LOG_PREFIX . ' GMB image uploaded to Postly CDN: ' . $image_url );
			} catch ( \RuntimeException $e ) {
				error_log( self::LOG_PREFIX . ' GMB image upload failed (continuing without image): ' . $e->getMessage() );
			}
		}

		// GMB is always one post per amplification run (no multi-slot schedule like standard).
		$post_results = [];
		try {
			$result = $client->create_gmb_post( [
				'gmb_caption'    => $gmb_caption,
				'cta_url'        => $cta_url,
				'image_url'      => $image_url,
				'schedule_at'    => $schedule_dt,
				'timezone'       => $timezone,
				'gmb_channel_id' => $gmb_channel_id,
			] );

			$postly_id = $result['_id'] ?? ( $result['id'] ?? null );
			$post_results[] = [
				'platform'  => 'gmb',
				'slot'      => 1,
				'scheduled' => $schedule_dt->format( 'Y-m-d H:i' ),
				'status'    => 'scheduled',
				'postly_id' => $postly_id,
				'images'    => $image_url ? 1 : 0,
			];
		} catch ( \RuntimeException $e ) {
			error_log( self::LOG_PREFIX . ' GMB call failed: ' . $e->getMessage() );
			$post_results[] = [
				'platform'  => 'gmb',
				'slot'      => 1,
				'scheduled' => $schedule_dt->format( 'Y-m-d H:i' ),
				'status'    => 'error',
				'error'     => $e->getMessage(),
				'images'    => $image_url ? 1 : 0,
			];
		}

		return $post_results;
	}

	private static function get_shortlink( int $post_id, string $fallback ): string {
		if ( ! class_exists( '\BW_YOURLS_Helper' ) ) {
			return $fallback;
		}

		// Slug stored on the post (scos_sa_shortlink_slug or legacy bw_shortlink_slug)
		$slug = get_post_meta( $post_id, 'scos_sa_shortlink_slug', true )
			?: get_post_meta( $post_id, 'bw_shortlink_slug', true );

		if ( ! $slug ) {
			return $fallback;
		}

		// Build destination URL: permalink + UTM params.
		// YOURLS stores this as the long URL the shortlink resolves to.
		$long_url = add_query_arg( [
			'utm_source'   => 'social_media',
			'utm_medium'   => 'social',
			'utm_content'  => 'case-study_link',
			'utm_campaign' => 'none',
		], get_permalink( $post_id ) );

		try {
			// create_shortlink( $long_url, $keyword ) — long URL first, slug/keyword second.
			$result = \BW_YOURLS_Helper::create_shortlink( $long_url, $slug );
			if ( is_wp_error( $result ) ) {
				error_log( self::LOG_PREFIX . ' YOURLS WP_Error: ' . $result->get_error_message() );
			} elseif ( is_array( $result ) && ! empty( $result['shorturl'] ) ) {
				return $result['shorturl'];
			}
		} catch ( \Exception $e ) {
			error_log( self::LOG_PREFIX . ' YOURLS exception: ' . $e->getMessage() );
		}

		return $fallback;
	}

	private static function build_cta_url( string $permalink, string $shortlink ): string {
		$base_url = $shortlink ?: $permalink;
		if ( ! $base_url ) {
			return '';
		}

		$query = wp_parse_url( $base_url, PHP_URL_QUERY );
		if ( is_string( $query ) && $query !== '' ) {
			parse_str( $query, $existing_params );
			$utm_keys = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content' ];
			foreach ( $utm_keys as $utm_key ) {
				if ( ! empty( $existing_params[ $utm_key ] ) ) {
					error_log( self::LOG_PREFIX . " CTA URL already has {$utm_key}; skipping duplicate UTM injection." );
					return $base_url;
				}
			}
		}

		return add_query_arg( [
			'utm_source'   => 'social_media',
			'utm_medium'   => 'social',
			'utm_content'  => 'gmb_learn_more',
			'utm_campaign' => 'none',
		], $base_url );
	}

	private static function get_featured_og_image( int $post_id ): string {
		$featured_id = get_post_thumbnail_id( $post_id );
		if ( ! $featured_id ) {
			return '';
		}

		$og = wp_get_attachment_image_url( $featured_id, 'og-image' );
		if ( $og ) {
			return $og;
		}

		$sized = wp_get_attachment_image_url( $featured_id, [ 1200, 630 ] );
		if ( $sized ) {
			return $sized;
		}

		$full = wp_get_attachment_image_url( $featured_id, 'full' );
		return $full ?: '';
	}

	/**
	 * Collect all candidate images for a post using the per-type config.
	 *
	 * Config keys used:
	 *   no_featured      — skip the WP featured image
	 *   no_attachments   — skip post image attachments (not currently fetched by default,
	 *                      but governs future attachment-scan logic)
	 *   acf_gallery_keys — comma-separated ACF field keys (overrides global option when
	 *                      set to a non-empty string in per-type config)
	 *
	 * Falls back gracefully: if ACF gallery key doesn't exist on this post type the key
	 * simply returns empty and the engine continues with whatever images it found.
	 *
	 * @param  int   $post_id
	 * @param  array $config  Resolved config from Post_Type_Config::get_config().
	 * @return string[]
	 */
	private static function collect_images( int $post_id, array $config = [] ): array {
		$urls         = [];
		$no_featured  = ! empty( $config['no_featured'] );
		$acf_keys_raw = isset( $config['acf_gallery_keys'] )
			? (string) $config['acf_gallery_keys']
			: (string) get_option( 'bw_social_acf_gallery_keys', '' );

		// Featured image (unless disabled)
		if ( ! $no_featured ) {
			$featured_id = get_post_thumbnail_id( $post_id );
			if ( $featured_id ) {
				// Check for custom ACF key override (global option, not per-type)
				$featured_key = get_option( 'bw_social_acf_featured_key', '' );
				if ( $featured_key ) {
					$acf_featured = get_post_meta( $post_id, $featured_key, true );
					if ( $acf_featured ) {
						$url = is_array( $acf_featured ) ? ( $acf_featured['url'] ?? '' ) : (string) $acf_featured;
						if ( $url ) {
							$urls[] = $url;
						}
					}
				}
				if ( empty( $urls ) ) {
					$src = wp_get_attachment_image_url( $featured_id, 'large' );
					if ( $src ) {
						$urls[] = $src;
					}
				}
			}
		}

		// ACF gallery fields
		if ( $acf_keys_raw ) {
			$gallery_keys = array_filter( array_map( 'trim', explode( ',', $acf_keys_raw ) ) );
			foreach ( $gallery_keys as $key ) {
				$gallery = get_post_meta( $post_id, $key, true );
				if ( ! $gallery ) {
					continue; // Key absent or empty — fail gracefully
				}
				if ( is_array( $gallery ) ) {
					foreach ( $gallery as $img ) {
						if ( is_array( $img ) ) {
							$url = $img['url'] ?? '';
						} elseif ( is_numeric( $img ) ) {
							$url = wp_get_attachment_image_url( (int) $img, 'large' ) ?: '';
						} else {
							$url = (string) $img;
						}
						if ( $url && filter_var( $url, FILTER_VALIDATE_URL ) ) {
							$urls[] = $url;
						}
					}
				}
			}
		}

		// Deduplicate while preserving order
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Divide collected images into N sets for N social posts.
	 *
	 * - ≥ (max_per_post + 1) images: vary sets across posts.
	 * - Fewer images: all posts share the same set.
	 *
	 * @param  string[] $images
	 * @param  int      $post_count   Number of social posts being created.
	 * @param  int      $max_per_post Max images per post slot.
	 * @return array[]  $post_count-element array of image URL sets.
	 */
	private static function build_image_sets( array $images, int $post_count = 3, int $max_per_post = self::IMAGES_PER_POST ): array {
		$max_per_post = max( 1, $max_per_post );
		$n            = count( $images );
		$sets         = [];

		if ( $n >= ( $max_per_post + 1 ) ) {
			// Enough images to vary: post 1 = first N, post 2 = last N, rest = random N.
			$sets[0] = array_slice( $images, 0, $max_per_post );
			$sets[1] = array_slice( $images, -$max_per_post );
			$pool    = $images;
			shuffle( $pool );
			$random = array_slice( $pool, 0, $max_per_post );
			for ( $i = 0; $i < $post_count; $i++ ) {
				if ( ! isset( $sets[ $i ] ) ) {
					$sets[ $i ] = $random;
				}
			}
		} else {
			$base = array_slice( $images, 0, $max_per_post );
			for ( $i = 0; $i < $post_count; $i++ ) {
				$sets[ $i ] = $base;
			}
		}

		return $sets;
	}

	/**
	 * Upload each image in the set to Postly CDN.
	 * Skips images that fail to upload (logs warning, does not throw).
	 *
	 * @param  Postly_Client $client
	 * @param  string[]      $image_urls
	 * @return string[]      Uploaded CDN URLs.
	 */
	private static function upload_images( Postly_Client $client, array $image_urls ): array {
		$uploaded = [];
		foreach ( $image_urls as $url ) {
			try {
				$uploaded[] = $client->upload_image( $url );
			} catch ( \RuntimeException $e ) {
				// Non-fatal: log and continue
				error_log( "[SCOS SMA] Failed to upload image {$url}: " . $e->getMessage() );
			}
		}
		return $uploaded;
	}

	/**
	 * Detect content type for the prompt context.
	 * Uses BW_Content_Type_Helper if available, otherwise falls back to post_type.
	 */
	private static function get_content_type( \WP_Post $post ): string {
		if ( class_exists( '\BW_Content_Type_Helper' ) ) {
			try {
				return (string) \BW_Content_Type_Helper::get_content_type( $post->ID );
			} catch ( \Exception $e ) {
				// Fall through
			}
		}
		return $post->post_type;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Log
	// ──────────────────────────────────────────────────────────────────────────

	private static function write_log( int $post_id, array $entry ): void {
		$log = get_option( self::LOG_OPTION, [] );
		if ( ! is_array( $log ) ) {
			$log = [];
		}
		// Keep last 200 entries to prevent unbounded growth
		$log[ $post_id ] = $entry;
		if ( count( $log ) > 200 ) {
			$log = array_slice( $log, -200, null, true );
		}
		update_option( self::LOG_OPTION, $log, false );
	}

	/**
	 * Retrieve the log entry for a specific post, or all entries.
	 *
	 * @param  int|null $post_id
	 * @return array
	 */
	public static function get_log( ?int $post_id = null ): array {
		$log = get_option( self::LOG_OPTION, [] );
		if ( ! is_array( $log ) ) {
			return [];
		}
		if ( $post_id !== null ) {
			return $log[ $post_id ] ?? [];
		}
		return $log;
	}
}
