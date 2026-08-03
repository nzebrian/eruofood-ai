// k6 load / stress / soak script for the EruoFood Public API.
//
// Covers the critical read paths plus rate-limit / quota behaviour. Run against
// a deployed, seeded environment — NOT a laptop dev server — for meaningful
// numbers. Provide a valid API key with the read scopes.
//
//   BASE_URL=https://api.eruofood.ai/api/public/v1 \
//   API_KEY=efk_live_xxx.yyy \
//   k6 run --out json=results.json load/public-api.k6.js
//
// Profiles (select with SCENARIO=baseline|load|stress|soak):
//   baseline : 1 VU, 1 min      — establish uncontended latency
//   load     : ramp to 50 VUs   — expected peak
//   stress   : ramp to 400 VUs  — find the knee / breaking point
//   soak     : 30 VUs, 2 h      — memory leaks / GC / connection exhaustion
//
// Thresholds are gates: the run FAILS if p95 latency or the error rate exceeds
// them, so this doubles as a CI performance guard.

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080/api/public/v1';
const API_KEY = __ENV.API_KEY || '';
const SCENARIO = __ENV.SCENARIO || 'load';

const errorRate = new Rate('public_api_errors');
const rateLimited = new Rate('public_api_rate_limited');
const readLatency = new Trend('public_api_read_latency', true);

const profiles = {
  baseline: { executor: 'constant-vus', vus: 1, duration: '1m' },
  load: {
    executor: 'ramping-vus',
    stages: [
      { duration: '1m', target: 50 },
      { duration: '3m', target: 50 },
      { duration: '1m', target: 0 },
    ],
  },
  stress: {
    executor: 'ramping-vus',
    stages: [
      { duration: '2m', target: 100 },
      { duration: '2m', target: 250 },
      { duration: '2m', target: 400 },
      { duration: '2m', target: 0 },
    ],
  },
  soak: { executor: 'constant-vus', vus: 30, duration: '2h' },
};

export const options = {
  scenarios: { [SCENARIO]: profiles[SCENARIO] },
  thresholds: {
    http_req_duration: ['p(50)<150', 'p(95)<400', 'p(99)<800'],
    public_api_errors: ['rate<0.01'],
    http_req_failed: ['rate<0.02'],
  },
};

const READ_PATHS = [
  '/foods?per_page=20',
  '/recipes?per_page=20',
  '/restaurants?per_page=20',
  '/products?per_page=20',
  '/nutrition?per_page=20',
  '/search?q=jollof&type=recipe',
];

function headers() {
  return { headers: { 'X-Api-Key': API_KEY, Accept: 'application/json' } };
}

export default function () {
  const path = READ_PATHS[Math.floor(Math.random() * READ_PATHS.length)];
  const res = http.get(`${BASE_URL}${path}`, headers());

  readLatency.add(res.timings.duration);
  rateLimited.add(res.status === 429);
  // 429 is expected under stress and is NOT an application error.
  errorRate.add(res.status >= 500 || res.status === 401 || res.status === 403);

  check(res, {
    'status is 200 or 429': (r) => r.status === 200 || r.status === 429,
    'has rate-limit headers': (r) => r.headers['X-Ratelimit-Limit'] !== undefined,
  });

  sleep(Math.random() * 0.5 + 0.1);
}
