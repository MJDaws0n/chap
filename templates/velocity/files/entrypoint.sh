#!/usr/bin/env bash
set -euo pipefail

cd /data

MEMORY="${MEMORY:-2G}"
JAVA_EXTRA_ARGS="${JAVA_EXTRA_ARGS:-}"
SERVER_JAR_URL="${SERVER_JAR_URL:-}"
VELOCITY_VERSION="${VELOCITY_VERSION:-latest}"

if [[ -n "${SERVER_JAR_URL}" ]]; then
  echo "Downloading server jar from SERVER_JAR_URL"
  curl -fsSL "${SERVER_JAR_URL}" -o /data/server.jar || true
fi

if [[ ! -f /data/server.jar ]]; then
  echo "Attempting to find Velocity jar from GitHub releases (VelocityPowered/Velocity)..."
  VURL=$(python3 - <<'PY'
import os,sys,urllib.request,json
ver=os.environ.get('VELOCITY_VERSION','latest')
api='https://api.github.com/repos/VelocityPowered/Velocity/releases'
with urllib.request.urlopen(api, timeout=30) as r:
    rels=json.load(r)
rel=None
if ver=='latest':
    if rels:
        rel=rels[0]
else:
    for r in rels:
        if r.get('tag_name')==ver or r.get('name')==ver:
            rel=r
            break
if not rel:
    sys.exit(0)
asset=None
for a in rel.get('assets',[]):
    name=a.get('name','')
    if name.endswith('.jar'):
        asset=a.get('browser_download_url')
        break
if not asset:
    sys.exit(0)
print(asset)
PY
)
  if [[ -n "$VURL" ]]; then
    echo "Downloading Velocity jar: $VURL"
    curl -fsSL "$VURL" -o /data/server.jar || true
  fi
fi

if [[ ! -f /data/server.jar ]]; then
  echo "No /data/server.jar found and no SERVER_JAR_URL/GitHub asset available." >&2
  echo "Place a jar at /data/server.jar or set SERVER_JAR_URL to download one." >&2
  exit 1
fi

JAVA_MEM_ARGS=("-Xms${MEMORY}" "-Xmx${MEMORY}")

echo "Starting Velocity..."
exec java "${JAVA_MEM_ARGS[@]}" ${JAVA_EXTRA_ARGS} -jar /data/server.jar nogui
