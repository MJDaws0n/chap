#!/usr/bin/env bash
set -euo pipefail

cd /data

EULA_VALUE="${EULA:-FALSE}"
if [[ "${EULA_VALUE}" != "TRUE" && "${EULA_VALUE}" != "true" ]]; then
	echo "ERROR: You must accept the Minecraft EULA by setting EULA=TRUE" >&2
	echo "See: https://aka.ms/MinecraftEULA" >&2
	exit 1
fi

echo "eula=true" > /data/eula.txt

MC_VERSION="${MINECRAFT_VERSION:-latest}"
PAPER_VERSION="${PAPER_VERSION:-latest}"
MEMORY="${MEMORY:-2G}"
JAVA_EXTRA_ARGS="${JAVA_EXTRA_ARGS:-}"
SERVER_JAR_URL="${SERVER_JAR_URL:-}"

if [[ -n "${SERVER_JAR_URL}" ]]; then
  echo "Downloading server jar from SERVER_JAR_URL"
  curl -fsSL "${SERVER_JAR_URL}" -o /data/server.jar
fi

if [[ ! -f /data/server.jar ]]; then
  echo "Attempting to download Paper jar for Minecraft ${MC_VERSION} (paper version: ${PAPER_VERSION})..."
  PURL=$(python3 - <<'PY'
import os,sys,urllib.request,json
project='paper'
base='https://api.papermc.io/v2/projects/' + project
try:
    with urllib.request.urlopen(base, timeout=30) as r:
        pj=json.load(r)
except Exception:
    sys.exit(0)
ver=os.environ.get('MINECRAFT_VERSION','latest')
if ver=='latest':
    ver = pj.get('versions',[])[-1] if pj.get('versions') else ''
if not ver:
    sys.exit(0)
try:
    with urllib.request.urlopen(f"{base}/versions/{ver}", timeout=30) as r:
        vinfo=json.load(r)
except Exception:
    sys.exit(0)
builds=vinfo.get('builds',[])
if not builds:
    sys.exit(0)
build=max(b.get('build') if isinstance(b,dict) else b for b in builds)
jar_url=f"{base}/versions/{ver}/builds/{build}/downloads/paper-{ver}-{build}.jar"
print(jar_url)
PY
)
  if [[ -n "$PURL" ]]; then
    echo "Downloading Paper jar: $PURL"
    curl -fsSL "$PURL" -o /data/server.jar || true
  fi
fi

JAVA_MEM_ARGS=("-Xms${MEMORY}" "-Xmx${MEMORY}")

echo "Starting Paper..."
exec java "${JAVA_MEM_ARGS[@]}" ${JAVA_EXTRA_ARGS} -jar /data/server.jar nogui
