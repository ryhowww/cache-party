import { info } from "./logger.js";

/**
 * Extract critical (above-the-fold) CSS from a URL using Penthouse + Puppeteer.
 *
 * POST /api/critical-css
 * Body: { url: string, viewport_width?: number }
 * Returns: { css: string, url: string, viewport_width: number }
 */
export async function extractCriticalCSS(url, viewportWidth = 1300) {
  const penthouse = (await import("penthouse")).default;

  info("CRITICAL-CSS", `Extracting from ${url} (viewport: ${viewportWidth}px)`);

  const css = await penthouse({
    url,
    width: viewportWidth,
    height: 900,
    puppeteer: {
      executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
      args: [
        "--no-sandbox",
        "--disable-setuid-sandbox",
        "--disable-dev-shm-usage",
        "--disable-gpu",
        "--single-process",
      ],
    },
  });

  info("CRITICAL-CSS", `Done. ${css.length} bytes extracted from ${url}`);

  return css;
}
