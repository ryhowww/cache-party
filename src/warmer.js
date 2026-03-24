import pLimit from "p-limit";
import { getUrls } from "./sitemap.js";
import { updateWarmResults } from "./db.js";
import { info, error, warmLine } from "./logger.js";

// Track running warms by site ID.
const running = new Map();

export function isRunning(siteId) {
  return running.get(siteId) === true;
}

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

/**
 * Default warm settings (used when DB row doesn't have them).
 */
const DEFAULTS = {
  concurrency: 5,
  delay_ms: 100,
  timeoutMs: 10000,
  userAgent: "CacheWarmer/1.0 (+https://completeseo.com)",
};

async function warmUrl(url, site) {
  const timeoutMs = site.timeoutMs || DEFAULTS.timeoutMs;
  const userAgent = site.userAgent || DEFAULTS.userAgent;

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  const start = performance.now();

  try {
    const res = await fetch(url, {
      headers: {
        "User-Agent": userAgent,
        Accept: "text/html",
      },
      redirect: "follow",
      signal: controller.signal,
    });

    const ttfb = Math.round(performance.now() - start);
    await res.text(); // Consume full body.

    const cacheStatus = res.headers.get("cf-cache-status") || null;
    const age = res.headers.get("age") || null;

    warmLine(res.status, cacheStatus, ttfb, url);

    return { url, status: res.status, cacheStatus, age, ttfb, error: null };
  } catch (err) {
    const ttfb = Math.round(performance.now() - start);
    warmLine("ERR", null, ttfb, url);
    return { url, status: null, cacheStatus: null, age: null, ttfb, error: err.message };
  } finally {
    clearTimeout(timer);
  }
}

/**
 * Warm a site. Accepts a DB row from the sites table.
 *
 * Expected shape: { id, url, sitemap_path, concurrency, delay_ms, enabled }
 */
export async function warmSite(site) {
  const siteId = site.id;
  const domain = new URL(site.url).hostname;

  if (running.get(siteId)) {
    info("WARM", `${domain} - already running, skipping`);
    return null;
  }

  running.set(siteId, true);
  const startTime = performance.now();

  // Build a compat object for sitemap.js (expects domain + sitemapPath).
  const siteConfig = {
    domain,
    sitemapPath: site.sitemap_path || "/sitemap_index.xml",
    concurrency: site.concurrency || DEFAULTS.concurrency,
    delayMs: site.delay_ms || DEFAULTS.delay_ms,
    timeoutMs: DEFAULTS.timeoutMs,
    userAgent: DEFAULTS.userAgent,
  };

  try {
    const urls = await getUrls(siteConfig);
    if (urls.length === 0) {
      info("WARM", `${domain} - no URLs found, skipping`);
      return null;
    }

    info("WARM", `${domain} - warming ${urls.length} URLs with concurrency=${siteConfig.concurrency}, delay=${siteConfig.delayMs}ms`);

    const limit = pLimit(siteConfig.concurrency);
    const results = [];

    const batchSize = siteConfig.concurrency;
    for (let i = 0; i < urls.length; i += batchSize) {
      const batchUrls = urls.slice(i, i + batchSize);
      const batchResults = await Promise.all(
        batchUrls.map((url) => limit(() => warmUrl(url, siteConfig)))
      );
      results.push(...batchResults);

      if (i + batchSize < urls.length) {
        await sleep(siteConfig.delayMs);
      }
    }

    const duration = Math.round(performance.now() - startTime);
    const hits = results.filter((r) => r.cacheStatus === "HIT").length;
    const misses = results.filter((r) => r.cacheStatus === "MISS").length;
    const expired = results.filter((r) => r.cacheStatus === "EXPIRED").length;
    const errors = results.filter((r) => r.error).length;
    const ttfbs = results.filter((r) => r.ttfb && !r.error).map((r) => r.ttfb);
    const avgTtfb = ttfbs.length > 0 ? Math.round(ttfbs.reduce((a, b) => a + b, 0) / ttfbs.length) : 0;
    const durationStr = formatDuration(duration);

    const status = errors === 0 ? "success" : errors < urls.length ? "partial" : "error";

    const summary = {
      status,
      totalUrls: urls.length,
      hits,
      misses,
      expired,
      errors,
      avgTtfb,
      duration: durationStr,
    };

    // Persist to database.
    updateWarmResults(siteId, summary);

    info("WARM", `${domain} - complete`, summary);
    console.log(
      `[${new Date().toISOString()}] WARM: ${domain} - complete\n` +
        `  URLs: ${urls.length} | HIT: ${hits} | MISS: ${misses} | EXPIRED: ${expired} | Errors: ${errors} | Avg TTFB: ${avgTtfb}ms | Duration: ${durationStr}`
    );

    return summary;
  } catch (err) {
    error("WARM", `${domain} - failed: ${err.message}`);
    return null;
  } finally {
    running.set(siteId, false);
  }
}

function formatDuration(ms) {
  const seconds = Math.floor(ms / 1000);
  const minutes = Math.floor(seconds / 60);
  const remainingSeconds = seconds % 60;
  if (minutes > 0) return `${minutes}m ${remainingSeconds}s`;
  return `${seconds}s`;
}
