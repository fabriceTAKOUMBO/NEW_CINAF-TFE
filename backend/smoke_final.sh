#!/usr/bin/env bash
# Phase H — Smoke test final pour CINAF v2 (Hardening + e2e)
#
# Vérifie en bout en bout :
#  1. Login admin OK (token reçu)
#  2. Login producteur (nollywood@cinaf.com) OK
#  3. POST /api/studio/films avec un bunnyVideoId pointant vers le path
#     d'un AUTRE studio → doit renvoyer 403 (ownership check Phase H)
#  4. GET /api/catalogue/discover (catalogue public) → doit renvoyer >0
#     œuvres puisque CATALOGUE_SOURCE=db et la phase F a importé 96 œuvres.
#
# Usage : bash smoke_final.sh (depuis backend/, port 8001)
# Pré-requis : symfony server en cours sur 127.0.0.1:8001 + jq + curl

set -u
BASE="${BASE:-http://127.0.0.1:8001/api}"

echo "[smoke] BASE = $BASE"

# 1. Admin login
ADMIN_TOKEN=$(curl -sS -X POST "$BASE/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@cinaf.com","password":"Admin1234!"}' | php -r 'echo json_decode(stream_get_contents(STDIN), true)["access_token"] ?? "";')
if [ -z "$ADMIN_TOKEN" ] || [ "$ADMIN_TOKEN" = "null" ]; then
  echo "[FAIL] Admin login failed"
  exit 1
fi
echo "[OK] Admin login → token reçu"

# 2. Producteur login (nollywood-studios)
PROD_TOKEN=$(curl -sS -X POST "$BASE/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"nollywood@cinaf.com","password":"Producer1234!"}' | php -r 'echo json_decode(stream_get_contents(STDIN), true)["access_token"] ?? "";')
if [ -z "$PROD_TOKEN" ] || [ "$PROD_TOKEN" = "null" ]; then
  echo "[FAIL] Producer login failed"
  exit 1
fi
echo "[OK] Producer login → token reçu"

# 3. Path-mismatch test (Phase H)
# Le producteur nollywood essaie de créer un film en pointant un path
# du studio dakar-productions → le hardening doit le bloquer (403).
HTTP_CODE=$(curl -sS -o /tmp/cinaf_smoke_resp.json -w "%{http_code}" \
  -X POST -H "Authorization: Bearer $PROD_TOKEN" -H "Content-Type: application/json" \
  -d '{"title":"Hack Test","synopsis":"...","year":2024,"duration":90,"bunnyVideoId":"studios/dakar-productions/videos/hack.mp4"}' \
  "$BASE/producer/films")
if [ "$HTTP_CODE" != "403" ]; then
  echo "[FAIL] Path-mismatch attendu 403, reçu $HTTP_CODE"
  cat /tmp/cinaf_smoke_resp.json
  exit 1
fi
echo "[OK] Path-mismatch → 403 (Phase H ownership check)"

# 4. Catalogue public (DB)
COUNT=$(curl -sS "$BASE/catalogue/discover" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d["data"] ?? null) ? count($d["data"]) : 0;')
if [ -z "$COUNT" ] || [ "$COUNT" = "null" ] || [ "$COUNT" -le 0 ]; then
  echo "[FAIL] /catalogue/discover devrait lister >0 œuvres (96 importées Phase F)"
  exit 1
fi
echo "[OK] /catalogue/discover → $COUNT œuvre(s) listée(s) depuis la DB"

echo ""
echo "[smoke] Tous les checks finaux ont passé."
exit 0
