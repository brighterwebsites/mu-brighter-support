<?php
/**
 * Breadcrumbs Meta Box
 *
 * Adds breadcrumb field for social media shortlinks
 *
 * @package BrighterCore
 * @subpackage SocialAmplification
 * @version 1.0.0
 */

if (!defined('ABSPATH')) exit;

class BW_Breadcrumbs_Meta {

    /**
     * Initialize hooks
     */
    public function init() {
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('save_post', array($this, 'save_meta_box'), 10, 2);
    }

    /**
     * Add breadcrumbs meta box to posts and pages
     */
    public function add_meta_box() {
        // Suppressed when SEO Meta / SA handle fields, or Site Essentials is present (avoid legacy duplicate).
        if ( defined( 'SCOS_SEO_ACTIVE' ) || defined( 'SCOS_SA_ACTIVE' ) || defined( 'SITE_ESSENTIALS_VERSION' ) ) {
            return;
        }

        $post_types = array('post', 'page', 'folio', 'projects');

        foreach ($post_types as $post_type) {
            add_meta_box(
                'bw_breadcrumb_meta',
                __('Breadcrumb (Short Title)', 'brighterwebsites'),
                array($this, 'render_meta_box'),
                $post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * Render breadcrumbs meta box
     */
    public function render_meta_box($post) {
        wp_nonce_field('bw_breadcrumb_meta', 'bw_breadcrumb_nonce');

        $breadcrumb = get_post_meta($post->ID, '_bw_breadcrumb', true);

        // Auto-generate suggestion from title if empty
        if (empty($breadcrumb)) {
            $breadcrumb = $this->generate_breadcrumb_from_title($post->post_title);
        }

        ?>
        <div class="bw-breadcrumb-field">
            <p>
                <label for="bw_breadcrumb" style="font-weight: 600;">
                    <?php _e('Short Title for URLs', 'brighterwebsites'); ?>
                </label>
            </p>
            <input type="text"
                   id="bw_breadcrumb"
                   name="bw_breadcrumb"
                   value="<?php echo esc_attr($breadcrumb); ?>"
                   class="widefat"
                   placeholder="e.g., seo-signals" />
            <p class="description">
                <?php _e('Used for breadcrumbs and YOURLS shortlinks. Keep it short and SEO-friendly.', 'brighterwebsites'); ?>
            </p>
            <p class="description">
                <strong><?php _e('Example shortlink:', 'brighterwebsites'); ?></strong>
                <code>https://bweb1.com.au/<span id="breadcrumb-preview"><?php echo esc_html($breadcrumb ?: 'example'); ?></span>-fb</code>
            </p>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#bw_breadcrumb').on('input', function() {
                var value = $(this).val() || 'example';
                $('#breadcrumb-preview').text(value);
            });
        });
        </script>

        <style>
        .bw-breadcrumb-field input {
            margin-bottom: 10px;
        }
        .bw-breadcrumb-field code {
            background: #f0f0f1;
            padding: 3px 6px;
            font-size: 12px;
            word-break: break-all;
        }
        </style>
        <?php
    }

    /**
     * Save breadcrumb meta
     */
    public function save_meta_box($post_id, $post) {
        // Verify nonce
        if (!isset($_POST['bw_breadcrumb_nonce']) || !wp_verify_nonce($_POST['bw_breadcrumb_nonce'], 'bw_breadcrumb_meta')) {
            return;
        }

        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save breadcrumb
        if (isset($_POST['bw_breadcrumb'])) {
            $breadcrumb = sanitize_title($_POST['bw_breadcrumb']);
            update_post_meta($post_id, '_bw_breadcrumb', $breadcrumb);
        }
    }

    /**
     * Generate breadcrumb from post title
     */
    private function generate_breadcrumb_from_title($title) {
        // Remove common words
        $stop_words = array('the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for');

        $title = strtolower($title);
        $words = explode(' ', $title);
        $words = array_filter($words, function($word) use ($stop_words) {
            return !in_array($word, $stop_words) && strlen($word) > 2;
        });

        // Take first 3-4 meaningful words
        $words = array_slice($words, 0, 4);
        $breadcrumb = implode('-', $words);

        return sanitize_title($breadcrumb);
    }

    /**
     * Get breadcrumb for a post (with fallback)
     */
    public static function get_breadcrumb($post_id) {
        $breadcrumb = get_post_meta($post_id, '_bw_breadcrumb', true);

        if (empty($breadcrumb)) {
            $post = get_post($post_id);
            if ($post) {
                $breadcrumb = sanitize_title(substr($post->post_title, 0, 50));
            }
        }

        return $breadcrumb;
    }

}
