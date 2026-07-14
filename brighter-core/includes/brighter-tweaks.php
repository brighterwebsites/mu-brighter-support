<?php
/**
 * Brighter Tools: Tweaks
 *
 * File: brighter-tweaks.php
 * Version: 4.4.0
 *
 * Changelog:
 * 4.4.0 - Replaced global "Post Types" + "Preload Image Size" controls with a per-post-type
 *         registered image size selector (brighter_preload_post_type_sizes). Auto-migrates
 *         existing brighter_preload_post_types + brighter_preload_use_og_image on first load.
 *         Fixed Google Fonts Preload Card 1 save wiping the field: register_setting() used
 *         wp_kses_post as its sanitize_callback, which strips <link> tags (not part of the
 *         default "post" allowed tags), silently emptying the option on every save via
 *         options.php. Now uses a dedicated sanitiser that allows <link> attributes.
 *         Also removed process_save() resets of post-type/webp/size options that no longer
 *         belong to the Per-Page Preloads form (they are now saved independently via Card 2).
 * 4.3.2 - Fixed duplicate "Preload Featured Images on Singles" / "Google Fonts Preload"
 *         fields bleeding into the "Per-Page Preloads" card when embedded in Site
 *         Essentials > Performance. render_preload_form() now only calls
 *         do_settings_sections() for the standalone (non-embedded) tweaks page.
 * 4.3.1 - MERGED: All features from v4.0.0 + v4.3.0, OG image size, Google Fonts removal fixed
 * 4.3.0 - FIXED: Preloads now working, pagination fixed, cleaned duplicate code
 * 4.0.0 - Initial version
 *
 * Purpose: Site tweaks and performance optimizations
 * - Per-page image/asset preloads
 * - Auto-preload featured images on singles (using OG image size)
 * - Remove Google Fonts completely
 * - LCP image optimization with fetchpriority=high
 * - Normalize /core/storage/ paths
 */

defined('ABSPATH') || exit;

class Brighter_Tweaks {
    const OPT = 'bw_preloads_map';
    const OPT_THEME = 'theme_colour';
    const OPT_POST_TYPES = 'brighter_preload_post_types'; // Legacy, read only during migration.
    const OPT_POST_TYPE_SIZES = 'brighter_preload_post_type_sizes';
    const OPT_GOOGLE_FONTS = 'bw_google_fonts_preload';
    const OPT_PTSIZES_MIGRATED = 'brighter_tweaks_ptsizes_migrated';

    public static function boot() {
        // Admin
        add_action('admin_init', [__CLASS__, 'maybe_migrate_post_type_sizes'], 5);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_post_brighter_tweaks_save', [__CLASS__, 'handle_save_redirect']);
        
        // Frontend output - priority 1 for early loading
        add_action('wp_head', [__CLASS__, 'output_preloads'], 1);
        add_action('wp_head', [__CLASS__, 'output_featured_image_preload'], 1);
        add_action('wp_head', [__CLASS__, 'output_theme_color_meta'], 1);
        
        // Google Fonts removal - CRITICAL
        add_action('wp_loaded', [__CLASS__, 'remove_google_fonts']);
        
        // Meta box for individual pages
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('save_post_page', [__CLASS__, 'save_meta_box']);
        
        // LCP optimization filters
        add_filter('wp_get_attachment_image_attributes', [__CLASS__, 'add_fetchpriority'], 99, 3);
        add_filter('the_content', [__CLASS__, 'add_fetchpriority_to_content'], 99);
        
        // Path normalization
        add_action('template_redirect', [__CLASS__, 'normalize_paths']);
    }

    /**
     * One-time migration: convert the old global "Post Types" + "Preload Image Size"
     * (og-image vs full) controls into the new per-post-type size map. Runs once, guarded
     * by a dedicated flag option (not get_option() default checks, since register_setting()
     * registers a default for OPT_POST_TYPE_SIZES that would otherwise mask "never saved").
     */
    public static function maybe_migrate_post_type_sizes() {
        if (get_option(self::OPT_PTSIZES_MIGRATED)) {
            return;
        }

        $old_types = (array) get_option(self::OPT_POST_TYPES, []);
        $used_og = get_option('brighter_preload_use_og_image', 1);
        $default_size = $used_og ? 'og-image' : 'full';

        $map = [];
        foreach ($old_types as $type) {
            $type = sanitize_key($type);
            if ($type !== '') {
                $map[$type] = $default_size;
            }
        }

        update_option(self::OPT_POST_TYPE_SIZES, $map);
        update_option(self::OPT_PTSIZES_MIGRATED, 1);
    }

    /**
     * Render the per-post-type "which registered image size to preload" select fields.
     */
    public static function render_post_type_size_fields() {
        $selected = (array) get_option(self::OPT_POST_TYPE_SIZES, []);
        $post_types = get_post_types(['public' => true], 'objects');
        $registered_sizes = wp_get_registered_image_subsizes();

        foreach ($post_types as $type => $obj) {
            $current = isset($selected[$type]) ? $selected[$type] : 'none';
            echo '<div style="margin-bottom:10px">';
            echo '<label style="display:inline-block;min-width:180px;font-weight:600">' . esc_html($obj->labels->singular_name . " ($type)") . '</label> ';
            echo '<select name="' . esc_attr(self::OPT_POST_TYPE_SIZES) . '[' . esc_attr($type) . ']">';
            echo '<option value="none"' . selected('none', $current, false) . '>' . esc_html__('Do not preload', 'brighterwebsites') . '</option>';
            echo '<option value="full"' . selected('full', $current, false) . '>' . esc_html__('Full size (original)', 'brighterwebsites') . '</option>';
            foreach ($registered_sizes as $size_name => $size) {
                $label = $size_name;
                $label .= !empty($size['height']) ? " ({$size['width']}×{$size['height']})" : " ({$size['width']}w)";
                echo '<option value="' . esc_attr($size_name) . '"' . selected($size_name, $current, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
            echo '</div>';
        }

        echo '<p class="description">' . esc_html__('"Do not preload" skips this post type entirely. If the selected size does not exist for a given image, the full-size image is used as a fallback.', 'brighterwebsites') . '</p>';
    }

    /**
     * Register settings
     */
    public static function register_settings() {
        // Per-page preloads map
        register_setting('brighter_tweaks', self::OPT, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitise_preloads_map'],
            'default' => [],
        ]);

        // Theme colour
        register_setting('brighter_tweaks', self::OPT_THEME, [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitise_hex'],
            'default' => '',
        ]);

        // Per-post-type featured image preload size
        register_setting('brighter_tweaks', self::OPT_POST_TYPE_SIZES, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitise_post_type_sizes'],
            'default' => [],
        ]);

        // WebP conversion options for preloads
        register_setting('brighter_tweaks', 'brighter_preload_webp_append', [
            'type' => 'integer',
            'default' => 0
        ]);
        
        register_setting('brighter_tweaks', 'brighter_preload_webp_replace', [
            'type' => 'integer',
            'default' => 0
        ]);

        // Google Fonts preload
        register_setting('brighter_tweaks', self::OPT_GOOGLE_FONTS, [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitise_google_fonts_preload'],
            'default' => ''
        ]);

        // Settings section for post types
        add_settings_section(
            'preload_on_singles',
            'Preload Featured Images on Singles',
            function () {
                echo '<p>' . esc_html__('Choose which registered image size (if any) to preload as the featured image on singles, per post type.', 'brighterwebsites') . '</p>';
            },
            'brighter_tweaks'
        );

        add_settings_field('brighter_preload_post_type_sizes', 'Featured Image Size by Post Type', function () {
            self::render_post_type_size_fields();
        }, 'brighter_tweaks', 'preload_on_singles');
        
        // WebP options for featured image preloads
        add_settings_field('brighter_preload_webp_options', 'WebP Auto-Conversion', function () {
            $append = get_option('brighter_preload_webp_append', 0);
            $replace = get_option('brighter_preload_webp_replace', 0);
            
            echo '<label style="display:block;margin-bottom:8px">';
            echo '<input type="checkbox" name="brighter_preload_webp_append" value="1" ' . checked(1, $append, false) . '> ';
            echo 'Append .webp for auto preload (LiteSpeed e.g., image.jpg.webp)';
            echo '</label>';
            
            echo '<label style="display:block;margin-bottom:4px">';
            echo '<input type="checkbox" name="brighter_preload_webp_replace" value="1" ' . checked(1, $replace, false) . '> ';
            echo 'Replace with .webp for auto preloads (ShortPixel e.g., image.webp)';
            echo '</label>';
            
            echo '<p class="description">*Replace will take preference if both options selected.</p>';
        }, 'brighter_tweaks', 'preload_on_singles');
        
        // Google Fonts Preload section
        add_settings_section(
            'google_fonts_preload',
            'Google Fonts Preload',
            function () { 
                echo '<p>' . esc_html__('Add Google Fonts preload link tags to improve performance. Enter the full <link rel="preload"> tags.', 'brighterwebsites') . '</p>'; 
            },
            'brighter_tweaks'
        );
        
        add_settings_field('bw_google_fonts_preload', 'Google Fonts Preload Tags', function () {
            wp_cache_delete('bw_google_fonts_preload', 'options');
            $value = get_option(self::OPT_GOOGLE_FONTS, '');
            echo '<style>
                .bw-google-fonts-wrap { max-width: 800px; }
                .bw-google-fonts-wrap textarea { 
                    font-family: Consolas, Monaco, monospace; 
                    font-size: 12px;
                    width: 100%;
                    display: block;
                }
                .bw-google-fonts-wrap .description {
                    max-width: 800px;
                }
                .bw-google-fonts-wrap code {
                    display: block;
                    padding: 10px;
                    background: #f6f7f7;
                    border: 1px solid #dcdcde;
                    margin-top: 8px;
                    word-wrap: break-word;
                    white-space: pre-wrap;
                }
            </style>';
            echo '<div class="bw-google-fonts-wrap">';
            echo '<textarea name="' . esc_attr(self::OPT_GOOGLE_FONTS) . '" rows="8" placeholder="' . esc_attr('<link rel="preload" href="https://fonts.gstatic.com/..." as="font" type="font/woff2" crossorigin>') . '">' . esc_textarea($value) . '</textarea>';
            echo '<p class="description">' . esc_html__('Paste the full <link> tags, one per line. Example:', 'brighterwebsites') . '</p>';
            echo '<code>&lt;link rel="preload" href="https://fonts.gstatic.com/s/lato/v25/S6uyw4BMUTPHjx4wXg.woff2"<br>&nbsp;&nbsp;as="font" type="font/woff2" crossorigin&gt;</code>';
            echo '</div>';
        }, 'brighter_tweaks', 'google_fonts_preload');

        // Register post meta for per-page preloads
        register_post_meta('page', '_bw_preloads', [
            'type' => 'array',
            'single' => true,
            'default' => [],
            'sanitize_callback' => [__CLASS__, 'sanitise_meta_array'],
            'show_in_rest' => [
                'schema' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'auth_callback' => '__return_true',
        ]);
    }

    /** @var bool Set to true to log Asset Preload save/redirect to error_log (WP_DEBUG_LOG) */
    const DEBUG_SAVE = true;

    /**
     * Process form save (nonce check + option updates). Returns true if saved.
     */
    public static function process_save() {
        if (self::DEBUG_SAVE) {
            error_log('[Brighter_Tweaks] process_save() called');
            error_log('[Brighter_Tweaks] POST keys: ' . print_r(array_keys($_POST), true));
        }
        if (!current_user_can('manage_options')) {
            if (self::DEBUG_SAVE) {
                error_log('[Brighter_Tweaks] process_save FAIL: current_user_can(manage_options)=false');
            }
            return false;
        }
        if (empty($_POST['bw_tweaks_nonce']) || !wp_verify_nonce($_POST['bw_tweaks_nonce'], 'bw_tweaks_save')) {
            if (self::DEBUG_SAVE) {
                error_log('[Brighter_Tweaks] process_save FAIL: nonce missing or invalid. POST keys: ' . implode(', ', array_keys($_POST)));
            }
            return false;
        }
        
        if (self::DEBUG_SAVE) {
            error_log('[Brighter_Tweaks] Nonce valid, proceeding with save...');
        }
        
        if (isset($_POST[self::OPT_THEME])) {
            update_option(self::OPT_THEME, self::sanitise_hex(wp_unslash($_POST[self::OPT_THEME])));
        }

        // NOTE: Post-type image sizes, WebP options, and Google Fonts Preload are owned by
        // their own cards (submitted via options.php / the Settings API) and are intentionally
        // NOT touched here. This form (Per-Page Preloads) no longer renders those fields, so
        // resetting them here would wipe values saved from the other cards.

        // Save Google Fonts Preload (only present on this form for the legacy, non-embedded
        // standalone tweaks page where do_settings_sections() is still rendered).
        if (isset($_POST[self::OPT_GOOGLE_FONTS])) {
            $value = wp_unslash($_POST[self::OPT_GOOGLE_FONTS]);
            $sanitized = self::sanitise_google_fonts_preload($value);
            update_option(self::OPT_GOOGLE_FONTS, $sanitized);
            if (self::DEBUG_SAVE) {
                error_log('[Brighter_Tweaks] Google Fonts - Raw length: ' . strlen($value) . ', Sanitized length: ' . strlen($sanitized));
            }
        }
        
        // Merge per-page preloads with existing map so saving one pagination page
        // doesn't wipe URLs for pages that weren't visible on this screen.
        $existing = get_option(self::OPT, []);
        if (!is_array($existing)) {
            $existing = [];
        }
        $map = $existing;
        if (!empty($_POST[self::OPT]) && is_array($_POST[self::OPT])) {
            foreach ($_POST[self::OPT] as $pid => $raw) {
                $pid = (int) $pid;
                $lines = array_filter(array_map('trim', explode("\n", wp_unslash($raw))));
                if ($pid > 0 && $lines) {
                    $map[$pid] = array_values(array_unique(array_map([__CLASS__, 'sanitise_url'], $lines)));
                } elseif ($pid > 0 && isset($map[$pid])) {
                    // Explicitly cleared textarea on this page: remove entry.
                    unset($map[$pid]);
                }
            }
        }
        update_option(self::OPT, $map);
        return true;
    }

    /**
     * Redirect after save when form is submitted via admin-post (e.g. from Site Essentials).
     * Uses redirect_to from POST when present and valid, else referer, else Support Hub tweaks.
     */
    public static function handle_save_redirect() {
        if (self::DEBUG_SAVE) {
            error_log('[Brighter_Tweaks] handle_save_redirect() called. POST[action]=' . (isset($_POST['action']) ? $_POST['action'] : ''));
        }
        if (self::process_save()) {
            $redirect = '';
            if (!empty($_POST['redirect_to'])) {
                $to = esc_url_raw(wp_unslash($_POST['redirect_to']));
                if (wp_validate_redirect($to, admin_url())) {
                    $redirect = $to;
                }
            }
            if (!$redirect) {
                $redirect = wp_get_referer();
            }
            if (!$redirect || !wp_validate_redirect($redirect)) {
                $redirect = admin_url('admin.php?page=brighter_support&tab=tweaks');
            }
            $redirect = add_query_arg('tweaks_saved', '1', $redirect);
            if (self::DEBUG_SAVE) {
                error_log('[Brighter_Tweaks] redirect (success): ' . $redirect);
            }
            wp_safe_redirect($redirect);
            exit;
        }
        if (self::DEBUG_SAVE) {
            error_log('[Brighter_Tweaks] redirect (save failed): admin.php');
        }
        wp_safe_redirect(admin_url('admin.php'));
        exit;
    }

    /**
     * Render only the preload/tweaks form (for embedding in Site Essentials > Performance).
     * When $embed is true, form posts to admin-post.php so save works from the embedded page.
     * $redirect_to: optional URL to redirect to after save (e.g. Performance asset-preloading tab).
     */
    public static function render_preload_form($embed = false, $redirect_to = '') {
        if (!current_user_can('manage_options')) {
            return;
        }
        $theme = get_option(self::OPT_THEME, '');
        $map = get_option(self::OPT, []);
        $paged = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $per_page = 20;
        $q = new WP_Query([
            'post_type' => 'page',
            'posts_per_page' => $per_page,
            'paged' => $paged,
            's' => $search,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'fields' => 'ids',
        ]);

        if ($embed && $redirect_to !== '') {
            // POST to the Performance > Asset Preloading page; Site Essentials handles save (no admin-post.php)
            $form_action = wp_validate_redirect($redirect_to, '') ? $redirect_to : '';
            $form_method = 'post';
        } else {
            $form_action = '';
            $form_method = 'post';
        }
        ?>
        <form method="<?php echo esc_attr($form_method); ?>" action="<?php echo esc_url($form_action); ?>" style="margin-top:20px;">
            <?php wp_nonce_field('bw_tweaks_save', 'bw_tweaks_nonce'); ?>

            <?php
            // When embedded in Site Essentials > Performance, the "Preload Featured Images
            // on Singles" and "Google Fonts Preload" sections already have their own cards
            // (with their own save forms) above this one. Rendering them again here via
            // do_settings_sections() would duplicate those fields and, on submit, resubmit
            // stale values back into this card's own form. Only render them when this form
            // is the sole/standalone tweaks form (not embedded).
            if (!$embed) {
                do_settings_sections('brighter_tweaks');
                echo '<hr>';
            }
            ?>

            <h2 class="title"><?php esc_html_e('Per-Page Preloads', 'brighterwebsites'); ?></h2>
            <p><?php esc_html_e('Enter one asset URL per line. These will be preloaded only on that page. Supports images, fonts, CSS and JS.', 'brighterwebsites'); ?></p>

            <style>
                .bw-preload-row {
                    background: #fff;
                    border: 1px solid #c3c4c7;
                    padding: 15px;
                    margin-bottom: 12px;
                    border-radius: 4px;
                }
                .bw-preload-row:nth-of-type(even) {
                    background: #f6f7f7;
                }
                .bw-preload-page-info {
                    margin-bottom: 10px;
                }
                .bw-preload-page-info strong {
                    font-size: 14px;
                    color: #1d2327;
                }
                .bw-preload-page-info a {
                    text-decoration: none;
                    color: #2271b1;
                    font-size: 12px;
                }
                .bw-preload-page-info a:hover {
                    text-decoration: underline;
                }
                .bw-preload-page-info small {
                    color: #646970;
                    font-size: 11px;
                }
                .bw-preload-textarea {
                    width: 100%;
                    font-family: Consolas, Monaco, monospace;
                    font-size: 12px;
                    padding: 8px;
                    border: 1px solid #8c8f94;
                    border-radius: 3px;
                    box-sizing: border-box;
                }
            </style>

            <div style="margin-top:12px;">
                <?php
                if ($q->have_posts()):
                    foreach ($q->posts as $pid):
                        $title = get_the_title($pid) ?: '(no title)';
                        $url = get_permalink($pid);
                        $val = isset($map[$pid]) ? implode("\n", $map[$pid]) : '';
                        ?>
                        <div class="bw-preload-row">
                            <div class="bw-preload-page-info">
                                <strong><?php echo esc_html($title); ?></strong><br>
                                <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($url); ?></a><br>
                                <small><?php echo esc_html(sprintf('ID: %d | Status: %s', $pid, get_post_status($pid))); ?></small>
                            </div>
                            <textarea name="<?php echo esc_attr(self::OPT); ?>[<?php echo (int)$pid; ?>]"
                                      rows="4" class="bw-preload-textarea" placeholder="<?php esc_attr_e('Enter asset URLs (one per line)', 'brighterwebsites'); ?>"><?php echo esc_textarea($val); ?></textarea>
                        </div>
                        <?php
                    endforeach;
                else:
                    echo '<p>' . esc_html__('No pages found.', 'brighterwebsites') . '</p>';
                endif;
                ?>
            </div>

            <?php
            $total_pages = $q->max_num_pages ?: 1;
            if ($total_pages > 1) {
                echo '<p>';
                for ($i = 1; $i <= $total_pages; $i++) {
                    $link = add_query_arg([
                        'page' => $embed ? 'site-essentials-essentials' : 'brighter_support',
                        'tab' => $embed ? 'asset-preloading' : 'tweaks',
                        's' => $search,
                        'paged' => $i,
                    ], admin_url('admin.php'));
                    if ($i === $paged) {
                        echo '<span class="button button-primary" style="margin-right:6px;">' . esc_html($i) . '</span>';
                    } else {
                        echo '<a class="button" style="margin-right:6px;" href="' . esc_url($link) . '">' . esc_html($i) . '</a>';
                    }
                }
                echo '</p>';
            }
            ?>

            <p><button class="button button-primary"><?php esc_html_e('Save Tweaks', 'brighterwebsites'); ?></button></p>
        </form>
        <?php
        wp_reset_postdata();
    }

    /**
     * Admin page render
     * SECURITY: Nonce verification, capability checks, output escaping
     */
    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'brighterwebsites'));
        }
        if (self::process_save()) {
            echo '<div class="updated"><p>' . esc_html__('Brighter Tweaks saved.', 'brighterwebsites') . '</p></div>';
        }

        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Brighter Tweaks', 'brighterwebsites'); ?></h1>
            <form method="get" style="margin-top:10px;">
                <input type="hidden" name="page" value="brighter_support">
                <input type="hidden" name="tab" value="tweaks">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search pages?', 'brighterwebsites'); ?>">
                <button class="button"><?php esc_html_e('Search', 'brighterwebsites'); ?></button>
            </form>
            <?php self::render_preload_form(false); ?>
        </div>
        <?php
    }

    /**
     * Output preloads for current page
     */
    public static function output_preloads() {
        // Output Google Fonts preloads (site-wide) - already sanitized on save
        $google_fonts = get_option(self::OPT_GOOGLE_FONTS, '');
        if (!empty($google_fonts)) {
           echo $google_fonts . "\n";
        }
        
        // Work on any singular page/post
        if (!is_singular()) return;
        
        global $post;
        $pid = $post ? $post->ID : get_queried_object_id();
        if (!$pid) return;
        
        $urls = [];
        
        // Get preloads from settings (main tweaks table)
        $map = get_option(self::OPT, []);
        if (!empty($map[$pid]) && is_array($map[$pid])) {
            $urls = array_merge($urls, $map[$pid]);
        }
        
        // Also get preloads from post meta (sidebar box)
        $meta_urls = get_post_meta($pid, '_bw_preloads', true);
        if (is_array($meta_urls) && !empty($meta_urls)) {
            $urls = array_merge($urls, $meta_urls);
        }
        
        // Remove duplicates and empty values
        $urls = array_filter(array_unique($urls));
        
        if (empty($urls)) return;
        
        // Output preloads
        foreach ($urls as $u) {
            $u = self::normalise_url($u);
            if (empty($u)) continue;
            
            $attr = self::infer_preload_attrs($u);
            if (!$attr) continue;
            
            printf(
                "<link rel=\"preload\" href=\"%s\" as=\"%s\"%s%s fetchpriority=\"high\">\n",
                esc_url($u),
                esc_attr($attr['as']),
                !empty($attr['type']) ? ' type="' . esc_attr($attr['type']) . '"' : '',
                !empty($attr['crossorigin']) ? ' crossorigin' : ''
            );
        }
    }

    /**
     * Output featured image preload for singles.
     * Preloads the registered image size chosen for the current post type
     * (Site Essentials > Performance > Asset Preloading > Preload Featured Images on Singles).
     */
    public static function output_featured_image_preload() {
        if (!is_singular()) return;

        $post_type = get_post_type();
        $sizes_map = (array) get_option(self::OPT_POST_TYPE_SIZES, []);
        $size = isset($sizes_map[$post_type]) ? $sizes_map[$post_type] : 'none';

        if (empty($size) || $size === 'none') return;
        if (!has_post_thumbnail()) return;

        $id = get_post_thumbnail_id();

        $src = wp_get_attachment_image_url($id, $size);

        // Fall back to full size if the chosen size doesn't exist for this image.
        if (!$src) {
            $size = 'full';
            $src = wp_get_attachment_image_url($id, $size);
        }
        
        // Apply WebP conversion if enabled
        $webp_replace = get_option('brighter_preload_webp_replace', 0);
        $webp_append = get_option('brighter_preload_webp_append', 0);
        
        if ($webp_replace) {
            // ShortPixel style: replace extension (image.jpg → image.webp)
            $src = preg_replace('/\.(jpe?g|png)$/i', '.webp', $src);
        } elseif ($webp_append) {
            // LiteSpeed style: append .webp (image.jpg → image.jpg.webp)
            if (preg_match('/\.(jpe?g|png)$/i', $src)) {
                $src .= '.webp';
            }
        }
        
        $srcset = wp_get_attachment_image_srcset($id, $size);
        $mime = get_post_mime_type($id) ?: 'image/*';
        
        // Update mime type if using WebP
        if ($webp_replace || $webp_append) {
            $mime = 'image/webp';
        }
        
        if ($src) {
            $src = self::normalise_url($src);
            printf(
                '<link rel="preload" as="image" href="%s"%s imagesizes="100vw" type="%s" fetchpriority="high">' . "\n",
                esc_url($src),
                $srcset ? ' imagesrcset="' . esc_attr($srcset) . '"' : '',
                esc_attr($mime)
            );
        }
    }

    /**
     * Output theme-color meta tag from Business Info
     */
    public static function output_theme_color_meta() {
        // Get theme color from Business Info
        $theme_color = get_option('bw_mobile_theme_color', '');
        
        if (empty($theme_color)) {
            return;
        }
        
        // Ensure it has a hash
        if ($theme_color[0] !== '#') {
            $theme_color = '#' . $theme_color;
        }
        
        echo "\n<!-- Brighter Tweaks: Theme Color -->\n";
        echo '<meta name="theme-color" content="' . esc_attr($theme_color) . '">' . "\n";
    }

    /**
     * Remove Google Fonts - THE NUCLEAR OPTION
     * This removes fonts.googleapis.com from everywhere
     */
    public static function remove_google_fonts() {
        // Method 1: Filter style tags
        add_filter('style_loader_tag', function($html, $handle) {
            if (strpos($html, 'fonts.googleapis.com') !== false) {
                return '';
            }
            return $html;
        }, 10, 2);
        
        // Method 2: Output buffering to strip from final HTML
        ob_start(function($html) {
            // Remove <link> tags with fonts.googleapis.com
            $html = preg_replace(
                '/<link[^>]*fonts\.googleapis\.com[^>]*>/i',
                '',
                $html
            );
            
            // Remove @import statements with Google Fonts
            $html = preg_replace(
                '/@import\s+url\([\'"]?https?:\/\/fonts\.googleapis\.com[^\)]+\)[\'"]?;?/i',
                '',
                $html
            );
            
            return $html;
        });
    }

    /**
     * Add fetchpriority=high to preloaded images
     */
    public static function add_fetchpriority($attrs, $attachment, $size) {
        if (!is_singular()) return $attrs;
        
        $pid = get_queried_object_id();
        $urls = self::get_preload_urls($pid);
        
        if (empty($urls) || empty($attrs['src'])) return $attrs;
        
        foreach ($urls as $u) {
            if (strpos($attrs['src'], basename($u)) !== false) {
                $attrs['fetchpriority'] = 'high';
                $attrs['loading'] = 'eager';
                break;
            }
        }
        
        return $attrs;
    }

    /**
     * Add fetchpriority to images in content
     */
    public static function add_fetchpriority_to_content($html) {
        if (!is_singular()) return $html;
        
        $pid = get_queried_object_id();
        $urls = self::get_preload_urls($pid);
        
        if (empty($urls) || stripos($html, '<img') === false) return $html;
        
        return preg_replace_callback(
            '/<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i',
            function($m) use ($urls) {
                $src = $m[0];
                foreach ($urls as $u) {
                    if (strpos($m[1], basename($u)) !== false) {
                        if (stripos($src, 'fetchpriority=') === false) {
                            $src = str_replace('<img', '<img fetchpriority="high"', $src);
                        }
                        if (stripos($src, 'loading=') !== false) {
                            $src = preg_replace('/loading=["\']lazy["\']/i', 'loading="eager"', $src);
                        }
                        break;
                    }
                }
                return $src;
            },
            $html
        );
    }

    /**
     * Normalize /core/storage/ paths to /storage/
     * Also adds fetchpriority="high" to image preloads missing it
     */
    public static function normalize_paths() {
        if (is_feed() || is_admin() || wp_doing_ajax()) return;
        if (defined('REST_REQUEST') && REST_REQUEST) return;

        ob_start(function ($html) {
            // Normalize hidden path to public one
            $html = str_replace('/core/storage/', '/storage/', $html);

            // Add fetchpriority="high" to image preloads that don't have it
            $html = preg_replace_callback(
                '#<link\s+([^>]*\brel=["\']preload["\'][^>]*\bas=["\']image["\'][^>]*)>#i',
                function ($m) {
                    $tag = $m[0];
                    if (stripos($tag, 'fetchpriority=') !== false) {
                        return $tag;
                    }
                    return rtrim($tag, '>') . ' fetchpriority="high">';
                },
                $html
            );

            return $html;
        });
    }

    /**
     * Meta box for individual pages
     */
    public static function add_meta_box() {
        add_meta_box(
            'bw_preloads',
            __('Preload Assets', 'brighterwebsites'),
            [__CLASS__, 'render_meta_box'],
            'page',
            'side',
            'default'
        );
    }

    public static function render_meta_box($post) {
        $vals = get_post_meta($post->ID, '_bw_preloads', true);
        $text = is_array($vals) ? implode("\n", $vals) : '';
        
        echo '<p>' . esc_html__('Enter one asset URL per line. Supports CSS, JS, fonts, and images.', 'brighterwebsites') . '</p>';
        echo '<textarea style="width:100%;min-height:140px" name="bw_preloads_field">' . esc_textarea($text) . '</textarea>';
        wp_nonce_field('bw_preloads_save', 'bw_preloads_nonce');
    }

    public static function save_meta_box($post_id) {
        if (wp_is_post_revision($post_id)) return;
        if (!isset($_POST['bw_preloads_nonce']) || !wp_verify_nonce($_POST['bw_preloads_nonce'], 'bw_preloads_save')) return;
        if (!current_user_can('edit_page', $post_id)) return;
        
        $lines = isset($_POST['bw_preloads_field']) ? (string) wp_unslash($_POST['bw_preloads_field']) : '';
        $arr = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $lines)));
        
        $clean = [];
        foreach ($arr as $u) {
            $u = self::sanitise_url($u);
            if ($u) $clean[] = $u;
        }
        
        update_post_meta($post_id, '_bw_preloads', array_values(array_unique($clean)));
    }

    /**
     * Helper: Get all preload URLs for a page
     */
    private static function get_preload_urls($pid) {
        $map = get_option(self::OPT, []);
        $urls = isset($map[$pid]) ? (array)$map[$pid] : [];
        
        $meta = get_post_meta($pid, '_bw_preloads', true);
        if (is_array($meta)) {
            $urls = array_merge($urls, $meta);
        }
        
        return array_unique($urls);
    }

    /**
     * Normalise URL (fix /core/storage/ path)
     */
    private static function normalise_url($url) {
        return str_replace('/core/storage/', '/storage/', $url);
    }

    /**
     * Sanitisation helpers
     */
    public static function sanitise_preloads_map($map) {
        $out = [];
        if (!is_array($map)) return $out;
        
        foreach ($map as $pid => $lines) {
            $pid = (int)$pid;
            if ($pid <= 0) continue;
            
            if (is_string($lines)) $lines = explode("\n", $lines);
            $lines = array_filter(array_map('trim', $lines));
            
            $urls = [];
            foreach ($lines as $u) {
                $u = self::sanitise_url($u);
                if ($u) $urls[] = $u;
            }
            
            if ($urls) $out[$pid] = array_values(array_unique($urls));
        }
        return $out;
    }

    public static function sanitise_url($u) {
        if (strpos($u, '//') === 0) $u = 'https:' . $u;
        if (preg_match('#^/[^ ]#', $u)) return esc_url_raw($u);
        $ok = filter_var($u, FILTER_VALIDATE_URL);
        return $ok ? esc_url_raw($u) : '';
    }

    public static function sanitise_post_type_sizes($input) {
        $out = [];
        if (!is_array($input)) return $out;

        $valid_types = array_keys(get_post_types(['public' => true]));
        foreach ($input as $type => $size) {
            $type = sanitize_key($type);
            if (!in_array($type, $valid_types, true)) continue;
            $out[$type] = sanitize_key((string) $size) ?: 'none';
        }
        return $out;
    }

    public static function sanitise_google_fonts_preload($value) {
        $allowed_tags = [
            'link' => [
                'rel' => true,
                'href' => true,
                'as' => true,
                'type' => true,
                'crossorigin' => true,
            ],
        ];
        return wp_kses((string) $value, $allowed_tags);
    }

    public static function sanitise_hex($hex) {
        $hex = ltrim(trim((string)$hex), '#');
        if (!preg_match('/^[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $hex)) return '';
        return '#' . strtolower($hex);
    }

    public static function sanitise_meta_array($value) {
        $out = [];
        foreach ((array)$value as $v) {
            if (is_scalar($v)) {
                $s = sanitize_text_field((string)$v);
                if ($s !== '') $out[] = $s;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Infer preload attributes
     */
    public static function infer_preload_attrs($url) {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        switch ($ext) {
            case 'css': return ['as' => 'style', 'type' => 'text/css'];
            case 'js': return ['as' => 'script', 'type' => 'application/javascript'];
            case 'woff2': return ['as' => 'font', 'type' => 'font/woff2', 'crossorigin' => true];
            case 'woff': return ['as' => 'font', 'type' => 'font/woff', 'crossorigin' => true];
            case 'ttf': return ['as' => 'font', 'type' => 'font/ttf', 'crossorigin' => true];
            case 'otf': return ['as' => 'font', 'type' => 'font/otf', 'crossorigin' => true];
            case 'jpg':
            case 'jpeg': return ['as' => 'image', 'type' => 'image/jpeg'];
            case 'png': return ['as' => 'image', 'type' => 'image/png'];
            case 'webp': return ['as' => 'image', 'type' => 'image/webp'];
            case 'gif': return ['as' => 'image', 'type' => 'image/gif'];
            case 'svg': return ['as' => 'image', 'type' => 'image/svg+xml'];
            case 'avif': return ['as' => 'image', 'type' => 'image/avif'];
            case 'mp4':  return ['as' => 'video', 'type' => 'video/mp4'];
            case 'webm': return ['as' => 'video', 'type' => 'video/webm'];
            case 'ogv':  return ['as' => 'video', 'type' => 'video/ogg'];
            default: return ['as' => 'fetch', 'type' => ''];
        }
    }
}

Brighter_Tweaks::boot();

/**
 * Disable SEOPress Content Analysis meta box
 * TEMPORARY: Can be removed once SEOPress is configured properly
 */
add_filter('seopress_metabox_content_analysis', '__return_false');
