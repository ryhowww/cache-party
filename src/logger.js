function timestamp() {
  return new Date().toISOString();
}

export function log(level, category, message, data = {}) {
  const entry = {
    time: timestamp(),
    level,
    category,
    message,
    ...data,
  };
  console.log(JSON.stringify(entry));
}

export function info(category, message, data) {
  log("info", category, message, data);
}

export function error(category, message, data) {
  log("error", category, message, data);
}

export function warmLine(status, cacheStatus, ttfb, url) {
  const pad = (s, n) => s.toString().padEnd(n);
  console.log(
    `[${timestamp()}] ${pad(status, 4)} ${pad(cacheStatus || "---", 7)} ${pad(ttfb + "ms", 7)} ${url}`
  );
}
