# CLAUDE.md — mu-brighter-support-main (SCOS / Site Essentials)

This file is the standing instruction set for all development work on this codebase.
Read it fully before touching any file. All rules apply to every task unless explicitly overridden in the task prompt.

---

## 1. Ways of Working

### Clarify Before Building

Before starting any new functionality or major expansion:

- Do not assume — ask questions
- Get baseline requirements clear before writing code
- Confirm the use case is understood and the expected outcome is agreed

**Ask before building:**
- "What's the primary use case for this feature?"
- "Should this work for all content types or just specific ones?"
- "Are there any constraints I should know about?"
- "What's the expected user workflow?"

---

### Debug Threshold — Stop After 3–4 Attempts

If something is broken after 3–4 attempts using similar approaches, **stop and debug properly**. Do not keep varying the same fix.

**Debugging steps in order:**
1. Add `error_log()` to verify what data actually exists
2. Check PHP error logs (`/home/[domain]/logs/` on CyberPanel/LiteSpeed)
3. Enable `WP_DEBUG` and `WP_DEBUG_LOG` if not already on
4. Use Query Monitor to profile queries and hook order
5. Check browser console for JS errors
6. Confirm data is actually being saved — don't assume
7. Consider whether the approach itself needs changing, not just the implementation

---

### MCP-First Architecture

All new features must be built with MCP/Claude CLI compatibility in mind. Claude CLI + WP-CLI connected MCPs are active.

**Principles:**
- Separate business logic from interface layer
- Make classes reusable across REST API, WP-CLI, and MCP tool calls
- No duplication — one source of truth for logic
- When designing a feature, ask: "Could an AI agent call this directly?"

```php
// ✅ Core logic in its own class — reusable everywhere
class Content_Analyzer {
    public function analyze( $post_id ) {
        return $analysis;
    }
}

// REST API uses it
class Analysis_API {
    public function endpoint_analyze() {
        $analyzer = new Content_Analyzer();
        return $analyzer->analyze( $_POST['post_id'] );
    }
}

// WP-CLI / MCP tool uses the same logic — no duplication
class Analysis_MCP_Tool {
    public function tool_analyze_content( $params ) {
        $analyzer = new Content_Analyzer();
        return $analyzer->analyze( $params['post_id'] );
    }
}
```

---

### Versioning

**Minor increment (e.g. 1.0 → 1.1):** when >10 lines changed, or any structural/logic change.

**Major increment (e.g. 1.x → 2.0):** only after a full Plan → Build → Test cycle completes. All success criteria confirmed passing before bumping.

**If no version header exists:** add `// v1.0 | YYYY-MM-DD` on first edit.

**Format:** `v1.0`, `v1.1`, `v1.12` (top of file comment block). Update the date on every increment.

---

## 2. Code Standards

**PHP Namespace:** `SiteEssentials\`
**One class per file. File name must match class name.**

### Naming Conventions

| Type | Convention | Example |
|------|-----------|---------|
| Classes | `PascalCase` | `Content_Analyzer` |
| Methods | `snake_case()` | `get_post_meta()` |
| Properties | `$snake_case` | `$post_id` |
| Constants | `SCREAMING_SNAKE_CASE` | `SE_VERSION` |
| Files | Match class name | `Content_Analyzer.php` |

---

### Hook Registration — Always Named Callbacks

```php
// ✅ Named class method — can be removed later
add_action( 'save_post', [ $this, 'on_save_post' ], 10, 3 );
add_action( 'init', [ self::class, 'register_post_type' ] );

// ❌ Anonymous function — cannot be removed, never use
add_action( 'init', function() { ... } );
```

Hook priority defaults to 10 unless there is a specific reason. If using a non-default priority, add a comment explaining why.

---

### Database Queries — WP Methods, Not Direct SQL

```php
// ✅
$posts = get_posts( [
    'post_type'      => 'post',
    'posts_per_page' => 10,
    'no_found_rows'  => true,
] );

// ❌ Avoid direct SQL unless absolutely necessary
$posts = $wpdb->get_results( "SELECT * FROM {$wpdb->posts} ..." );
```

---

### Post Meta — Always Batch-Load

Never load meta individually inside a loop.

```php
// ✅ 1 query for all meta
$post_ids = wp_list_pluck( $posts, 'ID' );
update_meta_cache( 'post', $post_ids );
foreach ( $posts as $post ) {
    $value = get_post_meta( $post->ID, 'scos_seo_title', true ); // from cache
}

// ❌ N+1 — hits DB on every iteration
foreach ( $posts as $post ) {
    $value = get_post_meta( $post->ID, 'scos_seo_title', true );
}
```

---

### Performance Benchmarks

| Metric | Limit |
|--------|-------|
| Admin page load | < 500ms |
| Frontend overhead | < 50ms |
| Additional queries per page | < 5 |
| Memory increase | < 10MB |

Use `Cache_Helper::remember()` for any expensive or repeated operation:

```php
$data = Cache_Helper::remember( 'unique_cache_key', function() {
    return expensive_database_query();
}, 3600, 'module_name' );

// Invalidate on data change
add_action( 'save_post', function( $post_id ) {
    Cache_Helper::flush( 'module_name' );
} );
```

---

### Module Interface

Every module must implement `Module_Interface`:

```php
// Static — module metadata
public static function get_id();
public static function get_name();
public static function get_description();
public static function get_tier();           // basic | pro | agency
public static function get_dependencies();   // array of module IDs
public static function get_version();

// Instance — lifecycle
public function init();
public function render_settings();
```

**Module directory structure:**
```
modules/
└── {module-name}/
    ├── {Module_Name}_Module.php
    ├── class-{feature}.php
    ├── views/
    │   └── settings.php
    └── assets/
        ├── css/
        └── js/
```

---

## 3. Meta Key & Option Naming

Canonical reference: `mu-brighter-support-main/Naming-conventions.md` — that doc wins if there is a conflict. Update both together.

### Post Meta Prefixes

| Prefix | Scope | Example |
|--------|-------|---------|
| `scos_seo_` | SEO module | `scos_seo_focus_keyword` |
| `scos_ca_` | Content Architecture module | `scos_ca_topic` |
| `scos_sa_` | Social Amplification module | `scos_sa_generated_caption` |
| `scos_cpt_` | CPT customisation | `scos_cpt_icon` |
| `se_` | Site-wide / shared across modules | `se_anthropic_api_key` |

### Options Prefixes

| Prefix | Scope | Example |
|--------|-------|---------|
| `site_essentials_` | Plugin-level config, version, flags | `site_essentials_version` |
| `se_` | Site-wide user-configurable settings | `se_agency_name` |
| `scos_` | SCOS module-level settings | `scos_sa_default_tone` |

### Rules

- ALL new meta and option keys MUST follow the prefix table above
- NEVER create new keys with `bw_` prefix — deprecated
- If you encounter `bw_` keys in existing code, flag with `// TODO: migrate to scos_ or se_` — do NOT auto-rename
- Format: always lowercase, words separated by underscores
- Transients: `se_[name]`
- ACF field keys: `field_scos_[module]_[fieldname]`
- If a setting will clearly be shared across modules (e.g. API keys), use `se_` from the start

---

## 4. Refactor-First — brighter-core → site-essentials

**Before writing a single line of new code in `brighter-core`, run this assessment.**

### Step 1 — Classify the task

- **Bug fix in existing brighter-core logic?** → Fix in place. No migration needed.
- **New feature, new setting, new UI, or new integration?** → Go to Step 2.
- **Modification or extension of existing brighter-core functionality?** → Go to Step 2.

### Step 2 — Migration cost assessment

| Scenario | Estimate |
|---|---|
| A. Add to brighter-core | Time to add it where it lives now |
| B. Add to site-essentials | Time to add it correctly in site-essentials |

**If B ≤ A + 20%: build in site-essentials. Do not add to brighter-core.**

If B > A + 20%: build in brighter-core and add:
```php
// TODO: migrate to site-essentials — [brief reason cost was high]
```

### Step 3 — site-essentials module placement

| Capability | Module |
|---|---|
| SEO meta, canonical, robots, OG, sitemaps | `SEO` |
| Content metadata, ALTC, cluster assignment | `ContentArchitecture` |
| Reviews, Projects, Services CPTs and fields | `CustomPosts` |
| Social posting, talking points, short links | `SocialAmplification` |
| GA4 tracking, custom events, dimensions | `Analytics` |
| JSON-LD schema output | `Schema` |
| Business name, address, contact fields | `BusinessInfo` |
| Performance tweaks, security, WP cleanup | `WordPressTweaks` |
| Agency identity, support hub, redirects | `Agency` (site-essentials core) |

**File and folder conventions:**
- Module folder: `site-essentials/Modules/[ModuleName]/`
- Main class: `[ModuleName].php`
- Admin page: `[ModuleName]AdminPage.php` or `views/[module-name].php`
- Shared assets: `site-essentials/assets/`
- Module assets: `Modules/[ModuleName]/assets/`

### Step 4 — Admin UI (if applicable)

If the task includes any admin UI, follow Section 5 of this file in full.

### Step 5 — Multi-site safety check

SCOS deploys across multiple client sites from a shared MU plugin. Before implementing:

- [ ] Will this change affect all sites it's deployed on?
- [ ] Is it safe to deploy broadly, or does it need a per-site config gate?
- [ ] If it touches DB schema, CPT registration, or hook priorities — is it backward compatible?
- [ ] Does it require a `site_essentials_version` bump?

If any of these introduce risk: add a module enable/disable toggle or site-level config option before deploying.

---

## 5. Admin UI — SCOS Design System

Canonical references (read before generating any admin UI):
- `cursor-handoff/SPEC.md` — page templates, menu structure, do's/don'ts (wins over this file if conflict)
- `cursor-handoff/tokens.css` — all design tokens
- `cursor-handoff/scos-ui.css` — all component styles
- `cursor-handoff/snippets.html` — copy-paste markup for every component

### Mandatory: Page Wrapper

Every admin page body MUST open with:
```html
<div class="wrap scos">
```
Both classes required. No exceptions.

### Mandatory: Asset Enqueue

```php
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( strpos( $hook, 'site-essentials' ) === false ) return;
    $base = plugin_dir_url( __FILE__ ) . 'assets/';
    wp_enqueue_style( 'scos-tokens', $base . 'tokens.css', [],             '1.0.0' );
    wp_enqueue_style( 'scos-ui',     $base . 'scos-ui.css', ['scos-tokens'], '1.0.0' );
} );
```

`scos-tokens` must be a dependency of `scos-ui`. Never enqueue `scos-ui` alone.

### Five Canonical Page Templates — Pick One, Don't Invent

| Template | Used by |
|---|---|
| A. Tabbed settings page | Agency, Import/Export, API Keys |
| B. Modules grid | Site Essentials › Modules (default page) |
| C. Single-section settings | Small/single-purpose settings pages |
| D. Support landing (hero) | Support page only |
| E. Meta box | Post editor sidebars |

### Tokens — Never Raw Values

| ❌ Don't | ✅ Do |
|---|---|
| `color: #4f46e5` | `color: var(--scos-accent)` |
| `padding: 16px` | `padding: var(--scos-s-4)` |
| `font-size: 14px` | `font-size: var(--scos-fs-lg)` |
| `border-radius: 8px` | `border-radius: var(--scos-r-lg)` |
| `background: #eef2ff` | `background: var(--scos-accent-soft)` |

If a token doesn't exist for what you need, add it to `tokens.css` first.

### Page Header Pattern

```html
<header class="scos__header">
  <div>
    <h1 class="scos__title"><?php esc_html_e( 'Page title', 'scos' ); ?></h1>
    <p class="scos__subtitle">Site Essentials › Subsection</p>
  </div>
  <div class="scos__header-actions">
    <button type="submit" class="scos-btn scos-btn--primary">Save changes</button>
  </div>
</header>
```

One primary button per card footer. One primary button per page header. Never two `.scos-btn--primary` in the same card.

### Form Rows — Option Key Must Be Visible

```html
<table class="scos-form">
  <tbody>
    <tr>
      <th>
        <label for="se_agency_name">Agency name</label>
        <div class="scos-form__slug">se_agency_name</div>
      </th>
      <td>
        <input id="se_agency_name" name="se_agency_name" type="text" class="scos-input"
               value="<?php echo esc_attr( get_option('se_agency_name') ); ?>">
        <p class="description">Shown in client dashboards and the Support hub.</p>
      </td>
    </tr>
  </tbody>
</table>
```

`<label for>`, input `id`, and input `name` MUST all equal the option key.

### Security — Non-Negotiable on Every Admin Page

```php
// Capability check at the top of every render function
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'Insufficient permissions.', 'scos' ) );
}

// Nonce in every form
wp_nonce_field( 'scos_save_settings', 'scos_nonce' );

// Verify before processing
if ( ! isset( $_POST['scos_nonce'] ) || ! wp_verify_nonce( $_POST['scos_nonce'], 'scos_save_settings' ) ) {
    wp_die( 'Nonce check failed.' );
}

// Sanitize before saving, escape before output
update_option( 'se_agency_name', sanitize_text_field( $_POST['se_agency_name'] ) );
echo esc_html( get_option( 'se_agency_name' ) );
```

### Hard Don'ts

- ❌ Never use WP core `.button-primary` — use `.scos-btn.scos-btn--primary`
- ❌ Never nest `.scos-card` inside `.scos-card`
- ❌ Never use Bootstrap, Tailwind, or any third-party CSS framework in admin pages
- ❌ Never add inline `style=""` for color, spacing, or typography — use a class
- ❌ Never add emoji as functional icons in production
- ❌ Never enqueue `scos-ui.css` without `scos-tokens.css` as a dependency

### New Admin Page Checklist

Before considering any admin page complete:

- [ ] Wrapped in `<div class="wrap scos">`
- [ ] Uses one of the five canonical templates
- [ ] Page header follows the pattern above
- [ ] All inputs: `id` = `name` = option key; key shown via `.scos-form__slug`
- [ ] Option keys use correct prefix (Section 3)
- [ ] No raw colors, spacing, or font sizes — all via tokens
- [ ] `current_user_can()` check at top of render function
- [ ] `wp_nonce_field()` in form, `wp_verify_nonce()` before processing
- [ ] `sanitize_*()` before saving, `esc_*()` before output
- [ ] CSS enqueued only on SCOS pages, `scos-tokens` as dependency of `scos-ui`
- [ ] One primary button per card footer max
- [ ] Markup matched to a pattern in `snippets.html`
