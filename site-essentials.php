<?php
/**
 * Plugin Name: Site Essentials
 * Plugin URI:  https://brighterwebsites.com.au
 * Description: Modular site management system - Performance, Analytics, SEO, and more
 * Version:     1.1.1
 * Author:      Brighter Websites
 * Author URI:  https://brighterwebsites.com.au
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain: site-essentials
 * Domain Path: /languages
 *
 * This is the main loader for Site Essentials MU plugin.
 * It sets up constants, autoloading, and bootstraps the plugin.
 *
 * @package SiteEssentials
 * @version 1.1.1
 *
 * v1.1.1 | 2026-08-17 — Removed the SERVER_ADDR licence gate, which silently
 *                       disabled the whole plugin when the server IP changed
 *                       and published agency hosting IPs to a public repo.
 *                       Replaced with an opt-in, fail-open host warning.
 *                       License header corrected to GPL-3.0 to match LICENSE.
 * v1.1.0 | 2026-08-02 — Third-party head script output moved out of this bootstrap
 *                       into Core\Support_Scripts, which is now the single owner of
 *                       se_support_script_* storage, migration and rendering.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Deployment check.
 *
 * Warns when the plugin is running somewhere unexpected. It does NOT block
 * loading, and it is not a security or licensing control.
 *
 * The previous implementation matched $_SERVER['SERVER_ADDR'] against a
 * hardcoded IP allowlist and did a bare `return` on mismatch, which silently
 * disabled the entire plugin. That was removed because:
 *
 *   - It broke legitimate deploys. Server IPs change on a DC move, a rebuild,
 *     or a load balancer being introduced, and the failure was near-silent:
 *     one admin notice, with every module simply absent.
 *   - It was undetectable from WP-CLI, which bypassed the check, so CLI
 *     verification passed while the web front end had the plugin switched off.
 *   - It enforced nothing. This plugin is public and GPL-licensed, so the
 *     allowlist was readable by anyone and removable in three lines.
 *   - It published the agency's hosting IPs to a public repository.
 *
 * To scope the warning to specific sites, define SE_EXPECTED_HOSTS in
 * wp-config.php as a comma-separated list of hostnames. Left undefined, the
 * check is inert. Hostnames are used rather than IPs so the check survives
 * infrastructure moves.
 *
 * @since 1.1.1
 */
class SE_Deployment_Check {

    /**
     * Register the admin notice when the current host is not expected.
     *
     * @return void
     */
    public static function init() {
        if ( ! defined( 'SE_EXPECTED_HOSTS' ) || ! is_string( SE_EXPECTED_HOSTS ) || SE_EXPECTED_HOSTS === '' ) {
            return;
        }

        $expected = array_filter( array_map( 'trim', explode( ',', SE_EXPECTED_HOSTS ) ) );
        if ( empty( $expected ) ) {
            return;
        }

        $current = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( ! is_string( $current ) || $current === '' ) {
            return;
        }

        if ( ! in_array( strtolower( $current ), array_map( 'strtolower', $expected ), true ) ) {
            add_action( 'admin_notices', [ __CLASS__, 'render_notice' ] );
        }
    }

    /**
     * Render the unexpected-host notice.
     *
     * @return void
     */
    public static function render_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>Site Essentials:</strong> running on an unexpected host ('
            . esc_html( is_string( $host ) ? $host : 'unknown' )
            . '). The plugin is active; check this is intentional.</p>';
        echo '<p>Questions: <a href="mailto:support@brighterwebsites.com.au">support@brighterwebsites.com.au</a></p>';
        echo '</div>';
    }
}

add_action( 'init', [ 'SE_Deployment_Check', 'init' ] );

/**
 * Site Essentials Version
 *
 * @since 1.0.0
 */
define('SITE_ESSENTIALS_VERSION', '1.1.0');

/**
 * Site Essentials Base Path
 *
 * @since 1.0.0
 */
define('SITE_ESSENTIALS_PATH', plugin_dir_path(__FILE__) . 'site-essentials/');

/**
 * Site Essentials Base URL
 *
 * @since 1.0.0
 */
define('SITE_ESSENTIALS_URL', plugin_dir_url(__FILE__) . 'site-essentials/');

/**
 * Site Essentials Base File
 *
 * @since 1.0.0
 */
define('SITE_ESSENTIALS_FILE', __FILE__);

/**
 * PSR-4 Autoloader
 *
 * Automatically loads classes from the SiteEssentials namespace.
 * Follows PSR-4 standard for autoloading.
 *
 * @since 1.0.0
 */
spl_autoload_register(function($class) {
    $prefix = 'SiteEssentials\\';
    $base_dir = SITE_ESSENTIALS_PATH;

    // Check if class uses our namespace
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Convert namespace separators to directory separators
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // If file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Bootstrap Site Essentials - Module Registration
 *
 * Register modules early so they're available.
 *
 * @since 1.0.0
 */
// Priority 5: run before brighter-core (default init 10) so SCOS_* constants exist when
// legacy code registers taxonomies / meta — avoids duplicate ALTC taxonomy UI and old metaboxes.
add_action('init', function() {
    try {
        // Initialize Settings Manager (singleton)
        $settings = \SiteEssentials\Core\Settings_Manager::instance();

        // Register available modules
        // Add more modules here as they're created
        \SiteEssentials\Core\Module_Loader::register(
            'tweaks',
            \SiteEssentials\Modules\Tweaks\Tweaks_Module::class
        );

        \SiteEssentials\Core\Module_Loader::register(
            'seo',
            \SiteEssentials\Modules\Seo\Seo_Module::class
        );

        \SiteEssentials\Core\Module_Loader::register(
            'cpt',
            \SiteEssentials\Modules\CustomPosts\Cpt_Module::class
        );

        \SiteEssentials\Core\Module_Loader::register(
            'content_architecture',
            \SiteEssentials\Modules\ContentArchitecture\ContentArchitecture_Module::class
        );

        // SEO Meta is merged into the single "SEO Module" (id: seo) — see Seo_Module + SeoMeta_Module::bootstrap_features().

        \SiteEssentials\Core\Module_Loader::register(
            'social_amplification',
            \SiteEssentials\Modules\SocialAmplification\SocialAmplification_Module::class
        );

        // seo_schema is now absorbed into site_schema (one toggle for both per-post + site-wide schema)

        \SiteEssentials\Core\Module_Loader::register(
            'analytics',
            \SiteEssentials\Modules\Analytics\Analytics_Module::class
        );

        \SiteEssentials\Core\Module_Loader::register(
            'business_info',
            \SiteEssentials\Modules\BusinessInfo\BusinessInfo_Module::class
        );

        \SiteEssentials\Core\Module_Loader::register(
            'site_schema',
            \SiteEssentials\Modules\SiteSchema\SiteSchema_Module::class
        );

        \SiteEssentials\Core\Module_Loader::register(
            'revision_viewer',
            \SiteEssentials\Modules\RevisionViewer\RevisionViewer_Module::class
        );

        \SiteEssentials\Core\Module_Loader::register(
            'agentic',
            \SiteEssentials\Modules\Agentic\Agentic_Module::class
        );

        // CRITICAL: Disable WordPress core sitemaps (wp-sitemap.xml) so only our sitemap.xml is used.
        // WP core registers at init priority 5; we must run earlier. Use priority 0 so we run first.
        add_action('init', function() {
            $removed = remove_action('init', 'wp_sitemaps_get_server', 5);
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('[Site Essentials] wp_sitemaps disable: remove_action(init, wp_sitemaps_get_server, 5) = ' . ($removed ? 'true' : 'false'));
            }
        }, 0);
        add_filter('wp_sitemaps_enabled', '__return_false', 1);

        // Load all enabled modules
        \SiteEssentials\Core\Module_Loader::load_modules();
    } catch (\Exception $e) {
        // Log error and add admin notice
        error_log('Site Essentials Error: ' . $e->getMessage());
        error_log('Site Essentials Stack Trace: ' . $e->getTraceAsString());

        if (is_admin()) {
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>Site Essentials Error:</strong> ' . esc_html($e->getMessage());
                echo '</p></div>';
            });
        }
    }
}, 5); // Priority 5 to load early

/**
 * WP-CLI commands — registered early and independently of module enable/disable state.
 * This ensures `wp scos-social backfill` and `wp scos-social sendpost` are always
 * available when the plugin is present, regardless of whether the module loaded
 * cleanly via Module_Loader.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action( 'plugins_loaded', static function () {
		$backfill_file  = __DIR__ . '/site-essentials/Modules/SocialAmplification/CLI/Backfill_Command.php';
		$sendpost_file  = __DIR__ . '/site-essentials/Modules/SocialAmplification/CLI/Send_Post_Command.php';
		$pt_config_file = __DIR__ . '/site-essentials/Modules/SocialAmplification/Post_Type_Config.php';
		$engine         = __DIR__ . '/site-essentials/Modules/SocialAmplification/Amplification/Amplification_Engine.php';
		$postly         = __DIR__ . '/site-essentials/Modules/SocialAmplification/Amplification/Postly_Client.php';
		$anthropic      = __DIR__ . '/site-essentials/Modules/SocialAmplification/Amplification/Anthropic_Client.php';
		$hook           = __DIR__ . '/site-essentials/Modules/SocialAmplification/Publish_Hook.php';

		// Ensure all dependencies the CLI commands use are loaded.
		foreach ( [ $pt_config_file, $anthropic, $postly, $engine, $hook ] as $f ) {
			if ( file_exists( $f ) ) {
				require_once $f;
			}
		}

		if ( file_exists( $backfill_file ) ) {
			require_once $backfill_file;
			\WP_CLI::add_command(
				'scos-social backfill',
				\SiteEssentials\Modules\SocialAmplification\CLI\Backfill_Command::class
			);
		}

		if ( file_exists( $sendpost_file ) ) {
			require_once $sendpost_file;
			\WP_CLI::add_command(
				'scos-social sendpost',
				\SiteEssentials\Modules\SocialAmplification\CLI\Send_Post_Command::class
			);
		}
	}, 20 );
}

/**
 * HTTP / admin helpers that rely on wp_options but must not depend on the SEO module being enabled.
 *
 * @since 1.0.0
 */
add_action(
	'init',
	static function () {
		\SiteEssentials\Modules\SeoMeta\Redirections::register_misc_http_filters();
		\SiteEssentials\Modules\SeoMeta\Breakdance_Editor_Guard::init();
		\SiteEssentials\Core\Migration_Deprecated::init();
		\SiteEssentials\Core\Support_Scripts::init();
	},
	6
);

/**
 * Initialize Admin UI
 *
 * CRITICAL: Just instantiate - all hooks register in __construct() automatically.
 * This ensures hooks are registered before WordPress processes admin_menu and admin_init.
 *
 * @since 1.0.0
 */
add_action('init', function() {
    if (is_admin()) {
        try {
            // Just instantiate - constructor registers all hooks immediately
            new \SiteEssentials\Core\Admin_UI();
        } catch (\Exception $e) {
            error_log('Site Essentials Admin UI Error: ' . $e->getMessage());
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>Site Essentials Admin UI Error:</strong> ' . esc_html($e->getMessage());
                echo '</p></div>';
            });
        }
    }
}, 10); // Priority 10, after modules are loaded

/**
 * Initialize default settings on first load
 *
 * Note: MU plugins don't support activation hooks, so we check on every load.
 *
 * @since 1.0.0
 */
add_action('init', function() {
    $settings = \SiteEssentials\Core\Settings_Manager::instance();

    // Set defaults if this is first run
    if (!$settings->get('version')) {
        $settings->set('version', SITE_ESSENTIALS_VERSION);
        $settings->set('first_activated', time());
        flush_rewrite_rules();
    }
}, 20);

/**
 * Email delivery log prune (WP-Cron weekly).
 *
 * @since 1.0.0
 */
add_action( 'scos_email_log_prune', [ '\SiteEssentials\Modules\EmailDelivery\Email_Logger', 'prune_old_entries' ] );

/**
 * CyberPanel transactional email transport (pre_wp_mail).
 *
 * @since 1.0.0
 */
add_action(
    'init',
    static function () {
        ( new \SiteEssentials\Modules\EmailDelivery\Email_Delivery() )->boot();
    },
    15
);

/**
 * Client Onboarding — extends password_reset_expiration to the configured
 * window (default 7 days) for the agency-led onboarding flow. UI lives at
 * Agency → Onboarding (Views/agency-onboarding-tab.php).
 *
 * @since 1.0.0
 */
add_action(
    'init',
    static function () {
        ( new \SiteEssentials\Modules\ClientOnboarding\ClientOnboarding_Module() )->boot();
    },
    15
);
