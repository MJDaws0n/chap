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

download_server_jar() {
	echo "Downloading vanilla Minecraft server jar (version=${MC_VERSION})..."
	python3 - <<'PY'
import json, os, sys, urllib.request

mc_version = os.environ.get('MINECRAFT_VERSION','latest').strip() or 'latest'
manifest_url = 'https://piston-meta.mojang.com/mc/game/version_manifest_v2.json'

with urllib.request.urlopen(manifest_url, timeout=30) as r:
		manifest = json.load(r)

if mc_version == 'latest':
		mc_version = manifest['latest']['release']

version_url = None
for v in manifest.get('versions', []):
		if v.get('id') == mc_version:
				version_url = v.get('url')
				break

if not version_url:
		raise SystemExit(f'Unknown Minecraft version: {mc_version}')

with urllib.request.urlopen(version_url, timeout=30) as r:
		ver = json.load(r)

server = (ver.get('downloads') or {}).get('server') or {}
jar_url = server.get('url')
if not jar_url:
		raise SystemExit('Could not determine server jar URL')

dest = '/data/server.jar'
print('Jar URL:', jar_url)
urllib.request.urlretrieve(jar_url, dest)
print('Saved:', dest)
PY
}

if [[ ! -f /data/server.jar ]]; then
	download_server_jar
fi

JAVA_MEM_ARGS=("-Xms${MEMORY}" "-Xmx${MEMORY}")

echo "Starting Minecraft..."
exec java "${JAVA_MEM_ARGS[@]}" ${JAVA_EXTRA_ARGS} -jar /data/server.jar nogui
