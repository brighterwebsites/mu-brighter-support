<?php
/**
 * Admin UI
 *
 * Main settings page for Site Essentials.
 * Displays module toggles and individual module settings.
 *
 * @package    SiteEssentials
 * @subpackage Core
 * @version    1.1.0
 * @since      1.0.0
 *
 * v1.1 | 2026-07-21 — Remove Make.com webhook save handling from save_sma_settings()
 *                      (deprecated, unused on all sites).
 */

namespace SiteEssentials\Core;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin UI Class
 *
 * Manages the WordPress admin interface for Site Essentials.
 *
 * @since 1.0.0
 */
class Admin_UI {
    /**
     * Settings Manager instance
     *
     * @since 1.0.0
     * @var   Settings_Manager
     */
    private $settings;

    /**
     * Page slugs
     *
     * @since 1.0.0
     * @var   string
     */
    const PAGE_SLUG = 'site-essentials';
    const SEO_PAGE_SLUG = 'site-essentials-seo';
    const ESSENTIALS_PAGE_SLUG = 'site-essentials-essentials';
    const CPT_PAGE_SLUG = 'site-essentials-cpt';
    const BUSINESS_INFO_PAGE_SLUG = 'site-essentials-business-info';
    const SETTINGS_PAGE_SLUG = 'site-essentials-settings';
    const ANALYTICS_PAGE_SLUG = 'site-essentials-analytics';
    const SMA_PAGE_SLUG       = 'site-essentials-social-amplification';
    const SITE_SCHEMA_PAGE_SLUG = 'site-essentials-schema';
    const SUPPORT_PAGE_SLUG     = 'site-essentials-support';
    const AGENCY_PAGE_SLUG      = 'site-essentials-agency';
    const AGENTIC_PAGE_SLUG     = 'site-essentials-agentic';

    /** Legacy Support → Schema (bw-schema-admin); removed from brighter-core — redirect here. */
    private const LEGACY_BRIGHTER_SCHEMA_PAGE = 'brighter-schema';

    /**
     * Constructor
     *
     * CRITICAL: Registers all hooks in constructor so they work regardless of when object is created.
     * This is essential for MU plugins which load before plugins_loaded hook.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->settings = Settings_Manager::instance();

        // Register all hooks immediately in constructor
        // This ensures hooks are registered before WordPress processes them
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'maybe_redirect_disabled_seo_page'], 0);
        add_action('admin_init', [$this, 'maybe_redirect_legacy_brighter_schema_page'], 0);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'maybe_notice_seo_module_disabled_redirect']);
        add_action('admin_notices', [$this, 'maybe_notice_legacy_brighter_schema_removed']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_site_essentials_toggle_module', [$this, 'ajax_toggle_module']);
        add_action('wp_ajax_site_essentials_export_settings', [$this, 'ajax_export_settings']);
        add_action('wp_ajax_site_essentials_import_settings', [$this, 'ajax_import_settings']);
        add_action('wp_ajax_site_essentials_clear_sitemap_cache', [$this, 'ajax_clear_sitemap_cache']);
        add_action('admin_post_site_essentials_save_tweaks', [$this, 'save_tweaks_settings']);
        add_action('admin_post_site_essentials_save_seo', [$this, 'save_seo_settings']);
        add_action('admin_post_site_essentials_save_archive_meta', ['\SiteEssentials\Modules\SeoMeta\Archive_Settings', 'handle_save']);
        add_action('admin_post_scos_save_image_seo',               ['\SiteEssentials\Modules\SeoMeta\Image_SEO', 'handle_save']);
        add_action('admin_post_scos_save_redirections',            ['\SiteEssentials\Modules\SeoMeta\Redirections', 'handle_save']);
        add_action('admin_post_site_essentials_save_cpt', [$this, 'save_cpt_settings']);
        add_action('admin_post_site_essentials_save_sma', [$this, 'save_sma_settings']);
        add_action('admin_post_scos_save_ai_keys',        [$this, 'save_ai_keys']);
        add_action('admin_post_scos_save_email_settings',   [$this, 'save_email_settings']);
        add_action('wp_ajax_scos_send_test_email',          [$this, 'ajax_send_test_email']);
        add_action('admin_notices',                         [$this, 'maybe_notice_email_no_api_key']);
        add_action('admin_post_site_essentials_save_support', [$this, 'save_support_hub_settings']);
        add_action('admin_post_scos_save_agentic',            [$this, 'save_agentic_settings']);
        // Asset Preload form POSTs to the Performance page URL (not admin-post) so save is handled here
        add_action('admin_init', [$this, 'maybe_save_asset_preload'], 1);
    }

    /**
     * Old bookmarks to admin.php?page=brighter-schema → Site Essentials → Schema or Plugin Settings.
     *
     * @since 1.0.0
     * @return void
     */
    public function maybe_redirect_legacy_brighter_schema_page() {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( empty( $_GET['page'] ) || ! is_string( $_GET['page'] ) ) {
            return;
        }
        $page = sanitize_key( wp_unslash( $_GET['page'] ) );
        if ( $page !== self::LEGACY_BRIGHTER_SCHEMA_PAGE ) {
            return;
        }
        if ( $this->settings->is_module_enabled( 'site_schema' ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::SITE_SCHEMA_PAGE_SLUG ) );
            exit;
        }
        wp_safe_redirect(
            add_query_arg(
                [
                    'page'                 => self::SETTINGS_PAGE_SLUG,
                    'tab'                  => 'modules',
                    'scos_legacy_schema'   => 'removed',
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * If the SEO admin URL is hit while the SEO module is off, send users to Plugin Settings → Modules with a query flag for a notice.
     *
     * @since 1.0.0
     * @return void
     */
    public function maybe_redirect_disabled_seo_page() {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( empty( $_GET['page'] ) || ! is_string( $_GET['page'] ) ) {
            return;
        }
        $page = sanitize_key( wp_unslash( $_GET['page'] ) );
        if ( $page !== self::SEO_PAGE_SLUG ) {
            return;
        }
        if ( $this->settings->is_module_enabled( 'seo' ) ) {
            return;
        }
        wp_safe_redirect(
            add_query_arg(
                [
                    'page'     => self::SETTINGS_PAGE_SLUG,
                    'tab'      => 'modules',
                    'scos_seo' => 'disabled',
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * After redirect from removed brighter-schema URL when Schema module is off.
     *
     * @since 1.0.0
     * @return void
     */
    public function maybe_notice_legacy_brighter_schema_removed() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( empty( $_GET['page'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== self::SETTINGS_PAGE_SLUG ) {
            return;
        }
        if ( empty( $_GET['tab'] ) || sanitize_key( wp_unslash( $_GET['tab'] ) ) !== 'modules' ) {
            return;
        }
        if ( empty( $_GET['scos_legacy_schema'] ) || sanitize_key( wp_unslash( $_GET['scos_legacy_schema'] ) ) !== 'removed' ) {
            return;
        }
        echo '<div class="notice notice-info is-dismissible"><p>';
        esc_html_e( 'The legacy Support → Schema screen (brighter-schema) has been removed. Enable the Schema module below, then use Site Essentials → Schema for site-wide JSON-LD templates.', 'site-essentials' );
        echo '</p></div>';
    }

    /**
     * Admin notice after redirect from disabled SEO screen.
     *
     * @since 1.0.0
     * @return void
     */
    public function maybe_notice_seo_module_disabled_redirect() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( empty( $_GET['page'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== self::SETTINGS_PAGE_SLUG ) {
            return;
        }
        if ( empty( $_GET['tab'] ) || sanitize_key( wp_unslash( $_GET['tab'] ) ) !== 'modules' ) {
            return;
        }
        if ( empty( $_GET['scos_seo'] ) || sanitize_key( wp_unslash( $_GET['scos_seo'] ) ) !== 'disabled' ) {
            return;
        }
        echo '<div class="notice notice-warning is-dismissible"><p>';
        esc_html_e( 'The SEO Module is turned off. Enable the SEO Module card below to use sitemaps, on-page SEO, archive SEO, and related features.', 'site-essentials' );
        echo '</p></div>';
    }

    /**
     * Add admin menu items
     *
     * Creates top-level menu with submenu items for SEO, Essentials, and Settings.
     *
     * @since 1.0.0
     * @return void
     */
    public function add_admin_menu() {
        // Top-level menu (welcome page)
        add_menu_page(
            __('Site Essentials', 'site-essentials'),           // Page title
            __('Site Essentials', 'site-essentials'),           // Menu title
            'manage_options',                                    // Capability
            self::PAGE_SLUG,                                     // Menu slug
            [$this, 'render_welcome_page'],                     // Callback
            'dashicons-admin-generic',                           // Icon
            30                                                   // Position
        );

        // Top-level Support shell — highest priority, near top of admin menu
        add_menu_page(
            __( 'Support', 'site-essentials' ),
            __( 'Support', 'site-essentials' ),
            'manage_options',
            self::SUPPORT_PAGE_SLUG,
            [ $this, 'render_support_page' ],
            'dashicons-sos',
            2
        );

        // 1. SEO — single "SEO Module" toggle (sitemaps + meta + archives + advanced + redirections).
        if ( $this->settings->is_module_enabled( 'seo' ) ) {
            add_submenu_page(
                self::PAGE_SLUG,
                __( 'SEO', 'site-essentials' ),
                __( 'SEO', 'site-essentials' ),
                'manage_options',
                self::SEO_PAGE_SLUG,
                [ $this, 'render_seo_page' ]
            );
        }

        // 2. Schema (only when SiteSchema module is active)
        if ( defined( 'SCOS_SITE_SCHEMA_ACTIVE' ) ) {
            add_submenu_page(
                self::PAGE_SLUG,
                __( 'Site Schema', 'site-essentials' ),
                __( 'Schema', 'site-essentials' ),
                'manage_options',
                self::SITE_SCHEMA_PAGE_SLUG,
                [ $this, 'render_site_schema_page' ]
            );
        }

        // 3. Custom Posts (only when CPT module is active)
        if ( defined( 'SCOS_CPT_ACTIVE' ) ) {
            add_submenu_page(
                self::PAGE_SLUG,
                __( 'Recommended Custom Posts & Fields', 'site-essentials' ),
                __( 'Custom Posts', 'site-essentials' ),
                'manage_options',
                self::CPT_PAGE_SLUG,
                [ $this, 'render_cpt_page' ]
            );
        }

        // 3b. FAQs — CPT is registered with show_in_menu=true so it appears top-level automatically.

        // 4. Business Info (only when BusinessInfo module is active)
        if ( defined( 'SCOS_BIZ_ACTIVE' ) ) {
            add_submenu_page(
                self::PAGE_SLUG,
                __( 'Business Info', 'site-essentials' ),
                __( 'Business Info', 'site-essentials' ),
                'manage_options',
                self::BUSINESS_INFO_PAGE_SLUG,
                [ $this, 'render_business_info_page' ]
            );
        }

        // 5. Analytics (only when Analytics module is active)
        if ( defined( 'SCOS_ANALYTICS_ACTIVE' ) ) {
            add_submenu_page(
                self::PAGE_SLUG,
                __( 'Analytics', 'site-essentials' ),
                __( 'Analytics', 'site-essentials' ),
                'manage_options',
                self::ANALYTICS_PAGE_SLUG,
                [ $this, 'render_analytics_page' ]
            );
        }

        // 6. Social Amplification (only when Social Amplification module is active)
        // Post Framing is linked from within the Social Amplification page, not as a separate menu item.
        if ( defined( 'SCOS_SA_ACTIVE' ) ) {
            add_submenu_page(
                self::PAGE_SLUG,
                __( 'Social Amplification', 'site-essentials' ),
                __( 'Social Amplification', 'site-essentials' ),
                'manage_options',
                self::SMA_PAGE_SLUG,
                [ $this, 'render_social_amplification_page' ]
            );
        }

        // 7. Performance (only when Tweaks module is active)
        if ( defined( 'SCOS_TWEAKS_ACTIVE' ) ) {
            add_submenu_page(
                self::PAGE_SLUG,
                __( 'Performance', 'site-essentials' ),
                __( 'Performance', 'site-essentials' ),
                'manage_options',
                self::ESSENTIALS_PAGE_SLUG,
                [ $this, 'render_performance_page' ]
            );
        }

        // 8. Agency white label — restricted to agency staff email domain // SCOS-AGENCY-PASS2 — modified to email-domain gate
        $current_user = wp_get_current_user(); // SCOS-AGENCY-PASS2 — modified to email-domain gate
        // TODO: replace with agency role config when role system is built
        if ( str_ends_with( $current_user->user_email, '@brighterwebsites.com.au' ) ) { // SCOS-AGENCY-PASS2 — modified to email-domain gate
            add_submenu_page(
                self::PAGE_SLUG,
                __( 'Agency', 'site-essentials' ),
                __( 'Agency', 'site-essentials' ),
                'manage_options',
                self::AGENCY_PAGE_SLUG,
                [ $this, 'render_agency_page' ]
            );
        }

        // 9. Agentic (only when Agentic module is active)
        if ( defined( 'SCOS_AGENTIC_ACTIVE' ) ) {
            add_submenu_page(
                self::PAGE_SLUG,
                __( 'Agentic', 'site-essentials' ),
                __( 'Agentic', 'site-essentials' ),
                'manage_options',
                self::AGENTIC_PAGE_SLUG,
                [ $this, 'render_agentic_page' ]
            );
        }

        // 10. Settings (always visible)
        add_submenu_page(
            self::PAGE_SLUG,
            __( 'Plugin Settings', 'site-essentials' ),
            __( 'Settings', 'site-essentials' ),
            'manage_options',
            self::SETTINGS_PAGE_SLUG,
            [ $this, 'render_settings_page' ]
        );

        // Keep first submenu item (Site Essentials -> welcome page) so top-level menu links to welcome, not SEO
    }

    /**
     * Register settings
     *
     * Uses WordPress Settings API.
     *
     * @since 1.0.0
     * @return void
     */
    public function register_settings() {
        // Core settings section
        add_settings_section(
            'site_essentials_modules',
            __('Modules', 'site-essentials'),
            [$this, 'render_modules_section'],
            self::PAGE_SLUG
        );

        // Let loaded modules register their own settings
        $loaded_modules = Module_Loader::get_loaded_modules();
        foreach ($loaded_modules as $module_id => $module) {
            add_settings_section(
                'site_essentials_module_' . $module_id,
                $module::get_name(),
                '__return_false', // No description callback
                self::PAGE_SLUG
            );
        }
    }

    /**
     * Enqueue admin assets
     *
     * @since 1.0.0
     * @param string $hook Current admin page hook
     * @return void
     */
    public function enqueue_assets($hook) {
        // Pure SCOS pages: only tokens.css + scos-ui.css, no legacy admin.css.
        // Consolidating all SCOS-only pages here avoids duplicate conditional checks
        // lower in the function and ensures reliable enqueuing on all server configs
        // (including WooCommerce sites with high OPcache pressure).
        $scos_ui_hooks = [
            'toplevel_page_' . self::SUPPORT_PAGE_SLUG,
            self::PAGE_SLUG . '_page_' . self::AGENCY_PAGE_SLUG,
            self::PAGE_SLUG . '_page_' . self::ANALYTICS_PAGE_SLUG,
            self::PAGE_SLUG . '_page_' . self::SMA_PAGE_SLUG,
            self::PAGE_SLUG . '_page_' . self::BUSINESS_INFO_PAGE_SLUG,
            self::PAGE_SLUG . '_page_' . self::CPT_PAGE_SLUG,
            self::PAGE_SLUG . '_page_' . self::SITE_SCHEMA_PAGE_SLUG,
            self::PAGE_SLUG . '_page_' . self::AGENTIC_PAGE_SLUG,
        ];

        if ( in_array( $hook, $scos_ui_hooks, true ) ) {
            wp_enqueue_style(
                'scos-tokens',
                SITE_ESSENTIALS_URL . 'assets/css/tokens.css',
                [],
                SITE_ESSENTIALS_VERSION
            );
            wp_enqueue_style(
                'scos-ui',
                SITE_ESSENTIALS_URL . 'assets/css/scos-ui.css',
                [ 'scos-tokens' ],
                SITE_ESSENTIALS_VERSION
            );
            return;
        }

        // Only load admin.css on other Site Essentials pages
        // CPT_PAGE_SLUG removed — uses scos-tokens + scos-ui via $scos_ui_hooks above.
        $allowed_hooks = [
            'toplevel_page_' . self::PAGE_SLUG,
            'toplevel_page_' . self::SEO_PAGE_SLUG,
            self::PAGE_SLUG . '_page_' . self::SEO_PAGE_SLUG,
            self::PAGE_SLUG . '_page_' . self::ESSENTIALS_PAGE_SLUG,
            self::PAGE_SLUG . '_page_' . self::BUSINESS_INFO_PAGE_SLUG,
            self::PAGE_SLUG . '_page_' . self::ANALYTICS_PAGE_SLUG,
            self::PAGE_SLUG . '_page_' . self::SMA_PAGE_SLUG,
            self::PAGE_SLUG . '_page_' . self::SITE_SCHEMA_PAGE_SLUG,
            self::PAGE_SLUG . '_page_' . self::SETTINGS_PAGE_SLUG,
        ];

        if (!in_array($hook, $allowed_hooks, true)) {
            return;
        }

        // Enqueue admin CSS
        wp_enqueue_style(
            'site-essentials-admin',
            SITE_ESSENTIALS_URL . 'assets/css/admin.css',
            [],
            SITE_ESSENTIALS_VERSION
        );

        // Enqueue admin JS
        wp_enqueue_script(
            'site-essentials-admin',
            SITE_ESSENTIALS_URL . 'assets/js/admin.js',
            ['jquery'],
            SITE_ESSENTIALS_VERSION,
            true
        );

        // Localize script with data
        wp_localize_script('site-essentials-admin', 'siteEssentials', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('site_essentials_admin'),
        ]);

        // Tweaks tab uses scos-card layout — also load design system CSS on Performance page
        if ( $hook === self::PAGE_SLUG . '_page_' . self::ESSENTIALS_PAGE_SLUG ) {
            wp_enqueue_style( 'scos-tokens', SITE_ESSENTIALS_URL . 'assets/css/tokens.css', [], SITE_ESSENTIALS_VERSION );
            wp_enqueue_style( 'scos-ui', SITE_ESSENTIALS_URL . 'assets/css/scos-ui.css', [ 'scos-tokens' ], SITE_ESSENTIALS_VERSION );
        }

        // SEO page needs both admin.js (clear-cache AJAX) and the SCOS design system
        if ( $hook === self::PAGE_SLUG . '_page_' . self::SEO_PAGE_SLUG
            || $hook === 'toplevel_page_' . self::SEO_PAGE_SLUG ) {
            wp_enqueue_style( 'scos-tokens', SITE_ESSENTIALS_URL . 'assets/css/tokens.css', [], SITE_ESSENTIALS_VERSION );
            wp_enqueue_style( 'scos-ui', SITE_ESSENTIALS_URL . 'assets/css/scos-ui.css', [ 'scos-tokens' ], SITE_ESSENTIALS_VERSION );
        }

        // Settings page needs admin.js (module toggle AJAX, import/export, cache clear) + SCOS CSS
        if ( $hook === self::PAGE_SLUG . '_page_' . self::SETTINGS_PAGE_SLUG ) {
            wp_enqueue_style( 'scos-tokens', SITE_ESSENTIALS_URL . 'assets/css/tokens.css', [], SITE_ESSENTIALS_VERSION );
            wp_enqueue_style( 'scos-ui', SITE_ESSENTIALS_URL . 'assets/css/scos-ui.css', [ 'scos-tokens' ], SITE_ESSENTIALS_VERSION );
        }

        // Welcome page — pure display, needs SCOS CSS
        if ( $hook === 'toplevel_page_' . self::PAGE_SLUG ) {
            wp_enqueue_style( 'scos-tokens', SITE_ESSENTIALS_URL . 'assets/css/tokens.css', [], SITE_ESSENTIALS_VERSION );
            wp_enqueue_style( 'scos-ui', SITE_ESSENTIALS_URL . 'assets/css/scos-ui.css', [ 'scos-tokens' ], SITE_ESSENTIALS_VERSION );
        }

    }

    /**
     * Render welcome page
     *
     * @since 1.0.0
     * @return void
     */
    public function render_welcome_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        include SITE_ESSENTIALS_PATH . 'Views/welcome-page.php';
    }

    /**
     * Render settings page
     *
     * @since 1.0.0
     * @return void
     */
    public function render_settings_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'modules';

        include SITE_ESSENTIALS_PATH . 'Views/settings-page.php';
    }

    /**
     * Support hub landing page — read-only, accessible to administrator/editor/shop_manager.
     *
     * @since 1.0.0
     * @return void
     */
    public function output_support_scripts(): void {
        $commenter = get_option( 'se_support_script_commenter', '' );
        $ahrefs    = get_option( 'se_support_script_ahrefs', '' );

        if ( $commenter !== '' ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-stored trusted code field
            echo "\n" . $commenter . "\n";
        }
        if ( $ahrefs !== '' ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-stored trusted code field
            echo "\n" . $ahrefs . "\n";
        }
    }

    /**
     * @return void
     */
    public function render_support_page() {
        // SCOS-SUPPORT-PASS2 — multi-role support page access
        $allowed_roles = [ 'administrator', 'editor', 'shop_manager' ];
        $user          = wp_get_current_user();
        $has_access    = (bool) array_intersect( $allowed_roles, (array) $user->roles );
        if ( ! $has_access ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'site-essentials' ) );
        }

        include SITE_ESSENTIALS_PATH . 'Views/support-page.php';
    }

    /**
     * Agency white label settings page (se_agency_* options).
     *
     * Handles inline save before render (direct POST pattern — no admin-post.php).
     *
     * @since 1.0.0
     * @return void
     */
    public function render_agency_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'site-essentials' ) );
        }

        $active_tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'agency-settings';
        $valid_tabs  = [ 'agency-settings', 'support-settings', 'access', 'onboarding' ];
        if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
            $active_tab = 'agency-settings';
        }

        $saved = false;

        // Inline save — fires before render, redirects after save
        if (
            isset( $_POST['se_agency_save'], $_POST['se_agency_nonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['se_agency_nonce'] ) ), 'se_agency_save' )
        ) {
            $post_tab = isset( $_POST['se_agency_tab'] ) ? sanitize_key( wp_unslash( $_POST['se_agency_tab'] ) ) : $active_tab;

            if ( 'agency-settings' === $post_tab ) {
                $text_fields = [
                    'se_agency_name', 'se_agency_contact', 'se_agency_url',
                    'se_agency_email', 'se_agency_phone', 'se_agency_location',
                    'se_agency_generator', 'se_agency_credit_prefix',
                    'se_agency_credit_anchor', 'se_agency_credit_utm',
                    'se_agency_credit_target', 'se_agency_credit_rel',
                    'se_agency_meta_designer', 'se_agency_meta_author',
                ];
                foreach ( $text_fields as $key ) {
                    if ( isset( $_POST[ $key ] ) ) {
                        update_option( $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
                    }
                }
                if ( isset( $_POST['se_agency_logo'] ) ) {
                    update_option( 'se_agency_logo', absint( $_POST['se_agency_logo'] ) );
                }
                if ( isset( $_POST['se_agency_humans_txt'] ) ) {
                    update_option( 'se_agency_humans_txt', sanitize_textarea_field( wp_unslash( $_POST['se_agency_humans_txt'] ) ) );
                }
                update_option( 'se_agency_humans_txt_enabled', ! empty( $_POST['se_agency_humans_txt_enabled'] ) ? '1' : '' );
            }

            if ( 'access' === $post_tab ) {
                $redir_admin        = isset( $_POST['se_agency_login_redirect_admin'] ) ? esc_url_raw( wp_unslash( $_POST['se_agency_login_redirect_admin'] ) ) : '';
                $redir_editor       = isset( $_POST['se_agency_login_redirect_editor'] ) ? esc_url_raw( wp_unslash( $_POST['se_agency_login_redirect_editor'] ) ) : '';
                $redir_shop_manager = isset( $_POST['se_agency_login_redirect_shop_manager'] ) ? esc_url_raw( wp_unslash( $_POST['se_agency_login_redirect_shop_manager'] ) ) : ''; // SCOS-SUPPORT-PASS2 — shop_manager redirect field added
                update_option( 'se_agency_login_redirect_admin', $redir_admin );
                update_option( 'se_agency_login_redirect_editor', $redir_editor );
                update_option( 'se_agency_login_redirect_shop_manager', $redir_shop_manager ); // SCOS-SUPPORT-PASS2 — shop_manager redirect field added
            }

            wp_safe_redirect(
                add_query_arg(
                    [ 'page' => self::AGENCY_PAGE_SLUG, 'tab' => $post_tab, 'updated' => '1' ],
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }

        // Client Onboarding tab — separate nonce, handles save / preview / send_test / send_live
        if (
            isset( $_POST['se_onboarding_save'], $_POST['se_onboarding_nonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['se_onboarding_nonce'] ) ), 'se_onboarding_save' )
        ) {
            $action = isset( $_POST['se_onboarding_action'] ) ? sanitize_key( wp_unslash( $_POST['se_onboarding_action'] ) ) : '';

            // Always persist edits to template/expiry on any submit so users don't lose work
            // when they click Preview/Test before Save. Subject + body + expiry are saved on
            // every submit; Preview/Test/Send then operate on the just-saved values.
            if ( isset( $_POST['se_onboarding_subject_template'] ) ) {
                update_option(
                    \SiteEssentials\Modules\ClientOnboarding\Onboarding_Email_Sender::OPT_SUBJECT,
                    sanitize_text_field( wp_unslash( $_POST['se_onboarding_subject_template'] ) )
                );
            }
            if ( isset( $_POST['se_onboarding_html_template'] ) ) {
                // Email HTML allows full markup — admin-stored, manage_options gated.
                // Use wp_kses_post for a sensible HTML allow-list rather than dropping <html>/<body>.
                $raw = wp_unslash( $_POST['se_onboarding_html_template'] );
                update_option(
                    \SiteEssentials\Modules\ClientOnboarding\Onboarding_Email_Sender::OPT_BODY,
                    is_string( $raw ) ? $raw : ''
                );
            }
            if ( isset( $_POST['se_onboarding_password_link_expiry_days'] ) ) {
                $days = absint( $_POST['se_onboarding_password_link_expiry_days'] );
                if ( $days < 1 ) { $days = \SiteEssentials\Modules\ClientOnboarding\Onboarding_Email_Sender::DEFAULT_EXPIRY_DAYS; }
                if ( $days > 30 ) { $days = 30; }
                update_option(
                    \SiteEssentials\Modules\ClientOnboarding\Onboarding_Email_Sender::OPT_EXPIRY_DAYS,
                    $days
                );
            }

            $user_id_post = isset( $_POST['se_onboarding_user_id'] ) ? absint( $_POST['se_onboarding_user_id'] ) : 0;

            $redirect_args = [ 'page' => self::AGENCY_PAGE_SLUG, 'tab' => 'onboarding' ];

            if ( 'save' === $action ) {
                $redirect_args['se_onboarding_result'] = 'saved';
            } elseif ( 'preview' === $action ) {
                $redirect_args['se_onboarding_result'] = 'preview';
                if ( $user_id_post ) {
                    $redirect_args['se_onboarding_uid'] = $user_id_post;
                }
            } elseif ( 'send_test' === $action || 'send_live' === $action ) {
                $context = ( 'send_test' === $action ) ? 'test' : 'live';

                // For send_test we still need a user to template from. Default to current admin if none picked.
                $target_uid = $user_id_post ?: get_current_user_id();

                // For send_live we hard-require a picked user
                if ( 'live' === $context && ! $user_id_post ) {
                    $redirect_args['se_onboarding_result'] = 'error';
                    $redirect_args['se_onboarding_msg']    = rawurlencode( __( 'Pick a user to send the onboarding email to.', 'site-essentials' ) );
                } else {
                    $sender = new \SiteEssentials\Modules\ClientOnboarding\Onboarding_Email_Sender();
                    $result = $sender->send( $target_uid, $context );

                    if ( is_wp_error( $result ) ) {
                        $redirect_args['se_onboarding_result'] = 'error';
                        $redirect_args['se_onboarding_msg']    = rawurlencode( $result->get_error_message() );
                    } else {
                        $recipient_user = get_user_by( 'id', $target_uid );
                        $shown_to       = ( 'test' === $context )
                            ? wp_get_current_user()->user_email
                            : ( $recipient_user ? $recipient_user->user_email : '' );

                        $redirect_args['se_onboarding_result'] = ( 'test' === $context ) ? 'sent_test' : 'sent_live';
                        $redirect_args['se_onboarding_to']     = rawurlencode( $shown_to );
                    }
                }
            } else {
                $redirect_args['se_onboarding_result'] = 'saved';
            }

            wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
            exit;
        }

        // SCOS-SUPPORT-PASS2 — support-settings tab has its own nonce
        if (
            isset( $_POST['se_support_save'], $_POST['se_support_nonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['se_support_nonce'] ) ), 'se_support_save' )
        ) {
            // Support tool links — 6 slots
            for ( $i = 1; $i <= 6; $i++ ) {
                update_option( "se_support_tool_{$i}_title", sanitize_text_field( wp_unslash( $_POST["se_support_tool_{$i}_title"] ?? '' ) ) );
                update_option( "se_support_tool_{$i}_url",   esc_url_raw( wp_unslash( $_POST["se_support_tool_{$i}_url"] ?? '' ) ) );
                update_option( "se_support_tool_{$i}_description", sanitize_textarea_field( wp_unslash( $_POST["se_support_tool_{$i}_description"] ?? '' ) ) );
                update_option( "se_support_tool_{$i}_highlight", isset( $_POST["se_support_tool_{$i}_highlight"] ) ? 1 : 0 );
            }
            // AI tool links — 4 slots
            for ( $i = 1; $i <= 4; $i++ ) {
                update_option( "se_support_ai_{$i}_title", sanitize_text_field( wp_unslash( $_POST["se_support_ai_{$i}_title"] ?? '' ) ) );
                update_option( "se_support_ai_{$i}_url",   esc_url_raw( wp_unslash( $_POST["se_support_ai_{$i}_url"] ?? '' ) ) );
                update_option( "se_support_ai_{$i}_description", sanitize_textarea_field( wp_unslash( $_POST["se_support_ai_{$i}_description"] ?? '' ) ) );
                update_option( "se_support_ai_{$i}_highlight", isset( $_POST["se_support_ai_{$i}_highlight"] ) ? 1 : 0 );
            }
            // Site notification banner (free text, shown at top of Support page)
            $valid_notif_types = [ '', 'info', 'warning', 'urgent' ];
            $notif_type = sanitize_key( wp_unslash( $_POST['se_support_notification_type'] ?? '' ) );
            update_option( 'se_support_notification',      sanitize_textarea_field( wp_unslash( $_POST['se_support_notification'] ?? '' ) ) );
            update_option( 'se_support_notification_type', in_array( $notif_type, $valid_notif_types, true ) ? $notif_type : 'warning' );

            // Third-party scripts — stored verbatim (manage_options only); sanitize_textarea_field
            // would strip <script> tags, so we use wp_unslash + trim for code fields.
            update_option( 'se_support_script_commenter', trim( wp_unslash( $_POST['se_support_script_commenter'] ?? '' ) ) );
            update_option( 'se_support_script_ahrefs',    trim( wp_unslash( $_POST['se_support_script_ahrefs'] ?? '' ) ) );

            wp_safe_redirect(
                add_query_arg(
                    [ 'page' => self::AGENCY_PAGE_SLUG, 'tab' => 'support-settings', 'updated' => '1' ],
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }

        include SITE_ESSENTIALS_PATH . 'Views/agency-page.php';
    }

    /**
     * Render SEO page
     *
     * @since 1.0.0
     * @return void
     */
    public function render_seo_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Check if SEO module is enabled
        if (!$this->settings->is_module_enabled('seo')) {
            $this->render_module_disabled_notice(__('SEO Module', 'site-essentials'), 'seo');
            return;
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'sitemaps';

        // Get SEO module if loaded
        $seo_module = Module_Loader::get_module('seo');

        include SITE_ESSENTIALS_PATH . 'Views/seo-page.php';
    }

    /**
     * Render Performance page (WordPress Tweaks, Image Optimization, Asset Preloading)
     *
     * @since 1.0.0
     * @return void
     */
    public function render_performance_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'tweaks';
        $tweaks_module = Module_Loader::get_module('tweaks');

        // Image Optimization tab: brighter-support-image-settings must be loaded (brighter-core)
        $image_settings_available = function_exists('brighter_get_image_sizes_config');

        include SITE_ESSENTIALS_PATH . 'Views/performance-page.php';
    }

    /**
     * Render Custom Posts (Recommended CPT) page
     *
     * @since 1.0.0
     * @return void
     */
    public function render_cpt_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        if (!$this->settings->is_module_enabled('cpt')) {
            $this->render_module_disabled_notice(__('Custom Posts', 'site-essentials'), 'cpt');
            return;
        }

        $cpt_module = Module_Loader::get_module('cpt');

        if (!$cpt_module || !is_object($cpt_module)) {
            echo '<div class="wrap scos"><div class="scos-notice scos-notice--warning"><p>';
            esc_html_e('Custom Posts module is not loaded.', 'site-essentials');
            echo '</p></div></div>';
            return;
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';

        // The cpt-page.php view owns the entire `<div class="wrap scos">` wrapper.
        $cpt_module->render_settings( $active_tab );
    }

    /**
     * Render Analytics page
     *
     * @since 1.0.0
     * @return void
     */
    public function render_analytics_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have sufficient permissions to access this page.', 'site-essentials' ) );
        }

        $analytics_module = Module_Loader::get_module( 'analytics' );

        if ( ! $analytics_module ) {
            echo '<div class="wrap"><div class="notice notice-warning"><p>';
            esc_html_e( 'Analytics module is not loaded.', 'site-essentials' );
            echo '</p></div></div>';
            return;
        }

        echo '<div class="wrap scos">';
        $analytics_module->render_settings();
        echo '</div>';
    }

    /**
     * Render Agentic page
     *
     * @since 1.0.0
     * @return void
     */
    public function render_agentic_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'site-essentials' ) );
        }

        $module = Module_Loader::get_module( 'agentic' );

        if ( ! $module ) {
            echo '<div class="wrap"><div class="notice notice-warning"><p>';
            esc_html_e( 'Agentic module is not loaded.', 'site-essentials' );
            echo '</p></div></div>';
            return;
        }

        echo '<div class="wrap scos">';
        $module->render_settings();
        echo '</div>';
    }

    /**
     * Save Agentic module settings.
     *
     * @since 1.0.0
     * @return void
     */
    public function save_agentic_settings(): void {
        if ( ! isset( $_POST['scos_agentic_nonce'] )
            || ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['scos_agentic_nonce'] ) ),
                'scos_save_agentic'
            ) ) {
            wp_die( esc_html__( 'Security check failed.', 'site-essentials' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'site-essentials' ) );
        }

        update_option(
            'scos_agentic_markdown_enabled',
            ! empty( $_POST['scos_agentic_markdown_enabled'] ) ? 1 : 0
        );

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'    => self::AGENTIC_PAGE_SLUG,
                    'updated' => 'true',
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * Render Business Info page
     *
     * When BusinessInfo module is active, delegates to the module's render_settings().
     * Falls back to the legacy brighter-core form if the module is not loaded.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_business_info_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have sufficient permissions to access this page.', 'site-essentials' ) );
        }

        // SCOS-BIZ-PASS1 — wrapper updated to wrap scos; page chrome now in the view
        $biz_module = Module_Loader::get_module( 'business_info' );

        if ( ! $biz_module ) {
            echo '<div class="wrap"><div class="notice notice-warning"><p>';
            esc_html_e( 'Business Info module is not loaded. Ensure brighter-core is active.', 'site-essentials' );
            echo '</p></div></div>';
            return;
        }

        echo '<div class="wrap scos">';
        $biz_module->render_settings();
        echo '</div>';
    }

    /**
     * Render Social Amplification page
     *
     * @since 1.1.0
     * @return void
     */
    public function render_social_amplification_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have sufficient permissions to access this page.', 'site-essentials' ) );
        }

        $sma_module = Module_Loader::get_module( 'social_amplification' );

        if ( ! $sma_module ) {
            echo '<div class="wrap"><div class="notice notice-warning"><p>';
            esc_html_e( 'Social Amplification module is not loaded.', 'site-essentials' );
            echo '</p></div></div>';
            return;
        }

        // SCOS-SA-PASS1 — saved notice moved into view; wrapper updated to wrap scos
        echo '<div class="wrap scos">';
        $sma_module->render_settings();
        echo '</div>';
    }

    /**
     * Save Social Amplification settings (admin-post handler)
     *
     * Saves to scos_sma_* keys and dual-writes YOURLS values to legacy bw_yourls_*
     * keys so that BW_YOURLS_Helper continues to work without changes.
     *
     * @since 1.1.0
     * @return void
     */
    public function save_sma_settings(): void {
        if ( ! isset( $_POST['scos_sma_nonce'] )
            || ! wp_verify_nonce( $_POST['scos_sma_nonce'], 'scos_sma_save' ) ) {
            wp_die( __( 'Security check failed.', 'site-essentials' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions.', 'site-essentials' ) );
        }

        // YOURLS — only save when these fields are actually submitted.
        $yourls_fields = [
            'scos_sma_yourls_url'       => [ 'bw_yourls_api_url',  'esc_url_raw' ],
            'scos_sma_yourls_signature' => [ 'bw_yourls_signature', 'sanitize_text_field' ],
            'scos_sma_yourls_username'  => [ 'bw_yourls_username',  'sanitize_text_field' ],
            'scos_sma_yourls_password'  => [ 'bw_yourls_password',  'sanitize_text_field' ],
        ];
        foreach ( $yourls_fields as $new_key => [ $legacy_key, $cb ] ) {
            if ( ! isset( $_POST[ $new_key ] ) ) {
                continue; // Not in this form submission — preserve existing value.
            }
            $val = $cb( $_POST[ $new_key ] );
            update_option( $new_key,    $val );
            update_option( $legacy_key, $val );
        }

        // The hidden _scos_sma_tab field tells us which tab's form submitted.
        $submitted_tab = isset( $_POST['_scos_sma_tab'] ) ? sanitize_key( $_POST['_scos_sma_tab'] ) : '';

        // ── Postly.ai / Anthropic pipeline settings ──────────────────────────
        // SCOS-SA-PASS1 — new scheduling/backfill fields (scos_sa_*) added.
        // SCOS-SA-PASS2 — per-channel fields, slot-gap-days added.
        $postly_fields = [
            'bw_postly_api_key'            => 'sanitize_text_field',
            'bw_postly_workspace_id'       => 'sanitize_text_field',
            'bw_postly_channel_ids'        => 'sanitize_text_field',
            'bw_social_acf_gallery_keys'   => 'sanitize_text_field',
            'bw_social_acf_featured_key'   => 'sanitize_text_field',
            'bw_social_publish_time_min'   => 'sanitize_text_field',
            'bw_social_publish_time_max'   => 'sanitize_text_field',
            'scos_sa_backfill_date_from'   => 'sanitize_text_field',
            'scos_sa_backfill_date_to'     => 'sanitize_text_field',
            // Per-channel fields (SCOS-SA-PASS2)
            'scos_sa_postly_fb_channel_id' => 'sanitize_text_field',
            'scos_sa_postly_ig_channel_id' => 'sanitize_text_field',
            'scos_sa_postly_gmb_channel_id' => 'sanitize_text_field',
        ];
        foreach ( $postly_fields as $key => $sanitizer ) {
            if ( isset( $_POST[ $key ] ) ) {
                update_option( $key, $sanitizer( $_POST[ $key ] ) );
            }
        }

        // Integer Postly fields submitted only on the Postly tab form.
        if ( $submitted_tab === 'postly' ) {
            if ( isset( $_POST['scos_sa_postly_post_count'] ) ) {
                update_option( 'scos_sa_postly_post_count', absint( $_POST['scos_sa_postly_post_count'] ) );
            }
            if ( isset( $_POST['scos_sa_backfill_limit'] ) ) {
                update_option( 'scos_sa_backfill_limit', absint( $_POST['scos_sa_backfill_limit'] ) );
            }
            if ( isset( $_POST['scos_sa_backfill_slot_gap_days'] ) ) {
                update_option( 'scos_sa_backfill_slot_gap_days', min( 365, absint( $_POST['scos_sa_backfill_slot_gap_days'] ) ) );
            }
        }

        // Channel enable toggles — only save on Postly tab (checkboxes absent when unchecked).
        if ( $submitted_tab === 'postly' ) {
            update_option( 'scos_sa_postly_fb_enabled', isset( $_POST['scos_sa_postly_fb_enabled'] ) ? '1' : '0' );
            update_option( 'scos_sa_postly_ig_enabled', isset( $_POST['scos_sa_postly_ig_enabled'] ) ? '1' : '0' );
        }

        // Dual-write new GMB key to legacy key so CLI backfill still works with old key.
        if ( isset( $_POST['scos_sa_postly_gmb_channel_id'] ) ) {
            update_option( 'se_postly_gmb_channel_id', sanitize_text_field( $_POST['scos_sa_postly_gmb_channel_id'] ) );
        }

        // Enable toggle — only save when the Postly tab form submits.
        // Checkboxes are absent from POST when unchecked, so we need the tab guard
        // to avoid mistakenly writing '' when the YOURLS form submits.
        if ( $submitted_tab === 'postly' ) {
            update_option( 'bw_social_enabled', isset( $_POST['bw_social_enabled'] ) ? '1' : '' );
        }

        // ── Posting Settings tab (SCOS-SA-PASS2) ─────────────────────────────
        if ( $submitted_tab === 'posting' ) {
            // Global framing angles — stored as JSON array
            $frames_raw = isset( $_POST[ \SiteEssentials\Modules\SocialAmplification\Post_Type_Config::FRAMES_OPTION ] )
                ? (array) $_POST[ \SiteEssentials\Modules\SocialAmplification\Post_Type_Config::FRAMES_OPTION ]
                : [];
            $frames_clean = [];
            foreach ( $frames_raw as $frame ) {
                $frame = sanitize_textarea_field( (string) $frame );
                if ( '' !== $frame ) {
                    $frames_clean[] = $frame;
                }
            }
            update_option(
                \SiteEssentials\Modules\SocialAmplification\Post_Type_Config::FRAMES_OPTION,
                wp_json_encode( $frames_clean )
            );

            // Global image settings
            update_option(
                \SiteEssentials\Modules\SocialAmplification\Post_Type_Config::MAX_IMAGES_OPTION,
                max( 1, absint( $_POST[ \SiteEssentials\Modules\SocialAmplification\Post_Type_Config::MAX_IMAGES_OPTION ] ?? 4 ) )
            );
            update_option(
                \SiteEssentials\Modules\SocialAmplification\Post_Type_Config::NO_FEATURED_OPTION,
                isset( $_POST[ \SiteEssentials\Modules\SocialAmplification\Post_Type_Config::NO_FEATURED_OPTION ] ) ? '1' : '0'
            );
            update_option(
                \SiteEssentials\Modules\SocialAmplification\Post_Type_Config::NO_ATTACH_OPTION,
                isset( $_POST[ \SiteEssentials\Modules\SocialAmplification\Post_Type_Config::NO_ATTACH_OPTION ] ) ? '1' : '0'
            );

            // Per post type config
            if ( isset( $_POST['scos_sa_pt_config'] ) && is_array( $_POST['scos_sa_pt_config'] ) ) {
                \SiteEssentials\Modules\SocialAmplification\Post_Type_Config::save_all(
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput — sanitized inside save_all()
                    (array) $_POST['scos_sa_pt_config']
                );
            } else {
                // No per-type data submitted — save empty array to clear any existing config.
                update_option( \SiteEssentials\Modules\SocialAmplification\Post_Type_Config::OPTION_KEY, [] );
            }
        }

        // Webhook secret: auto-generate if not already set; allow manual value if posted
        $posted_secret  = isset( $_POST['bw_social_webhook_secret'] )
            ? sanitize_text_field( $_POST['bw_social_webhook_secret'] )
            : '';
        $current_secret = get_option( 'bw_social_webhook_secret', '' );
        if ( ! $current_secret ) {
            // Generate a fresh secret when none exists yet
            update_option( 'bw_social_webhook_secret', wp_generate_password( 32, false ) );
        } elseif ( $posted_secret && $posted_secret !== $current_secret ) {
            // Only update if the form explicitly sends a different value
            update_option( 'bw_social_webhook_secret', $posted_secret );
        }

        // SCOS-SA-PASS1 — scos_sma_tab param used by hash-based JS tab switcher after redirect.
        wp_redirect( add_query_arg( [ 'page' => self::SMA_PAGE_SLUG, 'scos_sma_tab' => ( $submitted_tab ?: 'yourls' ), 'scos_sma_saved' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Save AI API Keys (Anthropic, etc.) from Settings → AI API Keys tab.
     *
     * @since 1.4.0
     * @return void
     */
    public function save_ai_keys(): void {
        if ( ! isset( $_POST['scos_ai_keys_nonce'] )
            || ! wp_verify_nonce( $_POST['scos_ai_keys_nonce'], 'scos_save_ai_keys' ) ) {
            wp_die( __( 'Security check failed.', 'site-essentials' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions.', 'site-essentials' ) );
        }

        if ( isset( $_POST['bw_anthropic_api_key'] ) ) {
            update_option( 'bw_anthropic_api_key', sanitize_text_field( $_POST['bw_anthropic_api_key'] ) );
        }
        if ( isset( $_POST['bw_anthropic_model'] ) ) {
            update_option( 'bw_anthropic_model', sanitize_text_field( $_POST['bw_anthropic_model'] ) );
        }

        wp_redirect( add_query_arg( [ 'page' => self::SETTINGS_PAGE_SLUG, 'tab' => 'ai-keys', 'updated' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Save Email / transactional delivery settings (Plugin Settings → Email).
     *
     * @since 1.0.0
     * @return void
     */
    public function save_email_settings(): void {
        if ( ! isset( $_POST['scos_email_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scos_email_nonce'] ) ), 'scos_save_email_settings' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'site-essentials' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'site-essentials' ) );
        }

        $prev_enabled = (bool) get_option( 'se_email_enabled', false );
        $enabled      = isset( $_POST['se_email_enabled'] ) ? 1 : 0;
        update_option( 'se_email_enabled', $enabled );

        $api_from_wpconfig = defined( 'SE_EMAIL_API_KEY' ) && is_string( SE_EMAIL_API_KEY ) && SE_EMAIL_API_KEY !== '';
        if ( ! $api_from_wpconfig && isset( $_POST['se_email_api_key'] ) ) {
            update_option( 'se_email_api_key', sanitize_text_field( wp_unslash( $_POST['se_email_api_key'] ) ) );
        }

        update_option(
            'se_email_from_address',
            isset( $_POST['se_email_from_address'] ) ? sanitize_email( wp_unslash( $_POST['se_email_from_address'] ) ) : ''
        );
        update_option(
            'se_email_from_name',
            isset( $_POST['se_email_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['se_email_from_name'] ) ) : ''
        );
        update_option(
            'se_email_reply_to',
            isset( $_POST['se_email_reply_to'] ) ? sanitize_email( wp_unslash( $_POST['se_email_reply_to'] ) ) : ''
        );

        if ( $enabled ) {
            if ( ! \SiteEssentials\Modules\EmailDelivery\Email_Logger::table_exists() ) {
                \SiteEssentials\Modules\EmailDelivery\Email_Logger::create_table();
            }
            \SiteEssentials\Modules\EmailDelivery\Email_Logger::schedule_prune_cron();
        } else {
            \SiteEssentials\Modules\EmailDelivery\Email_Logger::unschedule_prune_cron();
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'               => self::SETTINGS_PAGE_SLUG,
                    'tab'                => 'email',
                    'scos_email_saved'   => '1',
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * AJAX: send transactional email test to site admin email.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_send_test_email(): void {
        check_ajax_referer( 'site_essentials_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'site-essentials' ) ], 403 );
        }

        $admin_email = sanitize_email( get_bloginfo( 'admin_email' ) );
        $result      = \SiteEssentials\Modules\EmailDelivery\Email_Delivery::send_test_email( $admin_email );

        if ( ! empty( $result['success'] ) ) {
            wp_send_json_success(
                [
                    'message'    => isset( $result['message'] ) ? $result['message'] : '',
                    'message_id' => isset( $result['message_id'] ) ? $result['message_id'] : '',
                ]
            );
        }

        wp_send_json_error(
            [
                'message' => isset( $result['message'] ) ? $result['message'] : __( 'Send failed.', 'site-essentials' ),
            ]
        );
    }

    /**
     * Admin notice when transactional email is enabled but no API key is configured.
     *
     * @since 1.0.0
     * @return void
     */
    public function maybe_notice_email_no_api_key(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! get_option( 'se_email_enabled', false ) ) {
            return;
        }
        if ( \SiteEssentials\Modules\EmailDelivery\Email_Delivery::get_api_key() !== '' ) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        esc_html_e( 'Site Essentials Email is enabled but no API key is configured. Add a key in Plugin Settings → Email or define SE_EMAIL_API_KEY in wp-config.php.', 'site-essentials' );
        echo '</p></div>';
    }

    /**
     * Render Site Schema page
     *
     * @since 1.0.0
     * @return void
     */
    public function render_site_schema_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have sufficient permissions to access this page.', 'site-essentials' ) );
        }

        $schema_module = Module_Loader::get_module( 'site_schema' );

        if ( ! $schema_module ) {
            echo '<div class="wrap"><div class="notice notice-warning"><p>';
            esc_html_e( 'Site Schema module is not loaded.', 'site-essentials' );
            echo '</p></div></div>';
            return;
        }

        echo '<div class="wrap scos">';
        $schema_module->render_settings();
        echo '</div>';
    }

    /**
     * Render module disabled notice
     *
     * Shows a friendly notice when trying to access a disabled module,
     * with option to enable it or go back to settings.
     *
     * @since 1.0.0
     * @param string $module_name Display name of the module (e.g., "SEO", "Essentials")
     * @param string $module_id   Module ID slug (e.g., "seo", "tweaks")
     * @return void
     */
    private function render_module_disabled_notice($module_name, $module_id) {
        $settings_url = admin_url('admin.php?page=' . self::SETTINGS_PAGE_SLUG . '&tab=modules');
        $enable_url = wp_nonce_url(
            add_query_arg([
                'action' => 'enable_module',
                'module' => $module_id
            ], admin_url('admin.php?page=' . self::PAGE_SLUG)),
            'enable_module_' . $module_id
        );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($module_name); ?></h1>

            <div class="notice notice-warning" style="margin-top: 20px; padding: 20px; max-width: 800px;">
                <h2 style="margin-top: 0;">
                    <?php
                    /* translators: %s: module name */
                    printf(esc_html__('⚠️ %s Module Disabled', 'site-essentials'), esc_html($module_name));
                    ?>
                </h2>
                <p>
                    <?php
                    /* translators: %s: module name */
                    printf(
                        esc_html__('The %s module is currently turned off. Enable it to access its features.', 'site-essentials'),
                        '<strong>' . esc_html($module_name) . '</strong>'
                    );
                    ?>
                </p>
                <p style="margin-bottom: 0;">
                    <a href="<?php echo esc_url($settings_url); ?>" class="button button-primary">
                        <?php esc_html_e('Go to Module Settings', 'site-essentials'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>" class="button">
                        <?php esc_html_e('← Back to Dashboard', 'site-essentials'); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Render modules section
     *
     * Displays module toggles.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_modules_section() {
        $available_modules = Module_Loader::get_available_modules();
        $failed_modules = Module_Loader::get_failed_modules();

        if (empty($available_modules)) {
            echo '<p>' . esc_html__('No modules available.', 'site-essentials') . '</p>';
            return;
        }

        echo '<div class="scos-modules">';

        foreach ($available_modules as $module_id => $class_name) {
            $is_enabled = $this->settings->is_module_enabled($module_id);
            $is_loaded = Module_Loader::is_module_loaded($module_id);
            $has_failed = isset($failed_modules[$module_id]);

            $name = $class_name::get_name();
            $description = $class_name::get_description();
            $tier = $class_name::get_tier();
            $dependencies = $class_name::get_dependencies();

            include SITE_ESSENTIALS_PATH . 'Views/module-toggle.php';
        }

        echo '</div>';
    }

    /**
     * AJAX: Toggle module on/off
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_toggle_module() {
        error_log("========== AJAX TOGGLE START ==========");
        error_log("[Admin_UI] ajax_toggle_module() called");
        error_log("[Admin_UI] POST data: " . json_encode($_POST));

        check_ajax_referer('site_essentials_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            error_log("[Admin_UI] FAILED: Insufficient permissions");
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $module_id = isset($_POST['module_id']) ? sanitize_key($_POST['module_id']) : '';

        // CRITICAL: filter_var() properly handles string "false" and "true" from AJAX
        // (bool) "false" would incorrectly evaluate to true!
        $enabled = isset($_POST['enabled']) ? filter_var($_POST['enabled'], FILTER_VALIDATE_BOOLEAN) : false;

        error_log("[Admin_UI] Module: {$module_id}, Action: " . ($enabled ? 'ENABLE' : 'DISABLE'));
        error_log("[Admin_UI] Raw POST enabled value: " . var_export($_POST['enabled'], true) . " (type: " . gettype($_POST['enabled']) . ")");

        if (empty($module_id)) {
            error_log("[Admin_UI] FAILED: Module ID required");
            wp_send_json_error(['message' => 'Module ID required']);
        }

        // Get state BEFORE toggle
        $before_memory = $this->settings->get('enabled_modules', []);
        $before_db = get_option(Settings_Manager::CORE_OPTION, []);
        $before_db_modules = isset($before_db['enabled_modules']) ? $before_db['enabled_modules'] : [];

        error_log("[Admin_UI] State BEFORE toggle - Memory: " . json_encode($before_memory));
        error_log("[Admin_UI] State BEFORE toggle - DB: " . json_encode($before_db_modules));

        // Perform the toggle
        $result = false;
        if ($enabled) {
            error_log("[Admin_UI] Calling enable_module({$module_id})");
            $result = $this->settings->enable_module($module_id);

            // Flush rewrite rules when enabling SEO module to register sitemap endpoints
            if ($module_id === 'seo') {
                flush_rewrite_rules();
            }
            // Flush rewrite rules when enabling CPT module (projects archive)
            if ($module_id === 'cpt') {
                flush_rewrite_rules();
            }
        } else {
            error_log("[Admin_UI] Calling disable_module({$module_id})");
            $result = $this->settings->disable_module($module_id);

            // CRITICAL: Clear caches and rewrite rules when disabling modules
            // This ensures sitemap URLs and other module functionality is properly removed
            if ($module_id === 'seo') {
                // Clear sitemap cache
                Cache_Helper::flush('seo');
                // Flush rewrite rules to remove sitemap endpoints
                flush_rewrite_rules();
                // Clear LiteSpeed cache if active
                if (function_exists('litespeed_purge_all')) {
                    litespeed_purge_all();
                }
                // Clear standard WordPress cache
                wp_cache_flush();
            }
            if ($module_id === 'cpt') {
                flush_rewrite_rules();
            }
        }

        // CRITICAL: Nuclear cache clearing - clear EVERYTHING
        global $wpdb;

        // Clear WordPress object cache
        wp_cache_flush();

        // Clear LiteSpeed Cache if present
        if (defined('LSCWP_V')) {
            do_action('litespeed_purge_all');
        }

        // Force LiteSpeed to drop this specific option from cache
        if (function_exists('litespeed_purge_all')) {
            litespeed_purge_all();
        }

        // CRITICAL: Reload settings from DB to clear internal singleton cache
        $this->settings->reload();

        // Get state AFTER toggle - use BOTH get_option AND direct DB query
        $after_memory = $this->settings->get('enabled_modules', []);
        $after_db_via_getoption = get_option(Settings_Manager::CORE_OPTION, []);
        $after_db_modules_getoption = isset($after_db_via_getoption['enabled_modules']) ? $after_db_via_getoption['enabled_modules'] : [];

        // CRITICAL: Bypass ALL caches - read directly from database
        $raw_db_value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                Settings_Manager::CORE_OPTION
            )
        );
        $after_db_direct = maybe_unserialize($raw_db_value);
        $after_db_modules_direct = isset($after_db_direct['enabled_modules']) ? $after_db_direct['enabled_modules'] : [];

        // Verify the change in DB (not just memory)
        $is_now_enabled_in_db = in_array($module_id, $after_db_modules_direct, true);
        $verified = $is_now_enabled_in_db === $enabled;

        error_log("[Admin_UI] State AFTER toggle - Memory: " . json_encode($after_memory));
        error_log("[Admin_UI] State AFTER toggle - DB (get_option): " . json_encode($after_db_modules_getoption));
        error_log("[Admin_UI] State AFTER toggle - DB (direct query): " . json_encode($after_db_modules_direct));
        error_log("[Admin_UI] Verification: " . ($verified ? 'PASSED' : 'FAILED'));
        error_log("[Admin_UI] Expected: {$module_id} should be " . ($enabled ? 'ENABLED' : 'DISABLED'));
        error_log("[Admin_UI] Actual in DB: {$module_id} is " . ($is_now_enabled_in_db ? 'ENABLED' : 'DISABLED'));

        // Check if there's a cache mismatch
        $cache_mismatch = ($after_db_modules_getoption !== $after_db_modules_direct);
        error_log("[Admin_UI] Cache mismatch: " . ($cache_mismatch ? 'YES' : 'NO'));
        error_log("========== AJAX TOGGLE END ==========");

        wp_send_json_success([
            'message' => $enabled ? 'Module enabled' : 'Module disabled',
            'enabled' => $enabled,
            'verified' => $verified,
            'db_update_result' => $result,
            'reload_required' => true,
            'cache_mismatch' => $cache_mismatch,
            'litespeed_active' => defined('LSCWP_V'),
            'wp_cache_active' => defined('WP_CACHE') && WP_CACHE,
            'debug' => [
                'before_memory' => $before_memory,
                'before_db' => $before_db_modules,
                'after_memory' => $after_memory,
                'after_db_via_getoption' => $after_db_modules_getoption,
                'after_db_direct_query' => $after_db_modules_direct,
                'option_name' => Settings_Manager::CORE_OPTION,
                'cache_layer_mismatch' => $cache_mismatch ? 'YES - get_option() returned different data than direct DB query!' : 'NO - both match',
            ],
        ]);
    }

    /**
     * AJAX: Export settings
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_export_settings() {
        check_ajax_referer('site_essentials_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $module_ids = isset($_POST['module_ids']) ? (array) $_POST['module_ids'] : [];

        $json = $this->settings->export($module_ids);

        wp_send_json_success([
            'json'     => $json,
            'filename' => 'site-essentials-' . date('Y-m-d-His') . '.json',
        ]);
    }

    /**
     * AJAX: Import settings
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_import_settings() {
        check_ajax_referer('site_essentials_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $json = isset($_POST['json']) ? wp_unslash($_POST['json']) : '';
        $merge = isset($_POST['merge']) ? (bool) $_POST['merge'] : true;
        $module_ids = isset($_POST['module_ids']) ? (array) $_POST['module_ids'] : [];

        if (empty($json)) {
            wp_send_json_error(['message' => 'No settings data provided']);
        }

        $result = $this->settings->import($json, $merge, $module_ids);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => 'Settings imported successfully']);
    }

    /**
     * Handle Asset Preload form when POSTed to Performance > Asset Preloading (same-page submit).
     * Does not use admin-post.php so it works regardless of brighter-core load order.
     *
     * @since 1.0.0
     */
    public function maybe_save_asset_preload() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        if (!isset($_GET['page']) || $_GET['page'] !== self::ESSENTIALS_PAGE_SLUG) {
            return;
        }
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'asset-preloading') {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        if (empty($_POST['bw_tweaks_nonce']) || !wp_verify_nonce($_POST['bw_tweaks_nonce'], 'bw_tweaks_save')) {
            return;
        }
        if (!class_exists('Brighter_Tweaks')) {
            return;
        }
        if (!\Brighter_Tweaks::process_save()) {
            return;
        }
        $redirect = add_query_arg([
            'page'           => self::ESSENTIALS_PAGE_SLUG,
            'tab'            => 'asset-preloading',
            'tweaks_saved'    => '1',
        ], admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Save tweaks settings
     *
     * @since 1.0.0
     * @return void
     */
    public function save_tweaks_settings() {
        // Check nonce
        if (!isset($_POST['site_essentials_tweaks_nonce']) ||
            !wp_verify_nonce($_POST['site_essentials_tweaks_nonce'], 'site_essentials_tweaks')) {
            wp_die(__('Security check failed', 'site-essentials'));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'site-essentials'));
        }

        // Get enabled tweaks
        $enabled_tweaks = isset($_POST['enabled_tweaks']) && is_array($_POST['enabled_tweaks'])
            ? $_POST['enabled_tweaks']
            : [];

        // Build full tweaks array (all false by default, enabled ones true)
        $all_tweaks = [
            // Performance & Speed
            'disable_emojis'          => isset( $enabled_tweaks['disable_emojis'] ),
            'remove_jquery_migrate'   => isset( $enabled_tweaks['remove_jquery_migrate'] ),
            'optimize_heartbeat'      => isset( $enabled_tweaks['optimize_heartbeat'] ),
            'remove_query_strings'    => isset( $enabled_tweaks['remove_query_strings'] ),
            'disable_embeds_outbound' => isset( $enabled_tweaks['disable_embeds_outbound'] ),
            'remove_google_fonts'     => isset( $enabled_tweaks['remove_google_fonts'] ),
            // Security & Hardening
            'disable_xmlrpc'          => isset( $enabled_tweaks['disable_xmlrpc'] ),
            'disable_rest_api'        => isset( $enabled_tweaks['disable_rest_api'] ),
            'restrict_rest_users'     => isset( $enabled_tweaks['restrict_rest_users'] ),
            // SEO & Metadata Code Cleanup
            'remove_rsd_link'                 => isset( $enabled_tweaks['remove_rsd_link'] ),
            'remove_wlw_link'                 => isset( $enabled_tweaks['remove_wlw_link'] ),
            'remove_wp_version'               => isset( $enabled_tweaks['remove_wp_version'] ),
            'remove_shortlink'                => isset( $enabled_tweaks['remove_shortlink'] ),
            'remove_rest_api_links'           => isset( $enabled_tweaks['remove_rest_api_links'] ),
            'disable_embeds_inbound'          => isset( $enabled_tweaks['disable_embeds_inbound'] ),
            'disable_rss_feeds'               => isset( $enabled_tweaks['disable_rss_feeds'] ),
            'disable_rss_head_links'          => isset( $enabled_tweaks['disable_rss_head_links'] ),
            'disable_relational_links'        => isset( $enabled_tweaks['disable_relational_links'] ),
            'disable_gutenberg_block_library' => isset( $enabled_tweaks['disable_gutenberg_block_library'] ),
            'disable_dashicons_frontend'      => isset( $enabled_tweaks['disable_dashicons_frontend'] ),
            // Admin UX/UI
            'allow_editors_form_submissions'  => isset( $enabled_tweaks['allow_editors_form_submissions'] ),
            // Agentic
            'hide_honeypot_from_agents'       => isset( $enabled_tweaks['hide_honeypot_from_agents'] ),
            // Legacy key — kept so previously saved data isn't lost
            'disable_embeds'                  => isset( $enabled_tweaks['disable_embeds'] ),
        ];

        // Save settings
        $this->settings->update_module_settings('tweaks', ['enabled_tweaks' => $all_tweaks]);

        // Redirect back with success message
        $redirect_url = add_query_arg([
            'page'    => self::ESSENTIALS_PAGE_SLUG,
            'tab'     => 'tweaks',
            'updated' => 'true',
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Save SEO settings
     *
     * @since 1.0.0
     * @return void
     */
    public function save_seo_settings() {
        // Check nonce
        if (!isset($_POST['site_essentials_seo_nonce']) ||
            !wp_verify_nonce($_POST['site_essentials_seo_nonce'], 'site_essentials_seo')) {
            wp_die(__('Security check failed', 'site-essentials'));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'site-essentials'));
        }

        // Get sitemap settings
        $sitemap = isset($_POST['sitemap']) && is_array($_POST['sitemap']) ? $_POST['sitemap'] : [];

        // Process post types
        $post_types = isset($sitemap['post_types']) && is_array($sitemap['post_types'])
            ? array_map('sanitize_key', $sitemap['post_types'])
            : [];

        // Process taxonomies
        $taxonomies = isset($sitemap['taxonomies']) && is_array($sitemap['taxonomies'])
            ? array_map('sanitize_key', $sitemap['taxonomies'])
            : [];

        // Process exclude IDs
        $exclude_ids_string = isset($sitemap['exclude_ids']) ? sanitize_text_field($sitemap['exclude_ids']) : '';
        $exclude_ids = array_filter(array_map('intval', explode(',', $exclude_ids_string)));

        // Build settings array
        $sitemap_settings = [
            'enabled'                => isset($sitemap['enabled']),
            'html_sitemap_enabled'   => isset($sitemap['html_sitemap_enabled']),
            'post_types'             => $post_types,
            'taxonomies'             => $taxonomies,
            'include_images'         => isset($sitemap['include_images']),
            'entries_per_sitemap'    => isset($sitemap['entries_per_sitemap']) ? absint($sitemap['entries_per_sitemap']) : 2000,
            'exclude_ids'            => $exclude_ids,
            'include_author_archive' => isset($sitemap['include_author_archive']),
        ];

        // Save settings
        $this->settings->update_module_settings('seo', ['sitemap' => $sitemap_settings]);

        // Clear sitemap cache (internal)
        Cache_Helper::flush('seo');

        // Clear page caches (LiteSpeed, WP Super Cache, etc.)
        $this->clear_page_cache();

        // Flush rewrite rules since sitemap rules may have changed
        flush_rewrite_rules();

        // Redirect back with success message
        $redirect_url = add_query_arg([
            'page'    => self::SEO_PAGE_SLUG,
            'tab'     => 'sitemaps',
            'updated' => 'true',
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Save Custom Posts (CPT) settings
     *
     * @since 1.0.0
     * @return void
     */
    public function save_cpt_settings() {
        if (!isset($_POST['site_essentials_cpt_nonce']) ||
            !wp_verify_nonce($_POST['site_essentials_cpt_nonce'], 'site_essentials_cpt')) {
            wp_die(__('Security check failed', 'site-essentials'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'site-essentials'));
        }

        $cpt_options = isset($_POST['cpt_options']) && is_array($_POST['cpt_options']) ? $_POST['cpt_options'] : [];

        $post_link_mode = isset( $cpt_options['post_link_mode'] ) ? sanitize_key( (string) $cpt_options['post_link_mode'] ) : 'default';
        if ( ! in_array( $post_link_mode, [ 'default', 'custom_prefix', 'category_prefix' ], true ) ) {
            $post_link_mode = 'default';
        }
        $general_prefix = isset( $cpt_options['general_post_slug_prefix'] ) ? sanitize_title( (string) $cpt_options['general_post_slug_prefix'] ) : '';
        if ( 'custom_prefix' !== $post_link_mode ) {
            $general_prefix = '';
        }
        if ( 'custom_prefix' === $post_link_mode && '' === $general_prefix ) {
            $post_link_mode = 'default';
        }

        $opts = [
            'customer_success_stories'  => !empty($cpt_options['customer_success_stories']),
            'include_categories'        => !empty($cpt_options['include_categories']),
            'include_tags'              => !empty($cpt_options['include_tags']),
            'archive_slug'              => isset($cpt_options['archive_slug']) ? sanitize_title($cpt_options['archive_slug']) : 'projects',
            'enable_faq'                => !empty($cpt_options['enable_faq']),
            'enable_author_extension'   => !empty($cpt_options['enable_author_extension']),
            'enable_reviews'            => !empty($cpt_options['enable_reviews']),
            'post_link_mode'            => $post_link_mode,
            'general_post_slug_prefix'  => $general_prefix,
            'general_remove_category_base' => ! empty( $cpt_options['general_remove_category_base'] ),
        ];

        $this->settings->update_module_settings('cpt', $opts);
        
        // Module 15: Sync Author Extension enabled state
        if (!empty($opts['enable_author_extension'])) {
            update_option('bw_author_extension_enabled', true);
        } else {
            update_option('bw_author_extension_enabled', false);
        }

        // FAQ archive / redirect settings — only persist these when the FAQ tab
        // form actually submitted them (the scos_faq[*] inputs only exist on the
        // FAQ tab). Otherwise saves from other tabs would wipe these options.
        if ( isset( $_POST['scos_faq'] ) && is_array( $_POST['scos_faq'] ) ) {
            $faq_opts = $_POST['scos_faq'];
            update_option('scos_faq_archive_enabled',  !empty($faq_opts['archive_enabled']));
            update_option('scos_faq_archive_redirect', isset($faq_opts['archive_redirect']) ? esc_url_raw($faq_opts['archive_redirect']) : '');
            update_option('scos_faq_topic_redirect',   isset($faq_opts['topic_redirect'])   ? esc_url_raw($faq_opts['topic_redirect'])   : '');
            // Bump rewrite version so FAQ_Module re-flushes on next load
            delete_option('scos_faq_rewrite_version');
        }

        flush_rewrite_rules();

        // Preserve ?tab= so the user lands back on the tab they submitted from.
        $submitted_tab = isset( $_POST['_scos_cpt_tab'] ) ? sanitize_key( wp_unslash( $_POST['_scos_cpt_tab'] ) ) : 'settings';
        $valid_tabs    = [ 'settings', 'faq', 'projects', 'reviews', 'author' ];
        if ( ! in_array( $submitted_tab, $valid_tabs, true ) ) {
            $submitted_tab = 'settings';
        }

        $redirect_url = add_query_arg([
            'page'    => self::CPT_PAGE_SLUG,
            'tab'     => $submitted_tab,
            'updated' => 'true',
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Save Support & agency options (tabbed forms).
     *
     * @since 1.0.0
     * @return void
     */
    public function save_support_hub_settings() {
        if ( ! isset( $_POST['site_essentials_support_nonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['site_essentials_support_nonce'] ) ), 'site_essentials_support' ) ) {
            wp_die( esc_html__( 'Security check failed', 'site-essentials' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'site-essentials' ) );
        }

        $tab = isset( $_POST['se_support_save_tab'] ) ? sanitize_key( wp_unslash( $_POST['se_support_save_tab'] ) ) : '';
        $allowed = [ 'agency-setup', 'support', 'support-settings', 'access' ];
        if ( ! in_array( $tab, $allowed, true ) ) {
            wp_die( esc_html__( 'Invalid tab.', 'site-essentials' ) );
        }

        $staff_ok = function_exists( 'scos_agency_user_can_manage_agency_setup' )
            && scos_agency_user_can_manage_agency_setup( wp_get_current_user() );

        if ( in_array( $tab, [ 'agency-setup', 'access' ], true ) && ! $staff_ok ) {
            wp_die( esc_html__( 'You do not have permission to save these settings.', 'site-essentials' ) );
        }

        if ( 'agency-setup' === $tab ) {
            update_option( 'se_agency_name', sanitize_text_field( wp_unslash( $_POST['se_agency_name'] ?? '' ) ) );
            update_option( 'se_agency_contact', sanitize_text_field( wp_unslash( $_POST['se_agency_contact'] ?? '' ) ) );
            update_option( 'se_agency_url', esc_url_raw( wp_unslash( $_POST['se_agency_url'] ?? '' ) ) );
            update_option( 'se_agency_email', sanitize_email( wp_unslash( $_POST['se_agency_email'] ?? '' ) ) );
            update_option( 'se_agency_phone', sanitize_text_field( wp_unslash( $_POST['se_agency_phone'] ?? '' ) ) );
            update_option( 'se_agency_logo_id', absint( $_POST['se_agency_logo_id'] ?? 0 ) );
            update_option( 'se_agency_location', sanitize_text_field( wp_unslash( $_POST['se_agency_location'] ?? '' ) ) );
            update_option( 'se_agency_meta_designer', sanitize_text_field( wp_unslash( $_POST['se_agency_meta_designer'] ?? '' ) ) );
            update_option( 'se_agency_meta_web_author', sanitize_text_field( wp_unslash( $_POST['se_agency_meta_web_author'] ?? '' ) ) );
            update_option( 'se_agency_meta_generator', sanitize_text_field( wp_unslash( $_POST['se_agency_meta_generator'] ?? '' ) ) );
            update_option( 'se_agency_credit_prefix', sanitize_text_field( wp_unslash( $_POST['se_agency_credit_prefix'] ?? '' ) ) );
            update_option( 'se_agency_credit_anchor', sanitize_text_field( wp_unslash( $_POST['se_agency_credit_anchor'] ?? '' ) ) );
            update_option( 'se_agency_credit_utm', sanitize_text_field( wp_unslash( $_POST['se_agency_credit_utm'] ?? '' ) ) );
            update_option( 'se_agency_credit_target', sanitize_text_field( wp_unslash( $_POST['se_agency_credit_target'] ?? '' ) ) );
            update_option( 'se_agency_credit_rel', sanitize_text_field( wp_unslash( $_POST['se_agency_credit_rel'] ?? '' ) ) );
            update_option( 'se_agency_humans_txt', sanitize_textarea_field( wp_unslash( $_POST['se_agency_humans_txt'] ?? '' ) ) );
            $redir_admin  = isset( $_POST['se_agency_login_redirect_admin'] ) ? esc_url_raw( wp_unslash( $_POST['se_agency_login_redirect_admin'] ) ) : '';
            $redir_editor = isset( $_POST['se_agency_login_redirect_editor'] ) ? esc_url_raw( wp_unslash( $_POST['se_agency_login_redirect_editor'] ) ) : '';
            if ( function_exists( 'scos_agency_sanitize_login_redirect' ) ) {
                $redir_admin  = scos_agency_sanitize_login_redirect( $redir_admin );
                $redir_editor = scos_agency_sanitize_login_redirect( $redir_editor );
            }
            update_option( 'se_agency_login_redirect_admin', $redir_admin );
            update_option( 'se_agency_login_redirect_editor', $redir_editor );
        }

        if ( 'support' === $tab ) {
            update_option( 'se_support_landing_html', wp_kses_post( wp_unslash( $_POST['se_support_landing_html'] ?? '' ) ) );
        }

        if ( 'support-settings' === $tab ) {
            $urls = [
                'se_support_manual_full'            => 'se_support_manual_full',
                'se_support_manual_quick'           => 'se_support_manual_quick',
                'se_support_website_ranking'        => 'se_support_website_ranking',
                'se_support_map_ranking'            => 'se_support_map_ranking',
                'se_support_ai_content'             => 'se_support_ai_content',
                'se_support_ai_research'            => 'se_support_ai_research',
                'se_support_ai_social'              => 'se_support_ai_social',
                'se_support_ai_competitor'          => 'se_support_ai_competitor',
                'se_support_management_portal'      => 'se_support_management_portal',
            ];
            foreach ( $urls as $post_key => $opt_key ) {
                update_option( $opt_key, esc_url_raw( wp_unslash( $_POST[ $post_key ] ?? '' ) ) );
            }

            $allowed_tags = [
                'script' => [
                    'src'      => true,
                    'type'     => true,
                    'async'    => true,
                    'defer'    => true,
                    'data-key' => true,
                    'id'       => true,
                ],
                'link'   => [
                    'rel'         => true,
                    'href'        => true,
                    'as'          => true,
                    'type'        => true,
                    'crossorigin' => true,
                ],
            ];
            if ( isset( $_POST['se_support_simple_commenter_script'] ) ) {
                $raw = wp_unslash( $_POST['se_support_simple_commenter_script'] );
                update_option( 'se_support_simple_commenter_script', is_string( $raw ) ? wp_kses( $raw, $allowed_tags ) : '' );
            }
            if ( isset( $_POST['se_support_ahrefs_script'] ) ) {
                $raw = wp_unslash( $_POST['se_support_ahrefs_script'] );
                update_option( 'se_support_ahrefs_script', is_string( $raw ) ? wp_kses( $raw, $allowed_tags ) : '' );
            }
        }

        if ( 'access' === $tab ) {
            $domains = isset( $_POST['se_agency_staff_domains'] ) ? sanitize_text_field( wp_unslash( $_POST['se_agency_staff_domains'] ) ) : '';
            $domains = preg_replace( '/[^a-zA-Z0-9@.,\s\-]/', '', $domains );
            update_option( 'se_agency_staff_domains', $domains );
        }

        $redirect_url = add_query_arg(
            [
                'page'    => self::SUPPORT_PAGE_SLUG,
                'tab'     => $tab,
                'updated' => 'true',
            ],
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * AJAX: Clear sitemap cache
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_clear_sitemap_cache() {
        check_ajax_referer('site_essentials_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Clear our internal sitemap cache
        Cache_Helper::flush('seo');

        // Clear page caches
        $this->clear_page_cache();

        wp_send_json_success([
            'message' => 'Sitemap cache cleared successfully (internal + page cache)'
        ]);
    }

    /**
     * Clear all page caches
     *
     * Detects and clears LiteSpeed, WP Super Cache, W3 Total Cache, and WP Rocket.
     *
     * @since 1.0.0
     * @return void
     */
    private function clear_page_cache() {
        // Clear LiteSpeed Cache if active
        if (function_exists('litespeed_purge_all')) {
            litespeed_purge_all();
        } elseif (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) {
            \LiteSpeed_Cache_API::purge_all();
        }

        // Clear WP Super Cache if active
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        // Clear W3 Total Cache if active
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }

        // Clear WP Rocket cache if active
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
    }

    /**
     * Get deployment info for debugging
     *
     * @since 1.0.0
     * @return array
     */
    public static function get_deployment_info() {
        $info = [
            'version' => SITE_ESSENTIALS_VERSION,
            'commit' => 'unknown',
            'branch' => 'unknown',
            'deployed_at' => 'unknown',
        ];

        // Try to read version file (created by deploy script)
        $version_file = dirname(SITE_ESSENTIALS_PATH) . '/site-essentials-version.php';
        if (file_exists($version_file)) {
            $version_data = include $version_file;
            if (is_array($version_data)) {
                $info = array_merge($info, $version_data);
            }
        }

        return $info;
    }
}
