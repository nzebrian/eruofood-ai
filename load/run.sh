#!/usr/bin/env bash
# EruoFood AI — performance certification runner (staging only).
# Runs the full k6 profile matrix against a production-equivalent staging target
# and writes JSON results for docs/PERFORMANCE_REPORT.md.
#
# Prereqs: k6 installed; a seeded staging deploy; PERF_API_KEY (and optional
# OAUTH_CLIENT_ID/SECRET) exported. NEVER run against production.
set -euo pipefail

: "${BASE_URL:?set BASE_URL to the staging API base, e.g. https://staging.api.eruofood.ai}"
: "${API_KEY:?set API_KEY to a staging Public API key}"
OUT_DIR="${OUT_DIR:-load/results/$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$OUT_DIR"

for scenario in baseline load stress spike soak; do
  for script in public-api critical-flows; do
    echo "==> $script :: $scenario"
    SCENARIO="$scenario" k6 run \
      --summary-export "$OUT_DIR/${script}-${scenario}.summary.json" \
      --out "json=$OUT_DIR/${script}-${scenario}.json" \
      "load/${script}.k6.js" || echo "  (thresholds breached — recorded)"
  done
done

echo "Results in $OUT_DIR — transcribe p50/p95/p99, RPS, error rate into docs/PERFORMANCE_REPORT.md"
