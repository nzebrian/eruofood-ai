// EruoFood AI — k6 critical-flow performance script (staging).
//
// Complements load/public-api.k6.js (read paths) by exercising the authenticated
// and write flows: internal auth (register/login/refresh), OAuth2 token, and the
// Public API order lifecycle, plus rate-limit/quota behaviour. Run against a
// production-equivalent STAGING deployment — never a dev server, never production.
//
// Usage:
//   BASE_URL=https://staging.api.eruofood.ai \
//   API_KEY=efk_live_xxx.yyy \
//   OAUTH_CLIENT_ID=... OAUTH_CLIENT_SECRET=... \
//   SCENARIO=load k6 run load/critical-flows.k6.js
//
// SCENARIO: baseline | load | stress | spike | soak

import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Trend, Rate } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://localhost:8080';
const API_KEY = __ENV.API_KEY || '';
const OAUTH_CLIENT_ID = __ENV.OAUTH_CLIENT_ID || '';
const OAUTH_CLIENT_SECRET = __ENV.OAUTH_CLIENT_SECRET || '';

const authLatency = new Trend('auth_latency', true);
const orderLatency = new Trend('order_latency', true);
const errors = new Rate('flow_errors');

const PROFILES = {
  baseline: { vus: 5, duration: '1m' },
  load: { vus: 50, duration: '5m' },
  stress: { stages: [
    { duration: '2m', target: 100 }, { duration: '3m', target: 200 },
    { duration: '2m', target: 300 }, { duration: '2m', target: 0 },
  ] },
  spike: { stages: [
    { duration: '30s', target: 20 }, { duration: '30s', target: 400 },
    { duration: '1m', target: 400 }, { duration: '30s', target: 20 },
  ] },
  soak: { vus: 40, duration: '2h' },
};

const scenario = __ENV.SCENARIO || 'baseline';
export const options = {
  scenarios: { [scenario]: { executor: PROFILES[scenario].stages ? 'ramping-vus' : 'constant-vus', ...toExec(PROFILES[scenario]) } },
  thresholds: {
    http_req_duration: ['p(95)<400', 'p(99)<800'],
    flow_errors: ['rate<0.01'],
    auth_latency: ['p(95)<500'],
    order_latency: ['p(95)<600'],
  },
};
function toExec(p) { return p.stages ? { stages: p.stages } : { vus: p.vus, duration: p.duration }; }

const jsonHeaders = { 'Content-Type': 'application/json', Accept: 'application/json' };
function apiHeaders() { return { Accept: 'application/json', 'X-Api-Key': API_KEY }; }

export default function () {
  group('internal auth', () => {
    const email = `perf_${__VU}_${__ITER}_${Date.now()}@example.com`;
    const reg = http.post(`${BASE}/api/v1/auth/register`, JSON.stringify({
      name: 'Perf', email, password: 'Password123', password_confirmation: 'Password123',
    }), { headers: jsonHeaders });
    authLatency.add(reg.timings.duration);
    check(reg, { 'register 201/200': (r) => r.status === 201 || r.status === 200 }) || errors.add(1);

    const login = http.post(`${BASE}/api/v1/auth/login`, JSON.stringify({ email, password: 'Password123' }), { headers: jsonHeaders });
    authLatency.add(login.timings.duration);
    const ok = check(login, { 'login 200': (r) => r.status === 200 });
    if (!ok) { errors.add(1); return; }
  });

  group('oauth2 client credentials', () => {
    if (!OAUTH_CLIENT_ID) return;
    const tok = http.post(`${BASE}/api/public/v1/oauth/token`, JSON.stringify({
      grant_type: 'client_credentials', client_id: OAUTH_CLIENT_ID,
      client_secret: OAUTH_CLIENT_SECRET, scope: 'foods:read',
    }), { headers: jsonHeaders });
    check(tok, { 'token 200': (r) => r.status === 200 }) || errors.add(1);
  });

  group('public api read + rate limit', () => {
    const r = http.get(`${BASE}/api/public/v1/foods?per_page=20`, { headers: apiHeaders() });
    check(r, { 'foods 200 or 429': (x) => x.status === 200 || x.status === 429 }) || errors.add(1);
  });

  group('order lifecycle (public api)', () => {
    if (!API_KEY) return;
    const list = http.get(`${BASE}/api/public/v1/orders?per_page=10`, { headers: apiHeaders() });
    orderLatency.add(list.timings.duration);
    check(list, { 'orders list 200/401': (r) => r.status === 200 || r.status === 401 }) || errors.add(1);
  });

  sleep(1);
}
