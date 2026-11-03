#!/bin/bash
# Deploie le plugin Jeefather sur une box Jeedom (SSH/SCP).
# Usage :
#   JEEDOM_HOST=<IP_BOX> JEEDOM_USER=root ./deploy/deploy.sh
#   JEEDOM_HOST=<IP_BOX> DOCKER_CONTAINER=jeedom ./deploy/deploy.sh   (Jeedom en Docker)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"          # racine du plugin (jeefather)
# charge .env local (gitignore, jamais commite) si present : JEEDOM_HOST, etc.
if [ -f "$ROOT/.env" ]; then set -a; . "$ROOT/.env"; set +a; fi
PLUGIN_ID="jeefather"
JEEDOM_HOST="${JEEDOM_HOST:? renseigne JEEDOM_HOST (IP/hostname de la box)}"
JEEDOM_USER="${JEEDOM_USER:-root}"
PLUGIN_PATH="${PLUGIN_PATH:-/var/www/html/plugins}"
DOCKER_CONTAINER="${DOCKER_CONTAINER:-}"
SSH_KEY="${SSH_KEY:-}"

SSH_OPTS=(-o BatchMode=yes -o StrictHostKeyChecking=accept-new)
SCP_OPTS=(-o BatchMode=yes -o StrictHostKeyChecking=accept-new)
[ -n "$SSH_KEY" ] && SSH_OPTS+=(-i "$SSH_KEY") && SCP_OPTS+=(-i "$SSH_KEY")

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "==> Preparation du paquet"
mkdir -p "$TMP/$PLUGIN_ID"
(cd "$ROOT" && find . -type f \
	-not -path './tests/*' -not -path './deploy/*' -not -path './.git/*' -not -name '.gitignore' -not -name '.env*' \
	-exec cp --parents {} "$TMP/$PLUGIN_ID/" \;)
tar -czf "$TMP/jeefather.tgz" -C "$TMP" "$PLUGIN_ID"

echo "==> Transfert vers ${JEEDOM_USER}@${JEEDOM_HOST}"
scp "${SCP_OPTS[@]}" "$TMP/jeefather.tgz" "${JEEDOM_USER}@${JEEDOM_HOST}:/tmp/jeefather.tgz"

run_remote() {
	local cmd="$1"
	if [ -n "$DOCKER_CONTAINER" ]; then
		cmd="docker exec $DOCKER_CONTAINER sh -c '$cmd'"
	fi
	ssh "${SSH_OPTS[@]}" "${JEEDOM_USER}@${JEEDOM_HOST}" "$cmd"
}

echo "==> Deploiement dans $PLUGIN_PATH/$PLUGIN_ID"
run_remote "
set -e
cd '$PLUGIN_PATH'
# backup HORS de plugins/ (un .old dans plugins/ serait re-scanne comme un plugin)
mkdir -p /var/www/html/.plugin-backups
rm -rf "/var/www/html/.plugin-backups/${PLUGIN_ID}.old"
[ -d '$PLUGIN_ID' ] && mv '$PLUGIN_ID' "/var/www/html/.plugin-backups/${PLUGIN_ID}.old"
tar -xzf /tmp/jeefather.tgz -C '$PLUGIN_PATH'
chown -R www-data:www-data '$PLUGIN_PATH/$PLUGIN_ID' 2>/dev/null || true
chmod -R 755 '$PLUGIN_PATH/$PLUGIN_ID'
echo '--- php -l ---'
for f in '$PLUGIN_PATH/$PLUGIN_ID'/core/class/*.php '$PLUGIN_PATH/$PLUGIN_ID'/core/ajax/*.php '$PLUGIN_PATH/$PLUGIN_ID'/desktop/php/*.php '$PLUGIN_PATH/$PLUGIN_ID'/mobile/php/*.php; do php -l \"\$f\"; done
# PAS de vidage du cache Jeedom : /tmp/jeedom/cache contient les valeurs courantes des
# commandes (value/valueDate/collectDate) ; le vider rend tous les widgets vides.
# (ligne /var/www/html/cache supprimée aussi : inutile et risquée)
rm -f /tmp/jeefather.tgz
echo '--- contenu deploye ---'
ls -la '$PLUGIN_PATH/$PLUGIN_ID'
"

echo
echo "==> OK. Etapes suivantes dans Jeedom :"
echo "    1. Plugins > Gestion des plugins : ouvrir 'Jeefather'."
echo "    2. Renseigner email + mot de passe du compte Grainfather, tester le login."
echo "    3. Creer un equipement, verifier dashboard et logs 'jeefather'."
