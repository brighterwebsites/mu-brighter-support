<?php
/**
 * Social Amplification Webhook Trigger
 *
 * Sends post data to Make.com when posts are published/updated
 *
 * @package BrighterCore
 * @subpackage SocialAmplification
 * @version 1.1.0
 *
 * v1.0 | original
 * v1.1 | 2026-06-29 — Read scos_seo_tldr first; bw_tldr as fallback only.
 * v1.2 | 2026-07-17 — Strip HTML from TLDR read (field now supports bold/lists/links
 *                      via wp_editor()).
 */

if (!defined('ABSPATH')) exit;

class BW_Social_Webhook_Trigger {

    /**
     * Make.com webhook URL
     */
    private $webhook_url;

    /**
     * Post types to trigger on
     */
    private $post_types = array('post', 'page', 'folio', 'projects');

    /**
     * Initialize hooks
     */
    public function init() {
        // Get webhook URL from settings (or use default for now)
        $this->webhook_url = get_option('bw_social_webhook_url', '');

        // DISABLED: Automatic webhook trigger on publish (now manual only)
        // Too many Make.com calls - use manual "Create Social Post" button instead
        // add_action('publish_post', array($this, 'trigger_webhook'), 10, 2);
        // add_action('publish_page', array($this, 'trigger_webhook'), 10, 2);
        // add_action('publish_folio', array($this, 'trigger_webhook'), 10, 2);
        // add_action('publish_projects', array($this, 'trigger_webhook'), 10, 2);

        // Add settings page hook
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * Register webhook settings
     */
    public function register_settings() {
        register_setting('brighter_social_amplification', 'bw_social_webhook_url', array(
            'sanitize_callback' => 'esc_url_raw',
            'default' => ''
        ));

        register_setting('brighter_social_amplification', 'bw_social_webhook_enabled', array(
            'sanitize_callback' => 'absint',
            'default' => 0
        ));
    }

    /**
     * Trigger webhook when post is published/updated
     *
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     */
    public function trigger_webhook($post_id, $post) {
        // Check if webhook is enabled
        if (!get_option('bw_social_webhook_enabled', 0)) {
            return;
        }

        // Don't trigger for revisions or autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // Only trigger for specified post types
        if (!in_array($post->post_type, $this->post_types, true)) {
            return;
        }

        // Only trigger for published posts
        if ($post->post_status !== 'publish') {
            return;
        }

        // Get breadcrumb and content type
        $breadcrumb = BW_Breadcrumbs_Meta::get_breadcrumb($post_id);
        $content_type = BW_Content_Type_Helper::get_content_type($post_id, $post->post_type);

        // Get image optimization data
        $image_data = $this->get_image_optimization_data($post_id);

        // Prepare payload
        $payload = array(
            'post_id' => $post_id,
            'post_url' => get_permalink($post_id),
            'post_title' => get_the_title($post_id),
            'post_type' => $post->post_type,
            'post_excerpt' => get_the_excerpt($post_id),
            'post_date' => get_the_date('c', $post_id),
            'post_modified' => get_the_modified_date('c', $post_id),
            'breadcrumb' => $breadcrumb,
            'content_type' => $content_type,
            'site_url' => get_site_url(),
            'trigger_time' => current_time('mysql'),
            'image_optimization' => $image_data, // Added for bulk image optimization
        );

        // Send webhook
        $this->send_webhook($payload);
    }

    /**
     * Send webhook to Make.com
     *
     * @param array $payload Data to send
     */
    private function send_webhook($payload) {
        // Always get fresh webhook URL from settings
        $webhook_url = get_option('bw_social_webhook_url', '');
        
        if (empty($webhook_url)) {
            error_log('BW Social Amplification: Webhook URL not configured (checked in send_webhook)');
            return;
        }

        // Send async request (don't wait for response)
        $response = wp_remote_post($webhook_url, array(
            'body' => json_encode($payload),
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'timeout' => 5,
            'blocking' => false, // Don't wait for response
            'sslverify' => true
        ));

        if (is_wp_error($response)) {
            error_log('BW Social Amplification: Webhook error for post ' . $payload['post_id'] . ' — ' . $response->get_error_message());
        }
    }

    /**
     * Manual trigger via button
     * Bypasses the "webhook enabled" check - manual triggers always work
     *
     * @param int $post_id Post ID
     * @return bool Success status
     */
    public function manual_trigger($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        // Don't trigger for revisions or autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return false;
        }

        // Only trigger for published posts
        if ($post->post_status !== 'publish') {
            return false;
        }

        // Get breadcrumb and content type
        $breadcrumb = BW_Breadcrumbs_Meta::get_breadcrumb($post_id);
        $content_type = BW_Content_Type_Helper::get_content_type($post_id, $post->post_type);

        // Get featured image data
        $featured_image_url = '';
        $featured_image_caption = '';
        $featured_image_social_url = '';
        if (has_post_thumbnail($post_id)) {
            $thumbnail_id = get_post_thumbnail_id($post_id);
            $featured_image_url = get_the_post_thumbnail_url($post_id, 'full');
            $featured_image_caption = get_the_post_thumbnail_caption($post_id);
            $featured_image_social_url = get_the_post_thumbnail_url($post_id, 'social-square'); // 1080x1080 for Instagram/Facebook
        }

        // Prepare payload (same as automatic trigger)
        $payload = array(
            'post_id' => $post_id,
            'post_url' => get_permalink($post_id),
            'post_title' => get_the_title($post_id),
            'post_type' => $post->post_type,
            'post_excerpt' => get_the_excerpt($post_id),
            'post_date' => get_the_date('c', $post_id),
            'post_modified' => get_the_modified_date('c', $post_id),
            'breadcrumb' => $breadcrumb,
            'content_type' => $content_type,
            'featured_image_url' => $featured_image_url,
            'featured_image_caption' => $featured_image_caption,
            'featured_image_social_url' => $featured_image_social_url, // 1080x1080 square for social media
            'site_url' => get_site_url(),
            'trigger_time' => current_time('mysql'),
            'trigger_type' => 'manual', // Flag to distinguish from automatic triggers
        );

        // Send webhook (bypasses enabled check)
        $this->send_webhook($payload);
        return true;
    }

    /**
     * Get image optimization data for webhook payload
     *
     * @param int $post_id Post ID
     * @return array Image data including featured and attached images
     */
    private function get_image_optimization_data($post_id) {
        // Get post content
        $post = get_post($post_id);
        // TLDR may contain basic HTML (bold/lists/links) via wp_editor() — strip tags,
        // this feeds AI image-optimization prompt context which expects plain text.
        $tldr = wp_strip_all_tags((string) get_post_meta($post_id, 'scos_seo_tldr', true));
        if (empty($tldr)) {
            $tldr = get_post_meta($post_id, 'bw_tldr', true); // TODO: remove fallback once all sites resaved
        }
        if (empty($tldr)) {
            $tldr = get_the_excerpt($post_id) ?: '';
        }

        // Prefer the pre-computed rendered markdown (scos_ca_content_md) — fully
        // rendered page content with resolved ACF / dynamic fields / loops. Fall
        // back to live aggregated content (rendered-first, JSON parse fallback).
        $raw_content = get_post_meta($post_id, 'scos_ca_content_md', true);
        if (empty($raw_content)) {
            $raw_content = class_exists('BW_Content_Analysis')
                ? BW_Content_Analysis::get_aggregated_content($post_id)
                : $post->post_content;
        }

        // Plain text version (fully stripped)
        $content_plain = wp_strip_all_tags($raw_content);

        // Source material version (with H2 as markdown for AI context)
        $source_material = $this->sanitize_content_for_prompt($raw_content);

        // Get featured image data
        $featured_image = null;
        $featured_image_id = get_post_thumbnail_id($post_id);
        if ($featured_image_id) {
            $featured_image = $this->get_single_image_data($featured_image_id);
        }

        // Get all attached images
        $attached_images = array();
        $attachments = get_attached_media('image', $post_id);
        foreach ($attachments as $attachment) {
            // Skip featured image (already included)
            if ($attachment->ID === $featured_image_id) {
                continue;
            }
            $attached_images[] = $this->get_single_image_data($attachment->ID);
        }

        return array(
            'content' => $content_plain,
            'source_material' => $source_material, // Formatted with H2 as markdown
            'tldr' => $tldr,
            'featured_image' => $featured_image,
            'attached_images' => $attached_images,
            'image_count' => array(
                'featured' => $featured_image ? 1 : 0,
                'attached' => count($attached_images),
                'total' => ($featured_image ? 1 : 0) + count($attached_images)
            )
        );
    }

    /**
     * Sanitize post content for prompt data (with H2 as markdown)
     *
     * @param string $content Raw post content (HTML)
     * @return string Cleaned content with H2 as markdown
     */
    private function sanitize_content_for_prompt($content) {
        if (empty($content) || !is_string($content)) {
            return '';
        }

        // Convert H2 headings to markdown format (## Heading)
        $content = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', '## $1', $content);

        // Convert paragraph tags to single newlines
        $content = preg_replace('/<p[^>]*>(.*?)<\/p>/is', "$1\n", $content);

        // Convert other block-level elements to newlines
        $content = preg_replace('/<br\s*\/?>/i', "\n", $content);
        $content = preg_replace('/<\/?(div|section|article|header|footer|aside|nav)[^>]*>/i', "\n", $content);

        // Strip all remaining HTML tags
        $content = wp_strip_all_tags($content);

        // Decode HTML entities
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace
        $content = preg_replace('/[ \t]+/', ' ', $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        
        // Remove leading/trailing whitespace from each line and blank lines
        $lines = explode("\n", $content);
        $lines = array_map('trim', $lines);
        $cleaned_lines = array();
        foreach ($lines as $line) {
            if ($line !== '') {
                $cleaned_lines[] = $line;
            }
        }

        $content = implode("\n", $cleaned_lines);
        return trim($content);
    }

    /**
     * Get single image data
     *
     * @param int $attachment_id Image attachment ID
     * @return array Image metadata
     */
    private function get_single_image_data($attachment_id) {
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            return null;
        }

        $image_meta = wp_get_attachment_metadata($attachment_id);
        
        return array(
            'id' => (int) $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
            'title' => $attachment->post_title,
            'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'caption' => $attachment->post_excerpt,
            'description' => $attachment->post_content,
            'filename' => basename(get_attached_file($attachment_id)),
            'mime_type' => $attachment->post_mime_type,
            'dimensions' => array(
                'width' => isset($image_meta['width']) ? (int) $image_meta['width'] : null,
                'height' => isset($image_meta['height']) ? (int) $image_meta['height'] : null
            )
        );
    }
}
