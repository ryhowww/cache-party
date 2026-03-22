import { info } from "./logger.js";

/**
 * Extract critical (above-the-fold) CSS from a URL using Penthouse + Puppeteer.
 *
 * POST /api/critical-css
 * Body: { url: string, css_url?: string, viewport_width?: number }
 * Returns: { css: string, url: string, viewport_width: number }
 */
export async function extractCriticalCSS(url, cssUrl, viewportWidth = 1300) {
  const penthouse = (await import("penthouse")).default;
  const puppeteer = await import("puppeteer");

  info("CRITICAL-CSS", `Extracting from ${url} (viewport: ${viewportWidth}px)`);

  // If no CSS URL provided, scrape the page to find linked stylesheets.
  if (!cssUrl) {
    info("CRITICAL-CSS", "No css_url provided, auto-detecting from page...");
    const browser = await puppeteer.default.launch({
      executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
      args: [
        "--no-sandbox",
        "--disable-setuid-sandbox",
        "--disable-dev-shm-usage",
        "--disable-gpu",
        "--single-process",
      ],
    });

    try {
      const page = await browser.newPage();
      await page.goto(url, { waitUntil: "networkidle2", timeout: 30000 });

      // Grab all stylesheet URLs from link tags AND noscript deferred blocks.
      const cssUrls = await page.evaluate(() => {
        const urls = [];
        // Regular link stylesheets.
        document.querySelectorAll('link[rel="stylesheet"]').forEach((el) => {
          if (el.href) urls.push(el.href);
        });
        // Deferred stylesheets inside noscript blocks.
        document.querySelectorAll("noscript").forEach((ns) => {
          const tmp = document.createElement("div");
          tmp.innerHTML = ns.textContent;
          tmp.querySelectorAll('link[rel="stylesheet"]').forEach((el) => {
            const href = el.getAttribute("href");
            if (href) urls.push(href);
          });
        });
        return urls;
      });

      await browser.close();

      if (cssUrls.length === 0) {
        throw new Error("No stylesheets found on page.");
      }

      cssUrl = cssUrls[0];
      info("CRITICAL-CSS", `Auto-detected CSS: ${cssUrl}`);
    } catch (err) {
      await browser.close();
      throw err;
    }
  }

  const css = await penthouse({
    url,
    cssString: undefined,
    css: cssUrl,
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
