import http from "k6/http";
import { check, sleep, group } from "k6";

/**
 * A3.7: ramping-VU load test against the most-solicited public endpoints
 * (cahier des charges 5.5 target: <500ms) - GET /api/funding (the main
 * data listing, filterable, public - the single most realistic "visitor
 * browsing the site" endpoint) and the 3 heaviest /api/analytics/*
 * aggregates (visualizations.html loads all of them on page load).
 *
 * Run against the Codespace/local dev backend, never the live Render
 * production service (free tier, real - no reason to load-test it when a
 * functionally identical environment exists to hammer safely).
 *
 * `App\EventListener\ApiRateLimitListener` (A3.4) will 429 this test
 * almost immediately at any real concurrency - it's designed to. Either
 * temporarily raise config/packages/rate_limiter.yaml's api_anonymous
 * limit for the run (never commit that change - `git checkout HEAD --
 * backend/config/packages/rate_limiter.yaml` after), or run against a
 * dedicated non-production instance where that's acceptable.
 *
 * Usage: BASE_URL=http://localhost:8080 k6 run loadtest/ramping.js
 */
const BASE_URL = __ENV.BASE_URL || "http://localhost:8080";

export const options = {
  scenarios: {
    funding_list: {
      executor: "ramping-vus",
      exec: "fundingList",
      startVUs: 0,
      stages: [
        { duration: "10s", target: 20 },
        { duration: "20s", target: 50 },
        { duration: "10s", target: 0 },
      ],
    },
    analytics_dashboard: {
      executor: "ramping-vus",
      exec: "analyticsDashboard",
      startVUs: 0,
      stages: [
        { duration: "10s", target: 20 },
        { duration: "20s", target: 50 },
        { duration: "10s", target: 0 },
      ],
    },
  },
  thresholds: {
    "http_req_duration{endpoint:funding_list}": ["p(95)<500"],
    "http_req_duration{endpoint:analytics_hero_stats}": ["p(95)<500"],
    "http_req_duration{endpoint:analytics_financing_trends}": ["p(95)<500"],
    "http_req_duration{endpoint:analytics_sector_distribution}": ["p(95)<500"],
    http_req_failed: ["rate<0.01"],
  },
};

export function fundingList() {
  group("GET /api/funding (filtered, realistic visitor query)", () => {
    const res = http.get(`${BASE_URL}/api/funding?country=SEN&year=2025&page=1&limit=20`, {
      tags: { endpoint: "funding_list" },
    });
    check(res, { "status is 200": (r) => 200 === r.status });
  });
  sleep(0.2);
}

export function analyticsDashboard() {
  group("GET /api/analytics/hero-stats", () => {
    const res = http.get(`${BASE_URL}/api/analytics/hero-stats`, { tags: { endpoint: "analytics_hero_stats" } });
    check(res, { "status is 200": (r) => 200 === r.status });
  });
  group("GET /api/analytics/financing-trends", () => {
    const res = http.get(`${BASE_URL}/api/analytics/financing-trends`, { tags: { endpoint: "analytics_financing_trends" } });
    check(res, { "status is 200": (r) => 200 === r.status });
  });
  group("GET /api/analytics/sector-distribution", () => {
    const res = http.get(`${BASE_URL}/api/analytics/sector-distribution`, { tags: { endpoint: "analytics_sector_distribution" } });
    check(res, { "status is 200": (r) => 200 === r.status });
  });
  sleep(0.2);
}
