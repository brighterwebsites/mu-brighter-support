<?php
/**
 * Content Analysis - Link Counting & Statistics
 *
 * File: class-content-analysis.php
 * Version: 1.5.0 | 2026-07-15
 *
 * Responsibilities:
 * - Count internal/external links (excluding header/footer/nav)
 * - Calculate content statistics (word count, images, H2s)
 * - Provide aggregated "full page" content (rendered-first, JSON parse fallback)
 * - Save analysis results on post save (debounced via WP-Cron)
 *
 * v1.4.0 | 2026-06-07 — get_aggregated_content() now prefers the fully rendered
 *   page (Rendered_Content_Extractor) so ACF / dynamic fields / Query Loops /
 *   Post Repeaters / image URLs are captured; raw _breakdance_data JSON parse is
 *   the fallback. Analysis is debounced onto a single WP-Cron event so the
 *   editor save stays fast.
 */

if (!defined('ABSPATH')) exit;

class BW_Content_Analysis {

    /**
     * Initialize content analysis
     */
    /**
     * Shared cron event for debounced rendered-content analysis. Both this class
     * and the Content Architecture module schedule and handle this single event,
     * so the loopback render happens once and is reused from cache.
     */
    const CRON_EVENT = 'scos_ca_render_analyze';

    public static function init() {
        // On save we only schedule — the heavy render/analysis runs in cron so
        // the editor save stays fast (see schedule_analysis / run_scheduled).
        add_action('save_post', [__CLASS__, 'on_save_post'], 20, 3);

        // Breakdance Builder writes _breakdance_data via its own REST API, which may
        // run after save_post fires or may not trigger save_post at all. Hook onto the
        // meta write so analysis always runs with the freshly committed BD content.
        add_action('updated_post_meta', [__CLASS__, 'on_breakdance_data_saved'], 10, 3);
        add_action('added_post_meta',   [__CLASS__, 'on_breakdance_data_saved'], 10, 3);

        // Debounced analysis worker (WP-Cron single event).
        add_action(self::CRON_EVENT, [__CLASS__, 'run_scheduled'], 10, 1);

        // Track post views on frontend
        add_action('wp_head', [__CLASS__, 'track_post_views']);

        // Register post_views shortcode (reading_time is in reading-time-shortcode.php)
        add_shortcode('post_views', [__CLASS__, 'post_views_shortcode']);
    }

    /**
     * save_post handler — validate then schedule a debounced analysis run.
     *
     * Capability / autosave / revision checks live here (request context) rather
     * than in analyze_content(), which also runs from WP-Cron where there is no
     * current user.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     * @param bool     $update  Whether this is an update.
     */
    public static function on_save_post($post_id, $post, $update) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!in_array($post->post_type, bw_cs_post_types(), true)) return;
        if (!current_user_can('edit_post', $post_id)) return;
        // Rendered analysis only applies to published content (drafts skipped).
        if ('publish' !== $post->post_status) return;

        self::schedule_analysis($post_id);
    }

    /**
     * Schedule a single debounced analysis run for a post (deduped).
     *
     * @param int $post_id Post ID.
     */
    public static function schedule_analysis($post_id) {
        $post_id = (int) $post_id;
        if (!wp_next_scheduled(self::CRON_EVENT, [$post_id])) {
            // ~15s delay lets Breakdance finish writing and any page cache purge.
            wp_schedule_single_event(time() + 15, self::CRON_EVENT, [$post_id]);
        }
    }

    /**
     * WP-Cron worker — load the post and run the analysis.
     *
     * @param int $post_id Post ID.
     */
    public static function run_scheduled($post_id) {
        $post = get_post((int) $post_id);
        if (!$post) return;
        self::analyze_content($post_id, $post, true);
    }

    /**
     * Main analysis function — runs from WP-Cron (run_scheduled) or directly.
     *
     * No capability check here: this also runs in WP-Cron where there is no
     * current user. The save_post entry point (on_save_post) performs the
     * capability / autosave / revision checks before scheduling.
     */
    public static function analyze_content($post_id, $post, $update) {
        // ContentArchitecture module owns stats (scos_ca_* keys) — skip legacy bw_* writes.
        if ( defined( 'SCOS_CA_ACTIVE' ) ) { return; }

        // Prevent revisions / autosave (cron-safe guards).
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;

        // Only analyze supported post types
        if (!in_array($post->post_type, bw_cs_post_types(), true)) return;

        // Rendered analysis only applies to published content (drafts skipped).
        if ('publish' !== $post->post_status) return;

        // Only recalculate if content changed
        $last_modified = get_post_meta($post_id, '_bw_last_analyzed', true);
        if ($last_modified === $post->post_modified) {
            return; // Skip - nothing changed
        }

        // 1. Aggregate content from all sources (rendered-first, JSON parse fallback)
        $raw_content = self::get_aggregated_content($post_id);

        // 2. Clean content (remove header/footer/nav)
        $clean_content = self::clean_content($raw_content);

        // 3. Extract and categorize links
        $link_data = self::analyze_links($clean_content, $post_id);

        // 4. Calculate content statistics
        $stats = self::calculate_stats($clean_content);

        // 5. Calculate reading time (based on word count)
        $reading_time = self::calculate_reading_time($stats['word_count']);

        // 6. Save all data
        update_post_meta($post_id, 'bw_internal_link_count', $link_data['internal_count']);
        update_post_meta($post_id, 'bw_external_link_count', $link_data['external_count']);
        update_post_meta($post_id, 'bw_internal_links', $link_data['internal_links']);
        update_post_meta($post_id, 'bw_external_links', $link_data['external_links']);

        update_post_meta($post_id, 'bw_word_count', $stats['word_count']);
        update_post_meta($post_id, 'bw_image_count', $stats['image_count']);
        update_post_meta($post_id, 'bw_h2_count', $stats['h2_count']);
        
        // Reading time fields (NEW - consolidates post_reading_minutes, post_reading_iso)
        update_post_meta($post_id, 'bw_reading_time', $reading_time['minutes']);
        update_post_meta($post_id, 'bw_reading_time_iso', $reading_time['iso']);

        update_post_meta($post_id, '_bw_last_analyzed', $post->post_modified);
    }

    /**
     * Get aggregated content for a post (post_content + ACF + Breakdance).
     *
     * Same source as Content Analysis uses for word count and stats. Use this when you need
     * "full" content including builder/meta content (e.g. Make prompt-data, exports).
     * Refactor: move to central helper (Option 2) when content is refactored into new modules.
     *
     * @param int $post_id Post ID
     * @return string Raw aggregated HTML/text (not sanitized for display)
     */
    public static function get_aggregated_content($post_id) {
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, bw_cs_post_types(), true)) {
            return '';
        }

        // Rendered-first: fetch what actually renders on the page (resolves ACF,
        // dynamic fields, Query Loops, Post Repeaters and image URLs). Falls back
        // to the raw _breakdance_data JSON parse + ACF below when the loopback
        // render is unavailable (draft) or fails. Filterable for per-site control.
        $extractor = '\\SiteEssentials\\Modules\\ContentArchitecture\\Rendered_Content_Extractor';
        if (apply_filters('bw_content_analysis_use_rendered', true, $post_id)
            && class_exists($extractor)
            && $extractor::is_available($post_id)) {
            $rendered = $extractor::get_html($post_id);
            if ('' !== $rendered) {
                return $rendered;
            }
        }

        return self::aggregate_content($post_id, $post);
    }

    /**
     * Aggregate content from all sources.
     * When _breakdance_data is present and non-empty, use it as the primary content (Breakdance overwrites editor content).
     * When empty, use post_content. ACF is always appended when available.
     */
    private static function aggregate_content($post_id, $post) {
        $bd_content = self::get_breakdance_content($post_id);

        if ($bd_content !== '') {
            // Breakdance data exists: use it as primary content (do not double-count with post_content)
            $content = $bd_content;
        } else {
            $content = $post->post_content;
        }

        // ACF fields (if ACF is active)
        if (function_exists('get_fields')) {
            $fields = get_fields($post_id);
            if ($fields) {
                $content .= ' ' . self::extract_acf_content($fields);
            }
        }

        return $content;
    }

    /**
     * Clean content - remove header/footer/nav elements
     */
    private static function clean_content($html) {
        if (empty($html)) return '';

        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        // 1. Remove semantic HTML5 tags
        $exclude_tags = apply_filters('bw_content_analysis_exclude_tags', [
            'header',
            'footer',
            'nav'
        ]);

        foreach ($exclude_tags as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            $to_remove = [];
            foreach ($elements as $element) {
                $to_remove[] = $element;
            }
            foreach ($to_remove as $element) {
                if ($element->parentNode) {
                    $element->parentNode->removeChild($element);
                }
            }
        }

        // 2. Remove elements with exclusion classes
        $xpath = new DOMXPath($dom);
        $exclude_classes = apply_filters('bw_content_analysis_exclude_classes', [
            'ga-hrcy-header',
            'ga-hrcy-footer',
            'site-header',
            'site-footer',
            'main-navigation',
            'site-navigation'
        ]);

        foreach ($exclude_classes as $class) {
            $nodes = $xpath->query("//*[contains(@class, '$class')]");
            $to_remove = [];
            foreach ($nodes as $node) {
                $to_remove[] = $node;
            }
            foreach ($to_remove as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        return $dom->saveHTML();
    }

    /**
     * Extract and categorize links
     */
    private static function analyze_links($html, $post_id) {
        if (empty($html)) {
            return [
                'internal_count' => 0,
                'external_count' => 0,
                'internal_links' => [],
                'external_links' => []
            ];
        }

        // Extract all links
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $all_links = [];
        foreach ($dom->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            if ($href) {
                $all_links[] = $href;
            }
        }

        // Categorize links
        $internal = [];
        $external = [];
        $site_url = site_url();
        $home_url = home_url();

        $social_domains = apply_filters('bw_content_analysis_social_domains', [
            'facebook.com',
            'linkedin.com',
            'twitter.com',
            'x.com',
            'instagram.com',
            'youtube.com',
            'tiktok.com',
            'pinterest.com'
        ]);

        foreach ($all_links as $url) {
            // Skip empty, anchors, javascript
            if (empty($url) || $url === '#' || strpos($url, 'javascript:') === 0) continue;

            // Skip anchor links
            if (strpos($url, '#') === 0) continue;

            // Skip media files
            if (preg_match('/\.(jpg|jpeg|png|gif|pdf|doc|docx|xls|xlsx|zip)$/i', $url)) continue;

            // Check if social
            $is_social = false;
            foreach ($social_domains as $social) {
                if (strpos($url, $social) !== false) {
                    $is_social = true;
                    break;
                }
            }
            if ($is_social) continue;

            // Normalize URL for comparison
            $normalized_url = $url;

            // Handle protocol-relative URLs
            if (strpos($url, '//') === 0) {
                $normalized_url = 'https:' . $url;
            }

            // Handle relative URLs
            if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
                $normalized_url = $site_url . $url;
                $internal[] = $normalized_url;
                continue;
            }

            // Check if internal or external
            if (strpos($normalized_url, $site_url) === 0 || strpos($normalized_url, $home_url) === 0) {
                $internal[] = $normalized_url;
            } else {
                $external[] = $normalized_url;
            }
        }

        return [
            'internal_count' => count(array_unique($internal)),
            'external_count' => count(array_unique($external)),
            'internal_links' => array_unique($internal),
            'external_links' => array_unique($external)
        ];
    }

    /**
     * Calculate reading time based on word count
     * Average reading speed: 200 words per minute
     *
     * @param int $word_count Word count
     * @return array Reading time data
     */
    private static function calculate_reading_time($word_count) {
        $minutes = max(1, ceil($word_count / 200)); // 200 wpm average
        $iso = 'PT' . $minutes . 'M'; // ISO 8601 duration format
        
        return array(
            'minutes' => $minutes,
            'iso' => $iso,
            'formatted' => $minutes . ' min read'
        );
    }

    /**
     * Calculate content statistics
     */
    private static function calculate_stats($html) {
        if (empty($html)) {
            return [
                'word_count' => 0,
                'image_count' => 0,
                'h2_count' => 0
            ];
        }

        // Word count
        $text = strip_tags($html);
        $text = strip_shortcodes($text);
        $text = preg_replace('/\s+/', ' ', $text); // Normalize whitespace
        $word_count = str_word_count($text);

        // Image and H2 count
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $images = $dom->getElementsByTagName('img');
        $image_count = $images->length;

        $h2s = $dom->getElementsByTagName('h2');
        $h2_count = $h2s->length;

        return [
            'word_count' => $word_count,
            'image_count' => $image_count,
            'h2_count' => $h2_count
        ];
    }

    /**
     * Extract content from ACF fields recursively
     */
    private static function extract_acf_content($fields, $content = '') {
        if (!is_array($fields)) {
            if (is_string($fields)) {
                return $content . ' ' . $fields;
            }
            return $content;
        }

        foreach ($fields as $key => $value) {
            // Skip ACF internal fields starting with underscore
            if (is_string($key) && strpos($key, '_') === 0) {
                continue;
            }

            if (is_string($value)) {
                $content .= ' ' . $value;
            } elseif (is_array($value)) {
                // Recursively process arrays (repeaters, flexible content)
                $content = self::extract_acf_content($value, $content);
            }
        }

        return $content;
    }

    /**
     * Get Breakdance content from post meta _breakdance_data.
     * When present and non-empty, returns HTML extracted from the tree_json_string (headings, text, rich text, etc.).
     * Does not require Breakdance plugin class so it works in all contexts (save_post, REST, cron).
     */
    private static function get_breakdance_content($post_id) {
        $bd_data = get_post_meta($post_id, '_breakdance_data', true);
        if (empty($bd_data) || !is_string($bd_data)) {
            $bd_data = get_post_meta($post_id, 'breakdance_data', true);
        }
        if (empty($bd_data) || !is_string($bd_data)) {
            return '';
        }
        return self::parse_breakdance_structure($bd_data);
    }

    /**
     * Parse Breakdance _breakdance_data: outer JSON has tree_json_string containing inner JSON tree.
     * Walks the tree and extracts readable text/HTML from Heading, Text, RichText, Button, Badge, FancyTestimonial, etc.
     * Returns HTML so calculate_stats (word count, h2 count) and link analysis work.
     */
    private static function parse_breakdance_structure($data) {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (!is_array($decoded)) {
                return '';
            }
            $data = $decoded;
        }

        // Breakdance stores tree in tree_json_string (inner JSON as string)
        $tree_json = isset($data['tree_json_string']) ? $data['tree_json_string'] : null;
        if (empty($tree_json) || !is_string($tree_json)) {
            return '';
        }

        $tree = json_decode($tree_json, true);
        if (!is_array($tree) || empty($tree['root'])) {
            return '';
        }

        return self::extract_breakdance_tree_to_html($tree['root']);
    }

    /**
     * Recursively walk Breakdance tree and build HTML from node content.
     *
     * Generic content scanner — does NOT enumerate element types.
     * Instead it walks properties.content recursively and emits HTML
     * based on key-name patterns. This means new or custom elements
     * (Icon List, Accordion, Slider, Gallery, custom Scos* blocks, etc.)
     * are all handled automatically without per-element code.
     *
     * Emits:
     *   <h1>–<h6>  when text + tags (h1–h6) are siblings
     *   raw HTML    when a text value contains markup (RichText)
     *   <a href>    when a link/button_link/anchor key holds a URL object
     *               (text+link combos are merged to avoid double-counting)
     *   <img>       when image/img/thumbnail/images/gallery key holds an
     *               image object (URL resolved at render time; placeholder used)
     *   <p>         for any other meaningful plain-text string
     *
     * Skips: properties.design, properties.meta, properties.settings,
     *        all layout/style sub-keys, and enum-like strings (all
     *        lowercase-with-underscores, e.g. "media_library", "dark").
     *
     * Limitation: dynamically-loaded content (Post Repeater, Query Loop)
     * has no static text in the tree and cannot be counted here.
     */
    private static function extract_breakdance_tree_to_html($node) {
        if (!is_array($node)) {
            return '';
        }

        $props   = isset($node['data']['properties']) ? $node['data']['properties'] : [];
        $content = isset($props['content']) ? $props['content'] : [];

        $html = '';
        if (!empty($content) && is_array($content)) {
            $html .= self::scan_content_props($content);
        }

        if (!empty($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                $html .= self::extract_breakdance_tree_to_html($child);
            }
        }

        return $html;
    }

    /**
     * Recursively scan a Breakdance content-properties array and return HTML.
     *
     * Called from extract_breakdance_tree_to_html() with properties.content.
     * Also calls itself for nested objects (accordion items, slider slides, etc.).
     *
     * @param array  $data       The current properties sub-tree to scan.
     * @param string $parent_key The key name of the parent array (context hint).
     * @return string HTML fragment.
     */
    private static function scan_content_props(array $data, string $parent_key = ''): string {
        $html      = '';
        $skip_keys = [];

        // Keys that are purely layout/style — never contain readable content.
        static $style_keys = [
            'design', 'meta', 'settings', 'style', 'styles', 'typography',
            'spacing', 'padding', 'margin', 'border', 'background', 'shadow',
            'color', 'layout', 'layout_v2', 'imageDimensions', 'lazy_load',
            'preset', 'theme', 'size', 'alignment', 'position', 'visibility',
            'animation', 'transition', 'responsive', 'breakpoints',
        ];

        // Keys whose string value names a link target or image source type
        // (e.g. "from" = "media_library").  Not visible content.
        static $image_source_keys = ['from', 'id', 'lazy_load', 'alt', 'type'];

        // ── Pre-pass: detect text+link combos at this array level ───────────
        // When a link key and a text key are siblings, merge them into one
        // <a href>text</a> so the text is not double-counted.
        $link_url = '';
        foreach (['link', 'button_link', 'cta_link', 'cta', 'anchor', 'url_link'] as $lk) {
            if (isset($data[$lk]['url']) && is_string($data[$lk]['url'])) {
                $candidate = trim($data[$lk]['url']);
                if ($candidate !== '' && (strpos($candidate, 'http') === 0 || $candidate[0] === '/')) {
                    $link_url     = $candidate;
                    $skip_keys[]  = $lk;
                    break;
                }
            }
        }

        if ($link_url !== '') {
            // Gather the best sibling display text, in priority order.
            $display_text = '';
            foreach (['text', 'label', 'title', 'caption'] as $tk) {
                if (isset($data[$tk]) && is_string($data[$tk])) {
                    $t = trim(strip_tags($data[$tk]));
                    if ($t !== '') {
                        $display_text = $t;
                        $skip_keys[]  = $tk;
                        break;
                    }
                }
            }
            $anchor = $display_text !== '' ? $display_text : parse_url($link_url, PHP_URL_HOST);
            $html  .= '<a href="' . esc_url($link_url) . '">' . esc_html((string) $anchor) . '</a>';
        }

        // ── Main pass ────────────────────────────────────────────────────────
        foreach ($data as $key => $value) {
            $key_str = (string) $key;

            if (in_array($key_str, $skip_keys, true)) {
                continue;
            }

            // ── Skip pure style/layout keys ──────────────────────────────────
            if (in_array($key_str, $style_keys, true)) {
                continue;
            }

            // ── Image / gallery keys ─────────────────────────────────────────
            // Single image: { from, id, url/src, alt, lazy_load }
            // Gallery/images: array of image objects
            if (in_array($key_str, ['image', 'img', 'thumbnail', 'photo', 'images', 'gallery', 'slides_images'], true)
                && is_array($value)) {

                // Single image object
                if (isset($value['from']) || isset($value['id']) || isset($value['url']) || isset($value['src'])) {
                    $src = isset($value['url']) ? $value['url'] : (isset($value['src']) ? $value['src'] : '');
                    $alt = isset($value['alt']) && is_string($value['alt']) ? $value['alt'] : '';
                    $html .= '<img src="' . esc_attr($src) . '" alt="' . esc_attr($alt) . '">';
                } else {
                    // Array of image objects (Gallery, Slider background images, etc.)
                    foreach ($value as $img_item) {
                        if (!is_array($img_item)) continue;
                        if (isset($img_item['from']) || isset($img_item['id']) || isset($img_item['url']) || isset($img_item['src'])) {
                            $src = isset($img_item['url']) ? $img_item['url'] : (isset($img_item['src']) ? $img_item['src'] : '');
                            $alt = isset($img_item['alt']) && is_string($img_item['alt']) ? $img_item['alt'] : '';
                            $html .= '<img src="' . esc_attr($src) . '" alt="' . esc_attr($alt) . '">';
                        }
                    }
                }
                continue; // image sub-keys are config, not readable content
            }

            // ── Standalone link key (no sibling text — handled in pre-pass already) ─
            if (in_array($key_str, ['link', 'button_link', 'cta_link', 'cta', 'anchor', 'url_link'], true)) {
                continue; // already handled or no valid URL
            }

            // ── Text string ──────────────────────────────────────────────────
            if (is_string($value) && in_array($key_str, [
                'text', 'title', 'heading', 'label', 'caption',
                'description', 'content', 'testimonial', 'name',
                'occupation', 'summary', 'excerpt', 'body',
            ], true)) {
                $trimmed = trim($value);
                if ($trimmed === '') continue;

                // Skip enum-like values: all-lowercase with underscores/dashes, short.
                // e.g. "media_library", "dark", "from_media_library", "horizontal"
                if (preg_match('/^[a-z][a-z0-9_\-]*$/', $trimmed) && strlen($trimmed) < 30) {
                    continue;
                }

                // Heading: text key + sibling 'tags' key holding h1–h6
                if ($key_str === 'text'
                    && isset($data['tags']) && is_string($data['tags'])
                    && preg_match('/^h[1-6]$/i', $data['tags'])) {
                    $tag   = strtolower($data['tags']);
                    $clean = trim(strip_tags($trimmed));
                    if ($clean !== '') {
                        $html .= '<' . $tag . '>' . esc_html($clean) . '</' . $tag . '>';
                    }
                // Rich HTML (RichText, Accordion content, etc.)
                } elseif (strpos($trimmed, '<') !== false) {
                    $html .= wp_kses_post($trimmed);
                // Plain text
                } else {
                    $html .= '<p>' . esc_html($trimmed) . '</p>';
                }
                continue;
            }

            // ── Recurse into nested arrays (items[], slides[], panels[], etc.) ─
            if (is_array($value)) {
                $html .= self::scan_content_props($value, $key_str);
            }
        }

        return $html;
    }

    /**
     * Re-analyze a post whenever Breakdance writes new builder data.
     *
     * Mirrors the same hook in Content_Analysis.php (CA module). Both must be updated
     * together so bw_* and scos_ca_* keys stay in sync after a Breakdance builder save.
     *
     * @param int    $meta_id  Unused.
     * @param int    $post_id  Post being saved.
     * @param string $meta_key Meta key just written.
     */
    public static function on_breakdance_data_saved($meta_id, $post_id, $meta_key) {
        if ('_breakdance_data' !== $meta_key) {
            return;
        }
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, bw_cs_post_types(), true)) {
            return;
        }
        // Only schedule rendered analysis for published content (drafts skipped).
        if ('publish' !== $post->post_status) {
            return;
        }
        // Clear the timestamp so the skip-condition doesn't block this run.
        delete_post_meta($post_id, '_bw_last_analyzed');
        self::schedule_analysis($post_id);
    }

    /**
     * Track post views (privacy-friendly, no cookies)
     * Runs on wp_head for singular posts
     * 
     * Replaces old ACF-based post_views_count (KB only)
     * Now works on all post types with consistent bw_ naming
     */
    public static function track_post_views() {
        // Only track on singular posts (not archives, home, admin)
        if (!is_singular() || is_admin()) {
            return;
        }

        // Skip our own loopback render requests so analysis has no side effects.
        // (Literal marker; matches Rendered_Content_Extractor::MARKER.)
        if (isset($_GET['se_render'])) {
            return;
        }

        // Skip AJAX requests
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        
        // Filter out bots
        if (isset($_SERVER['HTTP_USER_AGENT']) && 
            stripos($_SERVER['HTTP_USER_AGENT'], 'bot') !== false) {
            return;
        }
        
        $post_id = get_the_ID();
        if (!$post_id) {
            return;
        }
        
        // Increment view count
        $views = (int) get_post_meta($post_id, 'bw_views_count', true);
        update_post_meta($post_id, 'bw_views_count', $views + 1);
        
        // Track last viewed timestamp
        update_post_meta($post_id, 'bw_last_viewed', current_time('mysql'));
    }
    
    /**
     * Post views shortcode
     * Usage: [post_views] or [post_views format="text"]
     */
    public static function post_views_shortcode($atts) {
        $atts = shortcode_atts([
            'format' => 'count',
        ], $atts, 'post_views');
        
        $post_id = get_the_ID();
        if (!$post_id) {
            return '';
        }
        
        $views = (int) get_post_meta($post_id, 'bw_views_count', true);
        
        switch (strtolower($atts['format'])) {
            case 'text':
                return sprintf('%s views', number_format($views));
            case 'count':
            default:
                return number_format($views);
        }
    }
}

// Initialize
BW_Content_Analysis::init();
