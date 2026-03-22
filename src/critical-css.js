import { info } from "./logger.js";

/**
 * Extract critical (above-the-fold) CSS from a URL using Puppeteer + the
 * `critical` npm package.
 *
 * POST /api/critical-css
 * Body: { url: string, viewport_width?: number }
 * Returns: { css: string, url: string, viewport_width: number }
 */
export async function extractCriticalCSS(url, viewportWidth = 1300) {
  const { generate } = await import("critical");

  info("CRITICAL-CSS", `Extracting from ${url} (viewport: ${viewportWidth}px)`);

  const executablePath = process.env.PUPPETEER_EXECUTABLE_PATH || undefined;

  const { css } = await generate({
    // `url` for remote pages (not `src` which is for local files).
    url,
    width: viewportWidth,
    height: 900,
    inline: false,
    extract: true,
    penthouse: {
      puppeteer: {
        executablePath,
        args: [
          "--no-sandbox",
          "--disable-setuid-sandbox",
          "--disable-dev-shm-usage",
          "--disable-gpu",
          "--single-process",
        ],
      },
    },
  });

  info("CRITICAL-CSS", `Done. ${css.length} bytes extracted from ${url}`);

  return css;
}
