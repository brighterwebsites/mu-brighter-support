<?php
/**
 * Social Amplification API Endpoints
 *
 * REST API endpoints for social media amplification system
 *
 * @package BrighterCore
 * @subpackage SocialAmplification
 * @version 1.1.0
 *
 * v1.0 | original
 * v1.1 | 2026-06-29 — Read scos_seo_tldr + scos_sa_shortlink_slug first; bw_ keys as fallback only.
 * v1.2 | 2026-07-17 — Strip HTML from TLDR reads (field now supports bold/lists/links
 *                      via wp_editor(); this data feeds plain-text AI prompts/captions).
 */

if (!defined('ABSPATH')) exit;

class BW_Social_Amplification_API {

    /**
     * @var Brighter_API_Auth Authentication handler
     */
    private $auth;

    /**
     * @var BW_Talking_Points Talking points manager
     */
    private $talking_points;

    /**
     * API namespace
     */
    const NAMESPACE = 'brighter-core/v1';

    /**
     * Constructor
     *
     * @param Brighter_API_Auth $auth Authentication handler
     * @param BW_Talking_Points $talking_points Talking points manager
     */
    public function __construct($auth, $talking_points) {
        $this->auth = $auth;
        $this->talking_points = $talking_points;
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Get content inventory
        register_rest_route(self::NAMESPACE, '/social-amplification/inventory', array(
            'methods'  => 'GET',
            'callback' => array($this, 'get_content_inventory'),
            'permission_callback' => array($this->auth, 'verify_token')
        ));

        // Get talking points
        register_rest_route(self::NAMESPACE, '/social-amplification/talking-points', array(
            'methods'  => 'GET',
            'callback' => array($this, 'get_talking_points'),
            'permission_callback' => array($this->auth, 'verify_token'),
            'args' => array(
                'content_type' => array(
                    'description' => 'Filter by content type slug',
                    'type'        => 'string',
                    'required'    => false
                )
            )
        ));

        // Prompt-data for Make.com (Gemini → GPT flow). No prompt built here; returns structured JSON.
        // See PROMPT-DATA-ENDPOINT.md for scenario, strategy, request/response.
        register_rest_route(self::NAMESPACE, '/social-amplification/generate-prompt', array(
            'methods'  => 'GET',
            'callback' => array($this, 'get_prompt_data'),
            'permission_callback' => array($this->auth, 'verify_token'),
            'args' => array(
                'post_id' => array(
                    'description'       => 'Post ID (required if url not provided)',
                    'type'              => 'integer',
                    'required'          => false,
                    'validate_callback' => function($value, $request, $key) {
                        if (empty($value) && !empty($request->get_param('url'))) return true;
                        return empty($value) || is_numeric($value);
                    }
                ),
                'url' => array(
                    'description'       => 'Post URL (required if post_id not provided)',
                    'type'              => 'string',
                    'format'            => 'uri',
                    'required'          => false,
                    'validate_callback' => function($value, $request, $key) {
                        if (empty($value) && !empty($request->get_param('post_id'))) return true;
                        return empty($value) || filter_var($value, FILTER_VALIDATE_URL);
                    }
                )
            )
        ));

        // Get content types
        register_rest_route(self::NAMESPACE, '/social-amplification/content-types', array(
            'methods'  => 'GET',
            'callback' => array($this, 'get_content_types'),
            'permission_callback' => array($this->auth, 'verify_token')
        ));

        // Create YOURLS shortlink
        register_rest_route(self::NAMESPACE, '/social-amplification/create-shortlink', array(
            'methods'  => 'POST',
            'callback' => array($this, 'create_shortlink'),
            'permission_callback' => array($this->auth, 'verify_token'),
            'args' => array(
                'post_id' => array(
                    'description' => 'Post ID (required if url not provided)',
                    'type'        => 'integer',
                    'required'    => false
                ),
                'url' => array(
                    'description' => 'Post URL (required if post_id not provided)',
                    'type'        => 'string',
                    'format'      => 'uri',
                    'required'    => false
                ),
                'platform' => array(
                    'description' => 'Social platform',
                    'type'        => 'string',
                    'enum'        => array('facebook', 'linkedin', 'twitter', 'instagram', 'gmb'),
                    'required'    => true
                ),
                'format' => array(
                    'description' => 'Content format for UTM',
                    'type'        => 'string',
                    'enum'        => array('link', 'img', 'reel', 'video'),
                    'default'     => 'link'
                )
            )
        ));

        // Get image optimization data
        register_rest_route(self::NAMESPACE, '/image-optimization/get-data', array(
            'methods'  => 'GET',
            'callback' => array($this, 'get_image_optimization_data'),
            'permission_callback' => array($this->auth, 'verify_token'),
            'args' => array(
                'post_id' => array(
                    'description'       => 'Post ID (required if url not provided)',
                    'type'              => 'integer',
                    'required'          => false,
                    'validate_callback' => function($value, $request, $key) {
                        if (empty($value) && !empty($request->get_param('url'))) return true;
                        return empty($value) || is_numeric($value);
                    }
                ),
                'url' => array(
                    'description'       => 'Post URL (required if post_id not provided)',
                    'type'              => 'string',
                    'format'            => 'uri',
                    'required'          => false,
                    'validate_callback' => function($value, $request, $key) {
                        if (empty($value) && !empty($request->get_param('post_id'))) return true;
                        return empty($value) || filter_var($value, FILTER_VALIDATE_URL);
                    }
                )
            )
        ));
    }

    /**
     * Get content inventory
     *
     * Returns all published content that can be amplified
     */
    public function get_content_inventory($request) {
        // Try cache first
        $cache_key = 'bw_social_inventory';
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return rest_ensure_response($cached);
        }

        $inventory = array();

        // Define post types to include
        $post_types = array('post', 'page', 'folio', 'projects');

        foreach ($post_types as $post_type) {
            if (!post_type_exists($post_type)) {
                continue;
            }

            $args = array(
                'post_type'      => $post_type,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'date',
                'order'          => 'DESC'
            );

            $query = new WP_Query($args);

            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    $post_id = get_the_ID();

                    $inventory[] = array(
                        'id'           => $post_id,
                        'title'        => get_the_title(),
                        'url'          => get_permalink(),
                        'excerpt'      => get_the_excerpt(),
                        'post_type'    => $post_type,
                        'content_type' => BW_Content_Type_Helper::get_content_type($post_id, $post_type),
                        'date'         => get_the_date('c'),
                        'modified'     => get_the_modified_date('c')
                    );
                }
                wp_reset_postdata();
            }
        }

        $response = array(
            'success' => true,
            'count'   => count($inventory),
            'items'   => $inventory
        );

        // Cache for 5 minutes
        set_transient($cache_key, $response, 300);

        return rest_ensure_response($response);
    }

    /**
     * Get talking points
     */
    public function get_talking_points($request) {
        $content_type = $request->get_param('content_type');
        $talking_points = $this->talking_points->get_talking_points_by_content_type($content_type);

        return rest_ensure_response(array(
            'success' => true,
            'count'   => count($talking_points),
            'items'   => $talking_points
        ));
    }

    /**
     * Get content types
     */
    public function get_content_types($request) {
        $content_types = $this->talking_points->get_content_types();

        return rest_ensure_response(array(
            'success' => true,
            'count'   => count($content_types),
            'items'   => $content_types
        ));
    }

    /**
     * Get prompt-data for Make.com (Gemini → GPT flow).
     *
     * Returns structured JSON only. No prompt is built here; Make builds it from this data.
     * Step 1: Gemini uses framing_options, source_material, context, count_h2.
     * Step 2: GPT uses Gemini output + selected fields from this response.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_prompt_data($request) {
        $post_id = $request->get_param('post_id');
        $url = $request->get_param('url');

        if (!$post_id && !$url) {
            return new WP_Error('missing_parameter', 'Either post_id or url is required', array('status' => 400));
        }

        if ($url && !$post_id) {
            $post_id = url_to_postid($url);
            if (!$post_id) {
                return new WP_Error('invalid_url', 'Could not find post for URL: ' . $url, array('status' => 404));
            }
        }

        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found', array('status' => 404));
        }

        $content_type = BW_Content_Type_Helper::get_content_type($post_id, $post->post_type);
        $purpose = get_post_meta($post_id, 'scos_ca_purpose', true) ?: get_post_meta($post_id, 'bw_purpose', true) ?: '';
        $intent  = get_post_meta($post_id, 'scos_ca_intent',  true) ?: get_post_meta($post_id, 'bw_intent',  true) ?: '';
        // TLDR may contain basic HTML (bold/lists/links) via wp_editor() — strip tags
        // since this feeds AI prompts and social captions that expect plain text.
        $tldr    = wp_strip_all_tags((string) get_post_meta($post_id, 'scos_seo_tldr', true));
        if (empty($tldr)) {
            $tldr = get_post_meta($post_id, 'bw_tldr', true); // TODO: remove fallback once all sites resaved
        }
        if (empty($tldr)) {
            $tldr = get_the_excerpt($post_id) ?: '';
        }
        $h2_count = (int) get_post_meta($post_id, 'bw_h2_count', true);
        $source_url = get_permalink($post_id) ?: '';
        // Prefer the pre-computed rendered markdown (scos_ca_content_md) — fully
        // rendered page content with resolved ACF / dynamic fields / loops. Fall
        // back to live aggregated content (rendered-first, JSON parse fallback).
        $raw_content = get_post_meta($post_id, 'scos_ca_content_md', true);
        if (empty($raw_content)) {
            $raw_content = class_exists('BW_Content_Analysis') ? BW_Content_Analysis::get_aggregated_content($post_id) : $post->post_content;
        }
        $source_material = self::sanitize_content_for_prompt($raw_content);

        $talking_points = $this->talking_points->get_talking_points_by_content_type($content_type);
        $framing_options = $this->build_framing_options($talking_points, $content_type);

        $data = array(
            'post_id'          => (int) $post_id,
            'source_url'       => $source_url,
            'context'          => array(
                'title'   => $post->post_title,
                'type'    => $content_type,
                'purpose' => $purpose,
                'intent'  => $intent,
            ),
            'framing_options'  => $framing_options,
            'source_material'  => $source_material,
            'source_tldr'      => $tldr,
            'count_h2'         => $h2_count,
        );

        return rest_ensure_response($data);
    }

    /**
     * Build framing_options array from talking points.
     *
     * @param array  $talking_points From get_talking_points_by_content_type().
     * @param string $content_type   Slug used for filtering (same for all items).
     * @return array
     */
    private function build_framing_options($talking_points, $content_type) {
        $out = array();
        foreach ($talking_points as $tp) {
            $min = isset($tp['word_count_min']) ? (int) $tp['word_count_min'] : 50;
            $max = isset($tp['word_count_max']) ? (int) $tp['word_count_max'] : 130;
            $out[] = array(
                'label'         => isset($tp['name']) ? $tp['name'] : '',
                'type'          => $content_type,
                'context'       => isset($tp['context']) ? $tp['context'] : '',
                'hook_examples' => self::text_to_array(isset($tp['example']) ? $tp['example'] : ''),
                'cta_examples'  => self::text_to_array(isset($tp['cta_example']) ? $tp['cta_example'] : ''),
                'target_length' => $min . '-' . $max,
            );
        }
        return $out;
    }

    /**
     * Split text into non-empty trimmed strings (newlines or commas).
     *
     * @param string $text
     * @return array
     */
    private static function text_to_array($text) {
        if (empty($text) || !is_string($text)) {
            return array();
        }
        $lines = preg_split('/[\r\n]+/', $text);
        $items = array();
        foreach ($lines as $line) {
            foreach (array_map('trim', explode(',', $line)) as $part) {
                if ($part !== '') {
                    $items[] = $part;
                }
            }
        }
        return array_values(array_unique($items));
    }

    /**
     * Sanitize post content for prompt data.
     *
     * - Converts H2 headings to markdown format (## Heading)
     * - Converts paragraphs to single newlines (no extra blank lines)
     * - Strips other HTML tags
     * - Removes excessive whitespace
     *
     * @param string $content Raw post content (HTML)
     * @return string Cleaned content ready for AI processing
     */
    private static function sanitize_content_for_prompt($content) {
        if (empty($content) || !is_string($content)) {
            return '';
        }

        // Convert H2 headings to markdown format (## Heading)
        $content = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', '## $1', $content);

        // Convert paragraph tags to single newlines
        // Replace <p>...</p> with content + single newline
        $content = preg_replace('/<p[^>]*>(.*?)<\/p>/is', "$1\n", $content);

        // Convert other block-level elements to newlines
        $content = preg_replace('/<br\s*\/?>/i', "\n", $content);
        $content = preg_replace('/<\/?(div|section|article|header|footer|aside|nav)[^>]*>/i', "\n", $content);

        // Strip all remaining HTML tags
        $content = wp_strip_all_tags($content);

        // Decode HTML entities
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace:
        // - Replace multiple spaces with single space
        $content = preg_replace('/[ \t]+/', ' ', $content);
        
        // - Replace 3+ newlines with single newline (removes excessive blank lines)
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        
        // - Remove leading/trailing whitespace from each line
        $lines = explode("\n", $content);
        $lines = array_map('trim', $lines);
        
        // - Remove all blank lines (no extra spacing between paragraphs)
        $cleaned_lines = array();
        foreach ($lines as $line) {
            if ($line !== '') {
                $cleaned_lines[] = $line;
            }
        }

        // Join lines back together
        $content = implode("\n", $cleaned_lines);

        // Final trim
        $content = trim($content);

        return $content;
    }

    /**
     * Create YOURLS shortlink
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function create_shortlink($request) {
        $post_id = $request->get_param('post_id');
        $url = $request->get_param('url');
        $platform = $request->get_param('platform');
        $format = $request->get_param('format') ?: 'link';

        // Validate that we have either post_id or url
        if (!$post_id && !$url) {
            return new WP_Error('missing_parameter', 'Either post_id or url is required', array('status' => 400));
        }

        // If URL provided, look up the post ID
        if ($url && !$post_id) {
            $post_id = url_to_postid($url);
            if (!$post_id) {
                return new WP_Error('invalid_url', 'Could not find post for URL: ' . $url, array('status' => 404));
            }
        }

        // Get post
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found', array('status' => 404));
        }

        // Get breadcrumb (shortlink slug)
        $breadcrumb = get_post_meta($post_id, 'scos_sa_shortlink_slug', true);
        if (empty($breadcrumb)) {
            $breadcrumb = get_post_meta($post_id, '_bw_breadcrumb', true); // TODO: remove fallback once all sites resaved
        }
        if (empty($breadcrumb)) {
            // Fallback to sanitized title
            $breadcrumb = sanitize_title(substr($post->post_title, 0, 50));
        }

        // Build shortlink keyword
        $keyword = BW_YOURLS_Helper::build_keyword($breadcrumb, $platform);

        // Get content type for UTM
        $content_type = BW_Content_Type_Helper::get_content_type($post_id, $post->post_type);

        // Build destination URL with UTM parameters
        $post_url = get_permalink($post_id);
        $destination_url = BW_YOURLS_Helper::build_destination_url($post_url, $platform, $content_type, $format);

        // Create shortlink via YOURLS
        $result = BW_YOURLS_Helper::create_shortlink($destination_url, $keyword, $post->post_title);

        // Check for errors
        if (is_wp_error($result)) {
            return $result;
        }

        // Build response
        $response = array(
            'success' => true,
            'shorturl' => $result['shorturl'],
            'keyword' => $result['keyword'],
            'destination_url' => $destination_url,
            'meta' => array(
                'post_id' => $post_id,
                'post_title' => $post->post_title,
                'post_url' => $post_url,
                'breadcrumb' => $breadcrumb,
                'platform' => $platform,
                'content_type' => $content_type,
                'format' => $format
            )
        );

        // Add warning if YOURLS modified the keyword
        if (isset($result['keyword_modified']) && $result['keyword_modified']) {
            $response['warning'] = array(
                'message' => 'YOURLS modified the keyword (likely stripped hyphens or special characters)',
                'keyword_requested' => $result['keyword_requested'],
                'keyword_actual' => $result['keyword']
            );
        }

        return rest_ensure_response($response);
    }

    /**
     * Get image optimization data
     *
     * Returns post content + all images (featured + attached) with metadata for AI optimization
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_image_optimization_data($request) {
        $post_id = $request->get_param('post_id');
        $url = $request->get_param('url');

        if (!$post_id && !$url) {
            return new WP_Error('missing_parameter', 'Either post_id or url is required', array('status' => 400));
        }

        if ($url && !$post_id) {
            $post_id = url_to_postid($url);
            if (!$post_id) {
                return new WP_Error('invalid_url', 'Could not find post for URL: ' . $url, array('status' => 404));
            }
        }

        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found', array('status' => 404));
        }

        // Get post context — strip tags: TLDR may contain basic HTML (bold/lists/links)
        // via wp_editor(), but this feeds a plain-text prompt context.
        $tldr = wp_strip_all_tags((string) get_post_meta($post_id, 'scos_seo_tldr', true));
        if (empty($tldr)) {
            $tldr = get_post_meta($post_id, 'bw_tldr', true); // TODO: remove fallback once all sites resaved
        }
        if (empty($tldr)) {
            $tldr = get_the_excerpt($post_id) ?: '';
        }

        $breadcrumb = get_post_meta($post_id, 'scos_sa_shortlink_slug', true);
        if (empty($breadcrumb)) {
            $breadcrumb = get_post_meta($post_id, '_bw_breadcrumb', true); // TODO: remove fallback once all sites resaved
        }
        if (empty($breadcrumb)) {
            $breadcrumb = sanitize_title(substr($post->post_title, 0, 50));
        }

        // Prefer pre-computed rendered markdown (scos_ca_content_md); fall back to
        // live aggregated content (rendered-first, JSON parse fallback).
        $content = get_post_meta($post_id, 'scos_ca_content_md', true);
        if (empty($content)) {
            $content = class_exists('BW_Content_Analysis')
                ? BW_Content_Analysis::get_aggregated_content($post_id)
                : $post->post_content;
        }

        // Strip HTML for cleaner AI processing
        $content_plain = wp_strip_all_tags($content);

        // Get featured image data
        $featured_image = null;
        $featured_image_id = get_post_thumbnail_id($post_id);
        if ($featured_image_id) {
            $featured_image = $this->get_image_data($featured_image_id);
        }

        // Get all attached images
        $attached_images = array();
        $attachments = get_attached_media('image', $post_id);
        foreach ($attachments as $attachment) {
            // Skip featured image (already included above)
            if ($attachment->ID === $featured_image_id) {
                continue;
            }
            $attached_images[] = $this->get_image_data($attachment->ID);
        }

        $data = array(
            'post_id'         => (int) $post_id,
            'title'           => $post->post_title,
            'url'             => get_permalink($post_id),
            'content'         => $content_plain,
            'tldr'            => $tldr,
            'breadcrumb'      => $breadcrumb,
            'featured_image'  => $featured_image,
            'attached_images' => $attached_images,
            'image_count'     => array(
                'featured' => $featured_image ? 1 : 0,
                'attached' => count($attached_images),
                'total'    => ($featured_image ? 1 : 0) + count($attached_images)
            )
        );

        return rest_ensure_response($data);
    }

    /**
     * Get image data for optimization
     *
     * @param int $attachment_id Image attachment ID
     * @return array Image metadata
     */
    private function get_image_data($attachment_id) {
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            return null;
        }

        // Get image metadata
        $image_meta = wp_get_attachment_metadata($attachment_id);
        
        return array(
            'id'          => (int) $attachment_id,
            'url'         => wp_get_attachment_url($attachment_id),
            'title'       => $attachment->post_title,
            'alt'         => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'caption'     => $attachment->post_excerpt,
            'description' => $attachment->post_content,
            'filename'    => basename(get_attached_file($attachment_id)),
            'mime_type'   => $attachment->post_mime_type,
            'dimensions'  => array(
                'width'  => isset($image_meta['width']) ? (int) $image_meta['width'] : null,
                'height' => isset($image_meta['height']) ? (int) $image_meta['height'] : null
            )
        );
    }
}
