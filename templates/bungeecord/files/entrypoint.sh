#!/usr/bin/env bash
set -euo pipefail

cd /data

# EULA not required for proxy by default; respect if provided
if [[ -n "${EULA:-}" ]]; then
  EULA_VALUE="${EULA:-FALSE}"
  if [[ "${EULA_VALUE}" != "TRUE" && "${EULA_VALUE}" != "true" ]]; then
    echo "ERROR: You must accept the Minecraft EULA by setting EULA=TRUE" >&2
    echo "See: https://aka.ms/MinecraftEULA" >&2
    exit 1
  fi
  echo "eula=true" > /data/eula.txt
fi

MEMORY="${MEMORY:-2G}"
JAVA_EXTRA_ARGS="${JAVA_EXTRA_ARGS:-}"
SERVER_JAR_URL="${SERVER_JAR_URL:-}"
PROXY_VERSION="${PROXY_VERSION:-latest}"

download_from_url() {
  local url="$1"
  echo "Downloading from URL: ${url}"
  curl -fsSL "${url}" -o /data/server.jar || return 1
  return 0
}

if [[ -n "${SERVER_JAR_URL}" ]]; then
  download_from_url "${SERVER_JAR_URL}"
fi

if [[ ! -f /data/server.jar ]]; then
  echo "Attempting to download BungeeCord jar from the official CI..."
  # Jenkins CDN for BungeeCord
  JB_URL="https://ci.md-5.net/job/BungeeCord/lastStableBuild/artifact/bootstrap/target/BungeeCord.jar"
  if download_from_url "${JB_URL}"; then
    echo "Downloaded BungeeCord from Jenkins: ${JB_URL}"
  else
    echo "Failed to download BungeeCord automatically." >&2
    echo "Place a jar at /data/server.jar or set SERVER_JAR_URL to download one." >&2
    exit 1
  fi
fi

JAVA_MEM_ARGS=("-Xms${MEMORY}" "-Xmx${MEMORY}")

echo "Starting server..."
exec java "${JAVA_MEM_ARGS[@]}" ${JAVA_EXTRA_ARGS} -jar /data/server.jar nogui
