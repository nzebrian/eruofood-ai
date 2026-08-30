#!/usr/bin/env bash
#
# M43 — do the web idempotency tests actually discriminate?
#
# ## Why this exists
#
# The M43 suite went green the first time it ran. So would a suite that asserted
# nothing. A test that says "an Idempotency-Key was sent" is only evidence if it
# goes red when the key stops being sent — otherwise it is a decoration that
# costs CI minutes and buys confidence it has not earned.
#
# So each control below removes exactly one protection and requires the suite to
# fail **on the specific test that protection belongs to**. A bare non-zero exit
# is not accepted: any unrelated breakage produces one, and a control that
# settles for it can pass while the mutation it injected did nothing at all.
#
# ## Why the real repository is never touched
#
# The lesson M37 learned the hard way, applied from the start. Mutating tracked
# files and restoring them afterwards works right up until the process dies on a
# fatal error or the SIGTERM a cancelled CI job receives — and then a
# deliberately-broken payment safeguard is sitting in somebody's working tree.
#
# Every mutation here happens inside a `mktemp` copy of `apps/web`. The real
# tree is fingerprinted with sha256 before and after, and the run fails if a
# single byte moved. `node_modules` is symlinked rather than copied: Node
# resolves it by walking up from the importing file, so the fixture's own
# sources are what get loaded, while 339 packages are not duplicated per
# control.
#
# Usage: bash apps/web/scripts/m43_web_idempotency_control.sh

set -euo pipefail

WEB_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$WEB_ROOT"

# The M43 suite. Nothing else is run: a control must be judged on the tests that
# describe the property it broke.
SUITE=(
  "src/lib/idempotency.test.ts"
  "src/lib/apiClient.idempotency.test.ts"
  "src/features/payments/paymentsApi.idempotency.test.ts"
)

# Files a control may mutate, and therefore files whose integrity must be proved
# afterwards.
PROTECTED=(
  "src/lib/idempotency.ts"
  "src/lib/apiClient.ts"
  "src/features/payments/paymentsApi.ts"
  "src/features/commerce/commerceApi.ts"
  "src/features/marketplace/marketplaceApi.ts"
  "src/lib/idempotency.test.ts"
  "src/lib/apiClient.idempotency.test.ts"
  "src/features/payments/paymentsApi.idempotency.test.ts"
)

confirmed=0
declare -a false_positives=()
declare -a broken=()

fingerprint() {
  local path
  for path in "${PROTECTED[@]}"; do
    if [[ -f "$path" ]]; then
      sha256sum "$path"
    else
      echo "ABSENT  $path"
    fi
  done | sort | sha256sum | cut -d' ' -f1
}

make_fixture() {
  local fixture
  fixture="$(mktemp -d "${TMPDIR:-/tmp}/m43-web-XXXXXXXX")"

  # Everything the suite needs, and nothing that would take a minute to copy.
  tar -c \
    --exclude=node_modules \
    --exclude=dist \
    --exclude=coverage \
    --exclude=.vite \
    -f - . | tar -x -C "$fixture" -f -

  ln -s "$WEB_ROOT/node_modules" "$fixture/node_modules"

  echo "$fixture"
}

# Replace exactly one occurrence of a literal, or fail loudly.
#
# A `find` string that is absent is a hard error rather than a skipped control:
# a mutation that silently did nothing would report the suite as discriminating
# when it never saw a change, which is the most misleading outcome available.
mutate() {
  local fixture="$1" file="$2" find="$3" replace="$4"

  FIXTURE="$fixture" FILE="$file" FIND="$find" REPLACE="$replace" python3 - <<'PY'
import os, sys

path = os.path.join(os.environ['FIXTURE'], os.environ['FILE'])
find, replace = os.environ['FIND'], os.environ['REPLACE']

with open(path, encoding='utf-8') as handle:
    source = handle.read()

count = source.count(find)
if count == 0:
    sys.exit(f"mutation target not found in {os.environ['FILE']}: {find[:80]!r}")
if count > 1:
    sys.exit(f"mutation target is ambiguous ({count} matches) in {os.environ['FILE']}: {find[:80]!r}")

with open(path, 'w', encoding='utf-8') as handle:
    handle.write(source.replace(find, replace))
PY
}

run_suite() {
  local fixture="$1"
  (cd "$fixture" && npx vitest run "${SUITE[@]}" --reporter=verbose 2>&1) || true
}

suite_exit() {
  local fixture="$1"
  (cd "$fixture" && npx vitest run "${SUITE[@]}" >/dev/null 2>&1)
}

# name | expected failing test | file | find | replace ...  (triples may repeat)
#
# The expected-test strings for the `it.each` cases carry single quotes because
# that is how vitest renders an interpolated `$name`: the title reported is
# `'payments.initiate' sends an Idempotency-Key`, not the bare operation name.
control() {
  local name="$1" expect="$2"
  shift 2

  printf '%-64s' "${name:0:64}"

  local fixture
  fixture="$(make_fixture)"

  # shellcheck disable=SC2064  # expand the path now, not at trap time
  trap "rm -rf '$fixture'" RETURN

  local file find replace
  while [[ $# -gt 0 ]]; do
    file="$1" find="$2" replace="$3"
    shift 3
    if ! mutate "$fixture" "$file" "$find" "$replace" 2>/tmp/m43-mutate-err; then
      broken+=("$name: $(cat /tmp/m43-mutate-err)")
      echo " BROKEN"
      return 0
    fi
  done

  local output
  output="$(run_suite "$fixture")"

  if ! grep -q '×' <<<"$output"; then
    false_positives+=("$name (the suite stayed green)")
    echo " FALSE POSITIVE"
    return 0
  fi

  if ! grep -F "$expect" <<<"$output" | grep -q '×'; then
    # It failed, but not on the test that owns this protection. That is a
    # different defect and must not be counted as confirmation.
    false_positives+=("$name (failed, but not on: $expect)")
    echo " WRONG TEST"
    return 0
  fi

  confirmed=$((confirmed + 1))
  echo " ok"
}

echo "EruoFood — M43 web idempotency negative controls"
echo "=============================================================================="
echo "Each safeguard is removed inside a disposable fixture; the M43 suite must then"
echo "fail on that safeguard's own test."
echo

before="$(fingerprint)"
echo "Protected-file fingerprint (before): $before"
echo

# -----------------------------------------------------------------------------

control "1. Idempotency-Key removed from payments.initiate" \
  "'payments.initiate' sends an Idempotency-Key" \
  "src/features/payments/paymentsApi.ts" \
  "apiClient.postIdempotent<PaymentIntent>('/payments/payments', payload, idempotencyKey)" \
  "apiClient.post<PaymentIntent>('/payments/payments', payload)"

control "2. Idempotency-Key removed from commerce checkout" \
  "'commerce.checkout' sends an Idempotency-Key" \
  "src/features/commerce/commerceApi.ts" \
  "apiClient.postIdempotent<Order>('/commerce/checkout', payload, idempotencyKey)" \
  "apiClient.post<Order>('/commerce/checkout', payload)"

control "3. Idempotency-Key removed from marketplace checkout" \
  "'marketplace.checkout' sends an Idempotency-Key" \
  "src/features/marketplace/marketplaceApi.ts" \
  "apiClient.postIdempotent<Order>('/checkout', payload, idempotencyKey)" \
  "apiClient.post<Order>('/checkout', payload)"

control "4. Idempotency-Key removed from wallet top-up" \
  "'payments.wallet.topup' sends an Idempotency-Key" \
  "src/features/payments/paymentsApi.ts" \
  "    apiClient.postIdempotent<PaymentIntent>(
      '/payments/wallet/topup'," \
  "    apiClient.post<PaymentIntent>(
      '/payments/wallet/topup',"

control "5. Idempotency-Key removed from wallet transfer" \
  "'payments.wallet.transfer' sends an Idempotency-Key" \
  "src/features/payments/paymentsApi.ts" \
  "apiClient.postIdempotent<Wallet>(" \
  "apiClient.post<Wallet>("

control "6. Idempotency-Key removed from refunds" \
  "'payments.refund' sends an Idempotency-Key" \
  "src/features/payments/paymentsApi.ts" \
  "apiClient.postIdempotent<unknown>(" \
  "apiClient.post<unknown>("

# The milestone's primary acceptance criterion, inverted.
control "7. a NEW key minted on the 401 refresh replay" \
  "replays the SAME key after a 401 token refresh" \
  "src/lib/apiClient.ts" \
  "import { IDEMPOTENCY_HEADER } from '@lib/idempotency';" \
  "import { IDEMPOTENCY_HEADER, newIdempotencyKey } from '@lib/idempotency';" \
  "src/lib/apiClient.ts" \
  "      return request<T>(path, init, false);" \
  "      return request<T>(
        path,
        {
          ...init,
          headers: {
            ...(init.headers as Record<string, string>),
            [IDEMPOTENCY_HEADER]: newIdempotencyKey(),
          },
        },
        false,
      );"

control "8. one key shared across independent operations" \
  "gives every operation, of every kind, its own key" \
  "src/lib/idempotency.ts" \
  "  const source = globalThis.crypto;" \
  "  return 'SHARED-KEY';

  const source = globalThis.crypto;"

control "9. keys made predictable instead of random" \
  "produces a distinct key on every call" \
  "src/lib/idempotency.ts" \
  "    return source.randomUUID();" \
  "    return 'key-' + String(Date.now());"

control "10. the key written to the console" \
  "never writes the key to the console" \
  "src/lib/apiClient.ts" \
  "    return request<T>(path, {
      method: 'POST'," \
  "    console.warn('idempotency key', idempotencyKey);

    return request<T>(path, {
      method: 'POST',"

control "11. a key applied to a read-only, non-financial call" \
  "does not key the read-only and non-financial calls" \
  "src/features/commerce/commerceApi.ts" \
  "  applyCoupon: (code: string) => apiClient.post<Cart>('/commerce/cart/coupon', { code })," \
  "  applyCoupon: (code: string) =>
    apiClient.postIdempotent<Cart>('/commerce/cart/coupon', { code }, newIdempotencyKey()),"

# -----------------------------------------------------------------------------
# Control 12 — the positive control. Without it every control above is satisfied
# by a suite that fails on everything, including a correct repository.

printf '%-64s' "12. positive control: an unmutated fixture passes"
positive_fixture="$(make_fixture)"
positive_ok=0
if suite_exit "$positive_fixture"; then
  positive_ok=1
  echo " ok"
else
  echo " FAILED"
  run_suite "$positive_fixture" | tail -30
fi
rm -rf "$positive_fixture"

# Control 13 — integrity. Everything above mutated a copy; the real tree must be
# byte-identical to what it was before the run.

printf '%-64s' "13. sha256 integrity: the real repository is unchanged"
after="$(fingerprint)"
integrity_ok=0
if [[ "$before" == "$after" ]]; then
  integrity_ok=1
  echo " ok"
else
  echo " FAILED"
fi

echo
echo "Protected-file fingerprint (after):  $after"
echo
echo "=============================================================================="
printf '%d/11 mutations confirmed by the test they targeted.\n' "$confirmed"

if [[ ${#broken[@]} -gt 0 ]]; then
  echo
  echo "BROKEN CONTROLS (the control itself needs updating):"
  printf '  - %s\n' "${broken[@]}"
fi

if [[ ${#false_positives[@]} -gt 0 ]]; then
  echo
  echo "FALSE POSITIVES — the suite did not discriminate:"
  printf '  - %s\n' "${false_positives[@]}"
fi

if [[ "$integrity_ok" -ne 1 ]]; then
  echo
  echo "INTEGRITY FAILURE — the real repository changed during this run."
fi

if [[ "$confirmed" -eq 11 && ${#broken[@]} -eq 0 && ${#false_positives[@]} -eq 0 && "$positive_ok" -eq 1 && "$integrity_ok" -eq 1 ]]; then
  echo
  echo "Every web idempotency safeguard discriminates, and the working tree is untouched."
  exit 0
fi

echo
echo "M43 web idempotency negative controls FAILED."
exit 1
