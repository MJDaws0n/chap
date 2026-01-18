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
SPONGE_VERSION="${SPONGE_VERSION:-latest}"
MEMORY="${MEMORY:-2G}"
JAVA_EXTRA_ARGS="${JAVA_EXTRA_ARGS:-}"
SERVER_JAR_URL="${SERVER_JAR_URL:-}"

if [[ -n "${SERVER_JAR_URL}" ]]; then
  echo "Downloading server jar from SERVER_JAR_URL"
  EXPANDED_URL="${SERVER_JAR_URL//\{version\}/${SPONGE_VERSION}}"
  curl -fsSL "${EXPANDED_URL}" -o /data/server.jar || true
fi

if [[ ! -f /data/server.jar ]]; then
  echo "Attempting to download SpongeVanilla jar from Sponge Maven repo..."
  SV_URL=""
  if [[ -z "${SPONGE_VERSION}" || "${SPONGE_VERSION}" == "latest" ]]; then
    SV_URL=$(python3 - <<'PY'
import sys,urllib.request,xml.etree.ElementTree as ET
base='https://repo.spongepowered.org/maven'
meta=base+'/maven2/org/spongepowered/spongevanilla/maven-metadata.xml'
try:
    with urllib.request.urlopen(meta, timeout=30) as r:
        xml=r.read()
    root=ET.fromstring(xml)
    ver=root.findtext('./versioning/release') or root.findtext('./versioning/latest')
    if not ver:
        versions=root.findall('./versioning/versions/version')
        ver=versions[-1].text if versions else ''
    if ver:
        print(f"{base}/maven2/org/spongepowered/spongevanilla/{ver}/spongevanilla-{ver}.jar")
except Exception:
    sys.exit(0)
PY
  )
  else
    SV_URL="https://repo.spongepowered.org/maven/org/spongepowered/spongevanilla/${SPONGE_VERSION}/spongevanilla-${SPONGE_VERSION}.jar"
  fi

  if [[ -n "$SV_URL" ]]; then
    if curl -fsSL "$SV_URL" -o /data/server.jar; then
      echo "Downloaded SpongeVanilla: $SV_URL"
    else
      echo "SpongeVanilla automatic download failed for URL: $SV_URL" >&2
    fi
  fi
fi

if [[ ! -f /data/server.jar ]]; then
  echo "No /data/server.jar found and no SERVER_JAR_URL or SpongeVanilla asset available." >&2
  echo "Place a compatible SpongeVanilla jar at /data/server.jar or set SERVER_JAR_URL." >&2
  exit 1
fi

JAVA_MEM_ARGS=("-Xms${MEMORY}" "-Xmx${MEMORY}")

echo "Starting SpongeVanilla..."
exec java "${JAVA_MEM_ARGS[@]}" ${JAVA_EXTRA_ARGS} -jar /data/server.jar nogui
