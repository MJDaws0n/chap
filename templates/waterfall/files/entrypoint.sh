#!/usr/bin/env bash
set -euo pipefail

cd /data

MEMORY="${MEMORY:-2G}"
JAVA_EXTRA_ARGS="${JAVA_EXTRA_ARGS:-}"
SERVER_JAR_URL="${SERVER_JAR_URL:-}"
WATERFALL_VERSION="${WATERFALL_VERSION:-latest}"

if [[ -n "${SERVER_JAR_URL}" ]]; then
  echo "Downloading server jar from SERVER_JAR_URL"
  EXPANDED_URL="${SERVER_JAR_URL//\{version\}/${WATERFALL_VERSION}}"
  curl -fsSL "${EXPANDED_URL}" -o /data/server.jar || true
fi

if [[ ! -f /data/server.jar ]]; then
  echo "Attempting to download Waterfall jar from PaperMC API..."
  WURL=$(python3 - <<'PY'
import os,sys,urllib.request,json
project='waterfall'
base='https://api.papermc.io/v2/projects/' + project
try:
    with urllib.request.urlopen(base, timeout=30) as r:
        pj=json.load(r)
except Exception:
    sys.exit(0)
ver=os.environ.get('WATERFALL_VERSION','latest')
versions=pj.get('versions',[])
if ver=='latest':
    if not versions:
        sys.exit(0)
    ver=versions[-1]
if ver not in versions:
    sys.exit(0)
with urllib.request.urlopen(f"{base}/versions/{ver}", timeout=30) as r:
    vinfo=json.load(r)
builds=vinfo.get('builds',[])
if not builds:
    sys.exit(0)
build=max(b.get('build') if isinstance(b,dict) else b for b in builds)
jar_url=f"{base}/versions/{ver}/builds/{build}/downloads/waterfall-{ver}-{build}.jar"
print(jar_url)
PY
)
  if [[ -n "$WURL" ]]; then
    echo "Downloading Waterfall jar: $WURL"
    curl -fsSL "$WURL" -o /data/server.jar || true
  fi
fi

if [[ ! -f /data/server.jar ]]; then
  echo "No /data/server.jar found and no SERVER_JAR_URL or PaperMC asset available." >&2
  echo "Place a jar at /data/server.jar or set SERVER_JAR_URL to download one." >&2
  exit 1
fi

JAVA_MEM_ARGS=("-Xms${MEMORY}" "-Xmx${MEMORY}")

echo "Starting Waterfall..."
exec java "${JAVA_MEM_ARGS[@]}" ${JAVA_EXTRA_ARGS} -jar /data/server.jar nogui
