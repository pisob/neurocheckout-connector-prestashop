#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${NC_BASE_URL:-}"
API_KEY="${NC_API_KEY:-}"
COUPON_PAYLOAD_FILE="${NC_COUPON_PAYLOAD_FILE:-}"
CARTRESTORE_PAYLOAD_FILE="${NC_CARTRESTORE_PAYLOAD_FILE:-}"
CRON_URL="${NC_CRON_URL:-}"

if [[ -z "$BASE_URL" || -z "$API_KEY" ]]; then
  echo "Usage: NC_BASE_URL=... NC_API_KEY=... [NC_COUPON_PAYLOAD_FILE=...] [NC_CARTRESTORE_PAYLOAD_FILE=...] [NC_CRON_URL=...] $0" >&2
  exit 1
fi

sign_body() {
  local body="$1"
  local timestamp nonce signature
  timestamp="$(date +%s)"
  nonce="$(php -r 'echo bin2hex(random_bytes(16));')"
  signature="$(php -r '$ts=$argv[1]; $nonce=$argv[2]; $body=$argv[3]; $key=$argv[4]; echo hash_hmac("sha256", $ts . "." . $nonce . "." . $body, $key);' "$timestamp" "$nonce" "$body" "$API_KEY")"
  printf '%s\n%s\n%s\n' "$timestamp" "$nonce" "$signature"
}

extract_field() {
  local json="$1"
  local field="$2"
  php -r '$data=json_decode($argv[1], true); if (!is_array($data)) { exit(1); } $value=$data; foreach (explode(".", $argv[2]) as $part) { if (!is_array($value) || !array_key_exists($part, $value)) { exit(2); } $value=$value[$part]; } if (is_array($value)) { echo json_encode($value); } else { echo (string) $value; }' "$json" "$field"
}

post_signed_json() {
  local label="$1"
  local endpoint="$2"
  local payload_file="$3"
  local body response http_code json recovery_url success_value

  if [[ -z "$payload_file" ]]; then
    echo "[SKIP] $label (payload absent)"
    return 0
  fi

  if [[ ! -f "$payload_file" ]]; then
    echo "[FAIL] $label payload introuvable: $payload_file" >&2
    return 1
  fi

  body="$(tr -d '\n' < "$payload_file")"
  mapfile -t sig_parts < <(sign_body "$body")

  response="$(curl -sS -w $'\n%{http_code}' \
    -X POST \
    -H 'Content-Type: application/json' \
    -H "X-API-Key: $API_KEY" \
    -H "X-Neuro-Timestamp: ${sig_parts[0]}" \
    -H "X-Neuro-Nonce: ${sig_parts[1]}" \
    -H "X-Neuro-Signature: ${sig_parts[2]}" \
    --data "$body" \
    "$endpoint")"

  http_code="$(printf '%s' "$response" | tail -n 1)"
  json="$(printf '%s' "$response" | sed '$d')"

  if [[ "$http_code" != "200" ]]; then
    echo "[FAIL] $label HTTP $http_code" >&2
    echo "$json" >&2
    return 1
  fi

  success_value="$(extract_field "$json" success)"
  if [[ "$success_value" != "1" && "$success_value" != "true" ]]; then
    echo "[FAIL] $label success=false" >&2
    echo "$json" >&2
    return 1
  fi

  recovery_url="$(extract_field "$json" recovery_url 2>/dev/null || true)"
  if [[ -n "$recovery_url" ]]; then
    if [[ "$recovery_url" != *"rt="* ]]; then
      echo "[FAIL] $label recovery_url sans token opaque" >&2
      echo "$recovery_url" >&2
      return 1
    fi
    if [[ "$recovery_url" == *"cart_id="* || "$recovery_url" == *"email="* || "$recovery_url" == *"coupon="* ]]; then
      echo "[FAIL] $label recovery_url expose encore des donnees sensibles" >&2
      echo "$recovery_url" >&2
      return 1
    fi
  fi

  echo "[OK] $label"
}

get_check() {
  local label="$1"
  local url="$2"
  local http_code response_file

  if [[ -z "$url" ]]; then
    echo "[SKIP] $label (URL absente)"
    return 0
  fi

  response_file="$(mktemp -t nc-smoke-body.XXXXXX)"
  http_code="$(curl -sS --proto '=https,http' --max-redirs 0 -o "$response_file" -w '%{http_code}' "$url")"
  rm -f -- "$response_file"

  if [[ "$http_code" != "200" && "$http_code" != "302" ]]; then
    echo "[FAIL] $label HTTP $http_code" >&2
    return 1
  fi

  echo "[OK] $label"
}

post_signed_json "coupon" "${BASE_URL%/}/module/neurocheckoutconnector/coupon" "$COUPON_PAYLOAD_FILE"
post_signed_json "cartrestore" "${BASE_URL%/}/module/neurocheckoutconnector/cartrestore" "$CARTRESTORE_PAYLOAD_FILE"
get_check "cron" "$CRON_URL"

echo "Smoke Presta termine. Pour valider le single-use complet, refaire manuellement un second clic sur le meme recovery_url et verifier used_at en base."
