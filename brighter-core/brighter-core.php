<?php
/**
 * Brighter Core MU Plugin Loader
 * Version: 4.3.1
 *
 * File: brighter-core.php
 * Purpose: Load all Brighter Core modules and manage plugin infrastructure
 *
 * Changelog:
 * 4.3.1 - REMOVE: bw-schema-admin (Support → Schema / brighter-schema); use Site Essentials → Schema.
 * 4.3.0 - FEATURE: Added ALTC (Authority-Led Topic Clusters) content optimization tracking system
 *         - New taxonomies: altc_strategic_lens, altc_topic
 *         - New post meta: bw_primary_altc_id, bw_primary_topic_id, bw_cont_maturity
 *         - ALTC Content Strategy meta box in post editor
 *         - Admin columns and filters for ALTC data
 *         - ALTC Overview Dashboard and Topic Breakdown pages with cannibalization risk analysis
 *         - GA4 integration for ALTC parameters (altc_primary, altc_topic, content_maturity)
 *         - Migration tool for bw_page_topic to taxonomy-based system
 * 4.2.1 - FIX: Direct module loading for reliability
 * 4.2.0 - SECURITY: Hardened SQL queries, added capability checks, input validation, XSS protection
 * 4.1.0 - Performance: Lazy loading, conditional module loading, optimized hooks
 * 4.0.0 - Version sync fixed, improved module loading with error handling
 */

if (!defined('ABSPATH')) exit;

// TEMPORARY DIAGNOSTIC
//error_log('=== BRIGHTER CORE LOADING ===');
//error_log('BRIGHTER_CORE_PATH: ' . plugin_dir_path(__FILE__));
//error_log('Module file exists? ' . (file_exists(plugin_dir_path(__FILE__) . 'includes/brighter-business-info.php') ? 'YES' : 'NO'));

// Define plugin constants
define('BRIGHTER_CORE_VERSION', '4.3.1');
define('BRIGHTER_CORE_PATH', plugin_dir_path(__FILE__));
define('BRIGHTER_CORE_URL', plugin_dir_url(__FILE__));

/**
 * Get whitelisted module names (SECURITY: prevents path traversal)
 */
function brighter_get_whitelisted_modules() {
    static $whitelist = null;

    if ($whitelist === null) {
        $whitelist = [
            'scos-support-options',
            'brighter-business-info',
            'brighter-support',
            'brighter-frontend',
            'brighter-og-meta',             // Open Graph & Meta Tags
            'brighter-admin-branding',
            'brighter-support-image-settings',
            'bw-admin-tweaks',
            'login-styling',
            'image-optimisation',
            'bw-custposts',
            'brighter-tweaks',
            'brighter-settings',
            'custom-wpemail',
            'helpers',
            'php-limits',
            'privacy-policy-style',
            'technical-settings',
            'bw-content-strategy',
            'bw-ga4-seeder',
            'bw-ga4-seed-admin',
            // bw-schema-admin removed — site-wide schema UI is Site Essentials > Schema (SiteSchema_Module).
            'scos-car-injection',           // SCOS CAR data injection (consolidates content strategy + ALTC)
            'scos-schema-output',           // SCOS Schema @graph output (JSON-LD)
            'bw-support-cache-dashbrd',
            // bw-faq REMOVED — FAQ submodule now fully owned by
            //   site-essentials/Modules/CustomPosts/FAQ/ (FAQ_Module +
            //   FAQ_Block + FAQ_REST + FAQ_Schema_Graph). Block name
            //   `brighter/faq-selector` retained for backward compat.

            // ── ALTC legacy modules ───────────────────────────────────────────
            // class-altc-taxonomies: keep — registers legacy altc_strategic_lens
            //   and altc_topic slugs so existing DB term relationships stay valid
            //   until data is fully migrated to scos_content_cluster / scos_topic.
            'class-altc-taxonomies',
            // Deleted (all replaced by site-essentials ContentArchitecture module):
            //   class-altc-meta-boxes, class-altc-admin-columns, class-altc-admin-pages,
            //   class-altc-ga4-integration, class-altc-migration, migrate-tldr-field,
            //   class-tldr-meta-box

            // ── Content Analysis ─────────────────────────────────────────────
            // class-content-analysis: keep — BW_Content_Analysis::get_aggregated_content()
            //   still used by Social Amplification for raw content extraction.
            //   analyze_content() is gated by SCOS_CA_ACTIVE and is a no-op on all
            //   sites running ContentArchitecture module; bw_* stat writes are retired.
            'class-content-analysis',
            'class-content-analysis-seeder',
            'class-content-stats-page',
            'class-column-toggles',
            'class-field-tooltips',
            // 'migrate-tldr-field', // ONE-TIME MIGRATION — ACF → bw_tldr already complete; safe to disable
            // 'class-tldr-meta-box', // REPLACED — SCOS_SEO_ACTIVE guard makes this a no-op; SeoMeta Meta_Box.php provides TLDR field
            'reading-time-shortcode', // Reading time shortcode (frontend + backend)
            'tldr-shortcode', // TLDR summary shortcode (frontend + backend)
            'breadcrumb-shortcode', // Breadcrumb shortcode (matches schema breadcrumbs)
            'class-author-extension', // Module 15: Author Extension (E-E-A-T fields)
            'post-type-enhancements', // Add author support to custom post types
        ];
    }

    return $whitelist;
}

/**
 * Validate module name (SECURITY: prevents path traversal)
 */
function brighter_validate_module_name($module) {
    // Check against whitelist
    if (!in_array($module, brighter_get_whitelisted_modules(), true)) {
        return false;
    }
    
    // Check for directory traversal attempts
    if (strpos($module, '..') !== false || strpos($module, '/') !== false || strpos($module, '\\') !== false) {
        return false;
    }
    
    // Check for null bytes
    if (strpos($module, "\0") !== false) {
        return false;
    }
    
    return true;
}

/**
 * Load a single module with error handling
 * 
 * SECURITY: Path validation prevents directory traversal
 */
function brighter_load_module($module) {
    static $loaded = [];
    
    // Prevent double-loading
    if (isset($loaded[$module])) {
        return true;
    }
    
    // SECURITY: Validate module name before building path
    if (!brighter_validate_module_name($module)) {
        error_log('Brighter Core Security: Module validation failed for: ' . esc_html($module));
        return false;
    }
    
    // Build safe path
    $path = BRIGHTER_CORE_PATH . 'includes/' . $module . '.php';
    
    // SECURITY: Verify path is within expected directory
    $real_path = realpath($path);
    $expected_dir = realpath(BRIGHTER_CORE_PATH . 'includes/');
    
    if ($real_path === false || $expected_dir === false || strpos($real_path, $expected_dir) !== 0) {
        error_log('Brighter Core Security: Path traversal attempt detected for module: ' . esc_html($module));
        return false;
    }
    
    if (!file_exists($real_path)) {
        error_log('Brighter Core: Module not found: ' . esc_html($module) . ' at path: ' . $real_path);
        return false;
    }
    
    try {
        require_once $real_path;
        $loaded[$module] = true;
        return true;
    } catch (Exception $e) {
        error_log('Brighter Core: Error loading module ' . esc_html($module) . ': ' . esc_html($e->getMessage()));
        return false;
    }
}

/**
 * Load all enabled modules
 * Direct loading for maximum compatibility
 */
function brighter_load_modules() {
    // Define which modules to load
    $modules = [
        'scos-support-options',
        'brighter-business-info',
        'brighter-frontend',
        'brighter-og-meta',             // Open Graph & Meta Tags
        'brighter-support',
        'brighter-admin-branding',
        'login-styling',
        'brighter-support-image-settings',
        'bw-admin-tweaks',
        'image-optimisation',
        'bw-custposts',
        'brighter-tweaks',
	'bw-content-strategy',
 	'bw-ga4-seeder',
 	'bw-ga4-seed-admin',
	'scos-car-injection',           // SCOS CAR data injection (consolidates content strategy + ALTC)
	'scos-schema-output',           // SCOS Schema @graph output (JSON-LD)
 	'bw-support-cache-dashbrd',
        // bw-faq removed — see site-essentials/Modules/CustomPosts/FAQ/
        'privacy-policy-style',
        'class-author-extension',       // Module 15: Author Extension (E-E-A-T fields)
        'post-type-enhancements',       // Add author support to custom post types

        // ── ALTC legacy taxonomies ────────────────────────────────────────────
        // Keep: legacy altc_strategic_lens and altc_topic slugs preserve existing
        //       DB term relationships until data fully migrated to scos_* equivalents.
        'class-altc-taxonomies',

        // ── Content Analysis ──────────────────────────────────────────────────
        // Keep: BW_Content_Analysis::get_aggregated_content() used by Social Amplification.
        //   analyze_content() is gated (SCOS_CA_ACTIVE) — bw_* stat writes are retired.
        'class-content-analysis',
        'class-content-analysis-seeder',
        'class-content-stats-page',
        'reading-time-shortcode', // Reading time shortcode (frontend + backend)
        'tldr-shortcode',         // TLDR summary shortcode (frontend + backend)
        'breadcrumb-shortcode',   // Breadcrumb shortcode (matches schema breadcrumbs)
    ];

    // Admin-only modules (backend only, not frontend)
    $admin_only = [
        'brighter-support',
        'brighter-admin-branding',
        'brighter-support-image-settings',
        // NOTE: bw-admin-tweaks removed from admin-only because it contains frontend admin bar replacement
       	'bw-ga4-seed-admin',
        // Legacy bw-schema-admin (Support > Schema) removed; use Site Essentials > Schema.
        // Content Analysis (admin-only)
        'class-content-analysis-seeder',
        'class-content-stats-page',
        // Deleted: class-altc-meta-boxes, class-altc-admin-columns, class-altc-admin-pages,
        //          class-altc-migration, migrate-tldr-field, class-tldr-meta-box
    ];
    
    // Load all modules - admin-only modules are safe to load on frontend
    // They simply won't register hooks/menus if not in admin context
    // This ensures admin menus work reliably regardless of when MU plugins load
    foreach ($modules as $module) {
        brighter_load_module($module);
    }
}

// Load modules immediately when this file is included
brighter_load_modules();

// Load API system (separate from modules due to subdirectory structure)
if (file_exists(BRIGHTER_CORE_PATH . 'includes/api/class-brighter-api.php')) {
    require_once BRIGHTER_CORE_PATH . 'includes/api/class-brighter-api.php';
}

// Load ntfy notification system
if (file_exists(BRIGHTER_CORE_PATH . 'includes/class-ntfy-notifications.php')) {
    require_once BRIGHTER_CORE_PATH . 'includes/class-ntfy-notifications.php';
}

// Load ntfy test helper (temporary - for testing ntfy connection)
if (file_exists(BRIGHTER_CORE_PATH . 'includes/ntfy/test-ntfy.php')) {
    require_once BRIGHTER_CORE_PATH . 'includes/ntfy/test-ntfy.php';
}

/**
 * Enqueue admin styles (only when needed, deferred loading)
 */
function brighter_core_enqueue_admin_styles($hook) {
    // Only load on our admin pages
    if (strpos($hook, 'brighter') === false) {
        return;
    }
    
    // Check if CSS file exists (cached check)
    static $css_exists = null;
    if ($css_exists === null) {
        $css_exists = file_exists(BRIGHTER_CORE_PATH . 'css/admin-support.css');
    }
    
    if (!$css_exists) {
        return;
    }
    
    wp_enqueue_style(
        'brighter-admin-support', 
        BRIGHTER_CORE_URL . 'css/admin-support.css',
        array(),
        BRIGHTER_CORE_VERSION
    );
}
add_action('admin_enqueue_scripts', 'brighter_core_enqueue_admin_styles', 20);

/**
 * Enqueue frontend styles (only when needed, cached check)
 */
function brighter_core_enqueue_frontend_styles() {
    if (is_admin()) return;
    
    // Cached file existence check
    static $css_exists = null;
    if ($css_exists === null) {
        $css_exists = file_exists(BRIGHTER_CORE_PATH . 'css/frontend.css');
    }
    
    if (!$css_exists) {
        return;
    }
    
    wp_enqueue_style(
        'brighter-frontend', 
        BRIGHTER_CORE_URL . 'css/frontend.css',
        array(),
        BRIGHTER_CORE_VERSION
    );
}
add_action('wp_enqueue_scripts', 'brighter_core_enqueue_frontend_styles', 20);

/**
 * Enqueue frontend JavaScript
 */
function brighter_core_enqueue_frontend_scripts() {
    if (is_admin()) return;
    
    // Breadcrumb overflow detection
    if (file_exists(BRIGHTER_CORE_PATH . 'js/breadcrumbs.js')) {
        wp_enqueue_script(
            'brighter-breadcrumbs',
            BRIGHTER_CORE_URL . 'js/breadcrumbs.js',
            array(),
            BRIGHTER_CORE_VERSION,
            true // Load in footer
        );
    }
}
add_action('wp_enqueue_scripts', 'brighter_core_enqueue_frontend_scripts', 20);

/**
 * Plugin activation hook
 */
function brighter_core_activate() {
    // SECURITY: Only admins can activate
    if (!current_user_can('activate_plugins')) {
        return;
    }
    
    if (!get_option('brighter_core_version')) {
        update_option('brighter_core_version', BRIGHTER_CORE_VERSION);
        update_option('brighter_core_activated', current_time('mysql'));
        
        // Set default cache duration
        update_option('brighter_cache_duration', HOUR_IN_SECONDS);
    }
    
    // Clear any existing caches on activation
    wp_cache_flush();
}
register_activation_hook(__FILE__, 'brighter_core_activate');

/**
 * Admin notice if critical modules are missing (cached to reduce checks)
 * 
 * SECURITY: All output properly escaped
 */
add_action('admin_notices', function() {
    // SECURITY: Only show to admins
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Check once per day
    $transient_key = 'brighter_module_check';
    $cached_check = get_transient($transient_key);
    
    if ($cached_check !== false) {
        if ($cached_check === 'ok') return;
        // SECURITY: Cached content already escaped when stored
        echo wp_kses_post($cached_check);
        return;
    }
    
    $critical_modules = ['brighter-business-info', 'brighter-support'];
    $missing = [];
    
    foreach ($critical_modules as $module) {
        if (!brighter_validate_module_name($module)) continue;
        
        $path = BRIGHTER_CORE_PATH . 'includes/' . $module . '.php';
        if (!file_exists($path)) {
            $missing[] = esc_html($module);
        }
    }
    
    if (!empty($missing)) {
        $notice = '<div class="notice notice-error"><p>';
        $notice .= '<strong>Brighter Core:</strong> Missing critical modules: ' . implode(', ', $missing);
        $notice .= '</p></div>';
        
        set_transient($transient_key, $notice, DAY_IN_SECONDS);
        echo wp_kses_post($notice);
    } else {
        set_transient($transient_key, 'ok', DAY_IN_SECONDS);
    }
});

/**
 * Performance monitoring (only in debug mode)
 * 
 * SECURITY: Restricted to super admins only
 * NOTE: Commented out - too noisy for production. Uncomment if needed for debugging.
 */
// if (defined('WP_DEBUG') && WP_DEBUG) {
//     add_action('shutdown', function() {
//         // SECURITY: Only log for super admins
//         if (!is_super_admin()) return;
//         
//         $queries = get_num_queries();
//         $timer = timer_stop(0, 3);
//         $memory = size_format(memory_get_peak_usage());
//         
//         error_log(sprintf(
//             'Brighter Core Performance: %d queries in %s seconds using %s memory',
//             absint($queries),
//             esc_html($timer),
//             esc_html($memory)
//         ));
//     });
// }

/**
 * Heartbeat optimization - reduce frequency
 */
add_filter('heartbeat_settings', function($settings) {
    // Slow down heartbeat to reduce server load
    $settings['interval'] = 60; // Default is 15 seconds, we set to 60
    return $settings;
});

/**
 * Disable unnecessary features for performance
 */
add_action('init', function() {
    // Remove emoji support (saves HTTP requests)
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('admin_print_styles', 'print_emoji_styles');
    
    // Remove RSD link (rarely used)
    remove_action('wp_head', 'rsd_link');
    
    // Remove Windows Live Writer link
    remove_action('wp_head', 'wlwmanifest_link');
    
    // Remove WordPress version meta tag (security + performance)
    remove_action('wp_head', 'wp_generator');
}, 1);

/**
 * Object cache helper - ensures persistent caching if available
 * 
 * SECURITY: Capability checks added
 */
function brighter_cache_set($key, $value, $group = 'brighter', $expiration = HOUR_IN_SECONDS) {
    // SECURITY: Sanitize cache key
    $key = sanitize_key($key);
    $group = sanitize_key($group);
    
    return wp_cache_set($key, $value, $group, absint($expiration));
}

function brighter_cache_get($key, $group = 'brighter', &$found = null) {
    // SECURITY: Sanitize cache key
    $key = sanitize_key($key);
    $group = sanitize_key($group);
    
    return wp_cache_get($key, $group, false, $found);
}

function brighter_cache_delete($key, $group = 'brighter') {
    // SECURITY: Only admins can delete cache
    if (!current_user_can('manage_options')) {
        return false;
    }
    
    // SECURITY: Sanitize cache key
    $key = sanitize_key($key);
    $group = sanitize_key($group);
    
    return wp_cache_delete($key, $group);
}

/**
 * Batch option loader - reduces queries for multiple options
 * 
 * SECURITY: SQL injection prevention with strict validation
 */
function brighter_get_options($option_names) {
    global $wpdb;
    
    // SECURITY: Validate input is array and not empty
    if (!is_array($option_names) || empty($option_names)) {
        return [];
    }
    
    // SECURITY: Sanitize all option names
    $sanitized_names = array_map('sanitize_key', $option_names);
    
    // SECURITY: Remove empty values
    $sanitized_names = array_filter($sanitized_names);
    
    if (empty($sanitized_names)) {
        return [];
    }
    
    // SECURITY: Use proper prepared statement with array
    $placeholders = implode(',', array_fill(0, count($sanitized_names), '%s'));
    
    // Build query with proper escaping
    $query = $wpdb->prepare(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ($placeholders)",
        $sanitized_names
    );
    
    $results = $wpdb->get_results($query);
    
    if (!$results) {
        return [];
    }
    
    $options = [];
    foreach ($results as $row) {
        $options[$row->option_name] = maybe_unserialize($row->option_value);
    }
    
    return $options;
}

/**
 * Force HTTPS in admin (security best practice)
 */
if (!defined('FORCE_SSL_ADMIN')) {
    define('FORCE_SSL_ADMIN', true);
}