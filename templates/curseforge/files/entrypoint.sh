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

CURSE_PACK="${CURSEFORGE_MODPACK:-}"
MEMORY="${MEMORY:-2G}"
JAVA_EXTRA_ARGS="${JAVA_EXTRA_ARGS:-}"
SERVER_JAR_URL="${SERVER_JAR_URL:-}"

if [[ -n "${SERVER_JAR_URL}" ]]; then
  echo "Downloading server jar from SERVER_JAR_URL"
  curl -fsSL "${SERVER_JAR_URL}" -o /data/server.jar || true
fi

if [[ -n "${CURSE_PACK}" ]]; then
  echo "CURSEFORGE_MODPACK is set to '${CURSE_PACK}'"
  # If CURSEFORGE_MODPACK looks like a URL to a zip, try to download and extract it
  if [[ "${CURSE_PACK}" =~ ^https?:// && "${CURSE_PACK##*.}" == "zip" ]]; then
    echo "Attempting to download modpack zip: ${CURSE_PACK}"
    curl -fsSL "${CURSE_PACK}" -o /data/modpack.zip || true
    if [[ -f /data/modpack.zip ]]; then
      echo "Extracting modpack into /data"
      python3 - <<'PY'
import zipfile,sys
z='modpack.zip'
try:
    with zipfile.ZipFile(z) as zf:
        zf.extractall('.')
    print('extracted')
except Exception as e:
    print('error', e)
    sys.exit(1)
PY
      rm -f /data/modpack.zip || true
    fi
  else
    echo "Automatic modpack install not implemented for '${CURSE_PACK}'. Place prepared server files in /data or set SERVER_JAR_URL." >&2
  fi
fi

if [[ ! -f /data/server.jar ]]; then
  echo "No /data/server.jar found and no SERVER_JAR_URL or installed modpack available." >&2
  echo "For modpack servers, prepare server files in /data or set SERVER_JAR_URL to download a server jar." >&2
  exit 1
fi

JAVA_MEM_ARGS=("-Xms${MEMORY}" "-Xmx${MEMORY}")

echo "Starting CurseForge/modpack server..."
exec java "${JAVA_MEM_ARGS[@]}" ${JAVA_EXTRA_ARGS} -jar /data/server.jar nogui
