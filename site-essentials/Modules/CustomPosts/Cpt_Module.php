<?php
/**
 * Custom Posts (Recommended CPT) Module
 *
 * Registers optional custom post types and taxonomy support:
 * - Customer Success Stories (post type: projects, has_archive, slug: configurable)
 * - Reviews (post type: bw_reviews, queryable SSOT data source, no public URLs or archive)
 * - Include WP Categories for projects
 * - Include WP Tags for projects
 *
 * When an option is enabled the CPT/taxonomy is registered; when disabled it is not.
 *
 * @package    SiteEssentials
 * @subpackage Modules\CustomPosts
 * @version    1.2.1
 * @since      1.0.0
 */

namespace SiteEssentials\Modules\CustomPosts;

use SiteEssentials\Core\Module_Interface;
use SiteEssentials\Core\Settings_Manager;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cpt_Module Class
 *
 * @since 1.0.0
 */
class Cpt_Module implements Module_Interface {
    /**
     * Settings Manager instance
     *
     * @since 1.0.0
     * @var   Settings_Manager
     */
    private $settings;

    /**
     * Post type slug for Customer Success Stories
     */
    const POST_TYPE_PROJECTS = 'projects';

    /**
     * Post type slug for Reviews (queryable SSOT — no archive/URLs)
     */
    const POST_TYPE_REVIEWS = 'bw_reviews';

    /**
     * Taxonomy slug for Review Platform
     */
    const TAXONOMY_REVIEW_PLATFORM = 'bw_review_platform';

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->settings = Settings_Manager::instance();
    }

    /**
     * Get module ID
     *
     * @since  1.0.0
     * @return string
     */
    public static function get_id() {
        return 'cpt';
    }

    /**
     * Get module name
     *
     * @since  1.0.0
     * @return string
     */
    public static function get_name() {
        return __('Recommended Custom Posts & Fields', 'site-essentials');
    }

    /**
     * Get module description
     *
     * @since  1.0.0
     * @return string
     */
    public static function get_description() {
        return __('Enable recommended custom post types (FAQ, Projects/Success Stories, Reviews), taxonomy support, and extended field sets (Author Extension for E-E-A-T).', 'site-essentials');
    }

    /**
     * Get module tier
     *
     * @since  1.0.0
     * @return string
     */
    public static function get_tier() {
        return 'basic';
    }

    /**
     * Get module dependencies
     *
     * @since  1.0.0
     * @return array
     */
    public static function get_dependencies() {
        return [];
    }

    /**
     * Get module version
     *
     * @since  1.0.0
     * @return string
     */
    public static function get_version() {
        return '1.2.0';
    }

    /**
     * Get default CPT options
     *
     * @since  1.0.0
     * @return array
     */
    private function get_default_options() {
        // All features opt-in: only explicit saves turn options on (avoids "Loaded" when toggle appears off).
        return [
            'customer_success_stories'       => false,
            'include_categories'             => false,
            'include_tags'                   => false,
            'archive_slug'                   => 'projects',
            'enable_faq'                     => false,
            'enable_author_extension'        => false,
            'enable_reviews'                 => false,
            // General (default WP posts) — UI on CPT page, not a module card.
            'post_link_mode'                 => 'default', // default | custom_prefix | category_prefix
            'general_post_slug_prefix'       => '',
            'general_remove_category_base'   => false,
        ];
    }

    /**
     * Initialize module
     *
     * Registers CPT and taxonomies only when corresponding options are enabled.
     *
     * @since 1.0.0
     * @return void
     */
    public function init() {
        if ( ! defined( 'SCOS_CPT_ACTIVE' ) ) {
            define( 'SCOS_CPT_ACTIVE', true );
        }

        $opts = $this->settings->get_module_setting('cpt', null, []);
        $opts = wp_parse_args(is_array($opts) ? $opts : [], $this->get_default_options());

        // ─── Projects CPT ────────────────────────────────────────────────────
        if (!empty($opts['customer_success_stories'])) {
            add_action('init', [$this, 'register_projects_cpt'], 20);

            if (!empty($opts['include_categories'])) {
                add_action('init', [$this, 'register_projects_categories'], 25);
            }
            if (!empty($opts['include_tags'])) {
                add_action('init', [$this, 'register_projects_tags'], 25);
            }

            // Project Selector Gutenberg block — pick projects by ID for the default editor.
            require_once __DIR__ . '/Projects/Project_Selector_Block.php';
            \SiteEssentials\Modules\CustomPosts\Projects\Project_Selector_Block::init();

            require_once __DIR__ . '/Projects/Project_Selector_REST.php';
            \SiteEssentials\Modules\CustomPosts\Projects\Project_Selector_REST::init();
        }

        // ─── Reviews CPT ─────────────────────────────────────────────────────
        if (!empty($opts['enable_reviews'])) {
            add_action('init', [$this, 'register_reviews_cpt'], 20);
            add_action('init', [$this, 'register_review_platform_taxonomy'], 25);
            add_action('init', [$this, 'seed_review_platform_terms'], 30);
            add_action('init', [$this, 'register_reviews_shortcodes'], 30);
            
            // Exclude from XML sitemaps (public=true makes it queryable but we don't want URLs)
            add_filter('wp_sitemaps_post_types', [$this, 'exclude_reviews_from_sitemap']);

            if (is_admin()) {
                add_action('add_meta_boxes', [$this, 'register_reviews_meta_boxes']);
                // Priority 10: save standard meta fields from $_POST
                add_action('save_post_bw_reviews', [$this, 'save_reviews_meta'], 10, 2);
                // Priority 20: auto-generate schema ID after meta + taxonomy are saved
                add_action('save_post_bw_reviews', [$this, 'auto_generate_schema_id'], 20, 2);
                add_filter('enter_title_here', [$this, 'reviews_title_placeholder'], 10, 2);
                add_filter('manage_bw_reviews_posts_columns', [$this, 'reviews_admin_columns']);
                add_action('manage_bw_reviews_posts_custom_column', [$this, 'reviews_admin_column_content'], 10, 2);

                // Platform taxonomy logo field
                add_action(self::TAXONOMY_REVIEW_PLATFORM . '_add_form_fields',  [$this, 'platform_taxonomy_add_form_fields']);
                add_action(self::TAXONOMY_REVIEW_PLATFORM . '_edit_form_fields', [$this, 'platform_taxonomy_edit_form_fields']);
                add_action('created_' . self::TAXONOMY_REVIEW_PLATFORM, [$this, 'save_platform_taxonomy_logo']);
                add_action('edited_'  . self::TAXONOMY_REVIEW_PLATFORM, [$this, 'save_platform_taxonomy_logo']);
                add_action('admin_enqueue_scripts', [$this, 'enqueue_platform_taxonomy_scripts']);

                // SVG upload support (scoped to admin — upload AJAX runs in admin context)
                add_filter('upload_mimes',               [$this, 'allow_platform_svg_upload']);
                add_filter('wp_check_filetype_and_ext',  [$this, 'fix_svg_filetype_check'], 10, 5);
                add_filter('wp_handle_upload_prefilter', [$this, 'sanitize_svg_upload']);
            }

            // ACF relationship field — fires after ACF is fully loaded
            add_action('acf/init', [$this, 'register_reviews_acf_fields']);

            // CSV import — must be registered outside is_admin() so admin-post.php can call it
            add_action('admin_post_site_essentials_reviews_csv_import', [$this, 'handle_reviews_csv_import']);
        }

        // ─── Author Extension ─────────────────────────────────────────────────
        if (!empty($opts['enable_author_extension'])) {
            update_option('bw_author_extension_enabled', true);
        } else {
            update_option('bw_author_extension_enabled', false);
        }

        // ─── FAQ System ────────────────────────────────────────────────────────
        if (!empty($opts['enable_faq'])) {
            require_once __DIR__ . '/FAQ/FAQ_Module.php';
            \SiteEssentials\Modules\CustomPosts\FAQ\FAQ_Module::init();
        }

        // ─── General: default post permalinks & category base (optional) ───────
        require_once __DIR__ . '/General_Post_Permalink_Settings.php';
        General_Post_Permalink_Settings::bootstrap( $opts );
    }

    // =========================================================================
    // PROJECTS CPT
    // =========================================================================

    /**
     * Register Projects CPT (Customer Success Stories)
     *
     * Fix 1: Labels now derived dynamically from archive_slug so that changing
     *        the rename field propagates to admin menu labels immediately.
     * Fix 2: Breadcrumbs read $post_type_obj->labels->name — fixing Fix 1
     *        automatically fixes the "hardcoded Customer Success" breadcrumb bug.
     *
     * @since 1.0.0
     * @return void
     */
    public function register_projects_cpt() {
        $opts = $this->settings->get_module_setting('cpt', null, []);
        $opts = wp_parse_args(is_array($opts) ? $opts : [], $this->get_default_options());
        if (empty($opts['customer_success_stories'])) {
            return;
        }
        $archive_slug = !empty($opts['archive_slug']) ? sanitize_title($opts['archive_slug']) : 'projects';

        // Derive human-readable display name from the slug (Fix 1 + Fix 2)
        $display_name = ucwords(str_replace(['-', '_'], ' ', $archive_slug));
        if (empty(trim($display_name))) {
            $display_name = 'Projects';
        }

        $labels = [
            'name'               => $display_name,
            'singular_name'      => $display_name,
            'menu_name'          => $display_name,
            'name_admin_bar'     => $display_name,
            'add_new'            => _x('Add New', 'project', 'site-essentials'),
            'add_new_item'       => sprintf(/* translators: %s: display name */ __('Add New %s', 'site-essentials'), $display_name),
            'new_item'           => sprintf(/* translators: %s: display name */ __('New %s', 'site-essentials'), $display_name),
            'edit_item'          => sprintf(/* translators: %s: display name */ __('Edit %s', 'site-essentials'), $display_name),
            'view_item'          => sprintf(/* translators: %s: display name */ __('View %s', 'site-essentials'), $display_name),
            'all_items'          => $display_name,
            'search_items'       => sprintf(/* translators: %s: display name */ __('Search %s', 'site-essentials'), $display_name),
            'not_found'          => sprintf(/* translators: %s: lowercase display name */ __('No %s found.', 'site-essentials'), strtolower($display_name)),
            'not_found_in_trash' => sprintf(/* translators: %s: lowercase display name */ __('No %s found in Trash.', 'site-essentials'), strtolower($display_name)),
        ];

        $args = [
            'labels'              => $labels,
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'query_var'           => true,
            'rewrite'             => ['slug' => $archive_slug],
            'capability_type'     => 'post',
            'has_archive'         => true,
            'hierarchical'        => false,
            'menu_position'       => 20,
            'menu_icon'           => 'dashicons-portfolio',
            'supports'            => ['title', 'editor', 'thumbnail', 'excerpt', 'revisions'],
            'show_in_rest'        => true,
        ];

        register_post_type(self::POST_TYPE_PROJECTS, $args);
    }

    /**
     * Register WordPress Category taxonomy for projects
     *
     * @since 1.0.0
     * @return void
     */
    public function register_projects_categories() {
        register_taxonomy_for_object_type('category', self::POST_TYPE_PROJECTS);
    }

    /**
     * Register WordPress Tags taxonomy for projects
     *
     * @since 1.0.0
     * @return void
     */
    public function register_projects_tags() {
        register_taxonomy_for_object_type('post_tag', self::POST_TYPE_PROJECTS);
    }

    // =========================================================================
    // REVIEWS CPT REGISTRATION
    // -------------------------------------------------------------------------
    // TODO: migrate Reviews bw_* keys to scos_review_* / scos_cpt_* — see
    //       .cursor/rules/meta-key-prefixes.mdc. Legacy keys still in use:
    //         - Post type:  bw_reviews
    //         - Taxonomy:   bw_review_platform
    //         - Meta:       bw_rating, bw_date, bw_date_precision, bw_verify_url,
    //                       bw_schema_id, bw_success_outcome, bw_customer_detail,
    //                       bw_is_featured, bw_review_excerpt
    //         - ACF:        bw_related_project, bw_reviews_related
    //         - Shortcodes: [bw_review_*]
    //       Plan: rename to scos_review_* with dual-read fallback, then deprecate.
    // =========================================================================

    /**
     * Register Reviews CPT
     *
     * Queryable SSOT data source — no archive page, no single URLs, but queryable via WP_Query/BDE.
     *
     * Key flags:
     *   public: true             — REQUIRED for front-end queryability in Breakdance loops
     *   publicly_queryable: true — REQUIRED for WP_Query and BDE to find posts
     *   query_var: true          — Required for query parameter parsing
     *   show_in_rest: true       — Required for ACF, BDE field access, and Gutenberg
     *   has_archive: false       — No /reviews/ archive page
     *   rewrite: false           — No single post URLs
     *
     * Note: public=true would normally include posts in sitemaps, but we exclude via
     *       wp_sitemaps_post_types filter (see exclude_reviews_from_sitemap method).
     *
     * @since 1.1.0
     * @return void
     */
    public function register_reviews_cpt() {
        $opts = $this->settings->get_module_setting('cpt', null, []);
        $opts = wp_parse_args(is_array($opts) ? $opts : [], $this->get_default_options());
        if (empty($opts['enable_reviews'])) {
            return;
        }
        $labels = [
            'name'               => _x('Reviews', 'post type general name', 'site-essentials'),
            'singular_name'      => _x('Review', 'post type singular name', 'site-essentials'),
            'menu_name'          => _x('Reviews', 'admin menu', 'site-essentials'),
            'name_admin_bar'     => _x('Review', 'add new on admin bar', 'site-essentials'),
            'add_new'            => _x('Add New', 'review', 'site-essentials'),
            'add_new_item'       => __('Add New Review', 'site-essentials'),
            'new_item'           => __('New Review', 'site-essentials'),
            'edit_item'          => __('Edit Review', 'site-essentials'),
            'view_item'          => __('View Review', 'site-essentials'),
            'all_items'          => __('All Reviews', 'site-essentials'),
            'search_items'       => __('Search Reviews', 'site-essentials'),
            'not_found'          => __('No reviews found.', 'site-essentials'),
            'not_found_in_trash' => __('No reviews found in Trash.', 'site-essentials'),
        ];

        $args = [
            'labels'              => $labels,
            'public'              => true,          // REQUIRED: Makes post type queryable on front-end (for BDE loops)
            'publicly_queryable'  => true,          // REQUIRED: Allows WP_Query and BDE loops to work
            'query_var'           => true,          // REQUIRED: Enables query_posts() and WP_Query
            'show_in_rest'        => true,          // REQUIRED: For ACF, BDE field access, and Gutenberg
            'has_archive'         => false,         // Disable archive page (/reviews/)
            'rewrite'             => false,         // Disable single post URLs
            'show_in_nav_menus'   => false,         // Hide from nav menu UI
            'exclude_from_search' => true,          // Exclude from front-end search
            'show_ui'             => true,          // Show in admin
            'show_in_menu'        => true,          // Show in admin menu
            'hierarchical'        => false,
            'menu_position'       => 21,
            'menu_icon'           => 'dashicons-star-filled',
            'supports'            => ['title', 'editor'],
            'capability_type'     => 'post',
        ];

        register_post_type(self::POST_TYPE_REVIEWS, $args);
    }

    /**
     * Exclude Reviews CPT from XML sitemaps
     *
     * Reviews are set to public=true for front-end queryability (BDE loops),
     * but we don't want them in sitemaps since they have no URLs.
     *
     * @since 1.1.0
     * @param array $post_types Post types included in sitemap
     * @return array
     */
    public function exclude_reviews_from_sitemap($post_types) {
        unset($post_types[self::POST_TYPE_REVIEWS]);
        return $post_types;
    }

    /**
     * Register Review Platform taxonomy
     *
     * Non-hierarchical (tag-like). No archive, no public URLs.
     * Queryable via tax_query in WP_Query / BDE loops.
     *
     * @since 1.1.0
     * @return void
     */
    public function register_review_platform_taxonomy() {
        $labels = [
            'name'          => _x('Review Platforms', 'taxonomy general name', 'site-essentials'),
            'singular_name' => _x('Review Platform', 'taxonomy singular name', 'site-essentials'),
            'menu_name'     => _x('Platforms', 'admin menu', 'site-essentials'),
            'all_items'     => __('All Platforms', 'site-essentials'),
            'edit_item'     => __('Edit Platform', 'site-essentials'),
            'view_item'     => __('View Platform', 'site-essentials'),
            'add_new_item'  => __('Add New Platform', 'site-essentials'),
            'new_item_name' => __('New Platform Name', 'site-essentials'),
            'search_items'  => __('Search Platforms', 'site-essentials'),
            'not_found'     => __('No platforms found.', 'site-essentials'),
        ];

        register_taxonomy(self::TAXONOMY_REVIEW_PLATFORM, self::POST_TYPE_REVIEWS, [
            'labels'            => $labels,
            'hierarchical'      => false,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'has_archive'       => false,
            'rewrite'           => false,
            'show_ui'           => true,
            'public'            => false,
            'query_var'         => true,
        ]);
    }

    /**
     * Seed default Review Platform terms on init
     *
     * Inserts Google, Facebook, Trustpilot only if they do not already exist.
     * Safe to run on every request — term_exists() uses WordPress object cache.
     * Admin can add, rename, or delete terms after initial seeding.
     *
     * @since 1.1.0
     * @return void
     */
    public function seed_review_platform_terms() {
        if (!taxonomy_exists(self::TAXONOMY_REVIEW_PLATFORM)) {
            return;
        }

        $default_terms = [
            ['name' => 'Google',     'slug' => 'google'],
            ['name' => 'Facebook',   'slug' => 'facebook'],
            ['name' => 'Trustpilot', 'slug' => 'trustpilot'],
        ];

        foreach ($default_terms as $term) {
            if (!term_exists($term['slug'], self::TAXONOMY_REVIEW_PLATFORM)) {
                wp_insert_term($term['name'], self::TAXONOMY_REVIEW_PLATFORM, [
                    'slug' => $term['slug'],
                ]);
            }
        }
    }

    // =========================================================================
    // REVIEW PLATFORM TAXONOMY — LOGO FIELD
    // =========================================================================

    /**
     * Enqueue the WP media uploader on the platform taxonomy admin screens.
     *
     * @since 1.2.0
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_platform_taxonomy_scripts($hook) {
        if (!in_array($hook, ['edit-tags.php', 'term.php'], true)) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || $screen->taxonomy !== self::TAXONOMY_REVIEW_PLATFORM) {
            return;
        }
        wp_enqueue_media();
        wp_add_inline_script('jquery', $this->platform_taxonomy_media_js());
    }

    /**
     * Render logo field on the "Add New Platform" form.
     *
     * @since 1.2.0
     * @return void
     */
    public function platform_taxonomy_add_form_fields() {
        wp_nonce_field('bw_platform_logo_save', 'bw_platform_logo_nonce');
        ?>
        <div class="form-field bw-platform-logo-wrap">
            <label><?php esc_html_e('Platform Logo', 'site-essentials'); ?></label>
            <div class="bw-platform-logo-preview"></div>
            <input type="hidden" name="bw_platform_logo_id" value="">
            <button type="button" class="button bw-platform-logo-select"><?php esc_html_e('Select Logo', 'site-essentials'); ?></button>
            <button type="button" class="button bw-platform-logo-remove" style="display:none"><?php esc_html_e('Remove', 'site-essentials'); ?></button>
            <p class="description"><?php esc_html_e('Accepted formats: SVG (recommended), PNG, WebP.', 'site-essentials'); ?></p>
        </div>
        <?php
    }

    /**
     * Render logo field on the "Edit Platform" form.
     *
     * @since 1.2.0
     * @param \WP_Term $term Current term object.
     * @return void
     */
    public function platform_taxonomy_edit_form_fields($term) {
        $logo_id   = absint(get_term_meta($term->term_id, 'bw_platform_logo_id', true));
        $image_url = $logo_id ? wp_get_attachment_url($logo_id) : '';
        wp_nonce_field('bw_platform_logo_save', 'bw_platform_logo_nonce');
        ?>
        <tr class="form-field bw-platform-logo-wrap">
            <th scope="row"><label><?php esc_html_e('Platform Logo', 'site-essentials'); ?></label></th>
            <td>
                <div class="bw-platform-logo-preview">
                    <?php if ($image_url) : ?>
                        <img src="<?php echo esc_url($image_url); ?>" style="max-width:120px;max-height:60px;display:block;margin-bottom:8px;">
                    <?php endif; ?>
                </div>
                <input type="hidden" name="bw_platform_logo_id" value="<?php echo esc_attr($logo_id ?: ''); ?>">
                <button type="button" class="button bw-platform-logo-select"><?php esc_html_e('Select Logo', 'site-essentials'); ?></button>
                <button type="button" class="button bw-platform-logo-remove"<?php echo $logo_id ? '' : ' style="display:none"'; ?>><?php esc_html_e('Remove', 'site-essentials'); ?></button>
                <p class="description"><?php esc_html_e('Accepted formats: SVG (recommended), PNG, WebP.', 'site-essentials'); ?></p>
            </td>
        </tr>
        <?php
    }

    /**
     * Save the platform logo attachment ID to term meta.
     *
     * Fires on both created_{taxonomy} and edited_{taxonomy}.
     *
     * @since 1.2.0
     * @param int $term_id Term ID.
     * @return void
     */
    public function save_platform_taxonomy_logo($term_id) {
        if (
            !isset($_POST['bw_platform_logo_nonce']) ||
            !wp_verify_nonce(sanitize_key(wp_unslash($_POST['bw_platform_logo_nonce'])), 'bw_platform_logo_save')
        ) {
            return;
        }
        if (!current_user_can('manage_categories')) {
            return;
        }
        if (!empty($_POST['bw_platform_logo_id'])) {
            $logo_id       = absint($_POST['bw_platform_logo_id']);
            $allowed_mimes = ['image/svg+xml', 'image/png', 'image/webp'];
            if (in_array(get_post_mime_type($logo_id), $allowed_mimes, true)) {
                update_term_meta($term_id, 'bw_platform_logo_id', $logo_id);
            }
        } else {
            delete_term_meta($term_id, 'bw_platform_logo_id');
        }
    }

    /**
     * Inline JS for the platform logo media uploader.
     *
     * @since 1.2.0
     * @return string
     */
    private function platform_taxonomy_media_js() {
        return <<<'JS'
(function($){
    var frame;
    $(document).on('click', '.bw-platform-logo-select', function(e){
        e.preventDefault();
        var $wrap = $(this).closest('.bw-platform-logo-wrap');
        if (frame) { frame.open(); return; }
        frame = wp.media({
            title: 'Select Platform Logo',
            button: { text: 'Use this logo' },
            multiple: false,
            library: { type: ['image/svg+xml', 'image/png', 'image/webp'] }
        });
        frame.on('select', function(){
            var att = frame.state().get('selection').first().toJSON();
            var src = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
            $wrap.find('[name="bw_platform_logo_id"]').val(att.id);
            $wrap.find('.bw-platform-logo-preview').html('<img src="' + src + '" style="max-width:120px;max-height:60px;display:block;margin-bottom:8px;">');
            $wrap.find('.bw-platform-logo-remove').show();
        });
        frame.open();
    });
    $(document).on('click', '.bw-platform-logo-remove', function(e){
        e.preventDefault();
        var $wrap = $(this).closest('.bw-platform-logo-wrap');
        $wrap.find('[name="bw_platform_logo_id"]').val('');
        $wrap.find('.bw-platform-logo-preview').html('');
        $(this).hide();
    });
})(jQuery);
JS;
    }

    // =========================================================================
    // SVG UPLOAD SUPPORT
    // =========================================================================

    /**
     * Whitelist SVG MIME type for users who can upload files.
     *
     * @since 1.2.0
     * @param array $mimes Allowed MIME types keyed by extension.
     * @return array
     */
    public function allow_platform_svg_upload($mimes) {
        if (self::current_user_can_upload_svg()) {
            $mimes['svg'] = 'image/svg+xml';
            // NOTE: svgz (gzipped SVG) is deliberately NOT allowed. sanitize_svg_upload()
            // operates on the raw bytes, so a gzipped payload passes through it
            // byte-for-byte unmodified — the sanitiser cannot see inside the archive.
        }
        return $mimes;
    }

    /**
     * Whether the current user may upload SVG.
     *
     * SVG is an executable document format, not an image: a crafted file can carry
     * script that runs in the admin's browser when the file is previewed in the
     * Media Library. Uploading one is therefore closer to installing a plugin than
     * to uploading a PNG, so it is gated on manage_options rather than upload_files.
     *
     * Previously any user with upload_files (Author and above) could upload SVG,
     * which made stored XSS in the admin reachable from a low-privileged account.
     *
     * @since 1.2.1
     * @return bool
     */
    private static function current_user_can_upload_svg() {
        /**
         * Filter who may upload SVG files.
         *
         * @param bool $allowed Defaults to current_user_can('manage_options').
         */
        return (bool) apply_filters(
            'site_essentials_allow_svg_upload',
            current_user_can('manage_options')
        );
    }

    /**
     * Ensure WordPress recognises SVG files when checking filetype vs. extension.
     *
     * WP 4.7.1+ verifies content matches extension; SVG (XML) can fail that check.
     *
     * @since 1.2.0
     * @param array       $data      Values for the extension, MIME type, and proper file name.
     * @param string      $file      Full path to the file.
     * @param string      $filename  The name of the file.
     * @param array       $mimes     Array of allowed MIME types.
     * @param string|bool $real_mime The actual MIME type or false if unknown (WP 5.1+).
     * @return array
     */
    public function fix_svg_filetype_check($data, $file, $filename, $mimes, $real_mime = false) {
        if (!empty($data['ext']) && !empty($data['type'])) {
            return $data;
        }
        // This filter relaxes the content-vs-extension verification WP added in
        // 4.7.1 to block polyglot uploads, so it must be gated by the same
        // capability as the MIME allowlist itself.
        if (!self::current_user_can_upload_svg()) {
            return $data;
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'svg') {
            $data['ext']             = $ext;
            $data['type']            = 'image/svg+xml';
            $data['proper_filename'] = $filename;
        }
        return $data;
    }

    /**
     * Sanitize SVG files on upload.
     *
     * Defence in depth only. The load-bearing control is the manage_options gate
     * in current_user_can_upload_svg() — pattern-based SVG sanitising cannot be
     * made complete, because SVG is XML and there are many ways to smuggle script
     * past a matcher that a browser's parser will still honour.
     *
     * The previous implementation matched three patterns and was bypassed by, at
     * minimum: self-closing <script xlink:href="data:..."/> (no closing tag to
     * match), nested <scr<script></script>ipt> (reforms after one pass), unquoted
     * attribute values (onload=alert(1)), entity-encoded schemes
     * (&#106;avascript:), SMIL <animate attributeName="href"> and
     * <set attributeName="onbegin">, and <foreignObject> carrying arbitrary HTML.
     *
     * @since 1.2.0
     * @since 1.2.1 Rejects files carrying active content rather than trying to clean them.
     * @param array $file Upload data array from $_FILES.
     * @return array
     */
    public function sanitize_svg_upload($file) {
        if (($file['type'] ?? '') !== 'image/svg+xml') {
            return $file;
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            $file['error'] = __('Could not read the uploaded SVG file.', 'site-essentials');
            return $file;
        }

        // A gzip magic number here means the sanitiser would be matching against
        // compressed bytes and silently passing everything through.
        if (strncmp($content, "\x1f\x8b", 2) === 0) {
            $file['error'] = __('Compressed SVG (.svgz) is not accepted. Upload an uncompressed .svg file.', 'site-essentials');
            return $file;
        }

        // Decode numeric and named entities before inspection so that
        // &#106;avascript: and &#x6a;avascript: are seen the way a parser sees them.
        $probe = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $probe = preg_replace('/\s+/', ' ', $probe);

        // Reject rather than clean. A file tripping any of these is not a logo.
        $forbidden = [
            '/<\s*script/i'                            => 'a <script> element',
            '/<\s*foreignObject/i'                     => 'a <foreignObject> element',
            '/<\s*(?:animate|set|handler)\b/i'         => 'SMIL animation elements',
            '/<\s*(?:iframe|embed|object|audio|video)/i' => 'an embedded media or frame element',
            '/\bon[a-z]+\s*=/i'                        => 'an inline event handler',
            '/(?:javascript|vbscript)\s*:/i'           => 'a script URI',
            '/<\s*use[^>]+href\s*=\s*["\']?\s*(?:data|https?):/i' => 'an external or data: <use> reference',
            '/<!ENTITY/i'                              => 'an XML entity declaration',
        ];
        foreach ($forbidden as $pattern => $description) {
            if (preg_match($pattern, $probe)) {
                $file['error'] = sprintf(
                    /* translators: %s: description of the disallowed SVG feature. */
                    __('This SVG was rejected because it contains %s. Export a plain SVG without scripting or animation.', 'site-essentials'),
                    $description
                );
                return $file;
            }
        }

        // Strip anything that is inert but has no place in a logo, then confirm
        // the file still parses as XML.
        $clean = preg_replace('/<\?xml-stylesheet[^>]*\?>/i', '', $content);
        $clean = preg_replace('/<!DOCTYPE[^>]*>/is', '', $clean);

        $previous = libxml_use_internal_errors(true);
        $doc      = new \DOMDocument();
        $parsed   = $doc->loadXML($clean, LIBXML_NONET | LIBXML_NOENT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$parsed || !$doc->documentElement || strtolower($doc->documentElement->nodeName) !== 'svg') {
            $file['error'] = __('This file is not valid SVG.', 'site-essentials');
            return $file;
        }

        // An unchecked write here would leave the original, unsanitised bytes in
        // place while the upload continued as if it had been cleaned.
        if (file_put_contents($file['tmp_name'], $clean) === false) {
            $file['error'] = __('Could not write the sanitised SVG file.', 'site-essentials');
            return $file;
        }

        return $file;
    }

    // =========================================================================
    // REVIEWS META BOXES
    // =========================================================================

    /**
     * Register Reviews meta box
     *
     * @since 1.1.0
     * @return void
     */
    public function register_reviews_meta_boxes() {
        add_meta_box(
            'bw_reviews_details',
            __('Review Details', 'site-essentials'),
            [$this, 'render_reviews_meta_box'],
            self::POST_TYPE_REVIEWS,
            'normal',
            'high'
        );
    }

    /**
     * Render Reviews meta box HTML
     *
     * @since 1.1.0
     * @param WP_Post $post Current post object
     * @return void
     */
    public function render_reviews_meta_box($post) {
        wp_nonce_field('bw_reviews_meta_save', 'bw_reviews_meta_nonce');

        $rating          = get_post_meta($post->ID, 'bw_rating', true);
        $date            = get_post_meta($post->ID, 'bw_date', true);
        $date_precision  = get_post_meta($post->ID, 'bw_date_precision', true) ?: 'full';
        $verify_url      = get_post_meta($post->ID, 'bw_verify_url', true);
        $schema_id       = get_post_meta($post->ID, 'bw_schema_id', true);
        $success_outcome = get_post_meta($post->ID, 'bw_success_outcome', true);
        $customer_detail = get_post_meta($post->ID, 'bw_customer_detail', true);
        $is_featured     = get_post_meta($post->ID, 'bw_is_featured', true);
        $review_excerpt  = get_post_meta($post->ID, 'bw_review_excerpt', true);
        $related_project = (int) get_post_meta($post->ID, 'bw_related_project', true);

        // Projects list for native selector — only needed when ACF is not active
        $projects = function_exists('get_field') ? [] : get_posts([
            'post_type'      => self::POST_TYPE_PROJECTS,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);

        include __DIR__ . '/views/reviews-meta-box.php';
    }

    /**
     * Save Reviews meta fields from $_POST
     *
     * Fires at priority 10 on save_post_bw_reviews.
     * auto_generate_schema_id() fires at priority 20 (after this).
     *
     * @since 1.1.0
     * @param int     $post_id Post ID
     * @param WP_Post $post    Post object
     * @return void
     */
    public function save_reviews_meta($post_id, $post) {
        if (!isset($_POST['bw_reviews_meta_nonce']) ||
            !wp_verify_nonce($_POST['bw_reviews_meta_nonce'], 'bw_reviews_meta_save')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // bw_rating: integer 1–5
        if (isset($_POST['bw_rating'])) {
            $rating = intval($_POST['bw_rating']);
            update_post_meta($post_id, 'bw_rating', max(1, min(5, $rating)));
        }

        // bw_date: YYYY-MM-DD or empty
        if (isset($_POST['bw_date'])) {
            $date = sanitize_text_field($_POST['bw_date']);
            update_post_meta($post_id, 'bw_date', preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '');
        }

        // bw_date_precision: year | month-year | full
        if (isset($_POST['bw_date_precision'])) {
            $precision = sanitize_text_field($_POST['bw_date_precision']);
            update_post_meta($post_id, 'bw_date_precision', in_array($precision, ['year', 'month-year', 'full'], true) ? $precision : 'full');
        }

        // bw_verify_url: URL
        if (isset($_POST['bw_verify_url'])) {
            update_post_meta($post_id, 'bw_verify_url', esc_url_raw($_POST['bw_verify_url']));
        }

        // bw_schema_id: text (manual override — auto-gen handled at priority 20)
        if (isset($_POST['bw_schema_id'])) {
            update_post_meta($post_id, 'bw_schema_id', sanitize_text_field($_POST['bw_schema_id']));
        }

        // bw_success_outcome: short text ~100 chars
        if (isset($_POST['bw_success_outcome'])) {
            update_post_meta($post_id, 'bw_success_outcome', sanitize_text_field($_POST['bw_success_outcome']));
        }

        // bw_customer_detail: short text ~100 chars
        if (isset($_POST['bw_customer_detail'])) {
            update_post_meta($post_id, 'bw_customer_detail', sanitize_text_field($_POST['bw_customer_detail']));
        }

        // bw_is_featured: checkbox → 1/0
        update_post_meta($post_id, 'bw_is_featured', !empty($_POST['bw_is_featured']) ? '1' : '0');

        // bw_review_excerpt: textarea ~150 chars
        if (isset($_POST['bw_review_excerpt'])) {
            update_post_meta($post_id, 'bw_review_excerpt', sanitize_textarea_field($_POST['bw_review_excerpt']));
        }

        // bw_related_project: native meta box (only active when ACF is not installed)
        if (isset($_POST['bw_related_project'])) {
            $project_id = absint($_POST['bw_related_project']);
            if ($project_id > 0) {
                update_post_meta($post_id, 'bw_related_project', $project_id);
            } else {
                delete_post_meta($post_id, 'bw_related_project');
            }
        }
    }

    /**
     * Auto-generate bw_schema_id on first save
     *
     * Fires at priority 20 (after save_reviews_meta at priority 10).
     * Only generates when bw_schema_id is empty after the priority-10 save.
     *
     * Format: {firstname}-{lastname}-{platform-slug}
     * On duplicate: appends -{post_id}
     *
     * This ID must be stable — once set it is not overwritten. Changing it
     * manually breaks any schema/AI references that use it.
     *
     * @since 1.1.0
     * @param int     $post_id Post ID
     * @param WP_Post $post    Post object
     * @return void
     */
    public function auto_generate_schema_id($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $existing = get_post_meta($post_id, 'bw_schema_id', true);
        if (!empty($existing)) {
            return; // Already set — do not overwrite
        }

        $schema_id = $this->generate_schema_id($post_id, $post);
        if (!empty($schema_id)) {
            update_post_meta($post_id, 'bw_schema_id', $schema_id);
        }
    }

    /**
     * Generate a schema ID from customer name + platform slug
     *
     * @since 1.1.0
     * @param int          $post_id  Post ID
     * @param WP_Post|null $post     Post object (optional; loaded if null)
     * @return string Generated ID, or empty string on failure
     */
    private function generate_schema_id($post_id, $post = null) {
        if (!$post) {
            $post = get_post($post_id);
        }
        if (!$post || empty($post->post_title)) {
            return '';
        }

        // Parse first / last name from post title (customer name)
        $parts = preg_split('/\s+/', trim($post->post_title), 2);
        $first = sanitize_title($parts[0] ?? '');
        $last  = sanitize_title($parts[1] ?? '');

        // Get platform slug from taxonomy (first assigned term)
        $platform_slug = '';
        $terms = get_the_terms($post_id, self::TAXONOMY_REVIEW_PLATFORM);
        if ($terms && !is_wp_error($terms) && !empty($terms)) {
            $platform_slug = $terms[0]->slug;
        }

        // Build base: firstname-lastname or firstname
        if ($first && $last) {
            $base = $first . '-' . $last;
        } elseif ($first) {
            $base = $first;
        } else {
            return '';
        }

        $schema_id = $base . ($platform_slug ? '-' . $platform_slug : '');

        // Append post_id on duplicate
        $duplicates = get_posts([
            'post_type'   => self::POST_TYPE_REVIEWS,
            'post_status' => 'any',
            'meta_key'    => 'bw_schema_id',
            'meta_value'  => $schema_id,
            'exclude'     => [$post_id],
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);

        if (!empty($duplicates)) {
            $schema_id .= '-' . $post_id;
        }

        return $schema_id;
    }

    // =========================================================================
    // REVIEWS ADMIN UI
    // =========================================================================

    /**
     * Override title field placeholder for Reviews CPT
     *
     * Changes "Add title" to "Customer Name".
     *
     * @since 1.1.0
     * @param string  $placeholder Default placeholder
     * @param WP_Post $post        Current post
     * @return string
     */
    public function reviews_title_placeholder($placeholder, $post) {
        if ($post->post_type === self::POST_TYPE_REVIEWS) {
            return __('Customer Name', 'site-essentials');
        }
        return $placeholder;
    }

    /**
     * Add custom admin list columns for Reviews
     *
     * @since 1.1.0
     * @param array $columns Existing columns
     * @return array
     */
    public function reviews_admin_columns($columns) {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'title') {
                $new['bw_review_platform'] = __('Platform', 'site-essentials');
                $new['bw_rating']          = __('Rating', 'site-essentials');
                $new['bw_date']            = __('Review Date', 'site-essentials');
                $new['bw_is_featured']     = __('Featured', 'site-essentials');
            }
        }
        return $new;
    }

    /**
     * Populate custom admin column content for Reviews
     *
     * @since 1.1.0
     * @param string $column  Column key
     * @param int    $post_id Post ID
     * @return void
     */
    public function reviews_admin_column_content($column, $post_id) {
        switch ($column) {
            case 'bw_review_platform':
                $terms = get_the_terms($post_id, self::TAXONOMY_REVIEW_PLATFORM);
                echo ($terms && !is_wp_error($terms)) ? esc_html($terms[0]->name) : '&mdash;';
                break;

            case 'bw_rating':
                $rating = get_post_meta($post_id, 'bw_rating', true);
                echo $rating !== '' ? esc_html($rating) . '/5' : '&mdash;';
                break;

            case 'bw_date':
                $date = get_post_meta($post_id, 'bw_date', true);
                echo $date ? esc_html($date) : '&mdash;';
                break;

            case 'bw_is_featured':
                $featured = get_post_meta($post_id, 'bw_is_featured', true);
                echo $featured === '1' ? '&#9733;' : '&mdash;';
                break;
        }
    }

    // =========================================================================
    // REVIEWS ACF RELATIONSHIP FIELD
    // =========================================================================

    /**
     * Register ACF Relationship Field: Related Project
     *
     * Requires ACF (function acf_add_local_field_group must exist).
     * If ACF Extended (ACFE) is active: registers bidirectional relationship so
     * that linking a Review to a Project also links the Project back to the Review.
     * If ACF Extended is NOT active: registers standard post_object field only and
     * shows an admin notice on Review and Project edit screens.
     *
     * Do not build a custom bidirectional hook — use ACF Extended or standard field.
     *
     * @since 1.1.0
     * @return void
     */
    public function register_reviews_acf_fields() {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        $is_acfe_active = defined('ACFE_VERSION') || function_exists('acfe_add_local_field_group');

        // Reviews side: Related Project field
        $related_project_field = [
            'key'          => 'field_bw_related_project',
            'label'        => __('Related Project', 'site-essentials'),
            'name'         => 'bw_related_project',
            'type'         => 'post_object',
            'post_type'    => [self::POST_TYPE_PROJECTS],
            'return_format' => 'id',
            'multiple'     => 0,
            'allow_null'   => 1,
            'ui'           => 1,
            'instructions' => __('Link this review to a project/success story.', 'site-essentials'),
        ];

        if ($is_acfe_active) {
            $related_project_field['bidirectional']        = 1;
            $related_project_field['bidirectional_target'] = ['field_bw_reviews_related'];
        }

        acf_add_local_field_group([
            'key'      => 'group_bw_reviews_relationship',
            'title'    => __('Related Project', 'site-essentials'),
            'fields'   => [$related_project_field],
            'location' => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => self::POST_TYPE_REVIEWS,
                    ],
                ],
            ],
            'position' => 'side',
        ]);

        if ($is_acfe_active) {
            // Projects side: Related Reviews field (reverse of bidirectional)
            acf_add_local_field_group([
                'key'   => 'group_projects_reviews',
                'title' => __('Related Reviews', 'site-essentials'),
                'fields' => [
                    [
                        'key'                  => 'field_bw_reviews_related',
                        'label'                => __('Related Reviews', 'site-essentials'),
                        'name'                 => 'bw_reviews_related',
                        'type'                 => 'post_object',
                        'post_type'            => [self::POST_TYPE_REVIEWS],
                        'return_format'        => 'id',
                        'multiple'             => 1,
                        'allow_null'           => 1,
                        'ui'                   => 1,
                        'instructions'         => __('Reviews linked to this project (synced automatically).', 'site-essentials'),
                        'bidirectional'        => 1,
                        'bidirectional_target' => ['field_bw_related_project'],
                    ],
                ],
                'location' => [
                    [
                        [
                            'param'    => 'post_type',
                            'operator' => '==',
                            'value'    => self::POST_TYPE_PROJECTS,
                        ],
                    ],
                ],
                'position' => 'side',
            ]);
        } else {
            // ACF Extended not active — show a notice on Review/Project edit screens
            $pt_reviews  = self::POST_TYPE_REVIEWS;
            $pt_projects = self::POST_TYPE_PROJECTS;
            add_action('admin_notices', function() use ($pt_reviews, $pt_projects) {
                $screen = function_exists('get_current_screen') ? get_current_screen() : null;
                if (!$screen || $screen->base !== 'post') {
                    return;
                }
                if (!in_array($screen->post_type, [$pt_reviews, $pt_projects], true)) {
                    return;
                }
                echo '<div class="notice notice-warning is-dismissible"><p>';
                printf(
                    /* translators: 1: opening link tag, 2: closing link tag */
                    esc_html__('ACF Extended is not active. The "Related Project" field is available on Reviews but bidirectional sync to Projects is disabled. %1$sLearn more about ACF Extended.%2$s', 'site-essentials'),
                    '<a href="https://www.acf-extended.com/" target="_blank" rel="noopener">',
                    '</a>'
                );
                echo '</p></div>';
            });
        }
    }

    // =========================================================================
    // REVIEWS SHORTCODES
    // =========================================================================

    /**
     * Register all Reviews shortcodes
     *
     * Usage: [bw_review_rating id="42"]
     * If id is omitted, current post ID is used (for use inside WP_Query loops).
     *
     * @since 1.1.0
     * @return void
     */
    public function register_reviews_shortcodes() {
        add_shortcode('bw_review_rating',          [$this, 'shortcode_review_rating']);
        add_shortcode('bw_review_date',            [$this, 'shortcode_review_date']);
        add_shortcode('bw_review_verify_url',      [$this, 'shortcode_review_verify_url']);
        add_shortcode('bw_review_schema_id',       [$this, 'shortcode_review_schema_id']);
        add_shortcode('bw_review_outcome',         [$this, 'shortcode_review_outcome']);
        add_shortcode('bw_review_customer_detail', [$this, 'shortcode_review_customer_detail']);
        add_shortcode('bw_review_excerpt',         [$this, 'shortcode_review_excerpt']);
        add_shortcode('bw_review_featured',        [$this, 'shortcode_review_featured']);

        // Review card — full rendered card, used by SCOS Review Card BDE element + direct shortcode
        require_once __DIR__ . '/Review_Card_Renderer.php';
        $renderer = new Review_Card_Renderer();
        add_shortcode('bw_review_card', [$renderer, 'shortcode']);

        // Statistics shortcodes
        add_shortcode('bw_review_count',           [$this, 'shortcode_review_count']);
        add_shortcode('bw_review_average',         [$this, 'shortcode_review_average']);

        // Aggregate review widget — combined stats + logo + stars, used by SCOS Aggregate Review BDE element
        require_once __DIR__ . '/Aggregate_Review_Renderer.php';
        $aggregate_renderer = new Aggregate_Review_Renderer();
        add_shortcode('bw_aggregate_review', [$aggregate_renderer, 'shortcode']);

        // JSON-LD token resolver — %%_scos_review_cards_json%%
        require_once __DIR__ . '/Review_Schema_Graph.php';
        Review_Schema_Graph::register();
    }

    /**
     * Resolve post ID from shortcode attributes
     *
     * @since 1.1.0
     * @param array $atts Shortcode attributes
     * @return int
     */
    private function get_shortcode_post_id($atts) {
        return !empty($atts['id']) ? absint($atts['id']) : (get_the_ID() ?: 0);
    }

    /** Returns star rating as integer string. @since 1.1.0 */
    public function shortcode_review_rating($atts) {
        $atts    = shortcode_atts(['id' => ''], $atts);
        $post_id = $this->get_shortcode_post_id($atts);
        $val     = get_post_meta($post_id, 'bw_rating', true);
        return $val !== '' ? esc_html($val) : '';
    }

    /** Returns formatted date respecting bw_date_precision. @since 1.1.0 */
    public function shortcode_review_date($atts) {
        $atts      = shortcode_atts(['id' => ''], $atts);
        $post_id   = $this->get_shortcode_post_id($atts);
        $date      = get_post_meta($post_id, 'bw_date', true);
        $precision = get_post_meta($post_id, 'bw_date_precision', true) ?: 'full';

        if (empty($date)) {
            return '';
        }
        $ts = strtotime($date);
        if (!$ts) {
            return esc_html($date);
        }
        switch ($precision) {
            case 'year':       return date_i18n('Y', $ts);
            case 'month-year': return date_i18n('F Y', $ts);
            default:           return date_i18n(get_option('date_format'), $ts);
        }
    }

    /** Returns raw verify URL. @since 1.1.0 */
    public function shortcode_review_verify_url($atts) {
        $atts    = shortcode_atts(['id' => ''], $atts);
        $post_id = $this->get_shortcode_post_id($atts);
        return esc_url(get_post_meta($post_id, 'bw_verify_url', true));
    }

    /** Returns schema ID string. @since 1.1.0 */
    public function shortcode_review_schema_id($atts) {
        $atts    = shortcode_atts(['id' => ''], $atts);
        $post_id = $this->get_shortcode_post_id($atts);
        return esc_html(get_post_meta($post_id, 'bw_schema_id', true));
    }

    /** Returns success outcome text. @since 1.1.0 */
    public function shortcode_review_outcome($atts) {
        $atts    = shortcode_atts(['id' => ''], $atts);
        $post_id = $this->get_shortcode_post_id($atts);
        return esc_html(get_post_meta($post_id, 'bw_success_outcome', true));
    }

    /** Returns customer second-line detail. @since 1.1.0 */
    public function shortcode_review_customer_detail($atts) {
        $atts    = shortcode_atts(['id' => ''], $atts);
        $post_id = $this->get_shortcode_post_id($atts);
        return esc_html(get_post_meta($post_id, 'bw_customer_detail', true));
    }

    /**
     * Returns bw_review_excerpt, or auto-truncates post_content (~150 chars) as fallback.
     *
     * @since 1.1.0
     */
    public function shortcode_review_excerpt($atts) {
        $atts    = shortcode_atts(['id' => ''], $atts);
        $post_id = $this->get_shortcode_post_id($atts);
        $excerpt = get_post_meta($post_id, 'bw_review_excerpt', true);

        if (!empty($excerpt)) {
            return esc_html($excerpt);
        }

        // Fallback: auto-truncate post_content to ~150 chars (25 words)
        $post = get_post($post_id);
        if ($post && !empty($post->post_content)) {
            return esc_html(wp_trim_words(wp_strip_all_tags($post->post_content), 25, '&hellip;'));
        }

        return '';
    }

    /** Returns 1 or 0 based on bw_is_featured. @since 1.1.0 */
    public function shortcode_review_featured($atts) {
        $atts    = shortcode_atts(['id' => ''], $atts);
        $post_id = $this->get_shortcode_post_id($atts);
        return get_post_meta($post_id, 'bw_is_featured', true) === '1' ? '1' : '0';
    }

    /**
     * Returns total count of published reviews
     *
     * Optional attributes:
     *   platform: Filter by platform slug (e.g., platform="google")
     *   featured: Filter by featured status (featured="1")
     *
     * Usage: [bw_review_count] or [bw_review_count platform="google"]
     *
     * @since 1.1.0
     */
    public function shortcode_review_count($atts) {
        $atts = shortcode_atts([
            'platform' => '',
            'featured' => '',
        ], $atts);

        $args = [
            'post_type'      => self::POST_TYPE_REVIEWS,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ];

        // Filter by platform
        if (!empty($atts['platform'])) {
            $args['tax_query'] = [
                [
                    'taxonomy' => self::TAXONOMY_REVIEW_PLATFORM,
                    'field'    => 'slug',
                    'terms'    => sanitize_title($atts['platform']),
                ],
            ];
        }

        // Filter by featured
        if (!empty($atts['featured'])) {
            $args['meta_query'] = [
                [
                    'key'   => 'bw_is_featured',
                    'value' => $atts['featured'] === '1' ? '1' : '0',
                ],
            ];
        }

        $query = new \WP_Query($args);
        $count = $query->post_count;

        return '<div class="bw-review-count">' . absint($count) . '</div>';
    }

    /**
     * Returns average star rating across all reviews
     *
     * Optional attributes:
     *   platform: Filter by platform slug (e.g., platform="google")
     *   featured: Filter by featured status (featured="1")
     *   decimals: Number of decimal places (default: 1)
     *
     * Usage: [bw_review_average] or [bw_review_average decimals="2"]
     *
     * @since 1.1.0
     */
    public function shortcode_review_average($atts) {
        $atts = shortcode_atts([
            'platform' => '',
            'featured' => '',
            'decimals' => '1',
        ], $atts);

        $args = [
            'post_type'      => self::POST_TYPE_REVIEWS,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ];

        // Filter by platform
        if (!empty($atts['platform'])) {
            $args['tax_query'] = [
                [
                    'taxonomy' => self::TAXONOMY_REVIEW_PLATFORM,
                    'field'    => 'slug',
                    'terms'    => sanitize_title($atts['platform']),
                ],
            ];
        }

        // Filter by featured
        if (!empty($atts['featured'])) {
            $args['meta_query'] = [
                [
                    'key'   => 'bw_is_featured',
                    'value' => $atts['featured'] === '1' ? '1' : '0',
                ],
            ];
        }

        $query = new \WP_Query($args);
        
        if (empty($query->posts)) {
            return '<div class="bw-review-average">0</div>';
        }

        $total = 0;
        $count = 0;

        foreach ($query->posts as $post_id) {
            $rating = get_post_meta($post_id, 'bw_rating', true);
            if ($rating !== '' && is_numeric($rating)) {
                $total += floatval($rating);
                $count++;
            }
        }

        if ($count === 0) {
            return '<div class="bw-review-average">0</div>';
        }

        $average = $total / $count;
        $decimals = max(0, min(2, intval($atts['decimals'])));

        return '<div class="bw-review-average">' . number_format($average, $decimals) . '</div>';
    }

    // =========================================================================
    // REVIEWS CSV IMPORT
    // =========================================================================

    /**
     * Handle Reviews CSV import form submission
     *
     * Expected CSV columns (case-sensitive headers):
     *   customer_name, review_text, rating, platform, date, date_precision,
     *   verify_url, success_outcome, customer_detail, is_featured, review_excerpt
     *
     * Rules:
     * - Rows where customer_name is empty are skipped
     * - platform: case-insensitive name match; creates new term if no match
     * - date_precision: year|month-year|full; defaults to full if empty/invalid
     * - is_featured: 1/yes/true (case-insensitive) → 1; everything else → 0
     * - bw_schema_id is auto-generated using same logic as manual save
     *
     * @since 1.1.0
     * @return void
     */
    public function handle_reviews_csv_import() {
        if (!isset($_POST['bw_reviews_csv_nonce']) ||
            !wp_verify_nonce($_POST['bw_reviews_csv_nonce'], 'bw_reviews_csv_import')) {
            wp_die(__('Security check failed.', 'site-essentials'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.', 'site-essentials'));
        }

        $redirect_base = add_query_arg(
            [ 'page' => 'site-essentials-cpt', 'tab' => 'reviews' ],
            admin_url('admin.php')
        );

        if (empty($_FILES['bw_reviews_csv']['tmp_name'])) {
            wp_safe_redirect(add_query_arg([
                'reviews_import' => 'error',
                'error_msg'      => rawurlencode(__('No file uploaded.', 'site-essentials')),
            ], $redirect_base));
            exit;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen($_FILES['bw_reviews_csv']['tmp_name'], 'r');
        if ($handle === false) {
            wp_safe_redirect(add_query_arg([
                'reviews_import' => 'error',
                'error_msg'      => rawurlencode(__('Could not read uploaded file.', 'site-essentials')),
            ], $redirect_base));
            exit;
        }

        $header = fgetcsv($handle);
        if (!$header) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
            wp_safe_redirect(add_query_arg([
                'reviews_import' => 'error',
                'error_msg'      => rawurlencode(__('CSV file is empty or could not be parsed.', 'site-essentials')),
            ], $redirect_base));
            exit;
        }

        $header    = array_map('trim', $header);
        $expected  = [
            'customer_name', 'review_text', 'rating', 'platform', 'date',
            'date_precision', 'verify_url', 'success_outcome', 'customer_detail',
            'is_featured', 'review_excerpt',
        ];
        $col_map = [];
        foreach ($expected as $col) {
            $idx = array_search($col, $header, true);
            $col_map[$col] = ($idx !== false) ? $idx : -1;
        }

        $imported    = 0;
        $skipped     = 0;
        $skip_detail = [];

        while (($row = fgetcsv($handle)) !== false) {
            $get = static function($col) use ($row, $col_map) {
                $idx = $col_map[$col];
                return ($idx >= 0 && isset($row[$idx])) ? trim($row[$idx]) : '';
            };

            $customer_name = $get('customer_name');
            if (empty($customer_name)) {
                $skipped++;
                $skip_detail[] = __('Row skipped: customer_name is empty', 'site-essentials');
                continue;
            }

            $post_id = wp_insert_post([
                'post_type'    => self::POST_TYPE_REVIEWS,
                'post_title'   => sanitize_text_field($customer_name),
                'post_content' => sanitize_textarea_field($get('review_text')),
                'post_status'  => 'publish',
            ]);

            if (is_wp_error($post_id)) {
                $skipped++;
                $skip_detail[] = sprintf(
                    /* translators: 1: customer name, 2: error message */
                    __('Row skipped for "%1$s": %2$s', 'site-essentials'),
                    $customer_name,
                    $post_id->get_error_message()
                );
                continue;
            }

            // Platform taxonomy
            $platform_name = $get('platform');
            if (!empty($platform_name)) {
                $term = get_term_by('name', $platform_name, self::TAXONOMY_REVIEW_PLATFORM);
                if (!$term) {
                    $all_terms = get_terms(['taxonomy' => self::TAXONOMY_REVIEW_PLATFORM, 'hide_empty' => false]);
                    if (!is_wp_error($all_terms)) {
                        foreach ($all_terms as $t) {
                            if (strcasecmp($t->name, $platform_name) === 0) {
                                $term = $t;
                                break;
                            }
                        }
                    }
                }
                if (!$term) {
                    $new_term = wp_insert_term(sanitize_text_field($platform_name), self::TAXONOMY_REVIEW_PLATFORM);
                    if (!is_wp_error($new_term)) {
                        $term = get_term($new_term['term_id'], self::TAXONOMY_REVIEW_PLATFORM);
                    }
                }
                if ($term && !is_wp_error($term)) {
                    wp_set_object_terms($post_id, [$term->term_id], self::TAXONOMY_REVIEW_PLATFORM);
                }
            }

            // Rating 1–5
            $rating = intval($get('rating'));
            if ($rating >= 1 && $rating <= 5) {
                update_post_meta($post_id, 'bw_rating', $rating);
            }

            // Date YYYY-MM-DD
            $date = $get('date');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                update_post_meta($post_id, 'bw_date', $date);
            }

            // Date precision
            $precision = $get('date_precision');
            update_post_meta($post_id, 'bw_date_precision', in_array($precision, ['year', 'month-year', 'full'], true) ? $precision : 'full');

            // Verify URL
            $verify_url = esc_url_raw($get('verify_url'));
            if (!empty($verify_url)) {
                update_post_meta($post_id, 'bw_verify_url', $verify_url);
            }

            // Success outcome
            $outcome = sanitize_text_field($get('success_outcome'));
            if (!empty($outcome)) {
                update_post_meta($post_id, 'bw_success_outcome', $outcome);
            }

            // Customer detail
            $detail = sanitize_text_field($get('customer_detail'));
            if (!empty($detail)) {
                update_post_meta($post_id, 'bw_customer_detail', $detail);
            }

            // Is featured: 1/yes/true → 1, else → 0
            update_post_meta($post_id, 'bw_is_featured', in_array(strtolower($get('is_featured')), ['1', 'yes', 'true'], true) ? '1' : '0');

            // Review excerpt
            $exc = sanitize_textarea_field($get('review_excerpt'));
            if (!empty($exc)) {
                update_post_meta($post_id, 'bw_review_excerpt', $exc);
            }

            // Auto-generate schema ID
            $schema_id = $this->generate_schema_id($post_id, get_post($post_id));
            if (!empty($schema_id)) {
                update_post_meta($post_id, 'bw_schema_id', $schema_id);
            }

            $imported++;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);

        wp_safe_redirect(add_query_arg([
            'reviews_import' => 'success',
            'imported'       => $imported,
            'skipped'        => $skipped,
        ], $redirect_base));
        exit;
    }

    // =========================================================================
    // SETTINGS
    // =========================================================================

    /**
     * Register settings (placeholder)
     *
     * @since 1.0.0
     * @return void
     */
    public function register_settings() {
        // Settings saved via Admin_UI save handler
    }

    /**
     * Render settings section (options page content)
     *
     * Dispatches into the tabbed cpt-page view. Active tab is read from
     * the $_GET['tab'] parameter; defaults to 'settings'.
     *
     * @since 1.0.0
     * @param string|null $active_tab Optional active tab override.
     * @return void
     */
    public function render_settings( $active_tab = null ) {
        $opts = $this->settings->get_module_setting('cpt', null, []);
        $opts = wp_parse_args(is_array($opts) ? $opts : [], $this->get_default_options());

        if ( null === $active_tab ) {
            $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
        }
        $active_tab = sanitize_key( (string) $active_tab );

        include __DIR__ . '/views/cpt-page.php';
    }
}
