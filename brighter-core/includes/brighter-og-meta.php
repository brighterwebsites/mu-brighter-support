<?php
/**
 * Brighter Tools: Open Graph & Meta Tags
 *
 * File: brighter-og-meta.php
 * Version: 1.0.0
 *
 * Purpose: Complete Open Graph, Twitter Card, and enhanced meta tag output
 * Designed to work alongside or replace SEOPress OG functionality
 *
 * Responsibilities:
 * - Open Graph tags (og:url, og:site_name, og:locale, og:type, og:title, og:description)
 * - Article meta tags (article:published_time, article:modified_time)
 * - Twitter Card tags
 * - OG Image tags (already handled by image-optimisation.php, enhanced here)
 *
 * Changelog:
 * 1.0.0 - Initial implementation for Site Essentials SEO module
 *
 * Notes:
 * - Runs at priority 10 to work with existing image-optimisation.php (priority 1)
 * - Uses SEOPress meta if available, fallbacks to WordPress defaults
 * - Archives get og:type="website", singles get og:type="article"
 */

if (!defined('ABSPATH')) exit;

/**
 * Main Open Graph & Meta Tags Output
 * Priority 2 = Right after image tags (priority 1) to avoid output buffering issues
 */
add_action('wp_head', 'brighter_output_og_meta_tags', 2);
function brighter_output_og_meta_tags() {
    // Suppressed when the SeoMeta module is active — Head_Output.php handles all OG/meta tags.
    if ( defined( 'SCOS_SEO_ACTIVE' ) ) { return; }
    if (is_admin() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }

    // Get business info
    $business_name = function_exists( 'brighter_get_option' ) ? ( brighter_get_option( 'business_name' ) ?: get_bloginfo( 'name' ) ) : get_option( 'bw_business_name', get_bloginfo( 'name' ) );
    $site_name = get_bloginfo('name');
    
    // Determine locale (default to en_AU, can be extended)
    $locale = get_locale();
    $og_locale = str_replace('-', '_', $locale);
    if (empty($og_locale)) {
        $og_locale = 'en_AU';
    }

    // Get current URL
    $current_url = home_url(add_query_arg(null, null));
    
    // =========================================
    // OG: URL, Site Name, Locale
    // =========================================
     echo '<meta property="og:url" content="' . esc_url($current_url) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr($business_name) . '">' . "\n";
    echo '<meta property="og:locale" content="' . esc_attr($og_locale) . '">' . "\n";
    
    // =========================================
    // OG: Type (website vs article)
    // =========================================
    $og_type = 'website'; // Default for pages and archives
    $is_article = false;
    
    if (is_singular() && !is_page()) {
        // Posts and CPTs (non-pages) are articles
        $og_type = 'article';
        $is_article = true;
    }
    
    echo '<meta property="og:type" content="' . esc_attr($og_type) . '">' . "\n";
    
    // =========================================
    // OG: Title
    // =========================================
    $og_title = brighter_get_og_title();
    if ($og_title) {
        echo '<meta property="og:title" content="' . esc_attr($og_title) . '">' . "\n";
    }
    
    // =========================================
    // OG: Description
    // =========================================
    $og_description = brighter_get_og_description();
    if ($og_description) {
        echo '<meta property="og:description" content="' . esc_attr($og_description) . '">' . "\n";
    }
    
    // =========================================
    // Article or Website Time Meta + Author + Section
    // =========================================
    if ($is_article && is_singular()) {
        global $post;
        
        // article:published_time
        $published_time = get_the_date('c', $post->ID); // ISO 8601 format
        echo '<meta property="article:published_time" content="' . esc_attr($published_time) . '">' . "\n";
        
        // article:modified_time
        $modified_time = get_the_modified_date('c', $post->ID); // ISO 8601 format
        echo '<meta property="article:modified_time" content="' . esc_attr($modified_time) . '">' . "\n";
        
        // article:author (author archive URL)
        $author_id = $post->post_author;
        $author_url = get_author_posts_url($author_id);
        if ($author_url) {
            echo '<meta property="article:author" content="' . esc_url($author_url) . '">' . "\n";
        }
        
        // article:section (Topic Cluster from ALTC taxonomy)
        $topics = wp_get_post_terms($post->ID, 'altc_topic', ['fields' => 'names']);
        if (!empty($topics) && !is_wp_error($topics)) {
            // Use the first topic as the section
            echo '<meta property="article:section" content="' . esc_attr($topics[0]) . '">' . "\n";
        }
        
    } elseif (is_singular()) {
        // For pages (og:type=website), use og:updated_time
        global $post;
        $updated_time = get_the_modified_date('c', $post->ID);
        echo '<meta property="og:updated_time" content="' . esc_attr($updated_time) . '">' . "\n";
    }
    
    // =========================================
    // Twitter Card
    // =========================================
     echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
}

/**
 * Get OG Title
 * Tries SEOPress meta first, falls back to WordPress defaults
 *
 * @return string
 */
function brighter_get_og_title() {
    // Singular posts/pages
    if (is_singular()) {
        global $post;

        $scos_title = get_post_meta($post->ID, 'scos_seo_title', true);
        if (!empty($scos_title)) {
            return $scos_title;
        }

        return get_the_title($post->ID);
    }
    
    // Archives
    if (is_archive()) {
        return get_the_archive_title();
    }
    
    // Home page
    if (is_home() || is_front_page()) {
        $seopress_home_title = get_option('seopress_titles_home_site_title', '');
        if (!empty($seopress_home_title)) {
            return $seopress_home_title;
        }
        
        return get_bloginfo('name') . ' - ' . get_bloginfo('description');
    }
    
    // Default
    return wp_get_document_title();
}

/**
 * Get OG Description
 * Tries SEOPress meta first, falls back to WordPress defaults
 *
 * @return string
 */
function brighter_get_og_description() {
    // Singular posts/pages
    if (is_singular()) {
        global $post;

        $scos_desc = get_post_meta($post->ID, 'scos_seo_description', true);
        if (!empty($scos_desc)) {
            return $scos_desc;
        }

        // Fallback to excerpt
        if (has_excerpt($post->ID)) {
            return wp_strip_all_tags(get_the_excerpt($post));
        }
        
        // Generate from content (first 160 chars)
        $content = wp_strip_all_tags($post->post_content);
        return wp_trim_words($content, 30, '...');
    }
    
    // Archives
    if (is_archive()) {
        $archive_desc = get_the_archive_description();
        if (!empty($archive_desc)) {
            return wp_strip_all_tags($archive_desc);
        }
    }
    
    // Home page
    if (is_home() || is_front_page()) {
        $seopress_home_desc = get_option('seopress_titles_home_site_desc', '');
        if (!empty($seopress_home_desc)) {
            return $seopress_home_desc;
        }
        
        return get_bloginfo('description');
    }
    
    // Default
    return get_bloginfo('description');
}

/**
 * Get OG Image for Archives
 * Used for blog archive pages
 * 
 * @return string|false URL of the image or false
 */
function brighter_get_archive_og_image() {
    // For blog archive, try to get the featured image from the posts page
    if (is_home() && !is_front_page()) {
        $posts_page_id = get_option('page_for_posts');
        if ($posts_page_id && has_post_thumbnail($posts_page_id)) {
            $attachment_id = get_post_thumbnail_id($posts_page_id);
            return wp_get_attachment_image_url($attachment_id, 'og-image');
        }
    }
    
    // For other archives, could be extended to use taxonomy images, etc.
    // For now, return false and let default image handling take over
    return false;
}
