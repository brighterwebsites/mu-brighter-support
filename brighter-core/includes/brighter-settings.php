<?php
/**
 * Brighter Tools: Settings
 *
 * File: brighter-settings.php
 * Purpose: Provides admin-side settings for Brighter Support, including
 * links to manuals, ranking tools, and login branding.
 *
 * Version: 4.0.1
 *
 * Changelog:
 * 4.0.1 - REMOVED brc_token ("Brighter API Token") field: registered on
 *         brighter_support_page/brighter_support_settings, but nothing in the codebase calls
 *         do_settings_sections('brighter_support_page') anymore, so the field was unreachable
 *         from any admin screen. It was also unrelated to and never used by the real, active
 *         API token (brighter_api_token, used by class-brighter-api-auth.php and rendered at
 *         Site Essentials > API settings) despite the similar name.
 * 4.0.0 - Initial version
 *
 * Responsibilities:
 * - Enqueue custom admin styles on Brighter Support admin pages only.
 * - Register and manage support settings (manual links, ranking links, login logo).
 * - Render input fields for editing URLs.
 *
 * Notes:
 * - The brighter_login_logo field below is registered on brighter_support_page, which is no
 *   longer rendered anywhere (Agency Settings tab now redirects to Site Essentials) — the
 *   option is still read live by login-styling.php, but there is currently no admin UI to
 *   set it. TODO: move this field to the Site Essentials Agency branding page.
 * - Styles are only loaded on the `brighter_support` admin page
 *   (avoids polluting other admin screens).
 * - Manual/Quick Links and Ranks Pro links allow dynamic site-specific
 *   references to training material and ranking tools.
 * - `brighter_login_logo` option sets a custom login page logo,
 *   replacing the default WordPress branding.
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Enqueue styles (optional, if you want separate styling for settings)
 */

add_action('admin_enqueue_scripts', function($hook) {

    // Only load our CSS on Brighter Support + AI Tracker pages
    $allowed_pages = [
        'toplevel_page_brighter_support',   // MU plugin support page
           ];

    if (!in_array($hook, $allowed_pages)) {
        return; // ? stop loading CSS everywhere else
    }

    wp_enqueue_style(
        'brighter-admin',
        plugin_dir_url(__FILE__) . 'css/admin-support.css',
        [],
        '1.0.0'
    );
});



/**
 * Register settings for login branding
 */
add_action('admin_init', function() {

    register_setting('brighter_support_settings', 'brighter_login_logo');

    add_settings_section(
        'manual_links_section',
        'Login branding',
        function () {
            echo '<p>' . esc_html__( 'Manual and ranking URLs are configured in Site Essentials ? Support ? Support settings.', 'brighterwebsites' ) . '</p>';
        },
        'brighter_support_page'
    );

    // 🔒 Login Page Logo
    add_settings_field('brighter_login_logo', 'Login Page Logo URL', function() {
        echo '<input type="url" name="brighter_login_logo" value="' . esc_url(get_option('brighter_login_logo')) . '" class="regular-text">';
        echo '<p class="description">Paste the URL of the image you want to show on the login page.</p>';
    }, 'brighter_support_page', 'manual_links_section');
});
