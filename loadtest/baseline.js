import http from "k6/http";
import { check, group } from "k6";
import { sleep } from "k6";

/**
 * A3.7: unloaded baseline (5 VUs) - isolates real per-request cost from
 * concurrency-induced contention on a shared dev machine (see README's
 * "Tests de charge" section for why the ramping-load scenario in
 * scenario.js alone cannot make that distinction on a resource-
 * constrained Codespace). Run this first when investigating a latency
 * regression: if the baseline is slow, it's the code/query; if only the
 * ramping scenario is slow, it's contention with whatever else is
 * running on the machine.
 *
 * `App\EventListener\ApiRateLimitListener` (A3.4, 30 anonymous requests/
 * minute) will 429 even this modest scenario - 5 VUs × 4 endpoint groups
 * easily exceeds 30/min within a few seconds (confirmed live). Either
 * temporarily raise config/packages/rate_limiter.yaml's api_anonymous
 * limit for the run (never commit that change - `git checkout HEAD --
 * backend/config/packages/rate_limiter.yaml` after, then `cache:clear`),
 * or read the numbers from the first handful of iterations only.
 *
 * Usage: BASE_URL=http://localhost:8080 k6 run loadtest/baseline.js
 * (BASE_URL defaults to the Codespace/local dev backend port - never
 * point this at the live Render production service.)
 */
const BASE_URL = __ENV.BASE_URL || "http://localhost:8080";

export const options = {
  vus: 5,
  duration: "20s",
  thresholds: {
    // cahier des charges 5.5 target.
    "http_req_duration{endpoint:funding_list}": ["p(95)<500"],
    "http_req_duration{endpoint:analytics_hero_stats}": ["p(95)<500"],
    "http_req_duration{endpoint:analytics_financing_trends}": ["p(95)<500"],
    "http_req_duration{endpoint:analytics_sector_distribution}": ["p(95)<500"],
  },
};

export default function () {
  group("GET /api/funding", () => {
    const res = http.get(`${BASE_URL}/api/funding?country=SEN&year=2025&page=1&limit=20`, { tags: { endpoint: "funding_list" } });
    check(res, { "status is 200": (r) => 200 === r.status });
  });
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
  sleep(0.3);
}
