# BW Universal Table System — v3.1 Spec
<!-- v3.1 | 2026-08-28 -->

**Status:** for review, pre-implementation
**Supersedes:** v3 draft (paths and activation gate corrected against the actual repo)
**Home:** `site-essentials/Modules/Tables/` in `wp-scos-strategic-content-operating-system`
**Module ID:** `tables` · **Tier:** `basic`
**First build target:** Guerilla Steel Stables (fresh, no v2 legacy)
**Retires:** brighterwebsites.com.au v2 (`bw-tables.css` / `bw-tables.js`, currently site-local — not in this repo)

---

## 0. What changed from v3, and why

The v3 draft is architecturally sound. Three things about it did not survive contact with the repo.

| v3 said | Reality | v3.1 |
|---|---|---|
| `modules/tables/` at repo root | `scripts/deploy-mu-plugins.sh` sets `OWNED_DIRS=(brighter-core site-essentials)`. Anything outside those two directories and four named root files is **never copied to a client site**. A root-level `modules/` would sit in git and deploy nowhere. | `site-essentials/Modules/Tables/` (§3.1) |
| `class-bw-tables.php` | The PSR-4 autoloader in `site-essentials.php` maps `SiteEssentials\Modules\Tables\Tables_Module` → `site-essentials/Modules/Tables/Tables_Module.php`. A `class-` prefixed filename never autoloads, and CLAUDE.md §2 requires filename to match class name. | `Tables_Module.php` (§3.1) |
| `apply_filters('scos_tables_enabled', false)` as the per-site gate | `Module_Loader` already does exactly this, better. `enabled_modules` defaults to `[]` — "Start with all modules disabled" (`Settings_Manager.php:177`) — and a disabled module's code is **never loaded at all**, not merely short-circuited. It also gets a visible on/off card in Site Essentials › Modules instead of requiring a PHP edit per site. | Module toggle is the gate. Filter dropped. (§3.2, §5.1) |

Everything else in v3 — the token contract, the class taxonomy, the JS build-once model, the block styles split — carries forward. Two further gaps found during review are closed in §4.3 (Gutenberg editor has no token mapping) and §6.5 (`:root` scoping is load-bearing for the JS breakpoint read).

---

## 1. Principles

1. **Nothing loads on a page with no table.** The primary constraint, not a nice-to-have. It is the reason the system leaves Breakdance's Global Settings Stylesheet.
2. **A site configures 3 tokens, not 18.** Everything else derives.
3. **One behaviour class + stackable modifiers.** No fused triple-purpose class names.
4. **No bare element selectors.** `table { }` never appears. WooCommerce, FiboFilters and plugin markup must be untouched.
5. **Degrades without JS.** Every table stays readable and scrollable if the script never runs.
6. **Reuse the loader, don't rebuild it.** Enablement, settings storage, the Modules grid card and the dependency check already exist in `site-essentials/Core/`. The module implements `Module_Interface` and inherits all of it.

---

## 2. Naming

Per the agency convention (site prefix / `bw-` shared library / unprefixed utility), this is tier 2 — a shared agency component.

- **Classes:** `bw-` prefix. Not a Brighter Websites marker; the system namespace.
- **Tokens:** `--bw-t-` prefix (`t` = table), so future shared `bw-` components don't collide in the same `:root`.

**Note on the `bw_` deprecation.** CLAUDE.md §3 forbids new `bw_` keys. That rule governs **PHP meta keys and option keys** — the `bw_` → `scos_`/`se_` migration — and does not extend to front-end CSS class or custom-property namespaces, which are a separate tier-2 convention. Add a one-line clarification to CLAUDE.md §3 alongside this build so the next reader doesn't flag `bw-` as a violation.

No collision risk with the admin design system: `--scos-*` tokens in `site-essentials/assets/css/tokens.css` are admin-only, `--bw-t-*` are front-end. Different prefixes, different surfaces.

---

## 3. Distribution architecture

### 3.1 File layout

```
site-essentials/
  Modules/
    Tables/
      Tables_Module.php     Module_Interface implementation, enqueue gate
      Block_Styles.php      core/table block style registration
      views/
        settings.php        Modules-grid settings panel (§9)
      assets/
        bw-tables.css
        bw-tables.js
```

Namespace `SiteEssentials\Modules\Tables`. One class per file, filename matches class name. Paths resolve through `SITE_ESSENTIALS_PATH` / `SITE_ESSENTIALS_URL` + `Modules/Tables/assets/…`.

### 3.2 Registration and the activation gate

Registered alongside the other modules in `site-essentials.php`:

```php
\SiteEssentials\Core\Module_Loader::register(
    'tables',
    \SiteEssentials\Modules\Tables\Tables_Module::class
);
```

That is the whole gate. `enabled_modules` defaults to empty, so the module deploys to all five sites via `deploy-mu-plugins.sh` and stays completely dormant — its class is never even instantiated — until someone ticks **Tables** on Site Essentials › Modules for that site.

Site setup remains two steps, as v3 intended: map the tokens (§4.1), flip the toggle. Neither one alone does anything visible. The difference from v3 is that the toggle is a checkbox an operator can find, not a filter that needs a code change on each host.

*No `scos_tables_enabled` filter.* A second gate on top of `is_module_enabled('tables')` gives two places to look when a table isn't styled, and buys nothing the toggle doesn't already provide.

### 3.3 Enqueue gate

Inside `Tables_Module::init()`, so it only ever runs on an enabled site:

```php
add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor' ] );
```

```php
public function enqueue_frontend() {
    if ( ! is_singular() ) return;
    $post = get_post();
    if ( ! $post || ! has_block( 'core/table', $post ) ) return;
    // enqueue css + js, version = filemtime()
}
```

- Named callbacks only, per CLAUDE.md §2. No closures — the existing `wp_enqueue_scripts` closures in `Tweaks_Module` are legacy, not the pattern to copy.
- `filemtime()` versioning, matching the house pattern already used in `SeoMeta/Meta_Box.php`, `SiteSchema_Module.php` and others. Cache-busts on deploy with no manual version bumps.
- Script in footer with `wp_script_add_data( 'bw-tables', 'strategy', 'defer' )` (WP 6.3+) rather than a hand-rolled `script_loader_tag` filter. No render blocking.
- `Cache_Helper` is not needed here. Nothing this module does is expensive or repeated.

### 3.4 Known gate limitations

Three, all accepted, all documented rather than silent:

1. **Breakdance pages.** `has_block()` reads `post_content` only; Breakdance content lives in postmeta, so Breakdance-built pages never trigger this gate. Correct for now — Gutenberg tables live in articles, and articles are where tables live on all five sites. When `bde-custom-elements` is extended later, Breakdance registers element assets itself and loads them only when the element is present. Two enqueue paths, deliberately. Don't try to unify them.
2. **Synced patterns / reusable blocks.** A `core/table` inside a `core/block` reference is invisible to `has_block( 'core/table' )` — the pattern is stored as a separate post. If this bites, the fix is to also test `has_block( 'core/block', $post )`, not to drop the gate.
3. **Archives.** `is_singular()` excludes the blog index and category archives. A full-content (not excerpt) loop showing a table there gets no styles. Acceptable — with §4.3's declared defaults it degrades to a plain readable table, not a broken one.

### 3.5 Load order — verify before shipping

System defaults are declared in `bw-tables.css`; the per-site skin overrides them in Breakdance's global CSS. Both target `:root`, so **specificity is equal and the later stylesheet wins** — this only works if `bw-tables.css` loads first.

Verify the actual handle/priority order on GS before deploying to the other four. If Breakdance's global stylesheet loads earlier, the fix is enqueue priority, not `!important`.

---

## 4. Token contract

### 4.1 Site inputs — 3 required, 2 optional

Two-level by design. The component tokens are their own tier: the system never reads a site's raw palette variables directly, and a site never overrides the system's internals. The two are joined by a small mapping block written once per site in Breakdance global CSS.

| Token | Purpose | Required | Default if unmapped |
|---|---|---|---|
| `--bw-t-accent` | Header gradient, featured column, panel borders | Yes | `#2f3337` (neutral charcoal) |
| `--bw-t-surface` | Table / card background | Yes | `#ffffff` |
| `--bw-t-ink` | Body text colour | Yes | `#1f2325` |
| `--bw-t-head-ink` | Header text | Only if accent is light | `#ffffff` |
| `--bw-t-radius` | Corner radius | Only if house style differs | `10px` |

Guerilla Steel example:

```css
:root {
  --bw-t-accent:  var(--gs_red-9);   /* #B0081E */
  --bw-t-surface: var(--gs_white);
  --bw-t-ink:     var(--gs_charcoal-10);
}
```

### 4.2 Derived — never set per site

Declared in the same `:root` block in `bw-tables.css` as the defaults above:

```css
--bw-t-border:    color-mix(in srgb, var(--bw-t-ink) 15%, transparent);
--bw-t-row:       color-mix(in srgb, var(--bw-t-ink) 4%,  var(--bw-t-surface));
--bw-t-muted:     color-mix(in srgb, var(--bw-t-ink) 60%, var(--bw-t-surface));
--bw-t-highlight: color-mix(in srgb, var(--bw-t-accent) 8%, var(--bw-t-surface));
--bw-t-head-from: var(--bw-t-accent);
--bw-t-head-to:   color-mix(in srgb, var(--bw-t-accent) 80%, #000);
--bw-t-shadow:    0 2px 10px color-mix(in srgb, var(--bw-t-ink) 12%, transparent);
--bw-t-bp:        680px;
```

Custom properties resolve lazily at the point of use, so a site overriding only `--bw-t-ink` in a later `:root` gets every derived token recomputed from it. The derived block never needs touching per site.

### 4.3 Declared defaults — the change from v3

v3 left the five inputs undeclared and relied on the site mapping block to supply them. That is fine on the front end, where the PHP gate guarantees a configured site — but it breaks in two places:

- **The Gutenberg editor.** `enqueue_block_editor_assets` loads the stylesheet unconditionally so block style previews render (§7). Breakdance's global CSS is not present in the editor, so every `--bw-t-*` is undefined there. An undefined `var()` makes the whole declaration invalid at computed-value time, so the editor preview would show exactly the broken header — white text on transparent — that v3 §5.1 correctly rejected as a failure mode.
- **Archives and any future gate leak** (§3.4).

Declaring neutral defaults in `bw-tables.css` fixes both in one move, costs nothing, and does not weaken the gate: activation is still the module toggle, and an unconfigured site never loads the stylesheet at all. The defaults are a floor for contexts the gate can't reach, not an activation mechanism.

This also removes the argument for per-use `var(--bw-t-accent, #333)` fallbacks scattered through the stylesheet. One `:root` block, defaults and derived together.

### 4.4 Contrast caveat

`--bw-t-head-ink` defaults to white. On a light accent that fails WCAG AA against the header gradient. `color-contrast()` would solve this automatically but browser support is still not safe to rely on — so it stays a manual override, checked as part of the deploy skill's smoke test (§10) and surfaced in the settings panel (§9).

---

## 5. Class taxonomy

### 5.1 Baseline

Applies automatically to `.wp-block-table`, plus opt-in `.bw-table` for non-Gutenberg markup (the future custom elements path).

**Resolved: yes, auto-apply — gated by the module toggle.** Two conditions before any table changes appearance:

1. The site has the token mapping block in Breakdance global CSS (§4.1).
2. The **Tables** module is enabled for that site in Site Essentials › Modules (§3.2).

Condition 2 is what makes the stylesheet load at all, so the module can ship to all five sites in one `deploy-*` with zero visual change anywhere. Rollout is an explicit per-site action, not a deploy-day surprise.

*Rejected alternative:* gating purely in CSS. Even with §4.3's declared defaults, an unconfigured site would then get neutral-grey styled tables rather than untouched ones — a visible change nobody asked for. Container style queries (`@container style(--bw-t-configured: 1)`) would technically work, but wrapping an entire stylesheet in one to solve a problem the module toggle already solves is complexity for its own sake.

### 5.2 Behaviour — pick one, mobile only

| Class | Behaviour below `--bw-t-bp` | Needs JS |
|---|---|---|
| *(none)* | Horizontal scroll container with edge fade affordance | No |
| `.bw-stack` | Each row becomes a card, cells stack full-width | No |
| `.bw-cards` | Each **column** becomes a card | Yes |

The no-class default is new. v2 had none, so an unclassed wide table just overflowed and broke the layout. Scroll-with-affordance is the correct floor.

### 5.3 Modifiers — stackable

| Class | Effect |
|---|---|
| `.bw-labels` | In `stack`: header text as `::before` label per cell. In `cards`: first column's values become row labels, and column 1 is omitted as its own card. |
| `.bw-hide-first` | Omit first column entirely (cards only, no label use) |
| `.bw-compare` | Last column highlighted as featured; in cards, last card gets accent border |
| `.bw-pricing` | SKU / config / price column width treatment |
| `.bw-compact` | Smaller type, tighter rows |

This collapses v2's three fused `collapse-col*` classes into `bw-cards` / `bw-cards bw-hide-first` / `bw-cards bw-labels`, and the JS drops from three query loops to one.

### 5.4 Not in this system

Three things from v2 are removed outright, not relocated:

- `.bw-panel-notes` — an accent info block, not a table element. Dropped.
- `:target { scroll-margin-top: 100px }` — a global anchor-offset concern.
- `--box-shaddow-brand` / `--box-shaddow-pretty-blue` — unused by any table rule, and typo'd.

If a callout block is wanted later it gets built deliberately, not carried forward because it happened to be in the same file.

---

## 6. JS behaviour

### 6.1 Entry

No `DOMContentLoaded` wrapper — per the Breakdance JS convention, guard instead:

```js
const targets = document.querySelectorAll('.bw-cards');
if (!targets.length) return;
```

### 6.2 Breakpoint — single source of truth

```js
const bp = getComputedStyle(document.documentElement)
             .getPropertyValue('--bw-t-bp').trim();
const mq = window.matchMedia(`(max-width: ${bp})`);
```

v2 hard-coded `680` in both files with nothing syncing them. This removes that class of bug permanently.

### 6.3 Build once, never tear down

```js
if (mq.matches) build();
mq.addEventListener('change', e => { if (e.matches) build(); });
```

- Desktop load: media query doesn't match, function exits. **Zero DOM work.**
- Mobile load: one build pass. Original table hidden via CSS `.bw-built`.
- Rotation / resize: `change` fires only on an actual breakpoint crossing, not per resize tick.
- No resize listener, no debounce timer, no teardown churn.

**Accepted trade-off:** on mobile the content exists twice in the DOM. A 12×6 table adds roughly 150 nodes. Lighthouse flags DOM size past ~1,400 nodes total, so this is comfortable but not free. Screen readers ignore `display:none`, so no accessibility cost.

The alternative — *moving* cells rather than cloning — has zero duplication and preserves IDs and event listeners, but requires full teardown logic to survive a landscape rotation back over the breakpoint. Deferred to v3.2 if node counts ever become a real Lighthouse problem.

### 6.4 Build rules

- **Strip IDs on clone.** `card.querySelectorAll('[id]').forEach(el => el.removeAttribute('id'))`. Fixes v2's duplicate-ID bug, which breaks in-page anchors and any `label[for]` inside a cell.
- **No `thead`?** Fall back to treating the first `tbody` row as the header. Gutenberg tables with the header-row toggle off are common and v2 silently dropped all labels for them.
- **`colspan` / `rowspan`: unsupported.** Explicit non-goal. Detect, skip the transform, leave the table in scroll mode, `console.warn` in debug. Silently producing a scrambled card grid is worse than not transforming.
- **`tfoot`: excluded** from cards in v1. Documented, not silent.
- **Idempotent.** Skip any table already carrying `.bw-built`.

### 6.5 `:root` scoping is load-bearing

`--bw-t-bp` must be declared on `:root`, not scoped to `.wp-block-table`, or §6.2's `getComputedStyle(document.documentElement)` read returns an empty string and `matchMedia('(max-width: )')` silently never matches — the cards transform would just stop happening with no error. Keep the whole token block on `:root` (§4.2) and add a comment in the CSS saying why.

Defensive floor in the JS: if the read comes back empty, fall back to `680px` and `console.warn` in debug rather than building a broken media query.

---

## 7. Block style variations

Registered on `core/table` in `Block_Styles.php`, called from `Tables_Module::init()`. Gutenberg emits `is-style-{name}`, which we can't rename — so the stylesheet maps both selectors.

| Registered style | Emitted class | Maps to |
|---|---|---|
| Scroll (default) | — | baseline |
| Stacked | `is-style-bw-stack` | `.bw-stack` |
| Stacked + labels | `is-style-bw-stack-labels` | `.bw-stack.bw-labels` |
| Cards | `is-style-bw-cards` | `.bw-cards` |
| Cards + labels | `is-style-bw-cards-labels` | `.bw-cards.bw-labels` |

Block styles are **mutually exclusive** — one at a time — so they cover behaviour only. `.bw-compare`, `.bw-pricing`, `.bw-compact` stay in Additional CSS class, stacked on top of whichever style is selected.

`register_block_style()` is server-side and needs no build step. This is the first use of it in the repo; the existing block precedents (`FAQ_Block.php`, `Project_Selector_Block.php`) use `register_block_type()` with a hand-written `wp.blocks` JS file — a different API, same shape, and worth reading for the enqueue conventions.

Anything richer (checkbox toggles for modifiers in the block sidebar) needs a JS `blocks.registerBlockType` filter and a build step. Out of scope.

---

## 8. Migration — Brighter Websites v2 → v3

| v2 class | v3 equivalent |
|---|---|
| `bw-collapse-row-labels` | `bw-stack bw-labels` |
| `bw-collapse-row` | `bw-stack` |
| `bw-collapse-col` | `bw-cards` |
| `bw-collapse-col-hide` | `bw-cards bw-hide-first` |
| `bw-collapse-col-labels` | `bw-cards bw-labels` |
| `bw-compare` / `bw-pricing` / `bw-compact` | unchanged |

`wp search-replace` per class across `post_content`, dry-run first. Order matters: replace the longest class names first, or `bw-collapse-col` will corrupt `bw-collapse-col-hide` and `bw-collapse-col-labels`.

BW migrates **last** — after GS proves the system on a site with no legacy classes to break. The v2 files are site-local to brighterwebsites.com.au and are not in this repo; deleting them is a separate manual step once the module is enabled there.

---

## 9. Admin surface

Every module in this repo appears as a card on Site Essentials › Modules and must implement `render_settings()`. v3 didn't cover this. The panel is small but earns its place — it is the only way to check token mapping without opening dev tools on the live site.

```php
public static function get_id()           { return 'tables'; }
public static function get_name()         { return 'Tables'; }
public static function get_description()  { return 'Responsive table styling for Gutenberg tables — scroll, stack or card layouts on mobile.'; }
public static function get_tier()         { return 'basic'; }
public static function get_dependencies() { return []; }
public static function get_version()      { return '1.0.0'; }
```

`views/settings.php` renders, following CLAUDE.md §5 (Template C, `<div class="wrap scos">`, `.scos-form`, `--scos-*` tokens only, `current_user_can('manage_options')` at the top):

- The five `--bw-t-*` token names with their purpose, each shown via `.scos-form__slug` so the name is copy-pasteable into Breakdance.
- A live swatch strip rendering `--bw-t-accent`, `--bw-t-surface`, `--bw-t-ink` — if the site hasn't mapped them the swatches show the §4.1 defaults, which is the "not configured yet" signal.
- A header-contrast readout for `--bw-t-accent` against `--bw-t-head-ink`, covering §4.4.
- The behaviour and modifier class reference (§5.2, §5.3) as a copy-paste table for whoever writes Additional CSS classes.

No settings are stored. This module has no options — the tokens live in Breakdance, the behaviour lives in block styles. The panel is reference and verification only, and `render_settings()` writes nothing, so no nonce handling is required.

---

## 10. Deploy skill — scope

Separate build, written from Claude's perspective per the skill-creation rules. Steps it should own:

1. Confirm the SCOS Tables module is present on the target site and enabled in `enabled_modules`.
2. Read existing Breakdance global variables via MCP; reuse a matching palette variable if one exists, create only if not.
3. Write the three-token `:root` skin into Breakdance global CSS.
4. Verify stylesheet load order (§3.5).
5. Smoke test: one page with a table, mobile + desktop, header contrast check, and confirm nothing is enqueued on a table-free page.

Note that `.claude/skills/` does not currently exist in this repo — decide whether the skill lives here (deployed to every site, harmless but pointless) or in the agency skills library alongside `breakdance-style-conventions`. The library is the better home.

---

## 11. Repo-fit checklist

Confirmed against the codebase, not assumed:

- [x] `site-essentials/Modules/Tables/` is inside `OWNED_DIRS` and will deploy.
- [x] `SiteEssentials\Modules\Tables\Tables_Module` autoloads from `Tables_Module.php` under the PSR-4 rule in `site-essentials.php`.
- [x] `Module_Loader::register()` rejects any class not implementing `Module_Interface` — all six static methods plus `init()` and `render_settings()` are required.
- [x] `enabled_modules` defaults to `[]`, so the module ships dormant.
- [x] `filemtime()` asset versioning matches existing modules.
- [x] `--bw-t-*` cannot collide with the admin `--scos-*` tokens.
- [ ] `SITE_ESSENTIALS_VERSION` bump — currently `1.1.0`. New module = minor bump per CLAUDE.md §1.
- [ ] CLAUDE.md: add Tables to the Step 3 module placement table (§4 of CLAUDE.md).
- [ ] CLAUDE.md: clarify that the `bw_` ban covers PHP keys, not CSS namespaces (§2 above).
- [ ] CLAUDE.md §5 and README point at `cursor-handoff/` for the design system; the directory in this repo is `design-set/` and the live CSS is `site-essentials/assets/css/`. Pre-existing error, worth fixing in the same pass.
- [ ] README module status table: add Tables once built.
- [ ] `.gitignore` excludes `*.md` except four names — this spec needs an explicit exception to be committable.

---

## 12. Decisions

**Resolved**

1. **Baseline auto-apply** — yes, gated by the module toggle alone. (§3.2, §5.1)
2. **Activation** — `Module_Loader` toggle. No `scos_tables_enabled` filter. (§3.2)
3. **Namespace** — `bw-` classes and `--bw-t-` tokens retained; CLAUDE.md clarified rather than the convention changed. (§2)
4. **Tier and admin surface** — `basic`, with a reference-and-verification settings panel. (§9)
5. **Token defaults** — declared in `bw-tables.css`, not left undefined. (§4.3)
6. **Rollout** — Guerilla Steel first, as part of the rebuild. Order for the remaining four determined from what GS surfaces.
7. **Breakpoint** — `680px` default, verified against real tables during the GS build, changeable in one place. (§4.2, §6.2)
8. **`.bw-panel-notes`** — removed entirely. (§5.4)

**Still open**

**The right-hand side of the token mapping.** The component tokens are settled; what they map *to* depends on the site's colour token convention, which isn't locked. GS is a fresh rebuild, so it's either the natural place to settle a semantic tier — or the component tokens map straight to raw palette variables (`--gs_red-9`) for now and the semantic tier gets decided on its own timeline. Both are workable; the second is faster and reversible, since only the mapping block would change.

This does not block implementation. The module doesn't care what the right-hand side is.
