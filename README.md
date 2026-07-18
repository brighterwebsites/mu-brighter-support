# Strategic Content Operating System (SCOS)

WordPress MU plugin stack that implements the [Website-First Blueprint](https://github.com/brighterwebsites/brighter-frameworks-docs) and [ALTC](https://brighterwebsites.com.au/altc-framework/) at the infrastructure level — content strategy, SEO, schema, evidence relationships, analytics, and amplification built into how the site runs.

**Repository:** `wp-scos-strategic-content-operating-system`  
**Status:** Production — deployed across managed client sites  
**Components:** `site-essentials/` (modular, current) + `brighter-core/` (legacy, migrating)

---

## Documentation

**Primary documentation (module guides, overview, stack):**  
👉 **[brighterwebsites.com.au/software/scos/](https://brighterwebsites.com.au/software/scos/)**

Per-module pages are being published there (SEO, Content Architecture, Custom Posts, Social Amplification, Analytics, Schema, Business Info, WordPress Tweaks).

**Framework concepts (ALTC, WFB, terminology):**  
[brighter-frameworks-docs](https://github.com/brighterwebsites/brighter-frameworks-docs) — separate repo, strategy SSOT.

**Developer notes in this repo:**  
- `CLAUDE.md` — standing dev instructions, module map, meta key conventions  
- `docs/` — implementation plans and module specs (not client-facing)  
- Local dev library index (`000-SCOS-Development-Library-Index.md`) — gitignored; agency-internal only

---

## What SCOS does

A Strategic Content Operating System is website infrastructure where content strategy, technical SEO, structured data, evidence, and amplification are **structural** — not bolted on via separate tools and manual processes.

This repo is Brighter Websites' implementation: a deployable MU plugin foundation for local service sites that need growth infrastructure, not brochureware.

**What that means in practice:**
- Per-page strategy is machine-readable ([Content Architecture Record / CAR](#content-architecture-record-car))
- Technical SEO is handled in-plugin (meta, canonicals, robots, sitemaps, archive control)
- Schema is generated from data already captured elsewhere
- Evidence layer CPTs (Reviews, Projects, FAQ) support E-E-A-T architecture
- GA4 reads CAR data for cluster/topic/maturity dimensions
- WP-CLI + MCP agents can read and write SCOS fields directly

---

## Plugin Structure

```
wp-scos-strategic-content-operating-system/
├── site-essentials.php          # Site Essentials loader (modular system)
├── brighter-core-loader.php     # Legacy brighter-core loader
├── brighter-ga4-tracking.php    # GA4 front-end tracking
├── site-essentials/             # ✅ New architecture — build here
│   ├── Core/                    # Admin UI, settings, module registry
│   └── Modules/                 # Feature modules (see table below)
├── brighter-core/               # ⚠️ Legacy — migrate to site-essentials
│   └── includes/                # CAR injection, schema output, GA4 seeder, API, ntfy…
└── archive/                     # Retired one-off scripts (do not deploy)
```

**Rule of thumb:** New features go in `site-essentials/` unless a genuine migration-cost exception applies. See `CLAUDE.md` and `.cursor/rules/scos-refactor-first.mdc`.


---
## Module status
Modules load only when enabled. Status reflects current `main` branch.
| Module | Location | Status |
|--------|----------|--------|
| **Content Architecture** | `site-essentials/Modules/ContentArchitecture/` | ✅ Active — CAR metabox, taxonomies (`scos_content_cluster`, `scos_topic`), workflow fields, content analysis |
| **SEO Meta** | `site-essentials/Modules/SeoMeta/` | ✅ Active — per-post SEO, archive SEO options, redirections, head output |
| **SEO / Sitemaps** | `site-essentials/Modules/Seo/` | ✅ Active — XML/image sitemaps |
| **Schema (per-post)** | `site-essentials/Modules/SeoSchema/` | ✅ Active |
| **Site Schema** | `site-essentials/Modules/SiteSchema/` | ✅ Active — site-wide JSON-LD templates |
| **Custom Posts** | `site-essentials/Modules/CustomPosts/` | ✅ Active — Reviews, Projects, FAQ (+ blocks, schema graph) |
| **Social Amplification** | `site-essentials/Modules/SocialAmplification/` | ✅ Active — framing, webhooks, REST, Postly/Anthropic hooks |
| **Analytics** | `site-essentials/Modules/Analytics/` | ✅ Active — GA4 config, seeding management |
| **Business Info** | `site-essentials/Modules/BusinessInfo/` | ✅ Active — centralised business data |
| **WordPress Tweaks** | `site-essentials/Modules/WordPressTweaks/` | ✅ Active |
| **Email Delivery** | `site-essentials/Modules/EmailDelivery/` | ✅ Active |
| **Client Onboarding** | `site-essentials/Modules/ClientOnboarding/` | ✅ Active |
| **CAR injection** | `brighter-core/includes/scos-car-injection.php` | ✅ Active — outputs `window.scosCAR` in `<head>` |
| **GA4 enhanced tracking** | `brighter-core/js/brighter-ga4-enhanced.js` | ✅ Active — reads `window.scosCAR` |
| **REST API** | `brighter-core/includes/api/` | ✅ Active — `/scos`, posts, FAQs, social endpoints |
| **ntfy monitoring** | `brighter-core/includes/ntfy/` | ✅ Active |
| **CAM (Content Authority Map)** | — | ❌ Not built — next major milestone |
| **Proof Library module** | — | ❌ Not built — Reviews/Projects CPTs exist; aggregation layer planned |
---
## Content Architecture Record (CAR)
On singular pages (when Content Architecture module is active), SCOS injects:
```javascript
window.scosCAR = {
  car: {
    cluster, topic,           // scos_content_cluster / scos_topic taxonomies
    maturity, intent, purpose, // scos_ca_* post meta
    "search-intent",          // Intent_Goal_Resolver (FAQ link or scos_ca_intent_goal)
    pillar, service_pathway,  // scos_ca_pillar_page_id, scos_ca_service_pathway_id
    metrics                   // scos_ca_* analysis fields (auto on save)
  },
  meta: { post_id, post_type, scos_version, car_generated }
};
```
GA4 custom dimensions consume this data. Legacy bw_* keys are still read as fallbacks during prefix migration.

Meta key prefixes: scos_ca_* (content architecture), scos_seo_* (SEO meta), scos_schema_*, scos_sa_* (social), scos_biz_* (business info). See CLAUDE.md § Meta Key Reference.

##Prefix migration
Authoritative new data uses scos_* keys. brighter-core dual-writes to legacy bw_* where needed. Do not create new bw_* keys.
Migration tooling (archive/scos-migration.php) has been retired — sites migrate via WP-CLI/MCP field writes.

## Stack & compatibility
Built for:

WordPress MU plugin deployment (CyberPanel / LiteSpeed)
Breakdance (themeless builder)
GA4 (custom event seeding from CAR)
WP-CLI + MCP (Claude CLI agent access to fields)
GitHub → server deploy (agency workflow)
SEOPress field management is superseded by SCOS SEO Meta; SEOPress may still be present on some sites during transition.

## AI / MCP access
SCOS is designed MCP-first: business logic in reusable classes, callable from admin UI, REST API, and WP-CLI.

REST: brighter-core/v1/ (token auth via X-Brighter-Token)
WP-CLI MCP: external server config per site (see SEO Command Center tools/wp-mcp-server/)
Key endpoint: /scos — returns CAR-shaped data for any URL or post ID

## Contributing / support
SCOS is proprietary agency infrastructure. For documentation corrections or module guides, see the SCOS documentation hub.
Agency contact: support@brighterwebsites.com.au

## License
GPL-3.0 — see LICENSE

© Brighter Websites. Framework and implementation methodology are proprietary IP.
Creator: Vanessa Wood · brighterwebsites.com.au
