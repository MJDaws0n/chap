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
MEMORY="${MEMORY:-2G}"
JAVA_EXTRA_ARGS="${JAVA_EXTRA_ARGS:-}"
SERVER_JAR_URL="${SERVER_JAR_URL:-}"

if [[ -n "${SERVER_JAR_URL}" ]]; then
  echo "Downloading server jar from SERVER_JAR_URL"
  # replace {version} placeholder with MC_VERSION if present
  SERVER_URL_EXPANDED="${SERVER_JAR_URL//\{version\}/${MC_VERSION}}"
  curl -fsSL "${SERVER_URL_EXPANDED}" -o /data/server.jar || true
fi

if [[ ! -f /data/server.jar ]]; then
  echo "Attempting to download Spigot jar from CDN for version ${MC_VERSION}..."
  SPIGOT_URL="https://cdn.getbukkit.org/spigot/spigot-${MC_VERSION}.jar"
  if curl -fsSL "$SPIGOT_URL" -o /data/server.jar; then
    echo "Downloaded Spigot jar from: $SPIGOT_URL"
  else
    echo "Failed to automatically obtain a Spigot jar for version ${MC_VERSION}." >&2
    echo "Options: 1) provide SERVER_JAR_URL pointing at a prepared spigot.jar, 2) run BuildTools to build spigot.jar and place it in /data, or 3) use the Paper template which can be auto-downloaded." >&2
    exit 1
  fi
fi

JAVA_MEM_ARGS=("-Xms${MEMORY}" "-Xmx${MEMORY}")

echo "Starting Spigot-compatible server..."
exec java "${JAVA_MEM_ARGS[@]}" ${JAVA_EXTRA_ARGS} -jar /data/server.jar nogui
