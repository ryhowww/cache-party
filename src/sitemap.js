import { XMLParser } from "fast-xml-parser";
import { info, error } from "./logger.js";

const parser = new XMLParser({ ignoreAttributes: false });

const JUNK_PATTERNS = [
  /\/feed\/?$/,
  /\/attachment\//,
  /\/wp-json\//,
  /\/wp-admin\//,
  /\/wp-login/,
  /\/xmlrpc\.php/,
  /\?replytocom=/,
];

function isJunk(url) {
  return JUNK_PATTERNS.some((p) => p.test(url));
}

async function fetchXml(url, userAgent, timeoutMs) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res = await fetch(url, {
      headers: { "User-Agent": userAgent },
      signal: controller.signal,
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const text = await res.text();
    return parser.parse(text);
  } finally {
    clearTimeout(timer);
  }
}

function extractLocs(parent, childTag) {
  if (!parent) return [];
  const items = Array.isArray(parent) ? parent : [parent];
  return items.map((item) => (typeof item === "string" ? item : item.loc)).filter(Boolean);
}

export async function getUrls(site) {
  const baseUrl = `https://${site.domain}${site.sitemapPath}`;
  const userAgent = site.userAgent;
  const timeoutMs = site.timeoutMs;

  info("SITEMAP", `Fetching ${baseUrl}`);

  let parsed;
  try {
    parsed = await fetchXml(baseUrl, userAgent, timeoutMs);
  } catch (err) {
    error("SITEMAP", `Failed to fetch ${baseUrl}: ${err.message}`);
    return [];
  }

  let urls = [];

  // Sitemap index format
  if (parsed.sitemapindex) {
    const childLocs = extractLocs(parsed.sitemapindex.sitemap, "loc");
    info("SITEMAP", `Found ${childLocs.length} child sitemaps`);

    for (const childUrl of childLocs) {
      try {
        const childParsed = await fetchXml(childUrl, userAgent, timeoutMs);
        if (childParsed.urlset?.url) {
          const locs = extractLocs(childParsed.urlset.url, "loc");
          urls.push(...locs);
        }
      } catch (err) {
        error("SITEMAP", `Failed to fetch child sitemap ${childUrl}: ${err.message}`);
      }
    }
  }
  // Simple urlset format
  else if (parsed.urlset?.url) {
    urls = extractLocs(parsed.urlset.url, "loc");
  }

  // Deduplicate and filter
  const unique = [...new Set(urls)];
  const filtered = unique.filter((u) => !isJunk(u));

  info("SITEMAP", `${site.name} - found ${filtered.length} URLs (${unique.length - filtered.length} filtered)`);
  return filtered;
}
