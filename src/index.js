import express from "express";
import cron from "node-cron";
import { getEnabledSites, getSite } from "./config.js";
import { warmSite, isRunning, getAllStatus } from "./warmer.js";
import { info } from "./logger.js";

const app = express();
app.use(express.json());

const PORT = process.env.PORT || 3000;
const AUTH_TOKEN = process.env.AUTH_TOKEN || null;

// Auth middleware for POST routes
function requireAuth(req, res, next) {
  if (!AUTH_TOKEN) return next();
  const header = req.headers.authorization;
  if (!header || header !== `Bearer ${AUTH_TOKEN}`) {
    return res.status(401).json({ error: "Unauthorized" });
  }
  next();
}

// Health check
app.get("/health", (_req, res) => {
  res.json({ status: "ok", uptime: process.uptime() });
});

// Status
app.get("/api/status", (_req, res) => {
  res.json({ sites: getAllStatus() });
});

// Trigger warm
app.post("/api/warm", requireAuth, (req, res) => {
  const { site: siteName, all } = req.body || {};

  if (all) {
    const sites = getEnabledSites();
    if (sites.length === 0) {
      return res.status(400).json({ error: "No enabled sites" });
    }

    const alreadyRunning = sites.filter((s) => isRunning(s.name)).map((s) => s.name);
    const toRun = sites.filter((s) => !isRunning(s.name));

    // Fire and forget
    for (const s of toRun) {
      warmSite(s);
    }

    return res.status(202).json({
      message: `Warming ${toRun.length} site(s)`,
      started: toRun.map((s) => s.name),
      skipped: alreadyRunning,
    });
  }

  if (!siteName) {
    return res.status(400).json({ error: 'Provide "site" name or "all": true' });
  }

  const site = getSite(siteName);
  if (!site) {
    return res.status(404).json({ error: `Site "${siteName}" not found` });
  }

  if (isRunning(siteName)) {
    return res.status(409).json({ error: `Warm already running for ${siteName}` });
  }

  // Fire and forget
  warmSite(site);

  res.status(202).json({ message: `Warming started for ${siteName}` });
});

// Critical CSS extraction
app.post("/api/critical-css", requireAuth, async (req, res) => {
  const { url, css_url, viewport_width } = req.body || {};

  if (!url) {
    return res.status(400).json({ error: 'Provide "url" to extract critical CSS from' });
  }

  try {
    const { extractCriticalCSS } = await import("./critical-css.js");
    const css = await extractCriticalCSS(url, css_url || null, viewport_width || 1300);
    res.json({ css, url, viewport_width: viewport_width || 1300 });
  } catch (err) {
    info("CRITICAL-CSS", `Error: ${err.message}`);
    res.status(500).json({ error: err.message });
  }
});

// Cron: every 20 hours
// node-cron doesn't support "every 20 hours" directly, so we use a workaround
// Schedule check every hour, track last run time
let lastCronRun = 0;
const CRON_INTERVAL_MS = 20 * 60 * 60 * 1000; // 20 hours

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

// Run initial warm on startup
info("STARTUP", "Cache Warmer starting");
const sites = getEnabledSites();
if (sites.length > 0) {
  info("STARTUP", `Running initial warm for ${sites.length} site(s)`);
  lastCronRun = Date.now();
  for (const s of sites) {
    warmSite(s);
  }
}

app.listen(PORT, () => {
  info("STARTUP", `Listening on port ${PORT}`);
});
