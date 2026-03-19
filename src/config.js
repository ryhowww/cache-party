import { readFileSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const configPath = join(__dirname, "..", "sites.json");

let config;

export function loadConfig() {
  const raw = readFileSync(configPath, "utf-8");
  config = JSON.parse(raw);
  return config;
}

export function getConfig() {
  if (!config) loadConfig();
  return config;
}

export function getSite(name) {
  const { sites, defaults } = getConfig();
  const site = sites.find((s) => s.name === name);
  if (!site) return null;
  return { ...defaults, ...site };
}

export function getEnabledSites() {
  const { sites, defaults } = getConfig();
  return sites.filter((s) => s.enabled).map((s) => ({ ...defaults, ...s }));
}
