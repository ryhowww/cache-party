import express from "express";
import cron from "node-cron";
import {
  getDb,
  normalizeUrl,
  registerSite,
  removeSite,
  listSites,
  getEnabledSites,
  getSiteById,
  updateSite,
  findSiteByDomain,
} from "./db.js";
import { warmSite, isRunning } from "./warmer.js";
import { info } from "./logger.js";

const app = express();
app.use(express.json({ limit: "2mb" }));

const PORT = process.env.PORT || 3000;
const AUTH_TOKEN = process.env.AUTH_TOKEN || null;

// ─── Auth middleware ─────────────────────────────────────────

function requireAuth(req, res, next) {
  if (!AUTH_TOKEN) return next();
  const header = req.headers.authorization;
  if (!header || header !== `Bearer ${AUTH_TOKEN}`) {
    return res.status(401).json({ error: "Unauthorized" });
  }
  next();
}

// ─── Health ──────────────────────────────────────────────────

app.get("/health", (_req, res) => {
  res.json({ status: "ok", uptime: process.uptime() });
});

// ─── Site Registration API ───────────────────────────────────

// List all sites for the authenticated key.
app.get("/api/sites", requireAuth, (req, res) => {
  const apiKey = AUTH_TOKEN || "default";
  const sites = listSites(apiKey);
  res.json({
    sites: sites.map(formatSite),
    total: sites.length,
  });
});

// Register a new site.
app.post("/api/sites/register", requireAuth, (req, res) => {
  const { url, sitemap_path } = req.body || {};

  if (!url) {
    return res.status(400).json({ error: 'Provide "url"' });
  }

  const apiKey = AUTH_TOKEN || "default";
  const { site, created } = registerSite(url, apiKey, sitemap_path);

  if (created) {
    info("SITES", `Registered: ${site.url}`);
    return res.status(201).json({
      id: site.id,
      url: site.url,
      sitemap_path: site.sitemap_path,
      status: "registered",
      message: "Site registered. First warm will run on next cycle.",
    });
  }

  return res.status(200).json({
    id: site.id,
    url: site.url,
    status: "already_registered",
    message: "Site is already registered.",
  });
});

// Remove a site.
app.delete("/api/sites/remove", requireAuth, (req, res) => {
  const { url } = req.body || {};

  if (!url) {
    return res.status(400).json({ error: 'Provide "url"' });
  }

  const apiKey = AUTH_TOKEN || "default";
  const removed = removeSite(url, apiKey);

  if (removed) {
    info("SITES", `Removed: ${normalizeUrl(url)}`);
    return res.json({ url: normalizeUrl(url), status: "removed" });
  }

  return res.status(404).json({ error: "Site not found" });
});

// Update a site.
app.patch("/api/sites/:id", requireAuth, (req, res) => {
  const apiKey = AUTH_TOKEN || "default";
  const site = updateSite(parseInt(req.params.id), apiKey, req.body);

  if (!site) {
    return res.status(404).json({ error: "Site not found or nothing to update" });
  }

  res.json(formatSite(site));
});

// Single site status.
app.get("/api/sites/:id/status", requireAuth, (req, res) => {
  const apiKey = AUTH_TOKEN || "default";
  const site = getSiteById(parseInt(req.params.id), apiKey);

  if (!site) {
    return res.status(404).json({ error: "Site not found" });
  }

  res.json(formatSite(site));
});

// ─── Status (backwards compat) ──────────────────────────────

app.get("/api/status", (_req, res) => {
  const sites = getEnabledSites();
  const status = {};
  for (const site of sites) {
    // Use domain as key for backwards compat.
    const domain = new URL(site.url).hostname;
    status[domain] = {
      lastRun: site.last_warm_at,
      duration: site.last_warm_duration,
      totalUrls: site.last_warm_urls,
      hits: site.last_warm_hits,
      misses: site.last_warm_misses,
      expired: site.last_warm_expired,
      errors: site.last_warm_errors,
      avgTtfb: site.last_warm_avg_ttfb ? `${site.last_warm_avg_ttfb}ms` : null,
    };
  }
  res.json({ sites: status });
});

// ─── Trigger warm ────────────────────────────────────────────

app.post("/api/warm", requireAuth, (req, res) => {
  const { site: siteName, url: singleUrl, all } = req.body || {};

  if (all) {
    const sites = getEnabledSites();
    if (sites.length === 0) {
      return res.status(400).json({ error: "No enabled sites" });
    }

    const alreadyRunning = sites.filter((s) => isRunning(s.id)).map((s) => s.url);
    const toRun = sites.filter((s) => !isRunning(s.id));

    for (const s of toRun) {
      warmSite(s);
    }

    return res.status(202).json({
      message: `Warming ${toRun.length} site(s)`,
      started: toRun.map((s) => s.url),
      skipped: alreadyRunning,
    });
  }

  // Find site by name (backwards compat) or by domain.
  if (!siteName) {
    return res.status(400).json({ error: 'Provide "site" name or "all": true' });
  }

  const site = findSiteByDomain(siteName);
  if (!site) {
    return res.status(404).json({ error: `Site "${siteName}" not found` });
  }

  if (isRunning(site.id)) {
    return res.status(409).json({ error: `Warm already running for ${site.url}` });
  }

  warmSite(site);
  res.status(202).json({ message: `Warming started for ${site.url}` });
});

// ─── Critical CSS extraction ─────────────────────────────────

app.post("/api/critical-css", requireAuth, async (req, res) => {
  const { url, css_url, dimensions, template, viewport_width } = req.body || {};

  if (!url) {
    return res.status(400).json({ error: 'Provide "url" to extract critical CSS from' });
  }

  try {
    const { extractCriticalCSS } = await import("./critical-css.js");

    const options = { template };
    if (css_url) options.cssUrl = css_url;
    if (dimensions) options.dimensions = dimensions;

    if (!dimensions && viewport_width) {
      options.dimensions = [{ width: viewport_width, height: 900 }];
    }

    const result = await extractCriticalCSS(url, options);
    res.json(result);
  } catch (err) {
    info("CRITICAL-CSS", `Error: ${err.message}`);
    res.status(500).json({ error: err.message });
  }
});

// ─── Merge/deduplicate CSS ───────────────────────────────────

app.post("/api/merge-css", requireAuth, async (req, res) => {
  const { css_strings } = req.body || {};

  if (!css_strings || !Array.isArray(css_strings) || css_strings.length === 0) {
    return res.status(400).json({ error: 'Provide "css_strings" array' });
  }

  try {
    const CleanCSS = (await import("clean-css")).default;
    const combined = css_strings.join(" ");
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

    const output = minified.styles;
    const sizeKB = Math.round(Buffer.byteLength(output, "utf8") / 1024);

    info("MERGE-CSS", `Merged ${css_strings.length} inputs → ${sizeKB}KB`);
    res.json({ css: output, sizeKB, inputCount: css_strings.length });
  } catch (err) {
    info("MERGE-CSS", `Error: ${err.message}`);
    res.status(500).json({ error: err.message });
  }
});

// ─── Cron: warm all enabled sites every 20 hours ─────────────

let lastCronRun = 0;
const CRON_INTERVAL_MS = 20 * 60 * 60 * 1000;

cron.schedule("0 * * * *", () => {
  const now = Date.now();
  if (now - lastCronRun < CRON_INTERVAL_MS) return;

  lastCronRun = now;
  info("CRON", "Starting scheduled warm for all sites");

  const sites = getEnabledSites();
  Promise.allSettled(sites.map((s) => warmSite(s))).then(() => {
    info("CRON", "All sites complete");
  });
});

// ─── Startup ─────────────────────────────────────────────────

// Initialize DB (creates schema, migrates sites.json if needed).
getDb();

info("STARTUP", "Cache Warmer starting");
const startupSites = getEnabledSites();
if (startupSites.length > 0) {
  info("STARTUP", `Running initial warm for ${startupSites.length} site(s)`);
  lastCronRun = Date.now();
  for (const s of startupSites) {
    warmSite(s);
  }
}

app.listen(PORT, () => {
  info("STARTUP", `Listening on port ${PORT}`);
});

// ─── Helpers ─────────────────────────────────────────────────

function formatSite(row) {
  return {
    id: row.id,
    url: row.url,
    sitemap_path: row.sitemap_path,
    enabled: row.enabled === 1,
    last_warm_at: row.last_warm_at || null,
    last_warm_status: row.last_warm_status || null,
    last_warm_duration: row.last_warm_duration || null,
    last_warm_urls: row.last_warm_urls || null,
    last_warm_hits: row.last_warm_hits || null,
    last_warm_misses: row.last_warm_misses || null,
    last_warm_expired: row.last_warm_expired || null,
    last_warm_errors: row.last_warm_errors || null,
    last_warm_avg_ttfb: row.last_warm_avg_ttfb || null,
  };
}
