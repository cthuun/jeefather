#!/bin/bash
# Test de l'API cloud Grainfather avec de vrais identifiants.
# Usage : EMAIL="toi@exemple.com" PASSWORD="secret" ./tests/test_api.sh
# Le script affiche la structure JSON reelle (pour figer le parsing si besoin).
set -u
BASE="https://community.grainfather.com/api"
EMAIL="${EMAIL:-}"
PASSWORD="${PASSWORD:-}"

if [ -z "$EMAIL" ] || [ -z "$PASSWORD" ]; then
	echo "Renseigne EMAIL et PASSWORD : EMAIL=... PASSWORD=... ./tests/test_api.sh" >&2
	exit 1
fi

echo "== 1. login =="
LOGIN=$(curl -s -w '\n%{http_code}' -X POST "$BASE/auth/login" \
	-H 'Accept: application/json' -H 'Content-Type: application/json' \
	-d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}")
HTTP=$(echo "$LOGIN" | tail -n1)
BODY=$(echo "$LOGIN" | head -n-1)
echo "HTTP $HTTP"
TOKEN=$(echo "$BODY" | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d.get("api_token") or d.get("accessToken") or d.get("token") or (d.get("data") or {}).get("api_token") or "")' 2>/dev/null)
if [ "$HTTP" != "200" ] || [ -z "$TOKEN" ]; then
	echo "LOGIN ECHOUE, reponse :"; echo "$BODY" | head -c 500; exit 1
fi
echo "token obtenu (tronque) : ${TOKEN:0:20}..."

echo; echo "== 2. fermentation devices =="
curl -s "$BASE/equipment/fermentation-devices" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' | python3 -m json.tool | head -c 3000

echo; echo; echo "== 3. brew sessions (page 1) =="
curl -s "$BASE/2/brew-sessions" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' | python3 -m json.tool | head -c 3000

echo; echo; echo "== 4. test 401 (token invalide -> re-login attendu cote plugin) =="
curl -s -o /dev/null -w 'HTTP %{http_code}\n' "$BASE/equipment/fermentation-devices" -H "Authorization: Bearer xxx"
