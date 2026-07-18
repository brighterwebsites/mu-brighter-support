<?php
/**
 * Site Essentials Welcome Page
 *
 * v1.1 | 2026-05-19
 *
 * SCOS design system. Hero + quick stats + navigational module cards.
 * Cards only render for active (loaded) modules. Settings card always visible.
 *
 * @package    SiteEssentials
 * @subpackage Views
 */

defined( 'ABSPATH' ) || exit;

use SiteEssentials\Core\Module_Loader;

$loaded_modules    = Module_Loader::get_loaded_modules();
$available_modules = Module_Loader::get_available_modules();
$loaded_count      = count( $loaded_modules );
$available_count   = count( $available_modules );

$current_branch = 'Unknown';
if ( file_exists( SITE_ESSENTIALS_PATH . '../.git/HEAD' ) ) {
	$head = file_get_contents( SITE_ESSENTIALS_PATH . '../.git/HEAD' );
	if ( preg_match( '#ref: refs/heads/(.+)#', $head, $m ) ) {
		$current_branch = trim( $m[1] );
	}
}

$is_loaded = function( string $module_id ) use ( $loaded_modules ): bool {
	return array_key_exists( $module_id, $loaded_modules );
};

/* ── SVG icon library ─────────────────────────────────────────── */
$icons = [
	'seo' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h4m-2-2v4"/></svg>',
	'schema' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75 22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3-4.5 16.5"/></svg>',
	'business_info' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>',
	'tweaks' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z"/></svg>',
	'cpt' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6.878V6a2.25 2.25 0 0 1 2.25-2.25h7.5A2.25 2.25 0 0 1 18 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 0 0 4.5 9v.878m13.5-3A2.25 2.25 0 0 1 19.5 9v.878m0 0a2.246 2.246 0 0 0-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0 1 21 12v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6c0-.98.626-1.813 1.5-2.122"/></svg>',
	'analytics' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>',
	'social_amplification' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 1 8.835-2.535m0 0A23.74 23.74 0 0 1 18.795 3c1.167 0 2.315.078 3.44.233m-3.44 15.267a23.735 23.735 0 0 0 3.44.233c.474 0 .944-.015 1.41-.046M10.34 6.66l-.036.048"/></svg>',
	'content_architecture' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/></svg>',
	'settings' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 0 1 0-.255c.007-.378-.138-.75-.43-.991l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>',
];

/* ── Module card definitions ──────────────────────────────────── */
$cards = [
	[
		'module_id' => 'seo',
		'title'     => __( 'SEO & Meta', 'site-essentials' ),
		'desc'      => __( 'Sitemaps, archive SEO, redirections, robots.txt, LLMs.txt, and image SEO.', 'site-essentials' ),
		'url'       => admin_url( 'admin.php?page=site-essentials-seo' ),
		'guide'     => 'https://brighterwebsites.com.au/software/seo-module/',
		'icon'      => 'seo',
		'tier'      => 'basic',
	],
	[
		'module_id' => 'site_schema',
		'title'     => __( 'Schema', 'site-essentials' ),
		'desc'      => __( 'JSON-LD schema markup for Local Business, Products, Services, and Success Stories.', 'site-essentials' ),
		'url'       => admin_url( 'admin.php?page=site-essentials-schema' ),
		'guide'     => 'https://brighterwebsites.com.au/software/schema/',
		'icon'      => 'schema',
		'tier'      => 'basic',
	],
	[
		'module_id' => 'business_info',
		'title'     => __( 'Business Info', 'site-essentials' ),
		'desc'      => __( 'Central store for business name, contact details, address, and privacy policy data.', 'site-essentials' ),
		'url'       => admin_url( 'admin.php?page=site-essentials-business-info' ),
		'guide'     => 'https://brighterwebsites.com.au/software/business-info/',
		'icon'      => 'business_info',
		'tier'      => 'basic',
	],
	[
		'module_id' => 'tweaks',
		'title'     => __( 'Performance', 'site-essentials' ),
		'desc'      => __( 'WordPress core tweaks, image optimisation, script management, and asset preloading.', 'site-essentials' ),
		'url'       => admin_url( 'admin.php?page=site-essentials-essentials' ),
		'guide'     => 'https://brighterwebsites.com.au/software/performance/',
		'icon'      => 'tweaks',
		'tier'      => 'basic',
	],
	[
		'module_id' => 'cpt',
		'title'     => __( 'Custom Posts', 'site-essentials' ),
		'desc'      => __( 'SCOS-recommended CPTs with extended field sets to power your authority content.', 'site-essentials' ),
		'url'       => admin_url( 'admin.php?page=site-essentials-cpt' ),
		'guide'     => 'https://brighterwebsites.com.au/software/custom-posts/',
		'icon'      => 'cpt',
		'tier'      => 'basic',
		'sub_tags'  => [ 'Reviews', 'FAQ', 'Success Stories', 'Authors' ],
	],
	[
		'module_id' => 'analytics',
		'title'     => __( 'Analytics', 'site-essentials' ),
		'desc'      => __( 'GA4 event tracking, custom dimensions, and measurement configuration.', 'site-essentials' ),
		'url'       => admin_url( 'admin.php?page=site-essentials-analytics' ),
		'guide'     => 'https://brighterwebsites.com.au/software/analytics/',
		'icon'      => 'analytics',
		'tier'      => 'basic',
	],
	[
		'module_id' => 'social_amplification',
		'title'     => __( 'Social Amplification', 'site-essentials' ),
		'desc'      => __( 'AI-generated captions, talking points, short links, and social post scheduling.', 'site-essentials' ),
		'url'       => admin_url( 'admin.php?page=site-essentials-social-amplification' ),
		'guide'     => 'https://brighterwebsites.com.au/software/social-amplification/',
		'icon'      => 'social_amplification',
		'tier'      => 'pro',
	],
	[
		'module_id' => 'content_architecture',
		'title'     => __( 'Content Architecture', 'site-essentials' ),
		'desc'      => __( 'ALTC topic clusters, content scoring, and metadata management.', 'site-essentials' ),
		'url'       => admin_url( 'admin.php?page=scos-content-architecture' ),
		'guide'     => 'https://brighterwebsites.com.au/software/content-architecture/',
		'icon'      => 'content_architecture',
		'tier'      => 'pro',
	],
	[
		'module_id' => null,
		'title'     => __( 'Settings', 'site-essentials' ),
		'desc'      => __( 'Enable or disable modules, manage API keys, and import or export settings.', 'site-essentials' ),
		'url'       => admin_url( 'admin.php?page=site-essentials-settings' ),
		'guide'     => null,
		'icon'      => 'settings',
		'tier'      => 'basic',
	],
];

$active_cards = array_filter( $cards, function( $card ) use ( $is_loaded ) {
	// Settings card always shown; all others require the module to be loaded
	return $card['module_id'] === null || $is_loaded( $card['module_id'] );
} );
?>

<style>
/* ── Welcome hero ───────────────────────────────────── */
.scos-welcome__hero {
  background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
  color: #fff;
  padding: var(--scos-s-10) var(--scos-s-8);
  border-radius: var(--scos-r-lg);
  margin-bottom: var(--scos-s-6);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--scos-s-6);
  flex-wrap: wrap;
}
.scos-welcome__hero-text h1 {
  color: #fff;
  font-size: 28px;
  font-weight: 700;
  margin: 0 0 var(--scos-s-2);
  line-height: 1.2;
}
.scos-welcome__hero-text p {
  color: rgba(255,255,255,.8);
  font-size: var(--scos-fs-lg);
  margin: 0;
}
.scos-welcome__stats {
  display: flex;
  gap: var(--scos-s-3);
  flex-shrink: 0;
  flex-wrap: wrap;
}
.scos-welcome__stat {
  background: rgba(255,255,255,.15);
  border: 1px solid rgba(255,255,255,.25);
  border-radius: var(--scos-r-lg);
  padding: var(--scos-s-2) var(--scos-s-4);
  text-align: center;
  min-width: 80px;
}
.scos-welcome__stat-num {
  display: block;
  font-size: 26px;
  font-weight: 700;
  color: #fff;
  line-height: 1.1;
}
.scos-welcome__stat-label {
  display: block;
  font-size: 11px;
  color: rgba(255,255,255,.75);
  text-transform: uppercase;
  letter-spacing: .05em;
  margin-top: 2px;
}

/* ── Module card grid ───────────────────────────────── */
.scos-welcome__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: var(--scos-s-4);
}
.scos-welcome__card {
  background: var(--scos-surface);
  border: 1px solid var(--scos-border);
  border-radius: var(--scos-r-lg);
  padding: var(--scos-s-5);
  display: flex;
  flex-direction: column;
  gap: var(--scos-s-3);
  text-decoration: none;
  color: inherit;
  transition: border-color .15s, box-shadow .15s, transform .12s;
}
.scos-welcome__card:hover {
  border-color: var(--scos-accent-border);
  box-shadow: 0 4px 14px rgba(79,70,229,.1);
  transform: translateY(-1px);
  text-decoration: none;
  color: inherit;
}
.scos-welcome__card--pro {
  border-color: #fed7aa; /* orange-200 */
  border-top: 3px solid var(--scos-tier-pro);
}
.scos-welcome__card--pro:hover {
  border-color: #fb923c;
  box-shadow: 0 4px 14px rgba(234,88,12,.1);
}
.scos-welcome__icon {
  width: 44px;
  height: 44px;
  border-radius: var(--scos-r-md);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.scos-welcome__icon svg {
  width: 22px;
  height: 22px;
}
.scos-welcome__icon--basic { background: #dbeafe; color: #1d4ed8; }
.scos-welcome__icon--pro   { background: #ffedd5; color: #ea580c; }
.scos-welcome__title {
  font-size: var(--scos-fs-xl);
  font-weight: 600;
  color: var(--scos-ink);
  margin: 0;
  line-height: 1.3;
}
.scos-welcome__desc {
  font-size: var(--scos-fs-md);
  color: var(--scos-ink-muted);
  margin: 0;
  line-height: 1.5;
  flex: 1;
}
.scos-welcome__sub-tags {
  display: flex;
  flex-wrap: wrap;
  gap: var(--scos-s-1);
}
.scos-welcome__sub-tag {
  font-size: 11px;
  font-weight: 500;
  background: var(--scos-accent-soft);
  color: var(--scos-accent-ink);
  border-radius: var(--scos-r-md);
  padding: 2px 8px;
}
.scos-welcome__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: auto;
  padding-top: var(--scos-s-2);
  border-top: 1px solid var(--scos-divider);
}
.scos-welcome__cta {
  font-size: var(--scos-fs-md);
  font-weight: 600;
  color: var(--scos-accent);
}
.scos-welcome__card:hover .scos-welcome__cta { text-decoration: underline; }
.scos-welcome__guide {
  font-size: var(--scos-fs-sm);
  color: var(--scos-ink-subtle);
}
.scos-welcome__guide:hover { color: var(--scos-accent); }
</style>

<div class="wrap scos">

	<!-- ── Hero ─────────────────────────────────────────────────── -->
	<div class="scos-welcome__hero">
		<div class="scos-welcome__hero-text">
			<h1><?php esc_html_e( 'Site Essentials', 'site-essentials' ); ?></h1>
			<p>
				v<?php echo esc_html( SITE_ESSENTIALS_VERSION ); ?>
				<?php if ( 'Unknown' !== $current_branch ) : ?>
					&nbsp;·&nbsp; <code style="background:rgba(255,255,255,.15);padding:1px 6px;border-radius:4px;font-size:12px"><?php echo esc_html( $current_branch ); ?></code>
				<?php endif; ?>
				&nbsp;·&nbsp; <?php esc_html_e( 'Strategic Content Operating System', 'site-essentials' ); ?>
			</p>
		</div>
		<div class="scos-welcome__stats">
			<div class="scos-welcome__stat">
				<span class="scos-welcome__stat-num"><?php echo esc_html( $loaded_count ); ?></span>
				<span class="scos-welcome__stat-label"><?php esc_html_e( 'Loaded', 'site-essentials' ); ?></span>
			</div>
			<div class="scos-welcome__stat">
				<span class="scos-welcome__stat-num"><?php echo esc_html( $available_count ); ?></span>
				<span class="scos-welcome__stat-label"><?php esc_html_e( 'Available', 'site-essentials' ); ?></span>
			</div>
		</div>
	</div>

	<!-- ── Module cards ─────────────────────────────────────────── -->
	<div class="scos-welcome__grid">

		<?php foreach ( $active_cards as $card ) :
			$is_pro     = 'pro' === $card['tier'];
			$icon_class = $is_pro ? 'scos-welcome__icon--pro' : 'scos-welcome__icon--basic';
			$card_class = 'scos-welcome__card' . ( $is_pro ? ' scos-welcome__card--pro' : '' );
		?>
		<a href="<?php echo esc_url( $card['url'] ); ?>" class="<?php echo esc_attr( $card_class ); ?>">

			<div style="display:flex;align-items:center;gap:var(--scos-s-3)">
				<div class="scos-welcome__icon <?php echo esc_attr( $icon_class ); ?>">
					<?php echo $icons[ $card['icon'] ] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
				<div>
					<h3 class="scos-welcome__title"><?php echo esc_html( $card['title'] ); ?></h3>
					<?php if ( $is_pro ) : ?>
						<span class="scos-badge scos-badge--pro" style="margin-top:4px;display:inline-block">PRO</span>
					<?php endif; ?>
				</div>
			</div>

			<p class="scos-welcome__desc"><?php echo esc_html( $card['desc'] ); ?></p>

			<?php if ( ! empty( $card['sub_tags'] ) ) : ?>
				<div class="scos-welcome__sub-tags">
					<?php foreach ( $card['sub_tags'] as $tag ) : ?>
						<span class="scos-welcome__sub-tag"><?php echo esc_html( $tag ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="scos-welcome__foot">
				<span class="scos-welcome__cta"><?php esc_html_e( 'Configure', 'site-essentials' ); ?> →</span>
				<?php if ( ! empty( $card['guide'] ) ) : ?>
					<span class="scos-welcome__guide" onclick="event.preventDefault();window.open('<?php echo esc_js( $card['guide'] ); ?>','_blank')">
						<?php esc_html_e( 'Guide ↗', 'site-essentials' ); ?>
					</span>
				<?php endif; ?>
			</div>

		</a>
		<?php endforeach; ?>

	</div>

</div>
