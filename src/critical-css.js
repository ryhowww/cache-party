import { info } from "./logger.js";

/**
 * Default viewport dimensions — three ranges covering standard WordPress breakpoints.
 *
 * WordPress themes typically break at 768px (mobile→tablet) and 1024px (tablet→desktop).
 * Each viewport lands clearly inside one range:
 *
 *   412×896  — Mobile (Lighthouse test viewport, inside 0–767px range)
 *   900×1024 — Tablet (inside 768–1023px range, avoids boundary)
 *   1300×900 — Desktop (above all common breakpoints)
 *
 * The merged output captures @media rules for all three ranges, so CLS is eliminated
 * regardless of which device the visitor uses.
 */
const DEFAULT_DIMENSIONS = [
  { width: 412, height: 896 },
  { width: 900, height: 1024 },
  { width: 1300, height: 900 },
];

const MAX_CRITICAL_SIZE = 40 * 1024; // 40KB uncompressed warning threshold

/**
 * Extract critical CSS from a URL at multiple viewport sizes.
 *
 * Runs Penthouse once per viewport (sequentially to limit memory), concatenates
 * the CSS outputs, then deduplicates and minifies via clean-css level 2.
 *
 * Follows the pattern from Addy Osmani's `critical` package:
 * - Dimensions sorted ascending by width
 * - CSS strings concatenated with space separator
 * - Single clean-css pass with selective level-2 dedup
 *
 * @param {string} url - Page URL to extract critical CSS from
 * @param {Object} options
 * @param {Array<{width: number, height: number}>} [options.dimensions] - Override viewports
 * @param {string} [options.cssUrl] - Override CSS URL (auto-detected if omitted)
 * @param {string} [options.template] - Template name for logging
 * @returns {Promise<{css: string, sizeKB: number, dimensions: string[], generatedAt: string}>}
 */
export async function extractCriticalCSS(url, options = {}) {
  const CleanCSS = (await import("clean-css")).default;
  const penthouse = (await import("penthouse")).default;
  const puppeteer = await import("puppeteer");

  const dimensions = (options.dimensions || DEFAULT_DIMENSIONS)
    .slice()
    .sort((a, b) => a.width - b.width);

  const template = options.template || "unknown";

  info(
    "CRITICAL-CSS",
    `Generating for ${url} (template: ${template}, viewports: ${dimensions.map((d) => `${d.width}x${d.height}`).join(", ")})`
  );

  const puppeteerArgs = {
    executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
    args: [
      "--no-sandbox",
      "--disable-setuid-sandbox",
      "--disable-dev-shm-usage",
      "--disable-gpu",
      "--single-process",
    ],
  };

  // Auto-detect CSS URL if not provided.
  let cssString = null;
  if (options.cssUrl) {
    info("CRITICAL-CSS", `Fetching CSS from ${options.cssUrl}`);
    const resp = await fetch(options.cssUrl);
    if (!resp.ok) throw new Error(`Failed to fetch CSS: HTTP ${resp.status}`);
    cssString = await resp.text();
  } else {
    cssString = await autoDetectAndFetchCSS(url, puppeteerArgs);
  }

  info("CRITICAL-CSS", `CSS input: ${cssString.length} bytes`);

  // Run Penthouse sequentially per viewport to limit memory pressure.
  // blockJSRequests: true — critical CSS renders before JS, so we extract
  // the server-rendered HTML state (same approach as NitroPack).
  const results = [];
  for (const dim of dimensions) {
    info("CRITICAL-CSS", `Extracting at ${dim.width}x${dim.height}...`);
    const css = await penthouse({
      url,
      cssString,
      width: dim.width,
      height: dim.height,
      blockJSRequests: true,
      timeout: 60000,
      renderWaitTime: 500,
      puppeteer: puppeteerArgs,
    });
    results.push(css);
    info("CRITICAL-CSS", `  → ${css.length} bytes`);
  }

  // Single viewport — skip dedup overhead.
  if (results.length === 1) {
    const output = results[0];
    return formatResult(output, dimensions, url);
  }

  // Multi-viewport — concatenate and deduplicate.
  // Following critical's pattern: join with space, single clean-css pass.
  const combined = results.join(" ");

  const minified = new CleanCSS({
    level: {
      1: { all: true },
      2: {
        all: false,
        removeDuplicateFontRules: true,
        removeDuplicateMediaBlocks: true,
        removeDuplicateRules: true,
        removeEmpty: true,
        mergeMedia: true,
      },
    },
  }).minify(combined);

  if (minified.errors && minified.errors.length > 0) {
    info("CRITICAL-CSS", `clean-css errors: ${minified.errors.join(", ")}`);
  }

  const output = minified.styles;
  return formatResult(output, dimensions, url);
}

function formatResult(css, dimensions, url) {
  const sizeBytes = Buffer.byteLength(css, "utf8");
  const sizeKB = Math.round(sizeBytes / 1024);

  if (sizeBytes > MAX_CRITICAL_SIZE) {
    info(
      "CRITICAL-CSS",
      `WARNING: ${url} critical CSS is ${sizeKB}KB — exceeds recommended 40KB max`
    );
  }

  info("CRITICAL-CSS", `Done. ${sizeKB}KB final output.`);

  return {
    css,
    sizeKB,
    dimensions: dimensions.map((d) => `${d.width}x${d.height}`),
    generatedAt: new Date().toISOString(),
  };
}

/**
 * Auto-detect stylesheet URLs from a page and fetch combined CSS.
 * Launches headless Chrome, collects all <link rel="stylesheet"> hrefs
 * plus any inside <noscript> blocks, fetches and concatenates them.
 */
async function autoDetectAndFetchCSS(url, puppeteerArgs) {
  const puppeteer = await import("puppeteer");
  info("CRITICAL-CSS", "Auto-detecting stylesheets...");

  const browser = await puppeteer.default.launch(puppeteerArgs);
  try {
    const page = await browser.newPage();
    await page.goto(url, { waitUntil: "networkidle2", timeout: 30000 });

    const cssUrls = await page.evaluate(() => {
      const urls = [];
      document.querySelectorAll('link[rel="stylesheet"]').forEach((el) => {
        if (el.href) urls.push(el.href);
      });
      document.querySelectorAll("noscript").forEach((ns) => {
        const tmp = document.createElement("div");
        tmp.innerHTML = ns.textContent;
        tmp.querySelectorAll('link[rel="stylesheet"]').forEach((el) => {
          const href = el.getAttribute("href");
          if (href) urls.push(href);
        });
      });
      return [...new Set(urls)];
    });

    await browser.close();

    if (cssUrls.length === 0) {
      throw new Error("No stylesheets found on page.");
    }

    info("CRITICAL-CSS", `Found ${cssUrls.length} stylesheets`);

    // Fetch and concatenate all stylesheets (Penthouse needs a single string).
    const sheets = await Promise.all(
      cssUrls.map(async (cssUrl) => {
        const resp = await fetch(cssUrl);
        if (!resp.ok) {
          info("CRITICAL-CSS", `  Skipping ${cssUrl}: HTTP ${resp.status}`);
          return "";
        }
        return resp.text();
      })
    );

    return sheets.join("\n");
  } catch (err) {
    await browser.close();
    throw err;
  }
}
