<?php
/**
 * Brighter Tools: Image Optimisation Settings (Admin UI)
 * 
 * File: brighter-support-image-settings.php
 * Version: 4.2.1
 *
 * Changelog:
 * 4.2.1 - REMOVED dead og:logo wp_head hook: read get_option('your_logo_option'), which is
 *         never set anywhere in the codebase, so it has never output anything.
 * 4.2.0 - Expose social-square (1080x1080) toggle in Manage Image Thumbnails & Sizes
 * 4.1.0 - Performance: Batch load settings, cache registered sizes, reduce queries
 * 4.0.0 - Initial release
 *
 * Purpose: Registers admin settings for image optimisation features
 * including resizing, JPEG quality, and image size toggles.
 */

if (!defined('ABSPATH')) exit;

/**
 * Cache for image settings (reduces get_option calls)
 */
class Brighter_Image_Settings_Cache {
    private static $settings = null;
    
    /**
     * Load all image settings in one go
     */
    public static function get_all() {
        if (self::$settings !== null) {
            return self::$settings;
        }
        
        global $wpdb;
        
        // Get all brighter image settings in one query
        $options = $wpdb->get_results(
            "SELECT option_name, option_value 
            FROM {$wpdb->options} 
            WHERE option_name LIKE 'enable_size_%' 
               OR option_name IN ('enable_image_resize', 'image_max_dimension', 'jpeg_quality', 'disable_big_image_threshold', 'brighter_enable_custom_hero', 'brighter_custom_hero_width', 'brighter_custom_hero_height')",
            OBJECT_K
        );
        
        self::$settings = [];
        foreach ($options as $key => $row) {
            self::$settings[$key] = maybe_unserialize($row->option_value);
        }
        
        return self::$settings;
    }
    
    /**
     * Get a specific setting with caching
     */
    public static function get($key, $default = null) {
        $all = self::get_all();
        return isset($all[$key]) ? $all[$key] : $default;
    }
    
    /**
     * Clear cache
     */
    public static function clear() {
        self::$settings = null;
    }
}

/**
 * Get image size configuration (cached)
 */
function brighter_get_image_sizes_config() {
    static $config = null;
    
    if ($config !== null) {
        return $config;
    }
    
    $config = [
        'thumbnail'     => [150, 150, true, 'Thumbnail (150x150)'],
        'medium'        => [300, 0, false, 'Medium (300x300)'],
        'medium_large'  => [768, 0, false, 'Medium Large (768w)'],
        'large'         => [1200, 0, false, 'Large (1200x?)'],
        'custom_768w'   => [768, 0, false, 'Custom 768w'],
        'custom_1200w'  => [1200, 0, false, 'Custom 1200w'],
        'og-image'      => [1200, 630, true, 'Open Graph (1200x630)'],
        'social-square' => [1080, 1080, true, 'Social Square (1080x1080)'],
        '1536x1536'     => [1536, 1536, false, '1536x1536'],
        '2048x2048'     => [2048, 2048, false, '2048x2048'],
    ];
    
    return $config;
}

/**
 * Register all image optimization settings
 */
add_action('admin_init', function () {
    // Register base options
    register_setting('brighter_optimisation_settings', 'enable_image_resize');
    register_setting('brighter_optimisation_settings', 'image_max_dimension');
    register_setting('brighter_optimisation_settings', 'jpeg_quality');
    register_setting('brighter_optimisation_settings', 'disable_big_image_threshold');

    register_setting('brighter_optimisation_settings', 'brighter_enable_custom_hero', [
        'sanitize_callback' => function ($v) { return !empty($v) ? 1 : 0; },
    ]);
    register_setting('brighter_optimisation_settings', 'brighter_custom_hero_width', [
        'sanitize_callback' => function ($v) { return absint($v); },
    ]);
    register_setting('brighter_optimisation_settings', 'brighter_custom_hero_height', [
        'sanitize_callback' => function ($v) {
            $h = absint($v);
            return $h;
        },
    ]);

    // Register settings for each image size
    $sizes = array_keys(brighter_get_image_sizes_config());
    foreach ($sizes as $size) {
        register_setting('brighter_optimisation_settings', "enable_size_$size");
    }

    // =========================================
    // Section: Image Settings
    // =========================================
    add_settings_section(
        'image_settings_section',
        'Image Settings',
        function() {
            echo '<p>Max upload dimension, resize on upload, JPEG quality, and registered image sizes (including OG 1200×630).</p>';
        },
        'brighter_optimisation_page'
    );

    // Enable image resize toggle
    add_settings_field('enable_image_resize', 'Enable Image Resizing?', function () {
        $enabled = Brighter_Image_Settings_Cache::get('enable_image_resize', 'yes');
        echo '<label><input type="checkbox" name="enable_image_resize" value="yes" ' . checked('yes', $enabled, false) . '> Resize uploaded images</label>';
        echo '<p class="description">If unchecked, original images will be stored without resizing.</p>';
    }, 'brighter_optimisation_page', 'image_settings_section');

    // Max upload dimension
    add_settings_field('image_max_dimension', 'Max Upload Dimension (px)', function () {
        $value = Brighter_Image_Settings_Cache::get('image_max_dimension', 2480);
        echo '<input type="number" name="image_max_dimension" value="' . esc_attr($value) . '" class="small-text" min="500" step="10">';
        echo '<p class="description">Maximum dimension for uploaded images (longest side).</p>';
    }, 'brighter_optimisation_page', 'image_settings_section');

    // Disable big image threshold
    add_settings_field('disable_big_image_threshold', 'Disable Big Image Size Threshold', function () {
        $disabled = Brighter_Image_Settings_Cache::get('disable_big_image_threshold', 0);
        echo '<label><input type="checkbox" name="disable_big_image_threshold" value="1" ' . checked(1, $disabled, false) . '> Disable WordPress 2560px threshold</label>';
        echo '<p class="description">Recommended for media-rich sites or when needing image metadata on larger uploads. WordPress by default scales down images larger than 2560px.</p>';
    }, 'brighter_optimisation_page', 'image_settings_section');

    // =========================================
    // Section: Manage Image Thumbnails & Sizes
    // =========================================
    add_settings_section(
        'image_thumbnails_section',
        'Manage Image Thumbnails & Sizes',
        function() {
            echo '<p>Enable or disable specific thumbnail sizes generated when images are uploaded.</p>';
        },
        'brighter_optimisation_page'
    );

    // Register: Checkboxes for each image size
    $size_config = brighter_get_image_sizes_config();
    foreach ($size_config as $size => $data) {
        $label = $data[3];
        add_settings_field("enable_size_$size", "Enable $label", function () use ($size) {
            $enabled = Brighter_Image_Settings_Cache::get("enable_size_$size", 1);
            echo '<input type="checkbox" name="enable_size_' . esc_attr($size) . '" value="1" ' . checked(1, $enabled, false) . '> ' . ucfirst($size);
        }, 'brighter_optimisation_page', 'image_thumbnails_section');
    }

    // Custom Hero size (custom dimensions)
    add_settings_field('brighter_custom_hero', 'Enable Custom Hero', function () {
        $enabled = Brighter_Image_Settings_Cache::get('brighter_enable_custom_hero', 0);
        $width   = Brighter_Image_Settings_Cache::get('brighter_custom_hero_width', 0);
        $height  = Brighter_Image_Settings_Cache::get('brighter_custom_hero_height', 0);
        echo '<input type="hidden" name="brighter_enable_custom_hero" value="0">';
        echo '<label><input type="checkbox" name="brighter_enable_custom_hero" value="1" ' . checked(1, $enabled, false) . '> custom_hero</label> ';
        echo ' W <input type="number" name="brighter_custom_hero_width" value="' . esc_attr($width) . '" class="small-text" min="1" step="1" placeholder="e.g. 960"> ';
        echo ' H <input type="number" name="brighter_custom_hero_height" value="' . esc_attr($height) . '" class="small-text" min="0" step="1" placeholder="0 (auto)">';
        echo '<p class="description">Width required; height 0 = auto aspect ratio.</p>';
    }, 'brighter_optimisation_page', 'image_thumbnails_section');

    // Register: JPEG quality field
    add_settings_field('jpeg_quality', 'JPEG Compression Quality', function () {
        $quality = Brighter_Image_Settings_Cache::get('jpeg_quality', 75);
        echo '<input type="number" min="30" max="100" step="1" name="jpeg_quality" value="' . esc_attr($quality) . '" />';
        echo '<p class="description">Lower = smaller files, higher = better quality. Recommended: 75-85</p>';
    }, 'brighter_optimisation_page', 'image_thumbnails_section');

    // Section: Registered sizes overview (cached for performance)
    add_settings_section('registered_sizes_section', 'Registered Image Sizes', function () {
        // Cache the output for 5 minutes
        $transient_key = 'brighter_registered_sizes_html';
        $cached_html = get_transient($transient_key);
        
        if ($cached_html !== false) {
            echo $cached_html;
            return;
        }
        
        ob_start();
        $all_sizes = wp_get_registered_image_subsizes();
        $settings = Brighter_Image_Settings_Cache::get_all();
        $custom_hero_enabled = !empty($settings['brighter_enable_custom_hero']);
        $custom_hero_w = isset($settings['brighter_custom_hero_width']) ? (int) $settings['brighter_custom_hero_width'] : 0;
        $custom_hero_h = isset($settings['brighter_custom_hero_height']) ? (int) $settings['brighter_custom_hero_height'] : 0;

        echo '<ul>';
        foreach ($all_sizes as $name => $size) {
            if ($name === 'custom_hero') {
                $enabled = $custom_hero_enabled;
                $width = $custom_hero_w;
                $height = $custom_hero_h;
            } else {
                $enabled = isset($settings["enable_size_$name"]) ? $settings["enable_size_$name"] : false;
                $width = $size['width'];
                $height = $size['height'];
            }
            $width = esc_html($width);
            $height = esc_html($height);
            $crop = ($name !== 'custom_hero' && !empty($size['crop'])) ? ' (cropped)' : '';
            $status = $enabled ? 'Enabled' : 'Disabled';
            echo '<li><strong>' . esc_html($name) . '</strong>: ' . $width . '&times;' . $height . esc_html($crop) . ' &mdash; ' . esc_html($status) . '</li>';
        }
        if (!isset($all_sizes['custom_hero'])) {
            $w = esc_html($custom_hero_w);
            $h = esc_html($custom_hero_h);
            echo '<li><strong>custom_hero</strong>: ' . $w . '&times;' . $h . ' &mdash; Disabled</li>';
        }
        echo '</ul>';
        
        $output = ob_get_clean();
        set_transient($transient_key, $output, 5 * MINUTE_IN_SECONDS);
        echo $output;
    }, 'brighter_optimisation_page');
});

/**
 * Clear cache when settings are saved
 */
add_action('update_option', function($option_name) {
    if (strpos($option_name, 'enable_size_') === 0 ||
        in_array($option_name, [
            'enable_image_resize', 'image_max_dimension', 'jpeg_quality', 'disable_big_image_threshold',
            'brighter_enable_custom_hero', 'brighter_custom_hero_width', 'brighter_custom_hero_height',
        ])) {
        Brighter_Image_Settings_Cache::clear();
        delete_transient('brighter_registered_sizes_html');
    }
}, 10, 1);

