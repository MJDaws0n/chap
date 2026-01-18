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
FORGE_VERSION="${FORGE_VERSION:-latest}"
MEMORY="${MEMORY:-2G}"
JAVA_EXTRA_ARGS="${JAVA_EXTRA_ARGS:-}"
SERVER_JAR_URL="${SERVER_JAR_URL:-}"

if [[ -n "${SERVER_JAR_URL}" ]]; then
  echo "Downloading server jar from SERVER_JAR_URL"
  curl -fsSL "${SERVER_JAR_URL}" -o /data/server.jar || true
fi

if [[ ! -f /data/server.jar ]]; then
  echo "Attempting to download Forge installer/universal from the Forge maven..."
  if [[ -n "${FORGE_VERSION}" && "${FORGE_VERSION}" != "latest" ]]; then
    INST_URL="https://maven.minecraftforge.net/net/minecraftforge/forge/${FORGE_VERSION}/forge-${FORGE_VERSION}-installer.jar"
    UNI_URL="https://maven.minecraftforge.net/net/minecraftforge/forge/${FORGE_VERSION}/forge-${FORGE_VERSION}-universal.jar"
    if curl -fsSL "$UNI_URL" -o /data/server.jar; then
      echo "Downloaded Forge universal: $UNI_URL"
    elif curl -fsSL "$INST_URL" -o /data/forge-installer.jar; then
      echo "Downloaded Forge installer: $INST_URL"
      echo "Forge installer saved to /data/forge-installer.jar. Please run it to install the server (or provide SERVER_JAR_URL)." >&2
    else
      echo "Forge automatic download failed for version ${FORGE_VERSION}." >&2
    fi
  fi
fi

if [[ ! -f /data/server.jar ]]; then
  echo "No /data/server.jar found and no SERVER_JAR_URL/Forge artifact available." >&2
  echo "Forge servers require installers; place a prepared forge server jar at /data/server.jar or set SERVER_JAR_URL." >&2
  exit 1
fi

JAVA_MEM_ARGS=("-Xms${MEMORY}" "-Xmx${MEMORY}")

echo "Starting Forge..."
exec java "${JAVA_MEM_ARGS[@]}" ${JAVA_EXTRA_ARGS} -jar /data/server.jar nogui
