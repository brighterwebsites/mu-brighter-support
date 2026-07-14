<?php
/**
 * SCOS Schema Output - Graphed JSON-LD
 *
 * v1.3 | 2026-06-29 — Guard inline Service block against admin template; no duplicate schema.
 * v1.4 | 2026-06-29 — Read scos_seo_tldr + scos_schema_custom first; bw_ keys as fallback only.
 * v1.5 | 2026-07-15 — Remove all bw_ fallback reads; migration confirmed complete on all sites.
 *
 * Outputs consolidated @graph schema for all page types.
 * Uses template_redirect to ensure WordPress conditionals work.
 *
 * Meta fields used:
 * - scos_schema_custom: Custom JSON-LD to merge into @graph
 * - scos_seo_tldr: TLDR/description text
 * - scos_seo_breadcrumb_title: Override breadcrumb label for current page
 * - scos_ca_purpose: Detect service/product pages (value: 'service-page', 'product-page')
 * - scos_ca_word_count, scos_ca_reading_time: Content metrics
 *
 * Kill Switch Controls:
 * - SCOS_DISABLE_SCHEMA: Set to true in wp-config.php to disable all schema output
 * - SCOS_SCHEMA_ALLOWED_SITES: Array of allowed domains (whitelist approach)
 *   Example: define('SCOS_SCHEMA_ALLOWED_SITES', ['brighterwebsites.com.au']);
 *
 * @package    BrighterCore
 * @subpackage Schema
 * @version    1.3 | 2026-06-29
 * @since      1.0.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Auto-build a LocalBusiness schema array from scos_biz_* Business Info fields.
 * Called when scos_site_schema_local_business is empty and business_name is set.
 *
 * @return array|null Schema array or null if insufficient data.
 */
if ( ! function_exists( 'scos_schema_build_local_business' ) ) {
function scos_schema_build_local_business() {
    if ( ! function_exists( 'brighter_get_option' ) ) { return null; }

    $name = brighter_get_option( 'business_name' );
    if ( ! $name ) { return null; }

    $org_type = brighter_get_option( 'organisation_type' ) ?: 'LocalBusiness';
    if ( $org_type === 'Local Business' ) $org_type = 'LocalBusiness';

    $schema = [
        '@context' => 'https://schema.org',
        '@type'    => $org_type,
        '@id'      => home_url( '/#' . strtolower( $org_type ) ),
        'name'     => $name,
        'url'      => home_url( '/' ),
    ];

    $desc  = brighter_get_option( 'service_description' );
    $phone = brighter_get_option( 'phone_number' );
    $email = brighter_get_option( 'email' );
    $price = brighter_get_option( 'price_tier' );
    $img   = brighter_get_option( 'business_image' );
    $logo  = brighter_get_option( 'business_logo' );
    $found = brighter_get_option( 'founding_date' );

    if ( $desc )  $schema['description']  = $desc;
    if ( $phone ) $schema['telephone']    = $phone;
    if ( $email ) $schema['email']        = $email;
    if ( $price ) $schema['priceRange']   = $price;
    if ( $img )   $schema['image']        = $img;
    if ( $logo )  $schema['logo']         = $logo;
    if ( $found ) $schema['foundingDate'] = $found;

    // Address
    $addr_parts = array_filter( [
        'streetAddress'   => brighter_get_option( 'address' ),
        'addressLocality' => brighter_get_option( 'city' ),
        'addressRegion'   => brighter_get_option( 'state' ),
        'postalCode'      => brighter_get_option( 'postcode' ),
        'addressCountry'  => brighter_get_option( 'country' ),
    ] );
    if ( $addr_parts ) {
        $schema['address'] = array_merge( [ '@type' => 'PostalAddress' ], $addr_parts );
    }

    // Geo
    $lat  = brighter_get_option( 'lat' );
    $long = brighter_get_option( 'long' );
    if ( $lat && $long ) {
        $schema['geo'] = [ '@type' => 'GeoCoordinates', 'latitude' => $lat, 'longitude' => $long ];
    }

    // sameAs
    $same_as_keys = [
        'social_link_facebook', 'social_link_twitter', 'social_link_instagram',
        'social_link_youtube', 'social_link_linkedin', 'social_link_pinterest',
        'knowledge_panel_share',
    ];
    $same_as = array_filter( array_map( 'brighter_get_option', $same_as_keys ) );
    if ( $same_as ) {
        $schema['sameAs'] = array_values( $same_as );
    }

    return $schema;
}
}

/**
 * Output schema on template_redirect (after WP knows what page we're on)
 */
add_action('template_redirect', 'bw_output_schema_graph', 1);

function bw_output_schema_graph() {
    
    // ============================================
    // KILL SWITCH SYSTEM  ///remove this. 
    // ============================================
    
    // Global kill switch: If SCOS_DISABLE_SCHEMA is true, stop all schema output
    if (defined('SCOS_DISABLE_SCHEMA') && SCOS_DISABLE_SCHEMA === true) {
        return;
    }
    
    // Whitelist approach: If SCOS_SCHEMA_ALLOWED_SITES is defined, check current domain
    if (defined('SCOS_SCHEMA_ALLOWED_SITES')) {
        $allowed_sites = SCOS_SCHEMA_ALLOWED_SITES;
        if (!is_array($allowed_sites)) {
            $allowed_sites = [];
        }
        
        // Get current domain (strip www. if present for comparison)
        $current_host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
        $current_host = preg_replace('/^www\./', '', $current_host);
        
        // Check if current domain is in whitelist
        $is_allowed = false;
        foreach ($allowed_sites as $allowed_domain) {
            $allowed_domain = strtolower($allowed_domain);
            $allowed_domain = preg_replace('/^www\./', '', $allowed_domain);
            
            if ($current_host === $allowed_domain) {
                $is_allowed = true;
                break;
            }
        }
        
        // If not in whitelist, stop schema output
        if (!$is_allowed) {
            return;
        }
    }
    
    // ============================================
    // STANDARD CHECKS
    // ============================================
    
    // Skip admin, feeds, REST, AJAX
    if (is_admin() || is_feed() || wp_doing_ajax()) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    
    // Buffer the output to inject into wp_head
    add_action('wp_head', 'bw_render_schema_graph', 5);
}

/**
 * Get current singular post/page ID when rendering schema (for Product/Service blocks).
 * Tries get_queried_object_id() then get_the_ID() so it works in wp_head.
 *
 * @return int 0 if not singular or invalid
 */
function bw_schema_get_singular_id() {
    if (!is_singular()) {
        return 0;
    }
    $id = get_queried_object_id();
    if (!$id && function_exists('get_the_ID')) {
        $id = get_the_ID();
    }
    $id = (int) $id;
    if (!$id || !get_post_status($id)) {
        return 0;
    }
    return $id;
}

/**
 * Normalize schema JSON string so unquoted %%variable%% values become quoted.
 * Allows "aggregateRating": %%_rating_json%% (invalid JSON) to decode; at output
 * the quoted "%%_rating_json%%" is then replaced by the resolved array/object.
 *
 * @param string $json Raw schema JSON (may contain unquoted %%...%%)
 * @return string JSON string safe for json_decode
 */
function bw_schema_normalize_json_placeholders($json) {
    if (!is_string($json) || strpos($json, '%%') === false) {
        return $json;
    }
    return preg_replace('/:\s*%%([^%]+)%%/', ': "%%$1%%"', $json);
}

/**
 * Parse comma/space-separated post IDs from option string.
 *
 * @param string $value Option value
 * @return int[] Array of positive integers
 */
function bw_schema_parse_post_id_list($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return [];
    }
    $parts = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
    $ids = array_values(array_filter(array_map('absint', $parts)));
    return $ids;
}

/**
 * Get featured image URL for a post (tries full, large, then attachment permalink).
 * Works in wp_head / outside the loop.
 *
 * @param int $post_id Post ID
 * @return string URL or empty
 */
function bw_schema_get_post_thumbnail_url($post_id) {
    $post_id = (int) $post_id;
    if (!$post_id) {
        return '';
    }
    $thumb_id = (int) get_post_thumbnail_id($post_id);
    if (!$thumb_id) {
        return '';
    }
    foreach (['full', 'large', 'medium_large'] as $size) {
        $url = wp_get_attachment_image_url($thumb_id, $size);
        if ($url) {
            return $url;
        }
    }
    $url = get_the_post_thumbnail_url($post_id, 'full');
    if ($url) {
        return $url;
    }
    return (string) get_permalink($thumb_id);
}

/**
 * Whether an option name may be read via %%_cmeta_options_<name>%% schema placeholders.
 * Restricted to known-safe prefixes; extend with {@see 'bw_schema_options_placeholder_key_allowed'}.
 *
 * @param string $option_key Option name (no table prefix).
 * @return bool
 */
function bw_schema_options_placeholder_key_allowed($option_key) {
    $option_key = (string) $option_key;
    if ($option_key === '' || strlen($option_key) > 191) {
        return false;
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $option_key)) {
        return false;
    }
    $prefixes = (array) apply_filters(
        'bw_schema_options_placeholder_allowed_prefixes',
        ['se_', 'scos_', 'site_essentials_']
    );
    foreach ($prefixes as $pfx) {
        $pfx = (string) $pfx;
        if ($pfx !== '' && strpos($option_key, $pfx) === 0) {
            return (bool) apply_filters('bw_schema_options_placeholder_key_allowed', true, $option_key);
        }
    }
    return (bool) apply_filters('bw_schema_options_placeholder_key_allowed', false, $option_key);
}

/**
 * Resolve a single schema variable for a given post/context.
 * Used by bw_schema_replace_variables. Variable names are case-sensitive.
 *
 * @param string $name   Variable name without %%, e.g. post_title, _cmeta_my_key, _cmeta_options_se_my_key, _acf_my_field
 * @param int    $post_id Post ID (0 for site-only context, e.g. Local Business)
 * @return string|int|float|array|null Resolved value; arrays allowed for ACF (Stage 3 repeaters)
 */
function bw_schema_resolve_variable($name, $post_id) {
    $name = trim($name);
    if ($name === '') {
        return '';
    }

    // Site-level (work with or without post)
    if ($name === 'site_name') {
        return get_bloginfo('name');
    }
    if ($name === 'site_url') {
        return home_url('/');
    }

    // Dynamic date: %%date_year_ahead%% → ISO date one year from today (useful for priceValidUntil)
    if ($name === 'date_year_ahead') {
        return date('Y-m-d', strtotime('+1 year'));
    }

    // Custom options: %%_cmeta_options_option_key%% → get_option( 'option_key' ). Must run before _cmeta_ (post meta) and before post-only gate.
    if (strpos($name, '_cmeta_options_') === 0) {
        $option_key = substr($name, strlen('_cmeta_options_'));
        if ($option_key === '' || !bw_schema_options_placeholder_key_allowed($option_key)) {
            return '';
        }
        $val = get_option($option_key, null);
        if ($val === null) {
            return '';
        }
        if (is_array($val) || is_object($val)) {
            return $val;
        }
        return $val === '' ? '' : (string) $val;
    }

    // Post-level (require valid post)
    if (!$post_id || !get_post_status($post_id)) {
        return '';
    }

    // Standard WP post fields
    $post = get_post($post_id);
    if (!$post) {
        return '';
    }
    $map = [
        'post_title'     => $post->post_title,
        'post_excerpt'   => get_the_excerpt($post_id),
        'post_date'      => get_the_date('c', $post_id),
        'post_modified'  => get_the_modified_date('c', $post_id),
        'post_url'       => get_permalink($post_id),
        'post_id'        => $post_id,
        'post_name'      => $post->post_name,
        'post_author'    => get_the_author_meta('display_name', $post->post_author),
    ];
    if (array_key_exists($name, $map)) {
        return $map[$name];
    }

    // Featured image: URL string or ImageObject for schema (post-centric, size fallbacks)
    if ($name === 'post_thumbnail_url') {
        $url = bw_schema_get_post_thumbnail_url($post_id);
        $url = apply_filters('bw_schema_post_thumbnail_url', $url, $post_id);
        return is_string($url) && $url !== '' ? $url : '';
    }
    if ($name === 'post_thumbnail') {
        $url = bw_schema_get_post_thumbnail_url($post_id);
        if (!$url) {
            return '';
        }
        $thumb_id = (int) get_post_thumbnail_id($post_id);
        $w = 0;
        $h = 0;
        if ($thumb_id) {
            $meta = wp_get_attachment_metadata($thumb_id, true);
            $w = isset($meta['width']) ? (int) $meta['width'] : 0;
            $h = isset($meta['height']) ? (int) $meta['height'] : 0;
        }
        $img = [
            '@type' => 'ImageObject',
            'url'   => $url,
        ];
        if ($w && $h) {
            $img['width'] = $w;
            $img['height'] = $h;
        }
        return $img;
    }

    // Custom meta: %%_cmeta_meta_key%% → get_post_meta($post_id, 'meta_key', true)
    if (strpos($name, '_cmeta_') === 0) {
        $meta_key = substr($name, 7);
        $val = get_post_meta($post_id, $meta_key, true);
        if (is_array($val) || is_object($val)) {
            return $val;
        }
        return $val === '' || $val === null ? '' : (string) $val;
    }

    // ACF: %%_acf_field_name%% → get_field('field_name', $post_id)
    if (strpos($name, '_acf_') === 0 && function_exists('get_field')) {
        $field_key = substr($name, 5);
        $val = get_field($field_key, $post_id);
        if (is_array($val) || is_object($val)) {
            return $val;
        }
        return $val === '' || $val === null ? '' : (string) $val;
    }

    return apply_filters('bw_schema_resolve_variable', '', $name, $post_id);
}

/**
 * Recursively replace %%variable%% placeholders in schema array (string values).
 * When a string value is exactly "%%var%%", it may be replaced with an array (e.g. ACF).
 *
 * @param array|string $data    Decoded schema (array or subtree)
 * @param int          $post_id Post ID for context (0 for site-only)
 * @return array|string Same structure with placeholders replaced
 */
function bw_schema_replace_variables($data, $post_id) {
    if (is_array($data)) {
        $out = [];
        foreach ($data as $key => $val) {
            $out[$key] = bw_schema_replace_variables($val, $post_id);
        }
        return $out;
    }
    if (!is_string($data)) {
        return $data;
    }
    $str = $data;

    // Whole value is a single variable → can replace with array/object
    if (preg_match('/^%%([^%]+)%%$/', $str, $m)) {
        $resolved = bw_schema_resolve_variable($m[1], $post_id);
        if (is_array($resolved) || is_object($resolved)) {
            return (array) $resolved;
        }
        if ($resolved !== '' && $resolved !== null) {
            return (string) $resolved;
        }
        return '';
    }

    // Mixed or multiple variables: replace in place, always string
    if (strpos($str, '%%') === false) {
        return $str;
    }
    $replaced = preg_replace_callback('/%%([^%]+)%%/', function ($matches) use ($post_id) {
        $resolved = bw_schema_resolve_variable($matches[1], $post_id);
        if (is_array($resolved) || is_object($resolved)) {
            return wp_json_encode($resolved);
        }
        return (string) $resolved;
    }, $str);
    return $replaced;
}

/**
 * Get author schema @id from user data
 * 
 * @param int|null $user_id User ID (defaults to post author)
 * @return string Author @id URL (e.g., home_url('/#author-slug'))
 */
function bw_get_author_schema_id($user_id = null) {
    if (!$user_id && is_singular()) {
        $user_id = get_post_field('post_author', get_the_ID());
    }
    
    if (!$user_id) {
        return home_url('/#author');
    }
    
    $user = get_userdata($user_id);
    if (!$user) {
        return home_url('/#author');
    }
    
    // Build slug from first-last name, fallback to username
    $first = sanitize_title($user->first_name ?: '');
    $last = sanitize_title($user->last_name ?: '');
    
    if ($first && $last) {
        return home_url('/#' . $first . '-' . $last);
    } elseif ($user->user_login) {
        return home_url('/#' . sanitize_title($user->user_login));
    }
    
    return home_url('/#author');
}

/**
 * Get author name from user data
 * 
 * @param int|null $user_id User ID
 * @return string Author display name
 */
function bw_get_author_name($user_id = null) {
    if (!$user_id && is_singular()) {
        $user_id = get_post_field('post_author', get_the_ID());
    }
    
    if (!$user_id) {
        return get_bloginfo('name');
    }
    
    $user = get_userdata($user_id);
    return $user ? $user->display_name : get_bloginfo('name');
}

/**
 * Render the actual schema JSON-LD
 */
function bw_render_schema_graph() {
    
    $graph = [];
    
    // ============================================
    // STATIC BLOCKS
    // ============================================
    
    // WebSite
    $graph[] = [
        "@type" => "WebSite",
        "@id" => home_url('/#website'),
        "url" => home_url('/'),
        "name" => get_bloginfo('name'),
        "publisher" => [
            "@id" => home_url('/#organization')
        ]
    ];
    
    // LocalBusiness — read from scos_site_schema_* (new) with bw_* fallback (legacy)
    // Auto-generate from Business Info fields when both stores are empty
    $local_business_schema = get_option( 'scos_site_schema_local_business', '' );
    if ( $local_business_schema === '' ) {
        $local_business_schema = get_option( 'bw_local_business_schema', '' );
    }
    if ( $local_business_schema === '' && function_exists( 'brighter_get_option' ) && brighter_get_option( 'business_name' ) ) {
        $biz_auto = scos_schema_build_local_business();
        if ( $biz_auto ) {
            $local_business_schema = wp_json_encode( $biz_auto );
        }
    }
    if (!empty($local_business_schema)) {
        $decoded = json_decode(bw_schema_normalize_json_placeholders($local_business_schema), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $decoded = bw_schema_replace_variables($decoded, 0);
            if (!isset($decoded['@id'])) {
                $decoded['@id'] = home_url('/#organization');
            }
            $graph[] = $decoded;
        }
    }
    
    /* TEMPORARILY COMMENTED OUT - Using admin options instead
    $graph[] = [
        "@type" => "LocalBusiness",
        "@id" => home_url('/#organization'),
        "name" => "Brighter Websites",
        "legalName" => "Vanessa Wood Trading As Brighter Websites",
        "description" => "Ballarat-based web design and SEO specialist serving regional Australian service businesses. We build AI-ready, conversion-optimized websites that generate measurable ROI for trades, professional services, and consultants.",
        "url" => home_url('/'),
        "telephone" => "+61412401933",
        "email" => "support@brighterwebsites.com.au",
        "priceRange" => "$$$",
        "address" => [
            "@type" => "PostalAddress",
            "streetAddress" => "14 Messenger Pde Lucas",
            "addressLocality" => "Ballarat",
            "addressRegion" => "VIC",
            "postalCode" => "3350",
            "addressCountry" => "AU"
        ],
        "geo" => [
            "@type" => "GeoCoordinates",
            "latitude" => "-37.5662",
            "longitude" => "143.8496"
        ],
        "areaServed" => [
            ["@type" => "City", "name" => "Ballarat", "sameAs" => "https://www.wikidata.org/wiki/Q17856"],
            ["@type" => "State", "name" => "Victoria", "sameAs" => "https://www.wikidata.org/wiki/Q36687"],
            ["@type" => "Country", "name" => "Australia", "sameAs" => "https://www.wikidata.org/wiki/Q408"]
        ],
        "openingHoursSpecification" => [
            [
                "@type" => "OpeningHoursSpecification",
                "dayOfWeek" => ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"],
                "opens" => "09:00",
                "closes" => "17:00"
            ]
        ],
        "identifier" => [
            ["@type" => "PropertyValue", "name" => "ABN", "value" => "85 329 119 455"]
        ],
        "sameAs" => [
            "https://www.facebook.com/BrighterWeb",
            "https://www.linkedin.com/company/brighterwebsites",
            "https://www.instagram.com/brighterwebsites",
            "https://www.youtube.com/@brighterwebsites",
            "https://www.linkedin.com/in/vanessarosewood/"
        ],
        "logo" => [
            "@type" => "ImageObject",
            "url" => home_url('/wp-content/uploads/2024/01/brighter-websites-logo.png'),
            "width" => 1200,
            "height" => 1200
        ],
        "founder" => [
            "@type" => "Person",
            "@id" => home_url('/#vanessa-wood'),
            "name" => "Vanessa Wood",
            "url" => home_url('/author/vanessa-wood/'),
            "sameAs" => [
                "https://www.facebook.com/vanessarosewoodau",
                "https://www.linkedin.com/in/vanessarosewood/"
            ]
        ],
        "foundingDate" => "2016",
        "foundingLocation" => [
            "@type" => "Place",
            "name" => "Ballarat, VIC, Australia"
        ]
    ];
    */
    
    // ============================================
    // DYNAMIC BLOCKS
    // ============================================
    
    $current_url = home_url($_SERVER['REQUEST_URI']);
    // Use get_queried_object_id() for reliable singular ID in wp_head context
    $post_id = is_singular() ? get_queried_object_id() : 0;
    if ($post_id && !get_post_status($post_id)) {
        $post_id = 0;
    }
    
    // WebPage - always add
    $webpage = [
        "@type" => "WebPage",
        "@id" => esc_url($current_url),
        "url" => esc_url($current_url),
        "isPartOf" => [
            "@id" => home_url('/#website')
        ]
    ];
    


    // Add author for singles (posts, projects), publisher for archives/pages
    // Author: articles, projects, news
    // Publisher only: service pages, Legal/Terms, Conversion Hub, Landing Page
    $post_type = is_singular() ? get_post_type() : '';
    $author_post_types = ['post', 'project', 'news']; // Post types that get author
    
    if (is_singular() && in_array($post_type, $author_post_types)) {
        $webpage["author"] = [
            "@type" => "Person",
            "@id" => bw_get_author_schema_id(),
            "name" => bw_get_author_name()
        ];
    } else {
        $webpage["publisher"] = [
            "@id" => home_url('/#organization')
        ];
    }
    
    $graph[] = $webpage;
    
    // ============================================
    // ARCHIVE PAGES - CollectionPage (includes blog home)
    // ============================================
    
    // is_home() = blog posts page (when using static front page)
    // is_archive() = category, tag, CPT archive, date archive, etc.
    if (is_home() || is_archive()) {
        $post_type = get_post_type() ?: 'post';
        $archive_url = get_post_type_archive_link($post_type) ?: $current_url;
        
        // Get archive title
        if (is_home()) {
            $archive_title = 'Blog';
        } else {
            $archive_title = post_type_archive_title('', false) ?: single_term_title('', false) ?: 'Archive';
        }
        
        $archive_description = term_description() ?: get_the_archive_description() ?: '';
        
        $posts = get_posts([
            'numberposts' => 5,
            'post_type' => $post_type,
            'post_status' => 'publish'
        ]);
        
        $entities = [];
        foreach ($posts as $post) {
            $entities[] = [
                "@type" => "BlogPosting",
                "headline" => get_the_title($post),
                "url" => get_permalink($post),
                "datePublished" => get_the_date('c', $post),
                "author" => [
                    "@type" => "Person",
                    "name" => get_the_author_meta('display_name', $post->post_author)
                ]
            ];
        }
        
        if (!empty($entities)) {
            $graph[] = [
                "@type" => "CollectionPage",
                "@id" => esc_url($archive_url) . '#collection',
                "name" => $archive_title,
                "url" => esc_url($archive_url),
                "description" => wp_strip_all_tags($archive_description),
                "mainEntity" => $entities
            ];
        }
    }
    
    // ============================================
    // SINGLE POSTS - Article (enhanced)
    // ============================================
    
    if (is_singular('post')) {
        // Get TLDR for description (fallback to excerpt)
        $tldr = get_post_meta($post_id, 'scos_seo_tldr', true);
        if (empty($tldr)) {
            $tldr = get_post_meta($post_id, 'tldr', true); // pre-bw_ ACF fallback
        }
        $description = !empty($tldr) ? wp_strip_all_tags($tldr) : (get_the_excerpt() ?: wp_trim_words(get_the_content(), 30));
        
        // Get SEOPress meta title for alternativeHeadline
        $meta_title = get_post_meta($post_id, '_seopress_titles_title', true);
        
        // Get primary topic for "about" — scos_topic taxonomy is canonical
        $about = null;
        $topic_terms = wp_get_post_terms( $post_id, 'scos_topic' );
        if ( is_wp_error( $topic_terms ) || empty( $topic_terms ) ) {
            $topic_terms = wp_get_post_terms( $post_id, 'altc_topic' ); // legacy taxonomy fallback
        }
        if ( ! is_wp_error( $topic_terms ) && ! empty( $topic_terms ) ) {
            $topic_term   = $topic_terms[0];
            $topic_sameas = get_term_meta( $topic_term->term_id, 'topic_sameas_url', true );
            $about = [ "@type" => "Thing", "name" => $topic_term->name ];
            if ( ! empty( $topic_term->description ) ) {
                $about["description"] = wp_strip_all_tags( $topic_term->description );
            }
            if ( ! empty( $topic_sameas ) ) {
                $about["sameAs"] = $topic_sameas;
            }
        }

        // Build image object (prefer OG image size 1200x630)
        $image_obj = null;
        if (has_post_thumbnail($post_id)) {
            $thumbnail_id = get_post_thumbnail_id($post_id);
            // Try to get 1200x630 OG size, fallback to large
            $image_url = wp_get_attachment_image_url($thumbnail_id, 'og-image'); // Custom OG size if registered
            if (!$image_url) {
                $image_url = get_the_post_thumbnail_url($post_id, 'large');
            }
            $image_caption = get_the_post_thumbnail_caption($post_id);
            $image_alt = get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true);
            
            $image_obj = [
                "@type" => "ImageObject",
                "url" => $image_url,
                "width" => 1200,
                "height" => 630
            ];
            if (!empty($image_caption)) {
                $image_obj["caption"] = $image_caption;
            } elseif (!empty($image_alt)) {
                $image_obj["caption"] = $image_alt;
            }
        }
        
        // Get word count and reading time
        $word_count   = (int) get_post_meta( $post_id, 'scos_ca_word_count',   true );
        $reading_time = (int) get_post_meta( $post_id, 'scos_ca_reading_time', true );

        // Build Article schema
        $article = [
            "@type" => "Article",
            "@id" => get_permalink() . '#article',
            "headline" => get_the_title(),
            "description" => $description,
            "url" => get_permalink(),
            "datePublished" => get_the_date('c'),
            "dateModified" => get_the_modified_date('c'),
            "author" => [
                "@type" => "Person",
                "@id" => bw_get_author_schema_id(),
                "name" => bw_get_author_name()
            ],
            "publisher" => [
                "@id" => home_url('/#organization')
            ],
            "mainEntityOfPage" => [
                "@id" => get_permalink()
            ],
            "isAccessibleForFree" => true,
            "speakable" => [
                "@type" => "SpeakableSpecification",
                "cssSelector" => [".speakthis", ".tldr-content", "h1", "h2"]
            ]
        ];
        
        // Add word count if available
        if ($word_count > 0) {
            $article["wordCount"] = $word_count;
        }
        
        // Add reading time if available (ISO 8601 duration format)
        if ($reading_time > 0) {
            $article["timeRequired"] = "PT{$reading_time}M"; // e.g., PT7M
        }
        
        // Add optional fields if available
        if (!empty($meta_title) && $meta_title !== get_the_title()) {
            $article["alternativeHeadline"] = $meta_title;
        }
        
        if ($image_obj) {
            $article["image"] = $image_obj;
        }
        
        if ($about) {
            $article["about"] = $about;
        }
        
        // TODO: Future enhancements
        // - "about" array from bw_supporting_topics (multiple topics)
        // - "mentions" for Place (Ballarat) - could be site-wide or per-post
        
        $graph[] = $article;
    }
    
    // ============================================
    // NEWS - NewsArticle (same as Article + news-specific fields)
    // ============================================
    
    if (is_singular('news')) {
        // Get TLDR for description (fallback to excerpt)
        $tldr = get_post_meta($post_id, 'scos_seo_tldr', true);
        if (empty($tldr)) {
            $tldr = get_post_meta($post_id, 'tldr', true); // pre-bw_ ACF fallback
        }
        $description = !empty($tldr) ? wp_strip_all_tags($tldr) : (get_the_excerpt() ?: wp_trim_words(get_the_content(), 30));
        
        // Get SEOPress meta title for alternativeHeadline
        $meta_title = get_post_meta($post_id, '_seopress_titles_title', true);
        
        // Get word count and reading time
        $word_count   = (int) get_post_meta( $post_id, 'scos_ca_word_count',   true );
        $reading_time = (int) get_post_meta( $post_id, 'scos_ca_reading_time', true );

        // Get primary topic for "about" — scos_topic taxonomy is canonical
        $about = null;
        $topic_terms = wp_get_post_terms( $post_id, 'scos_topic' );
        if ( is_wp_error( $topic_terms ) || empty( $topic_terms ) ) {
            $topic_terms = wp_get_post_terms( $post_id, 'altc_topic' ); // legacy taxonomy fallback
        }
        if ( ! is_wp_error( $topic_terms ) && ! empty( $topic_terms ) ) {
            $topic_term   = $topic_terms[0];
            $topic_sameas = get_term_meta( $topic_term->term_id, 'topic_sameas_url', true );
            $about = [ "@type" => "Thing", "name" => $topic_term->name ];
            if ( ! empty( $topic_term->description ) ) {
                $about["description"] = wp_strip_all_tags( $topic_term->description );
            }
            if ( ! empty( $topic_sameas ) ) {
                $about["sameAs"] = $topic_sameas;
            }
        }

        // Build image object
        $image_obj = null;
        if (has_post_thumbnail($post_id)) {
            $thumbnail_id = get_post_thumbnail_id($post_id);
            $image_url = wp_get_attachment_image_url($thumbnail_id, 'og-image') ?: get_the_post_thumbnail_url($post_id, 'large');
            $image_caption = get_the_post_thumbnail_caption($post_id);
            $image_alt = get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true);
            
            $image_obj = [
                "@type" => "ImageObject",
                "url" => $image_url,
                "width" => 1200,
                "height" => 630
            ];
            if (!empty($image_caption)) {
                $image_obj["caption"] = $image_caption;
            } elseif (!empty($image_alt)) {
                $image_obj["caption"] = $image_alt;
            }
        }
        
        // Build NewsArticle schema
        $news_article = [
            "@type" => "NewsArticle",
            "@id" => get_permalink() . '#news-article',
            "headline" => get_the_title(),
            "description" => $description,
            "url" => get_permalink(),
            "datePublished" => get_the_date('c'),
            "dateModified" => get_the_modified_date('c'),
            "coverageStartTime" => get_the_date('c'), // Same as published for news
            "author" => [
                "@type" => "Person",
                "@id" => bw_get_author_schema_id(),
                "name" => bw_get_author_name()
            ],
            "publisher" => [
                "@id" => home_url('/#organization')
            ],
            "mainEntityOfPage" => [
                "@id" => get_permalink()
            ],
            "articleSection" => "AI & Digital Marketing News",
            "dateline" => "Ballarat, Australia",
            "locationCreated" => [
                "@type" => "Place",
                "name" => "Ballarat, Victoria, Australia"
            ],
            "isAccessibleForFree" => true,
            "speakable" => [
                "@type" => "SpeakableSpecification",
                "cssSelector" => [".speakthis", ".tldr-content", "h1", "h2"]
            ]
        ];
        
        // Add word count if available
        if ($word_count > 0) {
            $news_article["wordCount"] = $word_count;
        }
        
        // Add reading time if available
        if ($reading_time > 0) {
            $news_article["timeRequired"] = "PT{$reading_time}M";
        }
        
        // Add optional fields
        if (!empty($meta_title) && $meta_title !== get_the_title()) {
            $news_article["alternativeHeadline"] = $meta_title;
        }
        
        if ($image_obj) {
            $news_article["image"] = $image_obj;
        }
        
        if ($about) {
            $news_article["about"] = $about;
        }
        
        $graph[] = $news_article;
    }
    
    // ============================================
    // SERVICE PAGES - Service (only if no custom schema)
    // ============================================
    
    if (is_singular('page')) {
        $purpose = get_post_meta($post_id, 'scos_ca_purpose', true);
        $has_custom_schema = !empty(get_post_meta($post_id, 'scos_schema_custom', true));

        // Skip inline Service generation if the admin template block below will handle this page.
        // That block fires when: purpose-auto is on, OR post ID is in the service IDs list.
        $service_template_set = ( get_option( 'scos_site_schema_service', '' ) !== '' );
        $service_purpose_auto = (bool) get_option( 'scos_site_schema_service_purpose_auto' );
        $service_id_list      = bw_schema_parse_post_id_list( get_option( 'scos_site_schema_service_ids', '' ) );
        $template_will_handle = $service_template_set && (
            ( $service_purpose_auto && $purpose === 'service-page' ) ||
            in_array( $post_id, $service_id_list, true )
        );

        // Only auto-generate the fallback inline Service schema when no custom schema AND
        // the admin template block won't already output one for this page.
        if ($purpose === 'service-page' && !$has_custom_schema && !$template_will_handle) {
            $description = get_the_excerpt();
          
            $graph[] = [
                "@type" => "Service",
                "@id" => get_permalink() . '#service',
                "name" => get_the_title(),
                "description" => $description,
                "url" => get_permalink(),
                "provider" => [
                    "@id" => home_url('/#organization')
                ],
                "areaServed" => [
                    ["@type" => "Country", "name" => "Australia"]
                ]
            ];
        }
    }
    
    // ============================================
    // ADMIN TEMPLATES: Success Stories | Product | Service
    // ============================================
    
    // Success Stories — on single project (CPT) only (CreativeWork removed; use Success Stories tab)
    if (is_singular('projects') && $post_id) {
        $success_stories_schema = get_option( 'scos_site_schema_success_stories', '' ) ?: get_option( 'bw_success_stories_schema', '' );
        if (!empty($success_stories_schema)) {
            $decoded = json_decode(bw_schema_normalize_json_placeholders($success_stories_schema), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $decoded = bw_schema_replace_variables($decoded, $post_id);
                if (isset($decoded[0])) {
                    $graph = array_merge($graph, $decoded);
                } else {
                    $graph[] = $decoded;
                }
            }
        }
    }
    
    // Product — on single post/page when its ID is in the list, OR on all WooCommerce products when auto-apply is enabled
    $singular_id = bw_schema_get_singular_id();
    if ($singular_id) {
        $product_post_ids = get_option( 'scos_site_schema_product_ids', '' ) ?: get_option( 'bw_product_post_ids', '' );
        $product_schema   = get_option( 'scos_site_schema_product', '' ) ?: get_option( 'bw_product_schema', '' );
        $ids = bw_schema_parse_post_id_list($product_post_ids);
        $ids = apply_filters('bw_schema_product_post_ids', $ids, $singular_id);
        $include_product = in_array($singular_id, $ids, true) || apply_filters('bw_schema_force_include_product', false, $singular_id);

        // WooCommerce: auto-apply the Product template to all product pages when checkbox is on
        if ( !$include_product && is_singular('product') && get_option('scos_site_schema_woo_product_auto') ) {
            $include_product = true;
        }

        // Content Architecture purpose: auto-apply when purpose = product-page
        if ( !$include_product && get_option('scos_site_schema_product_purpose_auto') ) {
            $purpose = get_post_meta( $singular_id, 'scos_ca_purpose', true );
            if ( $purpose === 'product-page' ) {
                $include_product = true;
            }
        }

        if ($include_product && $product_schema !== '') {
            $decoded = json_decode(bw_schema_normalize_json_placeholders($product_schema), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $decoded = bw_schema_replace_variables($decoded, $singular_id);
                if (isset($decoded[0]) && is_array($decoded[0])) {
                    $graph = array_merge($graph, $decoded);
                } else {
                    $graph[] = $decoded;
                }
            }
        }
    }
    
    // Service — on single post or page when its ID is in the list, or when purpose = service-page
    if ($singular_id) {
        $service_post_ids = get_option( 'scos_site_schema_service_ids', '' ) ?: get_option( 'bw_service_post_ids', '' );
        $service_schema   = get_option( 'scos_site_schema_service', '' ) ?: get_option( 'bw_service_schema', '' );
        $ids = bw_schema_parse_post_id_list($service_post_ids);
        $ids = apply_filters('bw_schema_service_post_ids', $ids, $singular_id);
        $include_service = in_array($singular_id, $ids, true) || apply_filters('bw_schema_force_include_service', false, $singular_id);

        // Content Architecture purpose: auto-apply when purpose = service-page
        if ( !$include_service && get_option('scos_site_schema_service_purpose_auto') ) {
            $purpose = get_post_meta( $singular_id, 'scos_ca_purpose', true );
            if ( $purpose === 'service-page' ) {
                $include_service = true;
            }
        }
        if ($include_service && $service_schema !== '') {
            $decoded = json_decode(bw_schema_normalize_json_placeholders($service_schema), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $decoded = bw_schema_replace_variables($decoded, $singular_id);
                if (isset($decoded[0]) && is_array($decoded[0])) {
                    $graph = array_merge($graph, $decoded);
                } else {
                    $graph[] = $decoded;
                }
            }
        }
    }
    
    // ============================================
    // BREADCRUMBS
    // ============================================
    
    $breadcrumbs = [];
    $position = 1;
    
    // Home - always first
    $breadcrumbs[] = [
        "@type" => "ListItem",
        "position" => $position++,
        "name" => "Home",
        "item" => home_url('/')
    ];
    
    // FRONT PAGE - just Home, nothing else
    if (is_front_page()) {
        // Only Home breadcrumb for front page
    }
    
    // BLOG ARCHIVE (is_home() = true for blog posts page)
    elseif (is_home()) {
        $breadcrumbs[] = [
            "@type" => "ListItem",
            "position" => $position,
            "name" => "Blog",
            "item" => get_post_type_archive_link('post') ?: home_url('/blog/')
        ];
    }
    
    // OTHER ARCHIVES (categories, tags, CPT archives, date archives)
    elseif (is_archive()) {
        $post_type = get_post_type() ?: 'post';
        $post_type_obj = get_post_type_object($post_type);
        
        if ($post_type_obj) {
            $breadcrumbs[] = [
                "@type" => "ListItem",
                "position" => $position,
                "name" => $post_type_obj->labels->name,
                "item" => get_post_type_archive_link($post_type)
            ];
        }
    }
    
    // SINGLES (posts, pages, CPTs)
    elseif (is_singular()) {
        $post_type = get_post_type();
        
        // Add post type archive for posts
        if ($post_type === 'post') {
            $breadcrumbs[] = [
                "@type" => "ListItem",
                "position" => $position++,
                "name" => "Blog",
                "item" => get_post_type_archive_link('post') ?: home_url('/blog/')
            ];
        }
        
        // Add CPT archive for custom post types (not pages)
        if ($post_type !== 'post' && $post_type !== 'page') {
            $post_type_obj = get_post_type_object($post_type);
            if ($post_type_obj && $post_type_obj->has_archive) {
                $breadcrumbs[] = [
                    "@type" => "ListItem",
                    "position" => $position++,
                    "name" => $post_type_obj->labels->name,
                    "item" => get_post_type_archive_link($post_type)
                ];
            }
        }
        
        // Add parent pages for hierarchical post types (pages)
        if (is_page()) {
            $ancestors = get_post_ancestors($post_id);
            $ancestors = array_reverse($ancestors);
            
            foreach ($ancestors as $ancestor_id) {
                $breadcrumbs[] = [
                    "@type" => "ListItem",
                    "position" => $position++,
                    "name" => get_the_title($ancestor_id),
                    "item" => get_permalink($ancestor_id)
                ];
            }
        }
        
        // Current page - check for breadcrumb override
        $breadcrumb_override = get_post_meta($post_id, 'scos_seo_breadcrumb_title', true);
        $current_name = !empty($breadcrumb_override) ? $breadcrumb_override : get_the_title();
        
        $breadcrumbs[] = [
            "@type" => "ListItem",
            "position" => $position,
            "name" => $current_name,
            "item" => get_permalink()
        ];
    }
    
    $graph[] = [
        "@type" => "BreadcrumbList",
        "@id" => esc_url($current_url) . '#breadcrumb',
        "itemListElement" => $breadcrumbs
    ];
    
    // ============================================
    // CUSTOM SCHEMA (from meta field)
    // ============================================
    
    if (is_singular() && $post_id) {
        $custom_schema = get_post_meta($post_id, 'scos_schema_custom', true);
        
        if (!empty($custom_schema)) {
            $decoded = json_decode(bw_schema_normalize_json_placeholders($custom_schema), true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                $decoded = bw_schema_replace_variables($decoded, $post_id);
                if (isset($decoded[0])) {
                    $graph = array_merge($graph, $decoded);
                } else {
                    $graph[] = $decoded;
                }
            }
        }
    }
    
    // ============================================
    // MODULE EXTENSIONS — let other modules push items into the @graph.
    // Used by site-essentials FAQ_Schema_Graph etc.
    // TODO: migrate scos-schema-output.php to site-essentials Schema module.
    // ============================================

    $graph = apply_filters( 'scos_schema_graph_items', $graph, $post_id );

    // ============================================
    // OUTPUT
    // ============================================
    
    // Filter out null and empty string so we don't output "image": "" etc.
    $graph = array_map(function($block) {
        return array_filter($block, function($value) {
            return $value !== null && $value !== '';
        });
    }, $graph);
    
    $schema = [
        "@context" => "https://schema.org",
        "@graph" => array_values($graph)
    ];
    
    echo '<script id="bw-schema-graph" type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</script>' . "\n";
}

