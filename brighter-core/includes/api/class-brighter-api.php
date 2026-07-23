<?php
/**
 * Brighter API Main Class
 *
 * Orchestrates all API components and initializes the REST API system
 *
 * @package BrighterCore
 * @subpackage API
 * @version 1.1.0
 *
 * v1.1 | 2026-07-21 — Deprecated Make.com social webhook/talking-points/API removed
 *                      (unused on all sites). YOURLS helper retained for Postly pipeline.
 */

if (!defined('ABSPATH')) exit;

class Brighter_API {

    /**
     * @var Brighter_API_Auth Authentication handler
     */
    private $auth;

    /**
     * @var Brighter_API_Endpoints Endpoints handler
     */
    private $endpoints;

    /**
     * @var Brighter_API_Admin Admin interface
     */
    private $admin;

    /**
     * @var Brighter_API Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return Brighter_API Instance
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_components();
        $this->register_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies() {
        $api_path = BRIGHTER_CORE_PATH . 'includes/api/';
        $social_path = BRIGHTER_CORE_PATH . 'includes/social-amplification/';

        require_once $api_path . 'class-brighter-api-auth.php';
        require_once $api_path . 'class-brighter-api-endpoints.php';
        require_once $api_path . 'class-brighter-api-admin.php';

        // Social amplification components (Make.com webhook/talking-points/API removed —
        // deprecated 2026-07-21, no longer used on any site. YOURLS helper stays: the
        // Postly amplification pipeline in site-essentials calls it directly.)
        require_once $social_path . 'class-yourls-helper.php'; // YOURLS API helper (required by Postly amplification)
        require_once $social_path . 'class-breadcrumbs-meta.php';
        require_once $social_path . 'class-content-type-helper.php';
    }

    /**
     * Initialize components
     */
    private function init_components() {
        // Initialize authentication
        $this->auth = new Brighter_API_Auth();

        // Initialize endpoints with auth dependency
        $this->endpoints = new Brighter_API_Endpoints($this->auth);

        // Initialize admin interface with auth dependency
        $this->admin = new Brighter_API_Admin($this->auth);

        // Initialize breadcrumbs meta (admin only)
        if (is_admin()) {
            $breadcrumbs_meta = new BW_Breadcrumbs_Meta();
            $breadcrumbs_meta->init();
        }
    }

    /**
     * Register WordPress hooks
     */
    private function register_hooks() {
        // Register REST routes
        add_action('rest_api_init', array($this->endpoints, 'register_routes'));

        // Initialize admin interface
        if (is_admin()) {
            $this->admin->init();
        }

        // Clear cache when content is updated
        add_action('save_post', array($this, 'clear_cache_on_save'), 10, 2);
        add_action('delete_post', array($this, 'clear_cache_on_delete'));
    }

    /**
     * Clear API cache when content is saved
     *
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     */
    public function clear_cache_on_save($post_id, $post) {
        // Don't clear cache for revisions or autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // Only clear cache for relevant post types
        $relevant_types = array('post', 'page', 'folio', 'kb', 'news', 'faq', 'projects');
        if (in_array($post->post_type, $relevant_types, true)) {
            Brighter_API_Endpoints::clear_cache();
        }
    }

    /**
     * Clear API cache when content is deleted
     *
     * @param int $post_id Post ID
     */
    public function clear_cache_on_delete($post_id) {
        Brighter_API_Endpoints::clear_cache();
    }

    /**
     * Get auth instance
     *
     * @return Brighter_API_Auth
     */
    public function get_auth() {
        return $this->auth;
    }

    /**
     * Get endpoints instance
     *
     * @return Brighter_API_Endpoints
     */
    public function get_endpoints() {
        return $this->endpoints;
    }

    /**
     * Get admin instance
     *
     * @return Brighter_API_Admin
     */
    public function get_admin() {
        return $this->admin;
    }
}

// Initialize API
function brighter_api() {
    return Brighter_API::instance();
}

// Start the API on init
add_action('init', 'brighter_api', 5);
