# Autoptimize Aggregation Pipeline — Research Report for Cache Party Integration

**Date:** 2026-03-23
**Purpose:** Map AO's CSS/JS aggregation pipeline to determine what to transplant into Cache Party
**Status:** Research complete — no implementation code written

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [AO's Output Buffer Orchestration](#2-aos-output-buffer-orchestration)
3. [CSS Aggregation Pipeline](#3-css-aggregation-pipeline)
4. [JS Aggregation Pipeline](#4-js-aggregation-pipeline)
5. [Minification Libraries](#5-minification-libraries)
6. [Cache System](#6-cache-system)
7. [Exclusion System](#7-exclusion-system)
8. [CSS Deferral & Critical CSS](#8-css-deferral--critical-css)
9. [AO Pro Additions](#9-ao-pro-additions)
10. [What We DON'T Need](#10-what-we-dont-need)
11. [Cache Party Current State](#11-cache-party-current-state)
12. [Integration Plan: What Changes](#12-integration-plan-what-changes)
13. [Code Surface Estimate](#13-code-surface-estimate)
14. [Filter Hook Compatibility Map](#14-filter-hook-compatibility-map)

---

## 1. Executive Summary

Autoptimize's aggregation pipeline is **~4,500 lines** of core code across 7 files. The architecture is straightforward:

1. **Output buffer** captures full HTML at `template_redirect` priority 2
2. **Regex extraction** finds all `<link>` and `<script>` tags (not WP hook-based)
3. **Concatenation** groups assets by media type (CSS) or position (JS)
4. **Minification** via bundled YUI CSSMin (935 lines) and JSMin (467 lines)
5. **Cache** writes MD5-hashed files to `wp-content/cache/autoptimize/`
6. **HTML replacement** swaps original tags with single aggregated `<link>`/`<script>`

**Key insight:** AO does NOT hook into `wp_print_styles`/`wp_print_scripts`. It processes the final HTML output via regex. This means Cache Party can transplant the pipeline without worrying about WordPress enqueue timing — it just needs the HTML buffer.

**What we're transplanting:**
- `autoptimizeStyles.php` (1,337 lines) → CSS collection, aggregation, URL rewriting
- `autoptimizeScripts.php` (862 lines) → JS collection, aggregation, try-catch wrapping
- `autoptimizeBase.php` (711 lines) → Shared utilities, marker system, CDN/path resolution
- `autoptimizeCSSmin.php` (69 lines) → CSSMin wrapper
- `autoptimizeCache.php` (855 lines) → Cache file management
- `external/php/yui-php-cssmin-bundled/Minifier.php` (935 lines) → YUI CSS minifier
- `external/php/jsmin.php` (467 lines) → Crockford JSMin

**What we're NOT transplanting:**
- `autoptimizeMain.php` — We have our own output buffer orchestrator
- `autoptimizeExtra.php` — Google Fonts, emoji, preconnect (we handle differently)
- `autoptimizeImages.php` — We have our own Images module
- `autoptimizeHTML.php` — HTML minification (not in scope)
- `autoptimizeCompatibility.php` — We'll handle compat our own way
- All admin UI, settings pages, toolbar, metabox, partners, exit survey

---

## 2. AO's Output Buffer Orchestration

### Bootstrap Sequence

```
plugins_loaded → autoptimizeMain::setup()
  ├── Define constants (cache dir, URL, prefix)
  └── Fire autoptimize_setup_done
        ├── version_upgrades_check() [pri 10]
        ├── check_cache_and_run() [pri 10]  ← decides whether to buffer
        ├── maybe_run_ao_compat() [pri 10]
        ├── maybe_run_ao_extra() [pri 15]
        └── maybe_run_criticalcss() [pri 11]

template_redirect [pri 2] → start_buffering()
  └── ob_start( end_buffering callback )
```

### Buffer Decision (`should_buffer()`)

Returns `true` only if ALL of:
- NOT `is_admin()`, `is_feed()`, `is_embed()`, `is_login()`, `is_customize_preview()`
- At least one optimization enabled (HTML, JS, CSS, or image)
- NOT `DONOTMINIFY` constant
- NOT `?ao_noptimize=1` query param
- NOT page builder preview (Elementor, Beaver, Divi, etc.)
- NOT logged-in user with `edit_posts` when `autoptimize_optimize_logged` = 'off'

### Buffer Processing (`end_buffering()`)

**Validation:** Rejects if no `<html>` tag, XSL stylesheets, AMP markup, `<!-- noptimize-page -->`

**Processing order:**
1. Filter `autoptimize_filter_html_before_minify` on raw HTML
2. **JS processing** — `autoptimizeScripts::read()` → `minify()` → `cache()` → `getcontent()`
3. **CSS processing** — `autoptimizeStyles::read()` → `minify()` → `cache()` → `getcontent()`
4. **HTML minification** (if enabled)
5. Filter `autoptimize_html_after_minify` on final HTML

**Cache Party equivalent:** We already have `class-output-buffer.php` at priority 3. We'll add CSS/JS aggregation as processors in the output buffer, running before our existing CSS deferral (pri 3) and JS delay (pri 4).

---

## 3. CSS Aggregation Pipeline

### Class: `autoptimizeStyles extends autoptimizeBase` (1,337 lines)

### Phase 1: Configuration & HTML Preparation

**Filter loading (lines 171-255):**

| Filter | Default | Purpose |
|--------|---------|---------|
| `autoptimize_filter_css_noptimize` | false | Skip all CSS optimization |
| `autoptimize_filter_css_allowlist` | '' | Only aggregate whitelisted URLs |
| `autoptimize_filter_css_removables` | '' | CSS to delete entirely |
| `autoptimize_filter_css_inlinesize` | 256 | Max bytes for inline CSS |
| `autoptimize_filter_css_justhead` | false | Only optimize `<head>` CSS |
| `autoptimize_filter_css_aggregate` | option | Enable/disable aggregation |
| `autoptimize_css_include_inline` | option | Include `<style>` tags in aggregation |
| `autoptimize_filter_css_exclude` | option | Exclusion keywords (comma-separated) |
| `autoptimize_filter_css_defer` | option | Enable CSS deferral |
| `autoptimize_filter_css_defer_inline` | option | Critical CSS to inline while deferring |
| `autoptimize_filter_css_inline` | option | Inline all CSS instead of linking |
| `autoptimize_filter_css_minify_excluded` | option | Minify non-aggregated CSS |

**Content protection (lines 258-272):**
1. Hide `<!--noptimize-->` sections via marker replacement
2. Hide `<script>` and `<noscript>` tags (prevent false CSS matches inside scripts)
3. Hide IE conditional comments `<!--[if IE]>`
4. Hide HTML comments

### Phase 2: Tag Extraction

**Regex pattern (line 275):**
```php
#(<style[^>]*>.*</style>)|(<link[^>]*stylesheet[^>]*>)#Usmi
```
Matches `<style>` blocks and `<link rel="stylesheet">` tags. Flags: ungreedy, dotall, multiline, case-insensitive.

**For each matched tag:**

1. **Check removability** — delete if in `cssremovables` list
2. **Check movability** via `ismovable()`:
   - If allowlist exists: only aggregate if tag matches allowlist
   - If no allowlist: aggregate unless tag matches `dontmove` list
   - Hardcoded dontmove: `data-noptimize`, `.wp-container-`
3. **Extract media attribute** — split comma-separated values, default to `'all'`

**For movable `<link>` tags:**
- Extract href, strip query string
- `getpath()` converts URL to filesystem path (checks if local + readable)
- If local `.css` file → add to `$this->css[]` array as `[media, path]`
- If external/dynamic → optionally defer, apply CDN rewrite

**For movable `<style>` tags:**
- Extract inline CSS content
- Strip CDATA wrappers
- Add to `$this->css[]` as `[media, 'INLINE;' . $code]`

**For non-movable (excluded) tags:**
- Optionally minify individually via `minify_single()`
- Optionally defer with media="print" onload
- Apply CDN URL replacement

### Phase 3: Aggregation & Deduplication

**CSS concatenation (lines 791-829):**
```php
foreach ($this->css as $group) {
    list($media, $css) = $group;

    if (preg_match('#^INLINE;#', $css)) {
        $css = preg_replace('#^INLINE;#', '', $css);
        $css = self::fixurls(ABSPATH . 'index.php', $css);
    } else {
        $css = self::fixurls($css_path, file_get_contents($css_path));
        $css = preg_replace('/\x{EF}\x{BB}\x{BF}/', '', $css); // Remove BOM
    }

    // Group by media attribute
    foreach ($media as $elem) {
        $this->csscode[$elem] .= "\n/*FILESTART*/" . $css;
    }
}
```

**Deduplication (lines 832-848):** CSS with identical content in different media groups is merged into a single entry with comma-separated media list (e.g., `"all, screen"`).

### Phase 4: @import Resolution

**Recursive loop (lines 851-911):**
- Local `@import` → inline the file content (recursive for nested imports)
- External `@import` → move to top of aggregated CSS
- Already-minified local files → use `build_injectlater_marker()` for late injection

### Phase 5: URL Rewriting — `fixurls()` (lines 1105-1174)

**Critical function:** Since aggregated CSS is served from `/wp-content/cache/`, all relative URLs in the original CSS must be converted to absolute paths.

```php
static function fixurls($file, $code) {
    // Convert @import to url() for uniform handling
    // Find all url() references via ASSETS_REGEX
    // Skip absolute URLs and data: URIs
    // Convert relative → absolute using original file's directory
    // Hash-based replacement to handle duplicate URLs
}
```

**ASSETS_REGEX:** `url\s*\(\s*(?!["\']?data:)(?![\'|\"]?[\#|\%|])([^)]+)\s*\)([^;},\s]*)`

### Phase 6: Minification & Caching

```php
// Check cache first (MD5 of unminified content)
$hash = md5($code);
$ccheck = new autoptimizeCache($hash, 'css');
if ($ccheck->check()) {
    $code = $ccheck->retrieve();
    continue;
}

// Cache miss: rewrite assets → minify → inject late code → filter
$code = $this->rewrite_assets($code);     // CDN URLs, datauris
$code = $this->run_minifier_on($code);    // YUI CSSMin
$code = $this->inject_minified($code);    // Replace INJECTLATER markers
$code = apply_filters('autoptimize_css_after_minify', $code);
```

### Phase 7: Output — `getcontent()` (lines 1000-1100)

**Injection point:** Before `<title>` tag (default, filterable via `autoptimize_filter_css_replacetag`)

**Three output modes:**

1. **Inline mode** (`$this->inline = true`): All CSS in `<style>` tags
2. **Defer mode** (`$this->defer = true`):
   - Critical CSS as `<style id="aoatfcss">` (render-blocking)
   - Full CSS as `<link media="print" onload="this.media='all'">`
   - `<noscript>` fallback with original media
3. **Normal mode**: `<link>` tags with aggregated URLs

---

## 4. JS Aggregation Pipeline

### Class: `autoptimizeScripts extends autoptimizeBase` (862 lines)

### Phase 1: Configuration

**Key options:**

| Option | Default | Purpose |
|--------|---------|---------|
| `autoptimize_js_aggregate` | off | Aggregate JS files |
| `autoptimize_js_defer_not_aggregate` | on | Defer all JS when not aggregating |
| `autoptimize_js_defer_inline` | on | Defer inline JS |
| `autoptimize_js_trycatch` | off | Wrap scripts in try-catch |
| `autoptimize_js_forcehead` | off | Force JS in `<head>` (vs before `</body>`) |
| `autoptimize_js_include_inline` | off | Include inline scripts in aggregation |

### Phase 2: Tag Extraction

**Regex pattern (line 343):**
```php
#<script.*</script>#Usmi
```

**Type validation — `should_aggregate()` (lines 522-550):**
Only aggregates scripts with type matching: `text/javascript`, `text/ecmascript`, `application/javascript`, `application/ecmascript`, or no type attribute. **Rejects:** `type="module"`, `type="text/template"`, etc.

**For each matched tag:**

1. **Check removability** — delete if in `jsremovables` list
2. **Check mergeability** via `ismergeable()`:
   - If allowlist exists: only merge if tag matches allowlist
   - If no allowlist: merge unless tag matches `dontmove` list

**Hardcoded `dontmove` exclusions (30+ entries):**
```
document.write, html5.js, show_ads.js, google_ad, admin-bar.min.js,
GoogleAnalyticsObject, plupload.full.min.js, syntaxhighlighter, adsbygoogle,
data-noptimize, data-cfasync, data-pagespeed-no-defer, nonce, post_id, ...
```

**For external scripts (`<script src="...">`):**
- Extract URL, strip query string, resolve to filesystem path
- If local `.js` file and mergeable → add to `$this->scripts[]`
- If excluded → optionally add async/defer, minify individually, apply CDN

**For inline scripts (`<script>...</script>`):**
- Strip CDATA and HTML comment wrappers
- If mergeable + include_inline → add to `$this->scripts[]` as `'INLINE;' . $code`
- If `defer_inline` enabled → base64-encode and convert to `<script defer src="data:text/javascript;base64,...">`

**Move system:** Scripts matching `$domove` list → prepended before aggregated. Scripts matching `$domovelast` → appended after.

### Phase 3: Concatenation

```php
foreach ($this->scripts as $script) {
    if (preg_match('#^INLINE;#', $script)) {
        $script = preg_replace('#^INLINE;#', '', $script);
        $script = rtrim($script, ";\n\t\r") . ';';
        if ($this->trycatch) {
            $script = 'try{' . $script . '}catch(e){}';
        }
    } else {
        $scriptsrc = file_get_contents($script);
        $scriptsrc = preg_replace('/\x{EF}\x{BB}\x{BF}/', '', $scriptsrc); // BOM
        $scriptsrc = rtrim($scriptsrc, ";\n\t\r") . ';';
        if ($this->trycatch) {
            $scriptsrc = 'try{' . $scriptsrc . '}catch(e){}';
        }
        // Already-minified files → late injection marker
        if ($this->can_inject_late($script)) {
            $scriptsrc = self::build_injectlater_marker($script, md5($scriptsrc));
        }
    }
    $this->jscode .= "\n" . $scriptsrc;
}
```

**Key details:**
- Each script ends with `;` before concatenation (prevents syntax errors)
- Optional try-catch wrapping per script (setting: `autoptimize_js_trycatch`)
- Already-minified files (`.min.js`) use marker system for late injection

### Phase 4: Minification & Caching

```php
$this->md5hash = md5($this->jscode);
$ccheck = new autoptimizeCache($this->md5hash, 'js');
if ($ccheck->check()) {
    $this->jscode = $ccheck->retrieve();
    return true;
}

// Cache miss
$tmp_jscode = trim(JSMin::minify($this->jscode));
$this->jscode = $this->inject_minified($this->jscode); // Replace INJECTLATER markers
$this->jscode = apply_filters('autoptimize_js_after_minify', $this->jscode);
```

### Phase 5: Output — `getcontent()`

**Placement:**
- `forcehead = true` → inject before `</head>` (no defer attribute)
- `forcehead = false` (default) → inject before `</body>` with `defer` attribute

**Output structure:**
```html
[moved-first scripts]
<script defer src="/wp-content/cache/autoptimize/js/autoptimize_abc123.js"></script>
[moved-last scripts]
```

---

## 5. Minification Libraries

### YUI CSS Minifier (935 lines)

**Namespace:** `Autoptimize\tubalmartin\CssMin\Minifier`
**Wrapper:** `autoptimizeCSSmin` (69 lines, adds `calc()` protection)

**Minification sequence:**
1. Extract & tokenize data: URIs
2. Extract & preserve `/*! */` important comments
3. Handle IE Matrix filters
4. Unquote simple attribute selectors (`[id="foo"]` → `[id=foo]`)
5. Extract & preserve quoted strings
6. Normalize whitespace to single spaces
7. Process rule bodies (shorten properties, colors, zeros)
8. Process at-rules & selectors
9. Split long lines if configured
10. Restore preserved tokens

**Special handling:**
- `calc()`, `min()`, `max()`, `clamp()` — hidden before minification, restored after (prevents space removal around `+`/`-` operators)
- Color shortening: `#ffffff` → `white`, `white` → `#fff`
- Zero-value shortening: `margin: 0px` → `margin:0`

### JSMin (467 lines)

**Class:** `JSMin` (Douglas Crockford's algorithm, PHP port)

**Algorithm:** Three-character lookahead state machine with actions:
- `ACTION_KEEP_A` — output character
- `ACTION_DELETE_A` — skip whitespace
- `ACTION_DELETE_A_B` — skip both characters

**Preserves:**
- String literals (single/double quotes, template literals with backticks)
- Regular expression literals (context-aware detection)
- `/*! */` important comments
- IE conditional comments (`/*@cc_on...@*/`)
- Spaces between `+`/`-` operators (prevents `a + ++b` → `a+++b`)

---

## 6. Cache System

### Class: `autoptimizeCache` (855 lines)

### Storage

**Base path:** `WP_CONTENT_DIR/cache/autoptimize/`
**Subdirectories:** `/js/`, `/css/`

**Filename format (server gzipping mode, default):**
```
autoptimize_{MD5_HASH}.{js|css}
```

**MD5 hash is computed from:** The concatenated, pre-minified content (so same source always hits cache).

### Cache Operations

**Write (`cache()`):**
1. Write minified content to file
2. Optionally create `.gz` (gzip level 9) and `.br` (Brotli level 11) pre-compressed variants
3. Optionally create `autoptimize_fallback.{js|css}` as 404 fallback
4. Fire `autoptimize_action_cache_file_created` action

**Read (`check()` + `retrieve()`):**
- `check()`: `file_exists($cachedir . $filename)`
- `retrieve()`: `file_get_contents()` of the cached file

**Invalidation (`clearall()`):**
- **Classic:** Scan directory, delete all `autoptimize_*` files
- **Advanced (filter-enabled):** Atomic rename of entire cache directory + recreate empty. Old artifacts cleaned up separately.
- Post-clear: fire `autoptimize_action_cachepurged` on shutdown, optionally warm homepage cache

**Size limits:** No hard maximum. Stats tracked via transient (count/size, 1-hour TTL).

### 404 Fallback Handler

**Purpose:** If a cached file is deleted (cache clear) but a page still references the old hash, the 404 handler redirects to a generic fallback file.

**Files:**
- `.htaccess` in cache directory with `ErrorDocument 404` directive
- `wp-content/autoptimize_404_handler.php` — serves fallback with 1-year cache headers

### Cache Party Equivalent

We already have `wp-content/uploads/cache-party/critical-css/` for critical CSS files. For aggregated CSS/JS, we'll use:
- `wp-content/cache/cache-party/css/`
- `wp-content/cache/cache-party/js/`

Same pattern as AO: MD5 hash filenames, gzip variants, cleanup via admin action.

---

## 7. Exclusion System

### Three Layers of Exclusion

**Layer 1: Hardcoded `dontmove` arrays**

CSS hardcoded exclusions (2 entries):
- `data-noptimize`
- `.wp-container-` (block editor dynamic classes)

JS hardcoded exclusions (30+ entries):
- `document.write`, `admin-bar.min.js`, `GoogleAnalyticsObject`, `adsbygoogle`
- `data-noptimize`, `data-cfasync`, `data-pagespeed-no-defer`
- `nonce`, `post_id` (wp_localize_script patterns)
- Various ad network and analytics scripts

**Layer 2: User-configured exclusions (options)**

- `autoptimize_js_exclude` — comma-separated JS exclusion keywords
- `autoptimize_css_exclude` — comma-separated CSS exclusion keywords

**Layer 3: Filter-based exclusions**

- `autoptimize_filter_js_exclude` — returns comma-separated string or array with flags
- `autoptimize_filter_css_exclude` — returns comma-separated string
- `autoptimize_filter_js_dontmove` — modify hardcoded array
- `autoptimize_filter_js_allowlist` / `autoptimize_filter_css_allowlist` — whitelist mode

### Matching Mechanism

**Substring matching via `strpos()`** — NOT regex, NOT exact match:
```php
foreach ($exclude_list as $keyword) {
    if (strpos($tag, $keyword) !== false) {
        // Excluded
    }
}
```

### Comment-Based Exclusion

```html
<!-- noptimize -->
<script src="fragile.js"></script>
<!-- /noptimize -->
```

Content between `<!-- noptimize -->` markers is hidden before processing via marker replacement system, then restored after.

### Data Attribute Exclusion

| Attribute | Effect |
|-----------|--------|
| `data-noptimize` | Excluded from all optimization |
| `data-pagespeed-no-defer` | Excluded from deferral |
| `data-cfasync="false"` | Excluded from moving (CF compatibility) |
| `data-cp-skip` | Cache Party: excluded from CP processing |
| `data-aoc-skip` | AOC: excluded from AOC processing |

### Cache Party's Exclusion Strategy

We need to support:
1. **`data-cp-skip`** — our marker (already works)
2. **`data-noptimize`** — AO compatibility (already respected via dontmove)
3. **Keyword-based exclusion** — port AO's substring matching approach
4. **Comment wrapping** — port `<!-- noptimize -->` / `<!-- /noptimize -->` support
5. **Filter hooks** — expose `cp_filter_css_exclude`, `cp_filter_js_exclude` (plus preserve AO filter names for backward compatibility)

---

## 8. CSS Deferral & Critical CSS

### CSS Deferral Pattern

**The `media="print" onload` technique (in AO's `getcontent()`):**

```html
<!-- Critical CSS inlined (render-blocking) -->
<style id="aoatfcss" media="all">{critical CSS here}</style>

<!-- Full CSS deferred (non-render-blocking) -->
<link rel="stylesheet" media="print" href="aggregated.css"
      onload="this.media='all'">

<!-- No-JS fallback -->
<noscript><link rel="stylesheet" media="all" href="aggregated.css"></noscript>
```

**Timing sequence in AO:**
1. Critical CSS `<style id="aoatfcss">` injected first (render-blocking, above the fold)
2. Full aggregated CSS deferred with `media="print"` + `onload` handler
3. `<noscript>` fallback for no-JS environments

### Critical CSS Integration

**AO's `defer_inline` option** stores the critical CSS string. Sources:
- AO Pro's CCSS system (per-template/per-page rules)
- Filter `autoptimize_filter_css_defer_inline` (what Cache Party uses via AO Bridge)
- Manual entry in AO settings textarea

**Cache Party's current approach:**
- Generates per-template critical CSS via Railway headless Chrome
- Merges all templates via Railway `/api/merge-css` endpoint (deduplication)
- Syncs merged CSS to AO's `autoptimize_css_defer_inline` option
- AO inlines it as `<style id="aoatfcss">`

**After AO removal:**
- Cache Party injects critical CSS directly via `wp_head` at priority 2
- Already has this code path (`Critical_CSS::inject_critical_css()`)
- Currently skipped when `ao_defer_active()` returns true
- Just needs to always activate when aggregation is built-in

---

## 9. AO Pro Additions

### Overview (2,469 lines across 10 feature files)

AO Pro v2.4.0 adds premium features on top of free AO. **Relevant to our architecture:**

### Critical CSS Glue (133 lines)

- Hooks into AO Core's CCSS events (rule updated, rule removed)
- Template matching: URL paths for pages, conditional tags (is_front_page, etc.) for templates
- On post save → enqueue page for CCSS generation
- Cache invalidation: clears page cache when CCSS rule changes

**Cache Party equivalent:** We already have a more sophisticated system — per-template CSS files, multi-viewport generation, Railway merge endpoint. No need to port AO Pro's CCSS glue.

### Delayed JavaScript (134 lines)

- Allowlist mode (default): only delay scripts matching keyword list
- "Delay ALL" mode: delays everything, uses blocklist for exclusions
- External scripts: `src=` → `data-src=` + `data-type="lazyscript"`
- Inline scripts: base64-encode content → `data-src="data:text/javascript;base64,..."`
- Triggered by `delayedLoad` event on user interaction

**Cache Party equivalent:** Our `class-js-delay.php` (198 lines) does the same thing with `data-type="cp-delay"`. Same pattern, same event system. No need to port.

### Delayed CSS (46 lines)

- Modifies AO's deferred CSS links: removes `onload`, changes `rel="stylesheet"` → `rel="lazy-stylesheet"`
- On `delayedLoad` event: swaps `rel` back to activate
- Differs from CP's approach (we use media="print" onload which loads immediately)

**Cache Party equivalent:** We handle CSS deferral differently — we want CSS to load on page load (not wait for interaction). No need to port AO Pro's delayed CSS.

### Delayed Iframes (250 lines)

- `src=` → `data-src=` + `data-type="lazyframe"`
- Dynamic thumbnails via oEmbed (YouTube, Vimeo, Dailymotion)
- Triggered by `delayedLoad` event

**Cache Party equivalent:** Our `class-iframe-lazy.php` (46 lines) does basic iframe lazy loading. Video facades are on the roadmap but separate from aggregation work.

### What's Useful from AO Pro

**Nothing directly.** AO Pro's value is in features we already have (JS delay, critical CSS) or don't need (page caching, Shortpixel CDN, wizard). The CCSS glue logic is simpler than what we've already built.

---

## 10. What We DON'T Need

### Definitely Exclude

| AO Feature | Why Skip | Lines Saved |
|------------|----------|-------------|
| `autoptimizeExtra.php` | Google Fonts, emoji removal, preconnect — we handle differently | 662 |
| `autoptimizeImages.php` | We have our own Images module | ~800 |
| `autoptimizeHTML.php` | HTML minification — not in scope | ~200 |
| `autoptimizeCompatibility.php` | We'll handle compat our way | 146 |
| `autoptimizeToolbar.php` | Admin toolbar cache stats | ~100 |
| `autoptimizeMetabox.php` | Per-post settings metabox | ~150 |
| `autoptimizeConfig.php` | Settings page UI | ~900 |
| `autoptimizeCLI.php` | WP-CLI commands — we have our own | ~100 |
| `autoptimizePartners.php` | Partner integrations | ~100 |
| `autoptimizeExitSurvey.php` | Deactivation survey | ~100 |
| `autoptimizeCriticalCSS*.php` | 5 files for CCSS — we have our own system | ~1,500 |
| `autoptimizeProTab.php` | Pro upsell tab | ~50 |
| `autoptimizeVersionUpdatesHandler.php` | Version migration | ~100 |
| `autoptimizeCacheChecker.php` | Cache size monitoring | ~150 |
| CDN URL rewriting | Cloudflare handles this for us | ~200 within Base |
| Datauri inlining | Not used, not needed | ~150 within Styles |
| PHP gzip mode | Server handles gzipping | ~100 within Cache |

### Trim from Transplanted Code

Within the files we DO transplant, we can strip:
- **CDN URL rewriting** (`url_replace_cdn()`, `rewrite_assets()` CDN portions) — CF handles this
- **Datauri inlining** (`build_or_get_datauri_image()`, `is_datauri_candidate()`) — not needed
- **Font-face CDN handling** (`hide_fontface_and_maybe_cdn()`) — CF handles this
- **PHP gzip cache mode** — server handles gzipping
- **404 fallback handler** — WP Engine / Cloudflare handle 404s

---

## 11. Cache Party Current State

### What CP Currently Delegates to AO

| Feature | Owner | Mechanism |
|---------|-------|-----------|
| CSS aggregation | AO | AO's full pipeline |
| CSS minification | AO | YUI CSSMin |
| JS aggregation | AO | AO's full pipeline |
| JS minification | AO | JSMin |
| CSS deferral (media="print") | AO | AO's `defer` option |
| Critical CSS inlining | AO | `autoptimize_css_defer_inline` option |

### What CP Handles Independently

| Feature | Implementation | Lines |
|---------|---------------|-------|
| JS delay (data-src swap) | `class-js-delay.php` | 198 |
| CSS delete by keyword | `class-css-deferral.php` | 187 |
| CSS deferral (standalone) | `class-css-deferral.php` | (same file) |
| Critical CSS generation | `class-critical-css.php` + Railway | 332 |
| Image optimization | Images module (7 files) | ~1,200 |
| Resource hints / preload | `class-resource-hints.php` | 123 |
| Iframe lazy loading | `class-iframe-lazy.php` | 46 |
| Output buffer orchestration | `class-output-buffer.php` | 79 |
| AO bridge | `class-ao-bridge.php` | 83 |
| Settings UI | `class-settings.php` | 790 |

### What Must Keep Working After AO Removal

1. **JS delay** — fully independent, no changes needed
2. **Iframe lazy loading** — fully independent, no changes needed
3. **Image optimization** — fully independent, no changes needed
4. **Interaction loader** — fully independent, no changes needed
5. **Critical CSS injection** — needs to activate direct `wp_head` injection (currently skipped when AO defer is active)
6. **CSS deferral** — standalone mode already exists, just needs to always activate
7. **Resource hints** — CSS preload detection needs to work with CP's aggregated files instead of AO's

### AO Bridge Disposal

`class-ao-bridge.php` (83 lines) can be entirely removed once aggregation is built-in. Its sole purpose is to:
- Tell AO to exclude `data-cp-skip` and `data-type="cp-delay"` scripts
- Set AO cache max to 1GB
- Inject preload headers after AO minifies
- Sync critical CSS to AO's `defer_inline` option

All of these become unnecessary when CP owns the full pipeline.

---

## 12. Integration Plan: What Changes

### New Files to Create

| File | Purpose | Estimated Lines |
|------|---------|-----------------|
| `class-css-aggregator.php` | CSS collection, aggregation, URL rewriting | ~800 |
| `class-js-aggregator.php` | JS collection, aggregation, try-catch | ~500 |
| `class-aggregator-base.php` | Shared utilities: marker system, path resolution, exclusion matching | ~400 |
| `class-css-minifier.php` | Wrapper around YUI CSSMin (bundled) | ~50 |
| `class-js-minifier.php` | Wrapper around JSMin (bundled) | ~50 |
| `class-aggregation-cache.php` | Cache file management, cleanup, invalidation | ~400 |
| `lib/yui-cssmin/Minifier.php` | Bundled YUI CSS minifier (copy from AO) | 935 |
| `lib/jsmin/jsmin.php` | Bundled JSMin (copy from AO) | 467 |

### Files to Modify

| File | Changes |
|------|---------|
| `class-output-buffer.php` | Add aggregation processors at priority 1-2 (before deferral/delay) |
| `class-css-deferral.php` | Always run in standalone mode (remove AO-active check) |
| `class-critical-css.php` | Always inject via `wp_head` (remove `ao_defer_active()` check) |
| `class-resource-hints.php` | Detect CP aggregated files instead of AO's for preload |
| `class-asset-optimizer.php` | Register aggregation classes, remove AO bridge |
| `class-settings.php` | Add aggregation settings (enable/disable, exclusions) |
| `class-module-loader.php` | No changes needed |

### Files to Remove

| File | Reason |
|------|--------|
| `class-ao-bridge.php` | No longer needed — CP owns the pipeline |

### Processing Order in Output Buffer

```
Priority 1: CSS Aggregation (collect → concat → minify → cache → replace)
Priority 2: JS Aggregation (collect → concat → minify → cache → replace)
Priority 3: CSS Deferral (media="print" onload on aggregated + excluded CSS)
Priority 4: JS Delay (data-src swap on non-aggregated scripts)
Priority 5: Iframe Lazy (src → data-lazy-src)
Priority 999: Picture Wrapper (from Images module)
Priority 1000: Lazy Loader (from Images module)
```

### Critical CSS Flow (Post-AO)

```
1. wp_head [pri 2]: Inject per-template critical CSS from file
   → <style id="cp-critical-css" data-template="front-page">...</style>

2. Output Buffer [pri 1]: CSS Aggregation
   → Collect all <link> and <style> tags
   → Exclude critical CSS (has data-cp-skip)
   → Aggregate remaining into single file
   → Write to cache

3. Output Buffer [pri 3]: CSS Deferral
   → Apply media="print" onload to aggregated <link>
   → Inline styles already collected by aggregator

Result:
<style id="cp-critical-css">/* above-fold CSS */</style>
<link rel="stylesheet" media="print" href="/cache/cache-party/css/cp_abc123.css"
      onload="this.media='all'">
<noscript><link rel="stylesheet" href="/cache/cache-party/css/cp_abc123.css"></noscript>
```

---

## 13. Code Surface Estimate

### What We're Transplanting (from AO)

| Component | AO Source | Est. CP Lines | Notes |
|-----------|-----------|---------------|-------|
| CSS aggregation pipeline | autoptimizeStyles.php (1,337) | ~800 | Strip CDN, datauris, font-face CDN |
| JS aggregation pipeline | autoptimizeScripts.php (862) | ~500 | Strip move system (CP has its own), CDN |
| Shared base utilities | autoptimizeBase.php (711) | ~400 | Strip CDN rewriting, keep marker system + path resolution |
| CSS minifier wrapper | autoptimizeCSSmin.php (69) | ~50 | Thin wrapper with calc() protection |
| JS minifier | jsmin.php (467) | 467 | Copy as-is (GPL) |
| CSS minifier | Minifier.php (935) | 935 | Copy as-is (GPL) |
| Cache management | autoptimizeCache.php (855) | ~400 | Strip PHP gzip mode, 404 handler, .htaccess generation |
| **TOTAL** | **5,236** | **~3,550** | **32% reduction from stripping unneeded features** |

### Modifications to Existing CP Code

| File | Current Lines | Est. Changes |
|------|---------------|-------------|
| class-output-buffer.php | 79 | +20 (add aggregation processor registration) |
| class-css-deferral.php | 187 | -30 (remove AO-active branch) |
| class-critical-css.php | 332 | -20 (remove ao_defer_active check) |
| class-resource-hints.php | 123 | ~20 (detect CP aggregated files) |
| class-asset-optimizer.php | 217 | ~50 (register aggregators, remove AO bridge) |
| class-settings.php | 790 | +100 (aggregation settings: enable, exclusions) |
| class-ao-bridge.php | 83 | DELETE |
| **NET** | | **~+140 lines modified** |

### Total New Code

- **New files:** ~3,550 lines (including 1,402 lines of bundled minifiers copied as-is)
- **Modified files:** ~140 lines net change
- **Custom CP code:** ~2,150 lines (excluding bundled minifiers)

### Phasing Recommendation

**This is doable in one focused session** but could be safer in two:

**Phase A: CSS Aggregation + Deferral** (~1,800 lines)
- CSS aggregator, minifier wrapper, cache, base utilities
- Wire into output buffer
- Activate standalone CSS deferral + critical CSS injection
- Test on completeseo.com with AO deactivated

**Phase B: JS Aggregation** (~1,200 lines)
- JS aggregator, JSMin bundling
- Wire into output buffer
- Test JS aggregation alongside existing JS delay
- Remove AO bridge

---

## 14. Filter Hook Compatibility Map

### AO Filters Cache Party Should Preserve

These filters are used by third-party code and client-site customizations. Cache Party should expose equivalent hooks:

#### CSS Pipeline

| AO Filter | CP Equivalent | Priority |
|-----------|--------------|----------|
| `autoptimize_filter_css_noptimize` | `cp_filter_css_noptimize` | Must have |
| `autoptimize_filter_css_exclude` | `cp_filter_css_exclude` | Must have |
| `autoptimize_filter_css_removables` | `cp_filter_css_removables` | Nice to have |
| `autoptimize_filter_css_aggregate` | `cp_filter_css_aggregate` | Must have |
| `autoptimize_filter_css_defer` | `cp_filter_css_defer` | Must have |
| `autoptimize_filter_css_defer_inline` | `cp_filter_css_defer_inline` | Must have |
| `autoptimize_css_do_minify` | `cp_css_do_minify` | Nice to have |
| `autoptimize_css_after_minify` | `cp_css_after_minify` | Nice to have |
| `autoptimize_filter_css_replacetag` | `cp_filter_css_replacetag` | Nice to have |

#### JS Pipeline

| AO Filter | CP Equivalent | Priority |
|-----------|--------------|----------|
| `autoptimize_filter_js_noptimize` | `cp_filter_js_noptimize` | Must have |
| `autoptimize_filter_js_exclude` | `cp_filter_js_exclude` | Must have |
| `autoptimize_filter_js_removables` | `cp_filter_js_removables` | Nice to have |
| `autoptimize_filter_js_aggregate` | `cp_filter_js_aggregate` | Must have |
| `autoptimize_filter_js_defer` | `cp_filter_js_defer` | Must have |
| `autoptimize_js_do_minify` | `cp_js_do_minify` | Nice to have |
| `autoptimize_js_after_minify` | `cp_js_after_minify` | Nice to have |
| `autoptimize_filter_js_replacetag` | `cp_filter_js_replacetag` | Nice to have |

#### Global

| AO Filter | CP Equivalent | Priority |
|-----------|--------------|----------|
| `autoptimize_filter_html_before_minify` | `cp_html_before_optimize` | Must have |
| `autoptimize_html_after_minify` | `cp_html_after_optimize` | Must have |
| `autoptimize_action_cachepurged` | `cp_action_cache_purged` | Must have |

### AOC Filters Cache Party Already Supports

These AOC hooks have CP equivalents (already implemented as fallbacks):

| AOC Filter | CP Filter | Status |
|-----------|-----------|--------|
| `aoc_delete_srtyle_kw` | `cp_delete_style_kw` | Already in CP |
| `aoc_defer_srtyle_kw` | `cp_defer_style_kw` | Already in CP |
| `aoc_defer_srtyle_except_kw` | `cp_defer_style_except_kw` | Already in CP |
| `aoc_script_delete_kw` | `cp_script_delete_kw` | Already in CP |
| `aoc_delay_script_tag_atts_kw` | `cp_delay_script_tag_atts_kw` | Already in CP |
| `aoc_delay_script_code_kw` | `cp_delay_script_code_kw` | Already in CP |
| `aoc_script_move_to_end_kw` | `cp_script_move_to_end_kw` | Already in CP |
| `aoc_fonts_to_preload` | `cp_fonts_to_preload` | Already in CP |
| `aoc_images_to_preload` | `cp_images_to_preload` | Already in CP |
| `aoc_lazy_iframe_exclude_kw` | `cp_lazy_iframe_exclude_kw` | Already in CP |
| `aoc_iframe_html` | `cp_iframe_html` | Already in CP |
| `aoc_script_handle_src` | `cp_script_handle_src` | Already in CP |
| `aoc_style_handle_src` | `cp_style_handle_src` | Already in CP |

### Backward Compatibility Decision

**Recommended approach:** Expose CP-namespaced filters (`cp_filter_*`) as the primary API. For the "must have" AO filters listed above, also check if any callbacks are registered on the AO filter name and apply them. This handles sites that have custom code targeting AO filters.

```php
// Example: check both CP and AO filter names
$exclude = apply_filters('cp_filter_css_exclude', $exclude);
if (has_filter('autoptimize_filter_css_exclude')) {
    $exclude = apply_filters('autoptimize_filter_css_exclude', $exclude);
}
```

---

## End of Research Report

**Next step:** Review this report together and decide on implementation approach (single session vs phased, which edge cases to handle in v1 vs defer).
