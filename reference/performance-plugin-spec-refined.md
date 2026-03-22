# WordPress Performance Plugin — Refined Spec

*Working name: TBD by Ryan*

---

## What This Is

A WordPress plugin that consolidates Autoptimize Custom + ImageOptimizer.ai into a single plugin, with new features (critical CSS, cache warmer connection, presets, debug overlay). Uses Autoptimize (the base plugin) as a dependency for CSS/JS minification — doesn't replace it.

Runs across 60+ client sites (home services on WP Engine) and Complete SEO's own properties.

---

## Architecture Decision: Wrap Autoptimize, Don't Replace It

**Why**: Autoptimize is 18K LOC of battle-tested minification with 40+ filter hooks, handling edge cases like IE conditional comments, wp_localize_script inline data, nonce variables, @import chain resolution, calc() expression preservation, and multisite domain mapping. Rebuilding this is months of work for zero user-visible benefit.

**What we build**: Everything that sits ON TOP of minification — deferral, delay, lazy loading, image optimization, critical CSS, presets, warmer connection. These are the parts that actually move PageSpeed scores.

**Dependency**: Autoptimize must be active. Plugin checks on activation and shows admin notice if missing. Asset Optimizer module gracefully degrades (deferred CSS/delayed JS still work without minification, just unminified).

---

## Plugin Structure

```
{plugin-name}/
├── {plugin-name}.php                # Main plugin file, module loader
├── includes/
│   ├── class-module-loader.php      # Detects settings, loads enabled modules
│   ├── class-settings.php           # Unified settings page (tabbed)
│   ├── class-debug.php              # Debug overlay (?perf-debug=1)
│   ├── class-preset-manager.php     # JSON export/import of configs
│   │
│   ├── assets/                      # Asset Optimizer module
│   │   ├── class-asset-optimizer.php # Main orchestrator
│   │   ├── class-css-deferral.php   # Deferred CSS loading (ported from AOC core.php)
│   │   ├── class-js-delay.php       # Delayed script execution (ported from AOC core.php)
│   │   ├── class-resource-hints.php # Preload, preconnect, fetchpriority
│   │   ├── class-critical-css.php   # Template-based critical CSS inlining
│   │   ├── class-ao-bridge.php      # Autoptimize integration hooks
│   │   └── js/
│   │       └── interaction-loader.js # Ported from aoc.js + enhancements
│   │
│   ├── images/                      # Image Optimizer module
│   │   ├── class-image-optimizer.php # Main orchestrator
│   │   ├── class-webp-converter.php # WebP conversion (Imagick/GD)
│   │   ├── class-picture-wrapper.php # <picture> element wrapping
│   │   ├── class-lazy-loader.php    # Smart lazy loading (images, iframes, video)
│   │   ├── class-auto-alt.php       # Auto alt text from filename
│   │   ├── class-video-facade.php   # YouTube/Vimeo lightweight placeholders
│   │   └── class-cli-commands.php   # WP-CLI bulk conversion
│   │
│   └── warmer/                      # Cache Warmer connection module
│       ├── class-warmer-client.php  # API client for Railway warmer
│       └── class-purge-hooks.php    # save_post etc. → notify warmer
│
├── presets/                         # Bundled configs
│   ├── home-services.json
│   └── agency-site.json
│
├── critical-css/                    # Generated critical CSS (per template)
│   └── (static files, generated via CLI)
│
└── uninstall.php
```

---

## Module 1: Asset Optimizer

### What It Does (ported from Autoptimize Custom)

This module does NOT minify or concatenate — Autoptimize handles that. This module handles everything that happens AFTER minification: deferring CSS, delaying JS, lazy loading resources, preloading critical assets.

### CSS Deferral
Intercepts HTML output buffer. Extracts stylesheets and moves them to a `<noscript id="deferred-styles">` block at `</body>`. Loaded on first user interaction via the interaction loader JS.

Filter hooks (preserved with original names including typos for backward compatibility):
- `aoc_delete_srtyle_kw` — delete stylesheets by keyword
- `aoc_defer_srtyle_kw` — defer specific stylesheets by keyword
- `aoc_defer_srtyle_except_kw` — defer all except matching keywords

New properly-named equivalents added alongside (both work):
- `cp_delete_style_kw`, `cp_defer_style_kw`, `cp_defer_style_except_kw`

### JS Delay
Extracts scripts matching keywords, places in `<noscript id="delayed-scripts">`, executes sequentially on first user interaction.

Filter hooks (preserved):
- `aoc_script_delete_kw` — delete scripts
- `aoc_delay_script_tag_atts_kw` — delay by tag attributes
- `aoc_delay_script_code_kw` — delay by inline code content
- `aoc_script_move_to_end_kw` — reposition to end of body

Default delayed: reCAPTCHA, GTM, Facebook pixel, Hotjar.

### Interaction Loader JS
Ported from `aoc.js` — vanilla JS, no dependencies.

Triggers: `mousemove` (once), `scroll` > 30px, `touchstart`.

On trigger:
1. Injects deferred CSS from noscript block
2. Executes delayed scripts sequentially (async/await for external)
3. Swaps `data-lazy-src` to `src` on iframes
4. Adds `aoc-scrolled` body class

**Enhancement**: Add `requestIdleCallback` fallback — if browser is idle 5 seconds with no interaction, start loading anyway. Handles users who land and read without scrolling.

### Resource Hints
- HTTP `Link` headers for CSS bundle preload
- Font preload via `aoc_fonts_to_preload` filter (with desktop-only media query option)
- Image preload via `aoc_images_to_preload` filter
- Per-post preload via `ao_post_optimize` post meta
- **New**: `fetchpriority="high"` on LCP image (configurable per page via meta box)

### Template-Based Critical CSS
**New feature.** Static critical CSS files per WordPress template type.

How it works:
1. Generate via WP-CLI: `wp {plugin} generate-critical --template=front-page --url=https://example.com/`
   - Uses `critical` npm package (Addy Osmani's tool)
   - Saves to `critical-css/{template}.css`
   - Falls back to manual placement if Node.js unavailable
2. On page load: detect current template → inline matching critical CSS in `<head>` before deferred styles
3. Template detection priority: custom template slug → page/single/archive → front-page → default

### Autoptimize Bridge
Hooks into Autoptimize's filter system:
- `autoptimize_filter_html_before_minify` — pre-process deferred/delayed noscript blocks
- `autoptimize_html_after_minify` — post-process, inject preloads, restore noscript blocks
- `autoptimize_filter_css_exclude` / `autoptimize_filter_js_exclude` — respect `data-cp-skip`
- `autoptimize_filter_cachecheck_maxsize` — 1GB max
- `AO_Helper` class — processes deferred CSS and delayed JS through Autoptimize's minification pipeline

### Plugin-Specific Configs
Auto-detection: if a known plugin is active (PixelYourSite, GTM plugins), automatically add their scripts to the delay list. Configurable via presets.

---

## Module 2: Image Optimizer

### WebP Conversion
Ported from ImageOptimizer.ai. Converts JPEG/PNG to WebP on upload. Imagick preferred, GD fallback. Quality: 80% (configurable). All registered thumbnail sizes.

Migration: reads existing `_imageoptimizerai_webp` post meta on activation.

### Picture Element Wrapping
Hooks `the_content` and `post_thumbnail_html` (priority 999). Rewrites `<img>` to `<picture>` with WebP `<source>`. Handles responsive `srcset`. Skips SVG/GIF/WebP/ICO/external.

Theme helper: `{plugin}_img($source, $attrs)` — for template use where `the_content` filter doesn't apply.

### Smart Lazy Loading
- `loading="lazy"` on all images by default
- First N images (default 2, configurable): `loading="eager"` + `fetchpriority="high"` instead
- Featured images in hero position: always eager
- iFrame lazy loading: `src` → `data-lazy-src`, swap on interaction
- Add `width`/`height` from attachment metadata where missing (CLS prevention)
- Exclude by keyword via filter hook

### Video Facades
Detect YouTube/Vimeo iframes in content. Replace with lightweight placeholder (thumbnail + play button CSS). Load actual player on click. Respect `data-cp-skip`.

### Auto Alt Text
Set alt from filename on upload. Dashes/underscores/dots → spaces. Only if empty. Toggleable.

### WP-CLI
```bash
wp {plugin} convert-webp [--dry-run] [--id=<id>]
wp {plugin} stats
```

### Admin UI
- Media library column: WebP status + savings %
- Attachment metabox: per-size stats, convert/regenerate buttons
- Bulk conversion with progress bar on settings page

---

## Module 3: Cache Warmer Connection

### Purpose
Connects to the external Railway-hosted cache warmer. Does NOT warm caches itself.

### Notify on Content Change
Hooks into `save_post`, `trashed_post`, `deleted_post` → URL-specific warm request.
Hooks into `wp_update_nav_menu`, `customize_save_after` → full site warm request.

Non-blocking: `wp_remote_post` with `'blocking' => false`.

### REST API Endpoint
`GET /wp-json/{plugin}/v1/cache-info` — exposes cache TTL and template list for the warmer to auto-calculate warming intervals.

### Admin UI
- API URL + Key fields
- Connection test button
- Last warm time + status display

---

## Settings Page

Single page: `Settings > {Plugin Name}` with tabs.

**General**: Module toggles, optimization mode (Safe/Balanced/Aggressive presets), warmer API config.

**Assets**: CSS deferral on/off + keywords, JS delay on/off + keywords, preload configs, critical CSS status.

**Images**: WebP toggle + quality, picture wrapping, lazy loading + eager count, auto alt, video facades.

**Warmer**: API config, connection status, hook toggles.

**Tools**: Debug overlay toggle, cache stats, clear cache, export/import presets.

### Presets
JSON export/import of all settings. Bundled defaults: `home-services.json`, `agency-site.json`.

```bash
wp {plugin} preset export > config.json
wp {plugin} preset import config.json
wp {plugin} preset apply home-services
```

---

## Debug Overlay

When `?perf-debug=1` + admin logged in: fixed panel showing applied optimizations, deferred asset count, WebP status, lazy/eager image count, LCP image, warmer status, critical CSS template match.

---

## Migration Path

1. Install alongside existing plugins (all modules off)
2. `wp {plugin} migrate --from=autoptimize-custom,imageoptimizerai` imports settings
3. Enable Image module → deactivate ImageOptimizer.ai
4. Enable Asset module → deactivate Autoptimize Custom (keep Autoptimize base active)
5. Enable Warmer → configure API key
6. Verify via debug overlay
7. Deactivate old plugins

---

## What This Plugin Does NOT Do

- **Minification/concatenation** — Autoptimize does this
- **Page caching** — Varnish (WPE) + Cloudflare
- **CDN** — Cloudflare
- **Image CDN** — all local via Imagick/GD
- **Critical CSS generation at runtime** — CLI/CI operation only
- **Cache warming directly** — Railway app does this

---

## Build Order

**Phase 1**: Foundation + Images (replace ImageOptimizer.ai)
**Phase 2**: Assets (replace Autoptimize Custom, keep Autoptimize base)
**Phase 3**: Critical CSS + Debug overlay
**Phase 4**: Warmer connection + Presets + Migration CLI

---

## Key Differences from Claude AI Spec

| Claude AI Spec | This Spec | Why |
|---|---|---|
| Replace Autoptimize entirely | Keep Autoptimize as dependency | 18K LOC of edge case handling we don't want to maintain |
| matthiasmullie/minify for CSS/JS | Autoptimize's YUI CSS + JSMin | Battle-tested, handles IE hacks, calc(), @import chains |
| Build own cache management | Use Autoptimize's cache | MD5 dedup, gzip, multisite support already done |
| Monolithic plugin | Modular wrapper | Each module independent, can enable/disable |
| Full rewrite | Port + enhance | AOC's output buffer approach works, just needs cleanup |

---

## Technical Constraints

- PHP 7.4+, WordPress 6.0+
- No external API calls on page load (warmer is non-blocking)
- Works behind WPE Varnish + Cloudflare (with or without APO/Cache Rules)
- `data-aoc-skip` and new `data-cp-skip` both respected
- Cache directory: `wp-content/cache/{plugin-name}/` (outside plugin dir)
- Autoptimize required as active plugin for Asset module minification

---

## Things to Think About (Ryan)

1. **Plugin name** — needs to work as a slug, be brandable, and make sense on 60+ client sites
2. **Autoptimize dependency** — comfortable with this? Alternative is a much bigger build to replace it
3. **Video facades** — worth building in v1 or defer to v2?
4. **Critical CSS** — the `critical` npm package needs Node.js which WPE doesn't have. Generate locally or in CI, commit the files. OK with that workflow?
5. **Preset defaults** — what should "Safe" vs "Balanced" vs "Aggressive" actually defer/delay? Need your input on the keyword lists per mode
