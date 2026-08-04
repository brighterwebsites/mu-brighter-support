<?php
/**
 * SCOS agency / support options: se_agency_* and se_support_* with legacy fallbacks.
 *
 * @package BrighterCore
 * @version 1.1
 *
 * v1.1 | 2026-08-02 — Third-party head scripts removed from this file. Storage and
 *                     wp_head output now belong to SiteEssentials\Core\Support_Scripts,
 *                     which is the single owner of se_support_script_* .
 * v1.0 | 2026-08-02 — Version header added retrospectively.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logical keys for support hub URLs and scripts (se_support_* + legacy options).
 *
 * @return array<string, array{0:string,1:?string}>
 */
function scos_se_support_option_map() {
	return [
		'manual_full'             => [ 'se_support_manual_full', 'manual_full_link' ],
		'manual_quick'            => [ 'se_support_manual_quick', 'manual_quick_link' ],
		'website_ranking'         => [ 'se_support_website_ranking', 'website_ranking_link' ],
		'map_ranking'             => [ 'se_support_map_ranking', 'map_ranking_link' ],
		'ai_content'              => [ 'se_support_ai_content', 'ai_content_writing' ],
		'ai_research'             => [ 'se_support_ai_research', 'ai_research' ],
		'ai_social'               => [ 'se_support_ai_social', 'ai_social_media' ],
		'ai_competitor'           => [ 'se_support_ai_competitor', 'ai_competitor_research' ],
		'management_portal'       => [ 'se_support_management_portal', 'management_portal' ],
		'landing_html'            => [ 'se_support_landing_html', null ],
	];
}

/**
 * Get support hub option: prefers se_support_*, then legacy wp_options key.
 *
 * @param string $logical_key Key from scos_se_support_option_map().
 * @param string $default     Default when all empty.
 * @return string
 */
function scos_se_support_get( $logical_key, $default = '' ) {
	$map = scos_se_support_option_map();
	if ( ! isset( $map[ $logical_key ] ) ) {
		return $default;
	}
	list( $new_key, $legacy_key ) = $map[ $logical_key ];
	$v = get_option( $new_key, '' );
	if ( is_string( $v ) && $v !== '' ) {
		return $v;
	}
	if ( null !== $legacy_key ) {
		$v = get_option( $legacy_key, '' );
		if ( is_string( $v ) && $v !== '' ) {
			return $v;
		}
	}
	return $default;
}

/**
 * Agency / white-label option defaults (when DB empty).
 *
 * @return array<string, string>
 */
function scos_se_agency_defaults() {
	return [
		'name'                  => 'Brighter Websites',
		'contact'               => 'Vanessa Wood',
		'url'                   => 'https://brighterwebsites.com.au/',
		'email'                 => 'support@brighterwebsites.com.au',
		'phone'                 => '',
		'location'              => 'Ballarat, Australia',
		'meta_designer'         => 'Brighter Websites',
		'meta_web_author'       => 'Vanessa Wood',
		'meta_generator'        => 'Brighter Websites SCOS + ALTC Framework v2.0',
		'credit_prefix'         => 'Proudly Built by',
		'credit_anchor'         => 'Brighter Websites',
		'credit_utm'            => '',
		'credit_target'         => '_blank',
		'credit_rel'            => 'noopener noreferrer',
		'humans_txt'            => '',
		'login_redirect_admin'  => '',
		'login_redirect_editor' => '',
		'staff_domains'         => '',
	];
}

/**
 * Map logical key to se_agency_* option name.
 *
 * @param string $key Logical key.
 * @return string|null
 */
function scos_se_agency_option_name( $key ) {
	$m = [
		'name'                  => 'se_agency_name',
		'contact'               => 'se_agency_contact',
		'url'                   => 'se_agency_url',
		'email'                 => 'se_agency_email',
		'phone'                 => 'se_agency_phone',
		'logo_id'               => 'se_agency_logo_id',
		'location'              => 'se_agency_location',
		'meta_designer'         => 'se_agency_meta_designer',
		'meta_web_author'       => 'se_agency_meta_web_author',
		'meta_generator'        => 'se_agency_meta_generator',
		'credit_prefix'         => 'se_agency_credit_prefix',
		'credit_anchor'         => 'se_agency_credit_anchor',
		'credit_utm'            => 'se_agency_credit_utm',
		'credit_target'         => 'se_agency_credit_target',
		'credit_rel'            => 'se_agency_credit_rel',
		'humans_txt'            => 'se_agency_humans_txt',
		'login_redirect_admin'  => 'se_agency_login_redirect_admin',
		'login_redirect_editor' => 'se_agency_login_redirect_editor',
		'staff_domains'         => 'se_agency_staff_domains',
	];
	return isset( $m[ $key ] ) ? $m[ $key ] : null;
}

/**
 * Get agency option with defaults for empty values (strings).
 *
 * @param string $key Logical key (name, contact, url, …).
 * @return string|int For logo_id returns int.
 */
function scos_se_agency_get( $key ) {
	$defaults = scos_se_agency_defaults();
	$opt      = scos_se_agency_option_name( $key );
	if ( ! $opt ) {
		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}
	$raw = get_option( $opt, null );
	if ( 'logo_id' === $key ) {
		if ( null === $raw || false === $raw || '' === $raw ) {
			return 0;
		}
		return absint( $raw );
	}
	if ( is_string( $raw ) && $raw !== '' ) {
		return $raw;
	}
	return isset( $defaults[ $key ] ) ? (string) $defaults[ $key ] : '';
}

/**
 * Whether the user's email host is considered staff (agency) per allowlist or legacy rule.
 *
 * @param \WP_User $user User object.
 * @return bool
 */
function scos_agency_user_is_staff( $user ) {
	if ( ! $user instanceof \WP_User || ! $user->ID ) {
		return false;
	}
	$email = strtolower( (string) $user->user_email );
	if ( ! is_email( $email ) ) {
		return false;
	}
	$host = '';
	$at   = strrpos( $email, '@' );
	if ( false !== $at ) {
		$host = substr( $email, $at + 1 );
	}
	if ( $host === '' ) {
		return false;
	}
	$list_raw = trim( (string) get_option( 'se_agency_staff_domains', '' ) );
	if ( $list_raw === '' ) {
		return (bool) preg_match( '/@brighterwebsites\.com\.au$/i', $user->user_email );
	}
	$parts = array_filter( array_map( 'trim', explode( ',', $list_raw ) ) );
	foreach ( $parts as $domain ) {
		$domain = strtolower( $domain );
		$domain = ltrim( $domain, '@' );
		if ( $domain !== '' && $host === $domain ) {
			return true;
		}
	}
	return false;
}

/**
 * User may edit agency-setup (Site Essentials) and legacy agency areas: manage_options + staff email.
 *
 * @param \WP_User $user User.
 * @return bool
 */
function scos_agency_user_can_manage_agency_setup( $user ) {
	if ( ! $user instanceof \WP_User || ! $user->ID ) {
		return false;
	}
	if ( ! user_can( $user, 'manage_options' ) ) {
		return false;
	}
	return scos_agency_user_is_staff( $user );
}

/**
 * One-time copy legacy support options to se_support_*.
 */
function scos_se_support_maybe_migrate_legacy() {
	if ( get_option( 'se_support_legacy_migrated', '' ) === '1' ) {
		return;
	}
	$map = scos_se_support_option_map();
	foreach ( $map as $pair ) {
		list( $new_key, $legacy_key ) = $pair;
		if ( null === $legacy_key ) {
			continue;
		}
		$cur = get_option( $new_key, '' );
		if ( is_string( $cur ) && $cur !== '' ) {
			continue;
		}
		$old = get_option( $legacy_key, '' );
		if ( is_string( $old ) && $old !== '' ) {
			update_option( $new_key, $old );
		}
	}
	update_option( 'se_support_legacy_migrated', '1' );
}

add_action( 'admin_init', 'scos_se_support_maybe_migrate_legacy', 5 );

/**
 * Sanitize admin redirect target (must stay in wp-admin).
 *
 * @param string $url Raw URL.
 * @return string
 */
function scos_agency_sanitize_login_redirect( $url ) {
	$url = trim( (string) $url );
	if ( $url === '' ) {
		return '';
	}
	$url = esc_url_raw( $url );
	return wp_validate_redirect( $url, '' );
}

/**
 * Current user matches staff domain allowlist (or legacy @brighterwebsites.com.au when list empty).
 *
 * @return bool
 */
function brighter_support_is_agency_user() {
	$user = wp_get_current_user();
	return scos_agency_user_is_staff( $user );
}

/**
 * Credit link URL: optional se_agency_credit_utm query string, else default UTM params.
 *
 * @return string Escaped URL.
 */
function scos_agency_build_credit_url() {
	$base = esc_url_raw( trailingslashit( rtrim( scos_se_agency_get( 'url' ), '/' ) ) );
	$utm  = trim( scos_se_agency_get( 'credit_utm' ) );
	if ( $utm !== '' ) {
		$join = strpos( $base, '?' ) !== false ? '&' : '?';
		return esc_url( $base . $join . ltrim( $utm, '?&' ) );
	}
	return esc_url(
		add_query_arg(
			[
				'utm_source'   => sanitize_title( get_bloginfo( 'name' ) ),
				'utm_medium'   => 'footer',
				'utm_campaign' => 'site-credit',
			],
			$base
		)
	);
}
