<?php
/**
 * Project Selector REST endpoint — editor-context only.
 *
 * Registers `site-essentials/v1/projects` for the Project Selector Gutenberg
 * block's picker UI. Uses the WordPress auth cookie/nonce
 * (`current_user_can( 'edit_posts' )`) — not for external API consumers.
 *
 * v1.0 | 2026-08-20
 *
 * @package    SiteEssentials
 * @subpackage Modules\CustomPosts\Projects
 */

namespace SiteEssentials\Modules\CustomPosts\Projects;

use SiteEssentials\Modules\CustomPosts\Cpt_Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Project_Selector_REST {

	const NAMESPACE_PREFIX = 'site-essentials/v1';

	/**
	 * Register REST routes on rest_api_init.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	/**
	 * Register the projects list endpoint.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_PREFIX,
			'/projects',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_all' ],
				'permission_callback' => [ self::class, 'permission_check' ],
			]
		);
	}

	/**
	 * Permission callback — editor-context only.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function permission_check(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * GET /site-essentials/v1/projects
	 *
	 * Returns all published projects ordered by title, for the block picker.
	 *
	 * @since 1.0.0
	 * @return \WP_REST_Response
	 */
	public static function get_all(): \WP_REST_Response {
		$projects = get_posts( [
			'post_type'      => Cpt_Module::POST_TYPE_PROJECTS,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		] );

		return new \WP_REST_Response( array_map( [ self::class, 'format_project' ], $projects ), 200 );
	}

	/**
	 * Shape a WP_Post into the response array expected by the block JS.
	 *
	 * @since 1.0.0
	 * @param \WP_Post $project Project post.
	 * @return array
	 */
	private static function format_project( \WP_Post $project ): array {
		$thumb_id = get_post_thumbnail_id( $project );

		return [
			'id'        => (int) $project->ID,
			'title'     => (string) get_the_title( $project ),
			'thumbnail' => $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '',
		];
	}
}
