<?php
/**
 * Airtable Helper
 *
 * Handles synchronization of Content Architecture Record (CAR) data to Airtable
 * Uses standardized content type helper and settings from options page
 *
 * @package BrighterCore
 * @subpackage SocialAmplification
 * @version 1.1.0
 *
 * v1.0 | original
 * v1.1 | 2026-06-29 — Read scos_seo_tldr + scos_sa_shortlink_slug first; bw_ keys as fallback only.
 * v1.2 | 2026-07-17 — Strip HTML from TLDR before sending to Airtable (field now supports
 *                      bold/lists/links via wp_editor()).
 */

if (!defined('ABSPATH')) exit;

class BW_Airtable_Helper {

    /**
     * Initialize hooks
     */
    public static function init() {
        // Sync CAR data to Airtable on post save
        add_action('save_post', array(__CLASS__, 'sync_car_to_airtable'), 10, 3);
        // Sync ALTC and Topic terms to Airtable on term save
        add_action('created_altc_strategic_lens', array(__CLASS__, 'sync_altc_term_to_airtable'), 10, 1);
        add_action('edited_altc_strategic_lens', array(__CLASS__, 'sync_altc_term_to_airtable'), 10, 1);
        add_action('created_altc_topic', array(__CLASS__, 'sync_topic_term_to_airtable'), 10, 1);
        add_action('edited_altc_topic', array(__CLASS__, 'sync_topic_term_to_airtable'), 10, 1);
    }

    /**
     * Check if Airtable is configured
     *
     * @return bool True if all required settings are present
     */
    public static function is_configured() {
        $token = get_option('bw_airtable_api_token', '');
        $base_id = get_option('bw_airtable_base_id', '');
        $table_id = get_option('bw_airtable_table_id', '');

        return !empty($token) && !empty($base_id) && !empty($table_id);
    }

    /**
     * Get Airtable API base URL for Content table
     *
     * @return string|false Base URL or false if not configured
     */
    private static function get_base_url() {
        if (!self::is_configured()) {
            return false;
        }
        return self::get_table_url(get_option('bw_airtable_table_id', ''));
    }

    /**
     * Get Airtable API URL for a specific table
     *
     * @param string $table_id Table ID (e.g. tblXXX)
     * @return string|false URL or false if base/table missing
     */
    private static function get_table_url($table_id) {
        $base_id = get_option('bw_airtable_base_id', '');
        if (empty($base_id) || empty($table_id)) {
            return false;
        }
        return 'https://api.airtable.com/v0/' . $base_id . '/' . $table_id;
    }

    /**
     * Get Airtable API token
     *
     * @return string|false API token or false if not configured
     */
    private static function get_api_token() {
        $token = get_option('bw_airtable_api_token', '');
        
        // Ensure token starts with "Bearer " if not already
        if (!empty($token) && strpos($token, 'Bearer ') !== 0) {
            $token = 'Bearer ' . $token;
        }

        return !empty($token) ? $token : false;
    }

    /**
     * Sync ALTC Strategic Lens term to Airtable on save.
     * Creates/updates record, stores Airtable record ID in term meta.
     *
     * @param int $term_id Term ID
     */
    public static function sync_altc_term_to_airtable($term_id) {
        $table_id = get_option('bw_airtable_altc_table_id', '');
        if (empty($table_id) || !self::get_api_token()) {
            return;
        }
        $term = get_term($term_id, 'altc_strategic_lens');
        if (!$term || is_wp_error($term)) {
            return;
        }
        $fields = array(
            'ALTC' => sanitize_text_field($term->name),
            'Description' => sanitize_textarea_field($term->description ?: ''),
        );
        self::sync_term_to_airtable($term_id, $table_id, $fields);
    }

    /**
     * Sync ALTC Topic term to Airtable on save.
     *
     * @param int $term_id Term ID
     */
    public static function sync_topic_term_to_airtable($term_id) {
        $table_id = get_option('bw_airtable_topics_table_id', '');
        if (empty($table_id) || !self::get_api_token()) {
            return;
        }
        $term = get_term($term_id, 'altc_topic');
        if (!$term || is_wp_error($term)) {
            return;
        }
        $fields = array(
            'Topic' => sanitize_text_field($term->name),
            'Description' => sanitize_textarea_field($term->description ?: ''),
        );
        self::sync_term_to_airtable($term_id, $table_id, $fields);
    }

    /**
     * Sync a term to Airtable (ALTC or Topic table).
     * Stores Airtable record ID in term meta _airtable_record_id.
     *
     * @param int $term_id Term ID
     * @param string $table_id Airtable table ID
     * @param array $fields Field name => value (e.g. 'ALTC' => '...', 'Description' => '...')
     */
    private static function sync_term_to_airtable($term_id, $table_id, $fields) {
        $url = self::get_table_url($table_id);
        if (!$url) return;
        $api_token = self::get_api_token();
        $meta_key = '_airtable_record_id';
        $existing_rec_id = get_term_meta($term_id, $meta_key, true);

        $fields = array_filter($fields, function($v) { return $v !== '' && $v !== null; });
        $body = array('fields' => $fields, 'typecast' => true);

        if (!empty($existing_rec_id)) {
            $response = wp_remote_request($url . '/' . $existing_rec_id, array(
                'method' => 'PATCH',
                'headers' => array(
                    'Authorization' => $api_token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($body, JSON_UNESCAPED_SLASHES),
                'timeout' => 15
            ));
            $code = !is_wp_error($response) ? wp_remote_retrieve_response_code($response) : 0;
            if ($code === 404 || $code === 403) {
                delete_term_meta($term_id, $meta_key);
                $existing_rec_id = '';
            }
        }

        if (empty($existing_rec_id)) {
            $response = wp_remote_post($url, array(
                'headers' => array(
                    'Authorization' => $api_token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($body, JSON_UNESCAPED_SLASHES),
                'timeout' => 15
            ));
            if (!is_wp_error($response)) {
                $resp_body = json_decode(wp_remote_retrieve_body($response), true);
                if (isset($resp_body['id'])) {
                    update_term_meta($term_id, $meta_key, $resp_body['id']);
                }
            }
        }
    }

    /**
     * Get Airtable record ID for an ALTC term (from term meta).
     *
     * @param int $term_id Term ID
     * @return string|null Airtable record ID or null
     */
    private static function get_altc_airtable_record_id($term_id) {
        if (!$term_id) return null;
        $rec_id = get_term_meta($term_id, '_airtable_record_id', true);
        return !empty($rec_id) ? $rec_id : null;
    }

    /**
     * Get Airtable record ID for a Topic term.
     *
     * @param int $term_id Term ID
     * @return string|null Airtable record ID or null
     */
    private static function get_topic_airtable_record_id($term_id) {
        if (!$term_id) return null;
        $rec_id = get_term_meta($term_id, '_airtable_record_id', true);
        return !empty($rec_id) ? $rec_id : null;
    }

    /**
     * Convert internal link URLs to Airtable record IDs (for Linked Record field).
     *
     * @param int $post_id Post ID
     * @return array Array of Airtable record IDs ['recXXX','recYYY']
     */
    private static function get_internal_links_as_airtable_ids($post_id) {
        $internal_links = get_post_meta($post_id, 'bw_internal_links', true);
        if (empty($internal_links) || !is_array($internal_links)) {
            return array();
        }
        $rec_ids = array();
        foreach ($internal_links as $url) {
            $linked_post_id = url_to_postid($url);
            if ($linked_post_id > 0) {
                $rec_id = get_post_meta($linked_post_id, '_airtable_record_id', true);
                if (!empty($rec_id)) {
                    $rec_ids[] = $rec_id;
                }
            }
        }
        return array_unique($rec_ids);
    }

    /**
     * Convert internal link URLs to post IDs (comma-separated) - legacy text field fallback
     *
     * @param int $post_id Post ID
     * @return string Comma-separated post IDs
     */
    private static function get_internal_links_as_ids($post_id) {
        $internal_links = get_post_meta($post_id, 'bw_internal_links', true);
        
        if (empty($internal_links) || !is_array($internal_links)) {
            return '';
        }
        
        $post_ids = array();
        foreach ($internal_links as $url) {
            $linked_post_id = url_to_postid($url);
            if ($linked_post_id > 0) {
                $post_ids[] = $linked_post_id;
            }
        }
        
        return !empty($post_ids) ? implode(', ', $post_ids) : '';
    }

    /**
     * Convert external link URLs to comma-separated list
     *
     * @param int $post_id Post ID
     * @return string Comma-separated URLs
     */
    private static function get_external_links_as_urls($post_id) {
        $external_links = get_post_meta($post_id, 'bw_external_links', true);
        
        if (empty($external_links) || !is_array($external_links)) {
            return '';
        }
        
        return implode(', ', $external_links);
    }

    /**
     * Get workflow progress as array of strings for Airtable Multiple Select
     * Uses meta key workflow_progress. Send with typecast:true for INVALID_MULTIPLE_CHOICE_OPTIONS.
     *
     * @param int $post_id Post ID
     * @return array Array of option strings e.g. ['content','entities-semantics','seo-basic','draft']
     */
    private static function get_workflow_progress_as_array($post_id) {
        $workflow_stages = get_post_meta($post_id, 'workflow_progress', true);
        
        if (empty($workflow_stages) || !is_array($workflow_stages)) {
            return array();
        }
        
        return array_map('sanitize_text_field', array_values($workflow_stages));
    }

    /**
     * Build CAR data structure for Airtable
     *
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     * @param bool $exclude_internal_links If true, omit Internal Links (for bulk phase 1)
     * @return array Airtable data structure
     */
    private static function build_car_data($post_id, $post, $exclude_internal_links = false) {
        // Get ALTC Cluster and Topic - prefer Airtable record IDs (Linked Record) when available
        $primary_altc_id = get_post_meta($post_id, 'bw_primary_altc_id', true);
        $primary_topic_id = get_post_meta($post_id, 'bw_primary_topic_id', true);

        $altc_term_id = $primary_altc_id;
        if (empty($altc_term_id)) {
            $altc_terms = wp_get_post_terms($post_id, 'altc_strategic_lens');
            $altc_term_id = !empty($altc_terms) ? $altc_terms[0]->term_id : 0;
        }
        $topic_term_id = $primary_topic_id;
        if (empty($topic_term_id)) {
            $topic_terms = wp_get_post_terms($post_id, 'altc_topic');
            $topic_term_id = !empty($topic_terms) ? $topic_terms[0]->term_id : 0;
        }

        $altc_record_id = $altc_term_id ? self::get_altc_airtable_record_id($altc_term_id) : null;
        $topic_record_id = $topic_term_id ? self::get_topic_airtable_record_id($topic_term_id) : null;

        // Internal Links as Linked Record (array of Airtable record IDs)
        $internal_links_rec_ids = self::get_internal_links_as_airtable_ids($post_id);

        // Use standardized content type helper
        $content_type = BW_Content_Type_Helper::get_content_type($post_id, $post->post_type);

        // Get pillar and service pathway titles
        $pillar_id = get_post_meta($post_id, 'bw_pillar_page_id', true);
        $pillar_title = $pillar_id ? get_the_title($pillar_id) : '';

        $service_pathway_id = get_post_meta($post_id, 'bw_service_pathway_id', true);
        $service_pathway_title = $service_pathway_id ? get_the_title($service_pathway_id) : '';

        // Build Airtable data structure
        $airtable_data = array(
            // Basic post info
            'Post ID' => $post_id,
            'Title' => $post->post_title,
            'URL' => get_permalink($post_id),
            'Published Date' => get_the_date('Y-m-d', $post_id),
            'Last Modified Date' => get_the_modified_date('Y-m-d', $post_id),
            'PostType' => $post->post_type,
            'PublishedStatus' => $post->post_status,
            'Content Type' => $content_type,
            
            // Taxonomy/Category
            'Category' => wp_get_post_categories($post_id, array('fields' => 'names'))[0] ?? '',
            
            // Content fields — TLDR may contain basic HTML (bold/lists/links) since it's
            // edited via a rich-text field; Airtable's TLDR column is plain text, so strip tags.
            'TLDR' => wp_strip_all_tags(
                (string) ( get_post_meta($post_id, 'scos_seo_tldr', true)
                    ?: get_post_meta($post_id, 'bw_tldr', true) ) // TODO: remove bw_ fallback once all sites resaved
            ),
            'Excerpt' => $post->post_excerpt ?: wp_trim_words($post->post_content, 55, '...'),
            
            // ALTC Strategy fields - Linked Record when we have Airtable record IDs
            'ALTC Cluster' => $altc_record_id ? array($altc_record_id) : null,
            'Topics' => $topic_record_id ? array($topic_record_id) : null,
            'Maturity Level'  => get_post_meta($post_id, 'scos_ca_maturity',  true) ?: get_post_meta($post_id, 'bw_cont_maturity', true),
            'Content Intent'  => get_post_meta($post_id, 'scos_ca_intent',    true) ?: get_post_meta($post_id, 'bw_intent',         true),
            'Content Purpose' => get_post_meta($post_id, 'scos_ca_purpose',   true) ?: get_post_meta($post_id, 'bw_purpose',        true),
            'Intent Goal'     => class_exists( 'SiteEssentials\\Modules\\ContentArchitecture\\Intent_Goal_Resolver' )
                ? SiteEssentials\Modules\ContentArchitecture\Intent_Goal_Resolver::resolve_question( $post_id )
                : ( get_post_meta($post_id, 'scos_ca_intent_goal', true) ?: get_post_meta($post_id, 'bw_altc_notes', true) ),
            'Intent Goal FAQ ID' => (int) get_post_meta($post_id, 'scos_ca_intent_goal_faq_id', true) ?: null,
            
            // Pillar relationship
            'Pillar' => $pillar_title,
            'Service Pathway' => $service_pathway_title,
            
            // Optimization & Index Status
            'Workflow Next Step' => get_post_meta($post_id, 'content_plan', true),
            'Workflow Progress' => self::get_workflow_progress_as_array($post_id),
            
            // Content Analysis
            'Internal Links Count' => (int) get_post_meta($post_id, 'bw_internal_link_count', true),
            'External Links Count' => (int) get_post_meta($post_id, 'bw_external_link_count', true),
            'Internal Links' => $exclude_internal_links ? null : $internal_links_rec_ids,
            'External Links' => self::get_external_links_as_urls($post_id),
            'Word Count' => (int) get_post_meta($post_id, 'bw_word_count', true),
            'H2 Count' => (int) get_post_meta($post_id, 'bw_h2_count', true),
            'Image Count' => (int) get_post_meta($post_id, 'bw_image_count', true),
            
            // Analyzed Date
            'Analysed Date' => ($analyzed = get_post_meta($post_id, '_bw_last_analyzed', true)) 
                ? substr($analyzed, 0, 10) // Extract YYYY-MM-DD
                : '',
            
            // SEO Fields (SEOPress/Yoast)
            'IndexTagSet' => get_post_meta($post_id, '_seopress_robots_index', true),
            'Canonical' => get_post_meta($post_id, '_seopress_robots_canonical', true),
            'MetaTitle' => get_post_meta($post_id, '_yoast_wpseo_title', true) 
                ?: get_post_meta($post_id, '_seopress_titles_title', true),
            'Meta Description' => get_post_meta($post_id, '_yoast_wpseo_metadesc', true)
                ?: get_post_meta($post_id, '_seopress_titles_desc', true),
            
            // SEO Fields (Brighter)
            'Short Link' => get_post_meta($post_id, 'scos_sa_shortlink_slug', true)
                         ?: get_post_meta($post_id, '_bw_breadcrumb', true), // TODO: remove bw_ fallback once all sites resaved
            'Breadcrumbs' => get_post_meta($post_id, 'bw_breadcrumb_schema', true),
            'Index Status' => get_post_meta($post_id, 'bw_index_status', true),
        );
        
        // Remove empty values to keep Airtable clean (allow empty array [] for Linked Record fields)
        $airtable_data = array_filter($airtable_data, function($value) {
            if ($value === '' || $value === null) return false;
            if ($value === 0 && !is_array($value)) return false;
            return true;
        });
        
        return $airtable_data;
    }

    /**
     * Sync CAR data to Airtable on post save
     *
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     * @param bool $update Whether this is an update
     */
    public static function sync_car_to_airtable($post_id, $post, $update) {
        // Skip autosave/revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        
        // Check if Airtable is configured
        if (!self::is_configured()) {
            return;
        }
        
        // Get post object if not provided
        if (!$post) {
            $post = get_post($post_id);
            if (!$post) return;
        }
        
        // Only sync published content
        if ($post->post_status !== 'publish') {
            return;
        }
        
        // Only sync specific post types
        $allowed_post_types = array('post', 'page', 'projects', 'folio', 'kb', 'news', 'faq');
        if (!in_array($post->post_type, $allowed_post_types, true)) {
            return;
        }
        
        // Rate limiting: Check last sync time (prevent rapid-fire updates)
        $last_sync = get_post_meta($post_id, '_airtable_last_sync', true);
        $now = time();
        if ($last_sync && ($now - $last_sync) < 5) {
            return; // Don't sync if updated within last 5 seconds
        }
        
        // Build CAR data
        $airtable_data = self::build_car_data($post_id, $post);
        
        // Get API configuration
        $api_token = self::get_api_token();
        $base_url = self::get_base_url();
        
        if (!$api_token || !$base_url) {
            error_log('[Airtable Sync] Configuration error: Missing API token or base URL');
            return;
        }
        
        // Check if Airtable record already exists for this post
        $airtable_record_id = get_post_meta($post_id, '_airtable_record_id', true);
        
        if ($airtable_record_id) {
            // Verify the record exists and we can access it
            $verify_url = $base_url . '/' . $airtable_record_id;
            $verify_response = wp_remote_get($verify_url, array(
                'headers' => array(
                    'Authorization' => $api_token,
                ),
                'timeout' => 15
            ));
            
            $verify_status = !is_wp_error($verify_response) ? wp_remote_retrieve_response_code($verify_response) : 0;
            
            if ($verify_status === 404 || $verify_status === 403) {
                // Record not found or no permission - clear stored ID and create new
                delete_post_meta($post_id, '_airtable_record_id');
                $airtable_record_id = false;
            }
        }
        
        if ($airtable_record_id) {
            // UPDATE existing record (PATCH)
            $patch_url = $base_url . '/' . $airtable_record_id;
            $response = wp_remote_request($patch_url, array(
                'method' => 'PATCH',
                'headers' => array(
                    'Authorization' => $api_token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode(array('fields' => $airtable_data, 'typecast' => true), JSON_UNESCAPED_SLASHES),
                'timeout' => 15
            ));
            
            // If record not found (deleted in Airtable), create new one
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 404) {
                delete_post_meta($post_id, '_airtable_record_id');
                $airtable_record_id = false;
            }
        }
        
        if (!$airtable_record_id) {
            // CREATE new record (POST)
            $response = wp_remote_post($base_url, array(
                'headers' => array(
                    'Authorization' => $api_token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode(array('fields' => $airtable_data, 'typecast' => true), JSON_UNESCAPED_SLASHES),
                'timeout' => 15
            ));
            
            // Store Airtable record ID for future updates
            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (isset($body['id'])) {
                    update_post_meta($post_id, '_airtable_record_id', $body['id']);
                }
            }
        }
        
        // Log errors only
        if (is_wp_error($response)) {
            error_log(sprintf('[Airtable Sync] ERROR for post %d (%s): %s', 
                $post_id, $post->post_type, $response->get_error_message()));
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            
            if ($status_code === 200 || $status_code === 201) {
                // Success - update last sync time
                update_post_meta($post_id, '_airtable_last_sync', time());
            } elseif ($status_code === 422) {
                // Validation error
                $body = wp_remote_retrieve_body($response);
                $error_data = json_decode($body, true);
                $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'Unknown validation error';
                error_log(sprintf('[Airtable Sync] VALIDATION ERROR for post %d (%s): %s', 
                    $post_id, $post->post_type, $error_message));
                // If "Record ID recXXX does not exist" - log which linked-record field likely triggered it
                if (preg_match('/Record ID (rec[a-zA-Z0-9]+) does not exist/i', $error_message, $m)) {
                    $invalid_rec_id = $m[1];
                    $linked_fields = array();
                    if (!empty($airtable_data['ALTC Cluster']) && is_array($airtable_data['ALTC Cluster'])) {
                        $linked_fields['ALTC Cluster'] = $airtable_data['ALTC Cluster'];
                    }
                    if (!empty($airtable_data['Topics']) && is_array($airtable_data['Topics'])) {
                        $linked_fields['Topics'] = $airtable_data['Topics'];
                    }
                    if (!empty($airtable_data['Internal Links']) && is_array($airtable_data['Internal Links'])) {
                        $linked_fields['Internal Links'] = $airtable_data['Internal Links'];
                    }
                    error_log(sprintf('[Airtable Sync] Linked-record fields sent for post %d: %s', 
                        $post_id, wp_json_encode($linked_fields)));
                    $source = self::find_rec_id_source($invalid_rec_id);
                    if ($source) {
                        error_log(sprintf('[Airtable Sync] Invalid rec ID %s likely from: %s', $invalid_rec_id, $source));
                    } else {
                        error_log(sprintf('[Airtable Sync] Invalid rec ID %s not found in post/term meta (may be stale - clear _airtable_record_id)', $invalid_rec_id));
                    }
                }
            } elseif ($status_code !== 200 && $status_code !== 201) {
                // Other errors
                $body = wp_remote_retrieve_body($response);
                error_log(sprintf('[Airtable Sync] API ERROR for post %d (%s): Status %d. Response: %s', 
                    $post_id, $post->post_type, $status_code, substr($body, 0, 200)));
            }
        }
    }

    /**
     * Find where an Airtable record ID is stored (post or term meta).
     * Used to diagnose "Record ID recXXX does not exist" validation errors.
     *
     * @param string $rec_id Airtable record ID (e.g. recFAYif2KQDWLN2r)
     * @return string|null Human-readable source e.g. "Post 123 (Internal Links target)" or "ALTC term 45"
     */
    private static function find_rec_id_source($rec_id) {
        global $wpdb;
        if (empty($rec_id) || strpos($rec_id, 'rec') !== 0) return null;
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_airtable_record_id' AND meta_value = %s LIMIT 1",
            $rec_id
        ));
        if ($post_id) {
            $post = get_post($post_id);
            $title = $post ? $post->post_title : 'post ' . $post_id;
            return sprintf('Post %d "%s" (Internal Links target - clear _airtable_record_id to re-sync)', $post_id, $title);
        }
        $term_id = $wpdb->get_var($wpdb->prepare(
            "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = '_airtable_record_id' AND meta_value = %s LIMIT 1",
            $rec_id
        ));
        if ($term_id) {
            $term = get_term($term_id);
            $tax = $term && !is_wp_error($term) ? $term->taxonomy : 'unknown';
            $name = $term && !is_wp_error($term) ? $term->name : 'term ' . $term_id;
            $field = ($tax === 'altc_strategic_lens') ? 'ALTC Cluster' : (($tax === 'altc_topic') ? 'Topics' : $tax);
            return sprintf('%s term %d "%s" (clear _airtable_record_id to re-sync)', $field, $term_id, $name);
        }
        return null;
    }

    /**
     * Bulk sync: ALTC terms to Airtable
     *
     * @return array ['synced' => int, 'errors' => int, 'log' => string[]]
     */
    public static function bulk_sync_altc_terms() {
        $table_id = get_option('bw_airtable_altc_table_id', '');
        if (empty($table_id) || !self::get_api_token()) {
            return array('synced' => 0, 'errors' => 0, 'log' => array('ALTC table not configured.'));
        }
        $terms = get_terms(array('taxonomy' => 'altc_strategic_lens', 'hide_empty' => false));
        $synced = 0;
        $errors = 0;
        $log = array();
        foreach ($terms as $term) {
            self::sync_altc_term_to_airtable($term->term_id);
            $rec_id = get_term_meta($term->term_id, '_airtable_record_id', true);
            if (!empty($rec_id)) {
                $synced++;
                $log[] = "ALTC: {$term->name}";
            } else {
                $errors++;
            }
            usleep(250000); // 250ms rate limit
        }
        return array('synced' => $synced, 'errors' => $errors, 'log' => $log);
    }

    /**
     * Bulk sync: Topic terms to Airtable
     *
     * @return array ['synced' => int, 'errors' => int, 'log' => string[]]
     */
    public static function bulk_sync_topics() {
        $table_id = get_option('bw_airtable_topics_table_id', '');
        if (empty($table_id) || !self::get_api_token()) {
            return array('synced' => 0, 'errors' => 0, 'log' => array('Topics table not configured.'));
        }
        $terms = get_terms(array('taxonomy' => 'altc_topic', 'hide_empty' => false));
        $synced = 0;
        $errors = 0;
        $log = array();
        foreach ($terms as $term) {
            self::sync_topic_term_to_airtable($term->term_id);
            $rec_id = get_term_meta($term->term_id, '_airtable_record_id', true);
            if (!empty($rec_id)) {
                $synced++;
                $log[] = "Topic: {$term->name}";
            } else {
                $errors++;
            }
            usleep(250000);
        }
        return array('synced' => $synced, 'errors' => $errors, 'log' => $log);
    }

    /**
     * Bulk sync: Content (phase 1 = static, phase 2 = internal links only)
     *
     * @param int $phase 1 = static data (no Internal Links), 2 = Internal Links only
     * @return array ['synced' => int, 'errors' => int, 'log' => string[]]
     */
    public static function bulk_sync_content($phase = 1) {
        if (!self::is_configured()) {
            return array('synced' => 0, 'errors' => 0, 'log' => array('Content table not configured.'));
        }
        $allowed = array('post', 'page', 'projects', 'folio', 'kb', 'news', 'faq');
        $posts = get_posts(array(
            'post_type' => $allowed,
            'post_status' => 'publish',
            'numberposts' => -1,
        ));
        $api_token = self::get_api_token();
        $base_url = self::get_base_url();
        $synced = 0;
        $errors = 0;
        $log = array();
        $exclude_internal_links = ($phase === 1);

        foreach ($posts as $post) {
            $post_id = $post->ID;
            $airtable_data = self::build_car_data($post_id, $post, $exclude_internal_links);
            $rec_id = get_post_meta($post_id, '_airtable_record_id', true);

            if ($phase === 2) {
                $airtable_data = array('Internal Links' => self::get_internal_links_as_airtable_ids($post_id));
                if (empty($rec_id)) continue; // Skip if no Airtable record yet
            }

            $airtable_data = array_filter($airtable_data, function($v) {
                return $v !== '' && $v !== null && $v !== 0;
            });

            if (empty($airtable_data) && $phase === 2) {
                $airtable_data = array('Internal Links' => array());
            }

            $body = array('fields' => $airtable_data, 'typecast' => true);

            if (!empty($rec_id)) {
                $response = wp_remote_request($base_url . '/' . $rec_id, array(
                    'method' => 'PATCH',
                    'headers' => array('Authorization' => $api_token, 'Content-Type' => 'application/json'),
                    'body' => json_encode($body, JSON_UNESCAPED_SLASHES),
                    'timeout' => 15
                ));
            } else {
                if ($phase === 2) continue;
                $response = wp_remote_post($base_url, array(
                    'headers' => array('Authorization' => $api_token, 'Content-Type' => 'application/json'),
                    'body' => json_encode($body, JSON_UNESCAPED_SLASHES),
                    'timeout' => 15
                ));
                if (!is_wp_error($response)) {
                    $resp = json_decode(wp_remote_retrieve_body($response), true);
                    if (isset($resp['id'])) {
                        update_post_meta($post_id, '_airtable_record_id', $resp['id']);
                    }
                }
            }

            $code = !is_wp_error($response) ? wp_remote_retrieve_response_code($response) : 0;
            if ($code === 200 || $code === 201) {
                $synced++;
                $log[] = ($phase === 1 ? 'Content' : 'Links') . ": {$post->post_title} (ID {$post_id})";
            } else {
                $errors++;
            }
            usleep(300000); // 300ms rate limit
        }

        return array('synced' => $synced, 'errors' => $errors, 'log' => $log);
    }
}
