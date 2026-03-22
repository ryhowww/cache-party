import { info } from "./logger.js";

/**
 * Extract critical (above-the-fold) CSS from a URL using Puppeteer + the
 * `critical` npm package.
 *
 * POST /api/critical-css
 * Body: { url: string, viewport_width?: number }
 * Returns: { css: string, url: string, viewport_width: number }
 *
 * Requires `puppeteer` and `critical` to be installed.
 */
export async function extractCriticalCSS(url, viewportWidth = 1300) {
  // Dynamic imports — these are heavy dependencies, only load when needed.
  const { generate } = await import("critical");

  info("CRITICAL-CSS", `Extracting from ${url} (viewport: ${viewportWidth}px)`);

  const result = await generate({
    src: url,
    width: viewportWidth,
    height: 900,
    inline: false,
    // Puppeteer options for Railway (headless Linux).
    puppeteer: {
      args: ["--no-sandbox", "--disable-setuid-sandbox"],
    },
  });

  // `critical` returns { css, html, uncritical } when inline: false.
  const css = typeof result === "string" ? result : result.css || "";

  info(
    "CRITICAL-CSS",
    `Done. ${css.length} bytes extracted from ${url}`
  );

  return css;
}
