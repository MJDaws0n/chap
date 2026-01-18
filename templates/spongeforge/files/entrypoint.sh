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
SPONGE_VERSION="${SPONGE_VERSION:-latest}"
MEMORY="${MEMORY:-2G}"
JAVA_EXTRA_ARGS="${JAVA_EXTRA_ARGS:-}"
SERVER_JAR_URL="${SERVER_JAR_URL:-}"

if [[ -n "${SERVER_JAR_URL}" ]]; then
  echo "Downloading server jar from SERVER_JAR_URL"
  curl -fsSL "${SERVER_JAR_URL}" -o /data/server.jar || true
fi

if [[ ! -f /data/server.jar ]]; then
  echo "Attempting to download SpongeForge jar from Sponge Maven repo..."
  if [[ -n "${SPONGE_VERSION}" && "${SPONGE_VERSION}" != "latest" ]]; then
    SF_URL="https://repo.spongepowered.org/maven/org/spongepowered/spongeforge/${SPONGE_VERSION}/spongeforge-${SPONGE_VERSION}.jar"
    if curl -fsSL "$SF_URL" -o /data/server.jar; then
      echo "Downloaded SpongeForge: $SF_URL"
    else
      echo "SpongeForge automatic download failed for version ${SPONGE_VERSION}." >&2
    fi
  fi
fi

if [[ ! -f /data/server.jar ]]; then
  echo "No /data/server.jar found and no SERVER_JAR_URL or SpongeForge asset available." >&2
  echo "Modded servers require specific installers; place a prepared forge+sponge jar at /data/server.jar or set SERVER_JAR_URL." >&2
  exit 1
fi

JAVA_MEM_ARGS=("-Xms${MEMORY}" "-Xmx${MEMORY}")

echo "Starting SpongeForge..."
exec java "${JAVA_MEM_ARGS[@]}" ${JAVA_EXTRA_ARGS} -jar /data/server.jar nogui
