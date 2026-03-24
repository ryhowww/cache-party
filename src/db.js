import Database from "better-sqlite3";
import { existsSync, readFileSync, renameSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import { info } from "./logger.js";

const __dirname = dirname(fileURLToPath(import.meta.url));
const DB_PATH = join(__dirname, "..", "sites.db");
const SITES_JSON_PATH = join(__dirname, "..", "sites.json");

let db;

export function getDb() {
  if (!db) {
    db = new Database(DB_PATH);
    db.pragma("journal_mode = WAL");
    initSchema();
    migrateFromJson();
  }
  return db;
}

function initSchema() {
  db.exec(`
    CREATE TABLE IF NOT EXISTS sites (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      url TEXT NOT NULL UNIQUE,
      api_key TEXT NOT NULL,
      sitemap_path TEXT DEFAULT '/sitemap_index.xml',
      concurrency INTEGER DEFAULT 5,
      delay_ms INTEGER DEFAULT 100,
      created_at TEXT DEFAULT (datetime('now')),
      last_warm_at TEXT,
      last_warm_status TEXT,
      last_warm_urls INTEGER,
      last_warm_hits INTEGER,
      last_warm_misses INTEGER,
      last_warm_expired INTEGER,
      last_warm_errors INTEGER,
      last_warm_avg_ttfb REAL,
      last_warm_duration TEXT,
      enabled INTEGER DEFAULT 1
    )
  `);
}

/**
 * One-time migration: import sites.json into the database.
 * Uses AUTH_TOKEN as the api_key for all imported sites.
 * Renames sites.json to sites.json.migrated after import.
 */
function migrateFromJson() {
  if (!existsSync(SITES_JSON_PATH)) return;

  const count = db.prepare("SELECT COUNT(*) as c FROM sites").get().c;
  if (count > 0) return; // DB already has data, skip.

  const apiKey = process.env.AUTH_TOKEN || "migrated";

  try {
    const raw = readFileSync(SITES_JSON_PATH, "utf-8");
    const config = JSON.parse(raw);
    const defaults = config.defaults || {};

    const insert = db.prepare(`
      INSERT OR IGNORE INTO sites (url, api_key, sitemap_path, concurrency, delay_ms, enabled)
      VALUES (?, ?, ?, ?, ?, ?)
    `);

    const tx = db.transaction(() => {
      for (const site of config.sites || []) {
        const url = normalizeUrl(`https://${site.domain}`);
        const sitemapPath = site.sitemapPath || defaults.sitemapPath || "/sitemap_index.xml";
        const concurrency = site.concurrency || defaults.concurrency || 5;
        const delayMs = site.delayMs || defaults.delayMs || 100;
        const enabled = site.enabled !== false ? 1 : 0;

        insert.run(url, apiKey, sitemapPath, concurrency, delayMs, enabled);
      }
    });

    tx();

    const imported = db.prepare("SELECT COUNT(*) as c FROM sites").get().c;
    info("DB", `Migrated ${imported} sites from sites.json`);

    renameSync(SITES_JSON_PATH, SITES_JSON_PATH + ".migrated");
    info("DB", "Renamed sites.json → sites.json.migrated");
  } catch (err) {
    info("DB", `Migration error: ${err.message}`);
  }
}

// ─── CRUD Operations ─────────────────────────────────────────

/**
 * Normalize a URL: lowercase hostname, ensure https, strip trailing slash.
 */
export function normalizeUrl(url) {
  try {
    const parsed = new URL(url);
    parsed.protocol = "https:";
    // Lowercase hostname, remove trailing slash.
    return parsed.origin + parsed.pathname.replace(/\/+$/, "");
  } catch {
    return url.replace(/\/+$/, "");
  }
}

/**
 * Register a site. Returns { site, created }.
 */
export function registerSite(url, apiKey, sitemapPath = "/sitemap_index.xml") {
  const db = getDb();
  const normalUrl = normalizeUrl(url);

  const existing = db.prepare("SELECT * FROM sites WHERE url = ?").get(normalUrl);
  if (existing) {
    return { site: existing, created: false };
  }

  const result = db.prepare(`
    INSERT INTO sites (url, api_key, sitemap_path) VALUES (?, ?, ?)
  `).run(normalUrl, apiKey, sitemapPath);

  const site = db.prepare("SELECT * FROM sites WHERE id = ?").get(result.lastInsertRowid);
  return { site, created: true };
}

/**
 * Remove a site by URL. Only removes if api_key matches.
 */
export function removeSite(url, apiKey) {
  const db = getDb();
  const normalUrl = normalizeUrl(url);
  const result = db.prepare("DELETE FROM sites WHERE url = ? AND api_key = ?").run(normalUrl, apiKey);
  return result.changes > 0;
}

/**
 * List all sites for an api_key.
 */
export function listSites(apiKey) {
  const db = getDb();
  return db.prepare("SELECT * FROM sites WHERE api_key = ? ORDER BY id").all(apiKey);
}

/**
 * Get all enabled sites (for warming).
 */
export function getEnabledSites() {
  const db = getDb();
  return db.prepare("SELECT * FROM sites WHERE enabled = 1 ORDER BY id").all();
}

/**
 * Get a single site by ID, scoped to api_key.
 */
export function getSiteById(id, apiKey) {
  const db = getDb();
  return db.prepare("SELECT * FROM sites WHERE id = ? AND api_key = ?").get(id, apiKey);
}

/**
 * Update a site's settings.
 */
export function updateSite(id, apiKey, updates) {
  const db = getDb();
  const allowed = ["sitemap_path", "concurrency", "delay_ms", "enabled"];
  const sets = [];
  const values = [];

  for (const key of allowed) {
    if (updates[key] !== undefined) {
      sets.push(`${key} = ?`);
      values.push(key === "enabled" ? (updates[key] ? 1 : 0) : updates[key]);
    }
  }

  if (sets.length === 0) return null;

  values.push(id, apiKey);
  db.prepare(`UPDATE sites SET ${sets.join(", ")} WHERE id = ? AND api_key = ?`).run(...values);

  return db.prepare("SELECT * FROM sites WHERE id = ? AND api_key = ?").get(id, apiKey);
}

/**
 * Update warm results for a site after a warm cycle.
 */
export function updateWarmResults(siteId, results) {
  const db = getDb();
  db.prepare(`
    UPDATE sites SET
      last_warm_at = ?,
      last_warm_status = ?,
      last_warm_urls = ?,
      last_warm_hits = ?,
      last_warm_misses = ?,
      last_warm_expired = ?,
      last_warm_errors = ?,
      last_warm_avg_ttfb = ?,
      last_warm_duration = ?
    WHERE id = ?
  `).run(
    new Date().toISOString(),
    results.status,
    results.totalUrls,
    results.hits,
    results.misses,
    results.expired,
    results.errors,
    results.avgTtfb,
    results.duration,
    siteId
  );
}

/**
 * Find a site by domain (for backwards-compat with warm endpoint).
 */
export function findSiteByDomain(domain) {
  const db = getDb();
  // Match by domain substring in URL.
  return db.prepare("SELECT * FROM sites WHERE url LIKE ? AND enabled = 1").get(`%${domain}%`);
}
