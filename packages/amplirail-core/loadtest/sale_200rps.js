// k6 load test for the Day 10 gate (Section 4.5): 200 sales per second for 10 minutes against the SIMULATOR in staging.
// Run: k6 run -e BASE=https://sandbox-api.amplirail.com -e KEY=arsec_sbx_xxx loadtest/sale_200rps.js
// Pass criteria: p95 < 150 ms added latency, error rate < 0.1%, then verify no duplicates and ledger balanced (SQL checks in ACCEPTANCE.md).
import http from 'k6/http';
import { check } from 'k6';
import { uuidv4 } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';

export const options = {
  scenarios: { sales: { executor: 'constant-arrival-rate', rate: 200, timeUnit: '1s', duration: '10m', preAllocatedVUs: 300, maxVUs: 1000 } },
  thresholds: { http_req_duration: ['p(95)<150'], http_req_failed: ['rate<0.001'] },
};

export default function () {
  const key = uuidv4();
  const res = http.post(`${__ENV.BASE}/v1/transactions`, JSON.stringify({
    amount: { amount_minor: 1000 + Math.floor(Math.random() * 90) * 100, currency: 'USD' },
    payment_token: 'tok_sim_approved', order_reference: `lt-${key}`,
  }), { headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${__ENV.KEY}`, 'Idempotency-Key': key } });
  check(res, { 'created': (r) => r.status === 201 });
}
