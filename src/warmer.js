import pLimit from "p-limit";
import { getUrls } from "./sitemap.js";
import { info, error, warmLine } from "./logger.js";

// Track running warms and last results
const running = new Map();
const lastRun = {};

export function isRunning(siteName) {
  return running.get(siteName) === true;
}

export function getLastRun(siteName) {
  return lastRun[siteName] || null;
}

export function getAllStatus() {
  return { ...lastRun };
}

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

async function warmUrl(url, site) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), site.timeoutMs);
  const start = performance.now();

  try {
    const res = await fetch(url, {
      headers: {
        "User-Agent": site.userAgent,
        Accept: "text/html",
      },
      redirect: "follow",
      signal: controller.signal,
    });

    const ttfb = Math.round(performance.now() - start);

    // Consume the body to ensure full transfer
    await res.text();

    const cacheStatus = res.headers.get("cf-cache-status") || null;
    const age = res.headers.get("age") || null;

    warmLine(res.status, cacheStatus, ttfb, url);

    return {
      url,
      status: res.status,
      cacheStatus,
      age,
      ttfb,
      error: null,
    };
  } catch (err) {
    const ttfb = Math.round(performance.now() - start);
    warmLine("ERR", null, ttfb, url);
    return {
      url,
      status: null,
      cacheStatus: null,
      age: null,
      ttfb,
      error: err.message,
    };
  } finally {
    clearTimeout(timer);
  }
}

export async function warmSite(site) {
  if (running.get(site.name)) {
    info("WARM", `${site.name} - already running, skipping`);
    return null;
  }

  running.set(site.name, true);
  const startTime = performance.now();

  try {
    const urls = await getUrls(site);
    if (urls.length === 0) {
      info("WARM", `${site.name} - no URLs found, skipping`);
      return null;
    }

    info("WARM", `${site.name} - warming ${urls.length} URLs with concurrency=${site.concurrency}, delay=${site.delayMs}ms`);

    const limit = pLimit(site.concurrency);
    const results = [];
    let batch = 0;

    // Process in batches for delay
    const batchSize = site.concurrency;
    for (let i = 0; i < urls.length; i += batchSize) {
      const batchUrls = urls.slice(i, i + batchSize);
      const batchResults = await Promise.all(
        batchUrls.map((url) => limit(() => warmUrl(url, site)))
      );
      results.push(...batchResults);

      if (i + batchSize < urls.length) {
        await sleep(site.delayMs);
      }
      batch++;
    }

    const duration = Math.round(performance.now() - startTime);
    const hits = results.filter((r) => r.cacheStatus === "HIT").length;
    const misses = results.filter((r) => r.cacheStatus === "MISS").length;
    const expired = results.filter((r) => r.cacheStatus === "EXPIRED").length;
    const errors = results.filter((r) => r.error).length;
    const ttfbs = results.filter((r) => r.ttfb && !r.error).map((r) => r.ttfb);
    const avgTtfb = ttfbs.length > 0 ? Math.round(ttfbs.reduce((a, b) => a + b, 0) / ttfbs.length) : 0;

    const durationStr = formatDuration(duration);

    const summary = {
      lastRun: new Date().toISOString(),
      duration: durationStr,
      totalUrls: urls.length,
      hits,
      misses,
      expired,
      errors,
      avgTtfb: `${avgTtfb}ms`,
    };

    lastRun[site.name] = summary;

    info("WARM", `${site.name} - complete`, summary);
    console.log(
      `[${new Date().toISOString()}] WARM: ${site.name} - complete\n` +
        `  URLs: ${urls.length} | HIT: ${hits} | MISS: ${misses} | EXPIRED: ${expired} | Errors: ${errors} | Avg TTFB: ${avgTtfb}ms | Duration: ${durationStr}`
    );

    return summary;
  } catch (err) {
    error("WARM", `${site.name} - failed: ${err.message}`);
    return null;
  } finally {
    running.set(site.name, false);
  }
}

function formatDuration(ms) {
  const seconds = Math.floor(ms / 1000);
  const minutes = Math.floor(seconds / 60);
  const remainingSeconds = seconds % 60;
  if (minutes > 0) return `${minutes}m ${remainingSeconds}s`;
  return `${seconds}s`;
}
