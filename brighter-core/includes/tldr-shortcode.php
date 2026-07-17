<?php
/**
 * TLDR Shortcode
 * 
 * Displays article summary/TLDR with semantic markup for:
 * - Screen readers
 * - Voice search (Google speakable schema)
 * - SEO
 * 
 * Usage: [tldr] or [tldr wrapper="div"]
 * 
 * Output includes:
 * - id="tldr-summary" for targeting
 * - class="speakthis" for Google speakable schema
 * - Proper paragraph wrapping
 */

if (!defined('ABSPATH')) exit;

add_shortcode('tldr', function($atts) {
    $atts = shortcode_atts([
        'wrapper' => 'div',  // div or section
        'class' => '',       // Additional CSS classes
    ], $atts, 'tldr');
    
    $post_id = get_the_ID();
    if (!$post_id) {
        return '';
    }
    
    // Get TLDR content — scos_seo_tldr is canonical; bare 'tldr' is a pre-bw_ legacy fallback
    $tldr = get_post_meta($post_id, 'scos_seo_tldr', true);
    if (empty($tldr)) {
        $tldr = get_post_meta($post_id, 'tldr', true);
    }
    
    // Return empty if no TLDR content
    if (empty($tldr)) {
        return '';
    }
    
    // Sanitize and prepare content
    $tldr = wp_kses_post($tldr); // Allow basic HTML tags
    $tldr = wpautop($tldr);      // Auto-paragraph
    
    // Build wrapper element
    $wrapper = in_array($atts['wrapper'], ['div', 'section']) ? $atts['wrapper'] : 'div';
    
    // Build class attribute
    $classes = ['tldr-summary', 'speakthis'];
    if (!empty($atts['class'])) {
        $classes[] = sanitize_html_class($atts['class']);
    }
    $class_attr = implode(' ', $classes);
    
    // Output with semantic markup
    $output = sprintf(
        '<%1$s id="tldr-summary" class="%2$s" itemscope itemtype="https://schema.org/SpeakableSpecification">
            <h3 class="tldr-heading">Summary</h3>
            <div class="tldr-content">%3$s</div>
        </%1$s>',
        $wrapper,
        esc_attr($class_attr),
        $tldr
    );
    
    return $output;
});

/**
 * TLDR styles are now in frontend.css
 * No inline styles needed - CSS is cached and loaded via brighter-frontend.css
 */

