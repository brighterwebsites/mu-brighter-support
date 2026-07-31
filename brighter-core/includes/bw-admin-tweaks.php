<?php
/**
 * Brighter Tools: Custom Admin
 *
 * File: custom-admin.php
 * Purpose: Enhancements and modifications to the WordPress admin UI.
 *
 * Version: 4.1.1 | 2026-07-30
 *
 * Changelog:
 * 4.1.1 - FIXED: login_redirect double-prefixed absolute URLs with admin_url(), causing
 *         /wp-admin/https:/site/wp-admin/ 404s. Editors now fall back to the dashboard
 *         instead of the Support hub when no redirect is configured.
 * 4.1.0 - CLEANED: Removed optimization status (moved to bw-content-strategy.php)
 * 4.0.0 - Initial version
 *
 * Responsibilities:
 * - Custom admin bar logo
 * - Frontend admin bar replacement
 *
 * Notes:
 * - Part of the Brighter Support Tools for Client Sites MU plugin 
 * - Loaded automatically by /mu-plugins/brighter-core.php
 */

if (!defined('ABSPATH')) exit;

// ==========================
// Frontend Admin Bar Replacement
// ==========================
add_filter('show_admin_bar', '__return_false');

add_action('wp_footer', function() {
    if (current_user_can('edit_posts') && !is_admin()) {
        global $post;
        ?>
        <style>
            .gs-admin-bar-links {
                position: fixed;
                bottom: 20px;
                right: 20px;
                display: flex;
                gap: 10px;
                z-index: 9999;
            }
            .gs-admin-bar-links a {
                background-color: rgba(0, 0, 0, 0.8);
                color: #fff;
                padding: 5px 10px;
                border-radius: 999px;
                font-size: 14px;
                text-decoration: none;
                font-family: sans-serif;
                transition: background 0.3s ease;
            }
            .gs-admin-bar-links a:hover {
                background-color: #000;
            }
        </style>
         <div class="gs-admin-bar-links">
            <?php
            $support_href = 'https://brighterwebsites.com.au/support';
            if ( function_exists( 'scos_se_agency_get' ) ) {
                $base = rtrim( (string) scos_se_agency_get( 'url' ), '/' );
                if ( $base !== '' ) {
                    $support_href = preg_match( '#/support$#i', $base ) ? $base : trailingslashit( $base ) . 'support';
                }
            }
            ?>
            <a href="<?php echo esc_url( $support_href ); ?>" target="_blank" rel="noopener">💬 Support</a>
            <a href="<?php echo esc_url(admin_url('edit.php')); ?>">📊 Dashboard</a>

            <?php if ($post && $post->ID):
                $post_id         = $post->ID;
                $wp_edit_url     = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
                $seo_url         = $wp_edit_url . '#scos_seo_meta';
                $bd_data         = get_post_meta( $post_id, '_breakdance_data', true );
                $edit_page_url   = ! empty( $bd_data )
                    ? home_url( '/?breakdance=builder&id=' . $post_id )
                    : $wp_edit_url;
            ?>
                <a href="<?php echo esc_url( $seo_url ); ?>">🔍 Edit SEO</a>
                <a href="<?php echo esc_url( $edit_page_url ); ?>">✏️ Edit Page</a>
            <?php endif; ?>
        </div>
        <?php
    }
});

/**
 * Resolve a stored se_agency_login_redirect_* value to a safe absolute URL.
 *
 * Stored values are absolute URLs (the Access tab uses <input type="url">), but
 * older or hand-edited values may be root-relative ("/wp-admin/edit.php") or
 * admin-relative ("admin.php?page=foo"). Only the admin-relative form needs
 * admin_url() prefixing — prefixing an already-absolute URL produced the
 * https://site/wp-admin/https://site/wp-admin/ 404. Off-site targets are
 * rejected by wp_validate_redirect() and fall through to $fallback.
 *
 * @param string $url      Raw option value.
 * @param string $fallback Absolute URL used when $url is empty or off-site.
 * @return string
 */
function bw_resolve_login_redirect( $url, $fallback ) {
    $url = trim( (string) $url );

    if ( '' === $url ) {
        return $fallback;
    }

    $is_complete = (bool) preg_match( '#^(https?:)?//#i', $url ) || '/' === $url[0];
    $candidate   = $is_complete ? $url : admin_url( $url );
    $validated   = wp_validate_redirect( $candidate, '' );

    return '' !== $validated ? $validated : $fallback;
}

/**
 * Redirect users to their configured landing page on login
 * Compatible with WPGhost redirect override
 */
// SCOS-SUPPORT-PASS2 — login redirect wired to Agency Access tab options
add_filter( 'login_redirect', 'bw_redirect_to_support_page', 100, 3 );
function bw_redirect_to_support_page( $redirect_to, $request, $user ) {
    // Only redirect on successful login (user object exists)
    if ( ! isset( $user->ID ) || ! isset( $user->roles ) ) {
        return $redirect_to;
    }

    // Set a transient to show the notice (expires in 60 seconds)
    set_transient( 'bw_backup_reminder_' . $user->ID, true, 60 );

    // SCOS-SUPPORT-PASS2 — removed hardcoded redirect, now controlled via Agency > Access tab
    $support   = admin_url( 'admin.php?page=site-essentials-support' ); // SCOS-SUPPORT-PASS2 — updated fallback from brighter_support to site-essentials-support
    $dashboard = admin_url();
    $roles     = (array) $user->roles;

    if ( in_array( 'administrator', $roles, true ) ) { // SCOS-SUPPORT-PASS2 — administrator redirect
        return bw_resolve_login_redirect( get_option( 'se_agency_login_redirect_admin', '' ), $support );
    }

    if ( in_array( 'shop_manager', $roles, true ) ) { // SCOS-SUPPORT-PASS2 — shop_manager redirect
        return bw_resolve_login_redirect( get_option( 'se_agency_login_redirect_shop_manager', '' ), $support );
    }

    if ( in_array( 'editor', $roles, true ) ) { // SCOS-SUPPORT-PASS2 — editor redirect
        return bw_resolve_login_redirect( get_option( 'se_agency_login_redirect_editor', '' ), $dashboard );
    }

    return $redirect_to;
}

/**
 * Display backup reminder notice after login redirect should move this to agency settings
 */
add_action( 'admin_notices', 'bw_backup_reminder_notice' );
function bw_backup_reminder_notice() {
    $user_id = get_current_user_id();
    
    // Check if the transient exists for this user
    if ( get_transient( 'bw_backup_reminder_' . $user_id ) ) {
        // Delete the transient so it only shows once
        delete_transient( 'bw_backup_reminder_' . $user_id );
        
        $backup_url = admin_url( 'admin.php?page=WPvivid' );
        ?>
        <div class="notice notice-warning is-dismissible" style="border-left-width: 6px; padding: 20px 30px; margin: 20px 20px 20px 0;">
            <p style="font-size: 16px; line-height: 1.6; margin: 0;">
                <strong style="font-size: 18px;">💾 Making big changes today?</strong><br>
                <span style="font-size: 15px;">Take a manual backup first! <a href="<?php echo esc_url( $backup_url ); ?>" style="font-weight: 600; text-decoration: none;">Go to backup page →</a></span>
            </p>
        </div>        <?php
    }
}