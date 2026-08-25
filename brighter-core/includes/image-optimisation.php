<?php
/**
 * Brighter Tools: Image Optimisation (Runtime)
 * 
 * File: image-optimisation.php
 * Purpose: Core logic for resizing uploads, managing registered sizes,
 * thumbnail control, comment disable on attachments, and LiteSpeed fade-in CSS. and OG image injection.
 *  
 * Version: 4.4.0
 *
 * Changelog:
 * 4.4.0 - Added LiteSpeed/ShortPixel WebP compatibility filter (shortpixel/image/filecheck), option-gated
 * 4.3.0 - Added option to disable WordPress big_image_size_threshold (2560px scaling)
 * 4.2.0 - Removed og:image:secure_url (not needed for HTTPS sites), added Twitter card output after image
 * 4.1.0 - Added direct OG image meta tag injection (bypasses SEOPress filter issues)
 * 4.0.0 - Initial release
 *
 * Responsibilities:
 * - Resize uploaded images to a max dimension
 * - Register, enable, or disable custom image sizes
 * - Enforce thumbnail generation preferences
 * - Disable comments on attachment pages
 * - Add lazyload fade-in CSS for LiteSpeed
 * - Control WordPress big image threshold
 *
 * Notes:
 * - Settings for these features are managed in brighter-support-image-settings.php
 * - This file runs the actual behaviour on upload and frontend
 *  
 */


if (!defined('ABSPATH')) exit;

// ==========================================
// ✅ LiteSpeed / ShortPixel WebP compatibility
// ==========================================
// LiteSpeed Cache checks for a WebP file next to the original before serving
// it; ShortPixel's own file-exists check (shortpixel/image/filecheck) can
// disagree with that lookup and block WebP delivery. Forcing it to true
// removes ShortPixel's check from the equation. Requires
// define('SHORTPIXEL_USE_DOUBLE_WEBP_EXTENSION', true) in wp-config.php to
// complete setup — that constant is not something a runtime filter can set,
// so it's a manual step (reminder shown on the settings screen when missing).
if (get_option('brighter_shortpixel_litespeed_compat', 0)) {
    add_filter('shortpixel/image/filecheck', '__return_true');
}

// ==========================================
// ✅ Disable big image size threshold (2560px)
// ==========================================
add_filter('big_image_size_threshold', function($threshold) {
    // Check if admin has disabled the threshold
    $disabled = get_option('disable_big_image_threshold', 0);
    
    if ($disabled) {
        return false; // Disable WordPress 2560px scaling
    }
    
    return $threshold; // Keep default WordPress behavior (2560px)
});

// ==========================================
// ✅ Resize images on upload
// ==========================================
add_filter('wp_handle_upload', function ($upload) {
    $file_path = $upload['file'];
    $file_type = $upload['type'];

    if (!preg_match('/^image\/(jpe?g|png|gif)$/', $file_type)) return $upload;
    if (get_option('enable_image_resize', 'yes') !== 'yes') return $upload;

    list($width, $height) = getimagesize($file_path);
    $max_size = intval(get_option('image_max_dimension', 2480));

    if ($width <= $max_size && $height <= $max_size) return $upload;

    $aspect_ratio = $width / $height;
    $new_width = $width >= $height ? $max_size : intval($max_size * $aspect_ratio);
    $new_height = $width >= $height ? intval($max_size / $aspect_ratio) : $max_size;

    switch ($file_type) {
        case 'image/jpeg': $src = imagecreatefromjpeg($file_path); break;
        case 'image/png': $src = imagecreatefrompng($file_path); break;
        case 'image/gif': $src = imagecreatefromgif($file_path); break;
        default: return $upload;
    }

    $dst = imagecreatetruecolor($new_width, $new_height);
    if (in_array($file_type, ['image/png', 'image/gif'])) {
        imagecolortransparent($dst, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

    $jpeg_quality = intval(get_option('jpeg_quality', 75));
    switch ($file_type) {
        case 'image/jpeg': imagejpeg($dst, $file_path, $jpeg_quality); break;
        case 'image/png':  imagepng($dst, $file_path); break;
        case 'image/gif':  imagegif($dst, $file_path); break;
    }

    imagedestroy($src);
    imagedestroy($dst);

    return $upload;
});

// ==========================================
// ✅ Image Sizes - Register / Deregister
// ==========================================
add_action('init', function () {
    $sizes = [
        'thumbnail'     => [150, 150, true],
        'medium'        => [300, 0, false],
        'medium_large'  => [768, 0, false],
        'large'         => [1200, 0, false],
        'custom_768w'   => [768, 0, false],
        'custom_1200w'  => [1200, 0, false],
        'og-image'      => [1200, 630, true],
        'social-square' => [1080, 1080, true],  // Social media square (Instagram/Facebook)
        '1536x1536'     => [1536, 1536, false],
        '2048x2048'     => [2048, 2048, false],
    ];

    global $_wp_additional_image_sizes;

    foreach ($sizes as $name => [$w, $h, $crop]) {
        // Enable social-square by default on first run
        if ($name === 'social-square' && get_option("enable_size_social-square") === false) {
            update_option("enable_size_social-square", 1);
        }
        
        if (get_option("enable_size_$name", 1)) {
            add_image_size($name, $w, $h, $crop);
        } else {
            remove_image_size($name);
            unset($_wp_additional_image_sizes[$name]); // hide from regenerators
        }
    }

    // Clean DB for disabled core sizes
    $option_map = [
        'thumbnail'     => 'thumbnail_size',
        'medium'        => 'medium_size',
        'large'         => 'large_size',
        'medium_large'  => 'medium_large_size',
        '1536x1536'     => '1536x1536_size',
        '2048x2048'     => '2048x2048_size',
    ];

    foreach ($option_map as $name => $prefix) {
        if (!get_option("enable_size_$name", 0)) {
            update_option("{$prefix}_w", 0);
            update_option("{$prefix}_h", 0);
            update_option("{$prefix}_crop", false);
        }
    }

    // Custom Hero size (user-defined dimensions from options)
    if (get_option('brighter_enable_custom_hero') && get_option('brighter_custom_hero_width')) {
        add_image_size(
            'custom_hero',
            (int) get_option('brighter_custom_hero_width'),
            (int) get_option('brighter_custom_hero_height', 0),
            false
        );
    } else {
        remove_image_size('custom_hero');
        if (isset($_wp_additional_image_sizes['custom_hero'])) {
            unset($_wp_additional_image_sizes['custom_hero']);
        }
    }
}, 10);

// ==========================================
// ✅ Custom Quality for Social Square (85% for external platforms)
// ==========================================
add_filter('wp_editor_set_quality', function($quality, $mime_type) {
    // Set 85% quality for social-square size (optimized for social media)
    if (doing_filter('wp_generate_attachment_metadata')) {
        $size_being_generated = apply_filters('intermediate_image_sizes_advanced', []);
        if (isset($size_being_generated['social-square'])) {
            return 85;
        }
    }
    return $quality;
}, 10, 2);

// ==========================================
// ✅ Filter: remove disabled sizes during generation
// ==========================================
add_filter('intermediate_image_sizes', function ($sizes) {
    return array_filter($sizes, function ($s) {
        if ($s === 'custom_hero') {
            return get_option('brighter_enable_custom_hero') && get_option('brighter_custom_hero_width');
        }
        return get_option("enable_size_$s", 0);
    });
});

add_filter('intermediate_image_sizes_advanced', function ($sizes) {
    foreach ($sizes as $name => $settings) {
        if ($name === 'custom_hero') {
            if (!get_option('brighter_enable_custom_hero') || !get_option('brighter_custom_hero_width')) {
                unset($sizes[$name]);
            }
        } elseif (!get_option("enable_size_$name", 1)) {
            unset($sizes[$name]);
        }
    }
    return $sizes;
});

// ==========================================
// ✅ Admin UI Dropdown - Media Editor
// ==========================================
add_filter('image_size_names_choose', function ($sizes) {
    $labels = [
        'custom_768w'   => 'Custom 768w',
        'custom_1200w'  => 'Custom 1200w',
        'og-image'      => 'Open Graph 1200x630',
    ];
    foreach ($labels as $key => $label) {
        if (get_option("enable_size_$key", 0)) {
            $sizes[$key] = $label;
        }
    }
    if (get_option('brighter_enable_custom_hero') && get_option('brighter_custom_hero_width')) {
        $w = (int) get_option('brighter_custom_hero_width');
        $h = (int) get_option('brighter_custom_hero_height', 0);
        $sizes['custom_hero'] = $h ? "Custom Hero ({$w}×{$h})" : "Custom Hero ({$w}w)";
    }
    return $sizes;
});

// ==========================================
// ✅ Disable comments on media attachments
// ==========================================
add_filter('comments_open', function ($open, $post_id) {
    $post = get_post($post_id);
    return ($post && $post->post_type === 'attachment') ? false : $open;
}, 10, 2);
/**
 * Inline CSS for lazy-loaded image fade-in
 * Only loads if LiteSpeed Cache plugin is active
 */
/**
 * LiteSpeed lazy load styles are now in frontend.css
 * No inline styles needed - CSS is cached and loaded via brighter-frontend.css
 * 
 * Note: Styles only apply when LiteSpeed Cache is active (via CSS selectors)
 * Removed conditional check as CSS impact is minimal (~5 lines) and only activates
 * when LiteSpeed adds the data-lazyloaded attribute.
 */


// ==========================================
// Force og-image (1200x630) for SEOPress OG tags
// ==========================================
/**
 * Filter SEOPress OG image URL to use og-image size (1200x630) when the image is an attachment.
 *
 * @param string $url    Current OG image URL from SEOPress
 * @param mixed  $context Optional second parameter from filter
 * @return string OG image URL (og-image size if attachment, else unchanged)
 */
function brighter_force_og_image_size($url, $context = null) {
    if (empty($url) || !is_string($url)) {
        return $url;
    }
    $attachment_id = attachment_url_to_postid($url);
    if (!$attachment_id) {
        return $url;
    }
    $og_url = wp_get_attachment_image_url($attachment_id, 'og-image');
    return $og_url ? $og_url : $url;
}
add_filter('seopress_social_og_image', 'brighter_force_og_image_size', 999, 2);
add_filter('seopress_social_og_default_image', 'brighter_force_og_image_size', 999, 2);

// ==========================================
// Force og:image meta tags directly (bypass SEOPress)
// ==========================================

add_action('wp_head', 'brighter_inject_og_image_tags', 1);
function brighter_inject_og_image_tags() {
    // Only run on single posts/pages
    if (!is_singular()) {
        return;
    }

    global $post;

    $attachment_id = 0;

    if (has_post_thumbnail($post->ID)) {
        $attachment_id = (int) get_post_thumbnail_id($post->ID);
    } elseif (class_exists('\SiteEssentials\Modules\SeoMeta\Archive_Settings')) {
        // No featured image — fall back to the post-type archive's default OG image.
        $post_type       = get_post_type($post->ID) ?: 'post';
        $archive_s       = \SiteEssentials\Modules\SeoMeta\Archive_Settings::get($post_type);
        $fallback_id     = (int) ($archive_s['og_image_id'] ?? 0);
        if ($fallback_id > 0) {
            $attachment_id = $fallback_id;
        }
    }

    if (!$attachment_id) {
        return;
    }

    $og_image_url = wp_get_attachment_image_url($attachment_id, 'og-image');

    // Fallback to full size if og-image size doesn't exist
    if (!$og_image_url) {
        $og_image_url = wp_get_attachment_image_url($attachment_id, 'full');
    }
    
    if ($og_image_url) {
        // Get image dimensions
        $image_meta = wp_get_attachment_metadata($attachment_id);
        $width = 1200;
        $height = 630;
        
        // Check if og-image size exists in metadata
        if (isset($image_meta['sizes']['og-image'])) {
            $width = $image_meta['sizes']['og-image']['width'];
            $height = $image_meta['sizes']['og-image']['height'];
        }
        
        // Get alt text
        $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        
        // Determine image type
        $image_type = 'image/jpeg'; // default
        if (isset($image_meta['file'])) {
            $ext = strtolower(pathinfo($image_meta['file'], PATHINFO_EXTENSION));
            if ($ext === 'png') {
                $image_type = 'image/png';
            } elseif ($ext === 'gif') {
                $image_type = 'image/gif';
            } elseif ($ext === 'webp') {
                $image_type = 'image/webp';
            }
        }
        
        // Output the meta tags directly
        echo "\n<!-- OG Image Tags -->\n";
        echo '<meta property="og:image" content="' . esc_url($og_image_url) . '">' . "\n";
        echo '<meta property="og:image:type" content="' . esc_attr($image_type) . '">' . "\n";
        echo '<meta property="og:image:width" content="' . esc_attr($width) . '">' . "\n";
        echo '<meta property="og:image:height" content="' . esc_attr($height) . '">' . "\n";
        if ($alt_text) {
            echo '<meta property="og:image:alt" content="' . esc_attr($alt_text) . '">' . "\n";
        }
        
        echo "\n<!-- Twitter Card (after image) -->\n";
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    }
}

// ==========================================
// ? Add author meta tag for LinkedIn/schema
// ==========================================

add_action('wp_head', 'brighter_inject_author_meta', 2);
function brighter_inject_author_meta() {
    // Only run on single posts/pages
    if (!is_singular()) {
        return;
    }
    
    global $post;
    
    // Get the author name
    $author_name = get_the_author_meta('display_name', $post->post_author);
    
    if ($author_name) {
        echo '<meta name="author" content="' . esc_attr($author_name) . '">' . "\n";
    }
}
 
