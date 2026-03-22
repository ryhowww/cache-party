# Performance Plugin Feature Spec

Features from two existing plugins that could be combined into a single unified performance plugin.

---

## Autoptimize Custom — Current Features

### Deferred CSS Loading
Intercepts the HTML output buffer, extracts all `<style>` and `<link rel="stylesheet">` tags, and moves them into a `<noscript id="deferred-styles">` block at `</body>`. CSS is loaded only when the user interacts (mousemove, scroll > 30px, or touchstart). This keeps the initial page load free of render-blocking CSS.

Filter hooks for customization:
- `aoc_delete_srtyle_kw` — delete specific stylesheets by keyword
- `aoc_defer_srtyle_kw` — defer specific stylesheets by keyword
- `aoc_defer_srtyle_except_kw` — defer all stylesheets except those matching keywords

### Delayed Script Loading
Same pattern for JS — scripts matching configured keywords are extracted from the page and placed in `<noscript id="delayed-scripts">`. They execute sequentially (with async/await for external scripts) on first user interaction.

Filter hooks:
- `aoc_script_delete_kw` — delete scripts by keyword
- `aoc_delay_script_tag_atts_kw` — delay scripts by tag attribute keyword
- `aoc_delay_script_code_kw` — delay scripts by inline code keyword
- `aoc_script_move_to_end_kw` — move scripts to end of body

Currently delayed: reCAPTCHA, GTM, Facebook pixel, Hotjar.

### Script/Style Handle Replacement
Can replace the `src` of any enqueued script or style by handle name. Useful for swapping in CDN versions or removing specific assets entirely.

### Lazy Loading
- **Images**: Adds `loading="lazy"` to `<img>` tags that don't already have it. Excludes by keyword via `aoc_lazy_image_exclude_kw` filter.
- **iFrames**: Converts `src=` to `data-lazy-src=` on iframe tags. The `aoc.js` loader swaps them back on user interaction.

### Resource Preloading
Sends HTTP `Link` headers (or injects `<link rel="preload">`) for:
- Autoptimize-generated CSS bundles
- Fonts (via `aoc_fonts_to_preload` filter, with optional desktop-only media query)
- Images (via `aoc_images_to_preload` filter, supports per-post preload via `ao_post_optimize` meta)

### Critical CSS
Inlines a small critical CSS block in `<head>` (scrollbar, body margin, page max-width) to prevent layout shift before deferred styles load.

### Autoptimize Integration
- Processes deferred styles and delayed scripts through Autoptimize's minification and caching pipeline (via `AO_Helper` class)
- CSS preload via HTTP Link headers for autoptimize cache files
- Exclusion rules: skips elements with `data-aoc-skip` attribute
- 1GB max cache size

### User Interaction Loader (`aoc.js`)
Vanilla JS (no dependencies). On first user interaction after `window.load`:
1. Injects deferred CSS from noscript block
2. Executes delayed scripts sequentially
3. Swaps `data-lazy-src` to `src` on iframes and scripts
4. Adds scroll-state body class (`aoc-scrolled`)

Triggers: `mousemove` (once), `scroll` > 30px, `touchstart`.

### Plugin-Specific Configs
Auto-includes config files from `plugins/` directory when the matching plugin is active. Currently has configs for PixelYourSite and Duracelltomi GTM (delay their scripts until interaction).

---

## ImageOptimizer.ai — Current Features

### WebP Conversion on Upload
Automatically converts JPEG and PNG images to WebP when uploaded to the media library. Converts the full-size image plus all registered WordPress thumbnail sizes. Uses Imagick (preferred) with GD fallback. Quality: 80%.

Skips conversion if the WebP file would be larger than the original. Stores WebP file paths in `_imageoptimizerai_webp` post meta.

### Picture Element Wrapping
Hooks into `the_content` and `post_thumbnail_html` filters (priority 999) to rewrite `<img>` tags as `<picture>` elements with WebP `<source>`:

```html
<picture>
  <source type="image/webp" srcset="image.webp">
  <img src="image.jpg" ...>
</picture>
```

Handles responsive images — if the `<img>` has `srcset`, all URLs are converted to their WebP equivalents in the `<source>` tag. Copies `sizes` attribute to `<source>`.

Skips: SVG, GIF, WebP, ICO, BMP, data URIs, external images. Protects existing `<picture>` elements from double-wrapping.

### Theme Helper Function
`imageoptimizerai_img($source, $attrs)` — accepts attachment ID, URL, or relative path. Outputs a `<picture>` tag with WebP source if available. For use in theme templates where `the_content` filter doesn't apply.

### Auto Alt Text on Upload
Sets `_wp_attachment_image_alt` from the filename on upload. Strips dashes, underscores, plus signs, and periods, replaces with spaces. Only runs if alt text is empty. Controlled by `imageoptimizerai_boolean` setting.

### WP-CLI Bulk Conversion
`wp imageoptimizerai convert-webp` — bulk converts all existing images to WebP. Supports `--dry-run` and `--id=<attachment_id>`. Processes in 50-image batches to manage memory. Reports converted/skipped/errors.

### Admin Features
- **Settings page** (Settings > Image Optimizer): toggle auto-alt, toggle WebP, toggle cleanup on uninstall, shows available conversion engine
- **Media library column**: shows WebP status with savings percentage or "Convert" link
- **Attachment metabox**: stats table showing original vs WebP file sizes for each thumbnail size, with convert/regenerate buttons
- **Bulk conversion UI**: progress bar on settings page with start/stop controls
- **AJAX endpoints**: single convert, batch convert, auto-batch, bulk stats

### Cleanup
Deletes WebP files when the original attachment is deleted. Optional setting to delete all WebP files on plugin uninstall.

---

## Potential Additional Features

- **Cache warmer connection** — trigger a cache warm after bulk operations or content changes
- **Cloudflare cache purge** — API integration to purge CF cache on content update (was partially built in autoptimize-custom but disabled)
- **Performance dashboard** — single admin page showing WebP conversion stats, deferred asset counts, and cache status
- **Per-page optimization controls** — meta box to configure preloaded images, critical CSS overrides, or skip optimization entirely for specific pages
