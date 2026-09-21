#!/usr/bin/env bash
# Nahrá release.zip + deploy.php + token cez FTP a zavolá deploy.php, ktorý archív rozbalí.
# Používa GitHub Actions; dá sa spustiť aj lokálne s FTP_* premennými v prostredí.
set -euo pipefail
: "${FTP_SERVER:?}" "${FTP_USERNAME:?}" "${FTP_PASSWORD:?}" "${FTP_REMOTE_DIR:?}" "${DEPLOY_URL:?}" "${DEPLOY_TOKEN:?}"
[ -f release.zip ] || { echo "release.zip chýba – najprv ho zostavte."; exit 1; }

REMOTE="${FTP_REMOTE_DIR%/}"
printf '%s' "$DEPLOY_TOKEN" | sha256sum | cut -d' ' -f1 > .deploy-token

cat > ~/.lftprc <<RC
set ftp:ssl-allow ${FTP_SSL:-true}
set ftp:ssl-force false
set ftp:ssl-protect-data true
set ssl:verify-certificate ${FTP_VERIFY_CERT:-false}
set ftp:passive-mode true
set net:max-retries 10
set net:reconnect-interval-base 5
set net:reconnect-interval-max 30
set net:timeout 120
set net:persist-retries 0
set cmd:fail-exit true
set xfer:log false
RC

echo "Nahrávam na $FTP_SERVER:$REMOTE …"
STAGE=$(mktemp -d)
mkdir -p "$STAGE/public" "$STAGE/storage/audio" "$STAGE/storage/logs"
cp public/deploy.php "$STAGE/public/deploy.php"
cp .deploy-token "$STAGE/.deploy-token"
cp release.zip "$STAGE/release.zip"
touch "$STAGE/storage/audio/.gitkeep" "$STAGE/storage/logs/.gitkeep"
lftp -p "${FTP_PORT:-21}" -u "$FTP_USERNAME","$FTP_PASSWORD" "$FTP_SERVER" -e "
mirror --reverse --no-perms --verbose=1 $STAGE $REMOTE;
bye"
rm -rf "$STAGE"
rm -f .deploy-token

echo "Rozbaľujem na serveri …"
RESP=$(curl -sS --max-time 900 --retry 3 --retry-delay 10 -w '\nHTTP %{http_code}' "${DEPLOY_URL%/}/deploy.php?token=${DEPLOY_TOKEN}")
echo "$RESP"
echo "$RESP" | tail -1 | grep -q 'HTTP 200' || { echo "deploy.php zlyhal"; exit 1; }
echo "Nasadenie dokončené."
