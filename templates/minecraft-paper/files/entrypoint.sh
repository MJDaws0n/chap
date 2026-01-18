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
PAPER_VERSION="${PAPER_VERSION:-latest}"

download_server_jar() {
	echo "Downloading PaperMC server jar (mc_version=${MC_VERSION}, paper_build=${PAPER_VERSION})..."
	python3 - <<'PY'
import json, os, sys, urllib.request

mc_version = os.environ.get('MINECRAFT_VERSION','latest').strip() or 'latest'
paper_build = os.environ.get('PAPER_VERSION','latest').strip() or 'latest'
manifest_url = 'https://piston-meta.mojang.com/mc/game/version_manifest_v2.json'

if mc_version == 'latest':
	with urllib.request.urlopen(manifest_url, timeout=30) as r:
		manifest = json.load(r)
	mc_version = manifest['latest']['release']

api_urls = [
	f'https://api.papermc.io/v2/projects/paper/versions/{mc_version}/builds/{paper_build}',
	f'https://fill.papermc.io/v3/projects/paper/versions/{mc_version}/builds/{paper_build}'
]

def fetch(url):
	with urllib.request.urlopen(url, timeout=30) as r:
		return json.load(r)

data = None
last_err = None
for url in api_urls:
	try:
		data = fetch(url)
		break
	except Exception as e:
		last_err = e

if data is None:
	raise SystemExit(f'Could not fetch PaperMC build metadata for {mc_version} build {paper_build}: {last_err}')

def find_url(obj):
	if isinstance(obj, dict):
		for k, v in obj.items():
			if k == 'url' and isinstance(v, str):
				return v
			res = find_url(v)
			if res:
				return res
	elif isinstance(obj, list):
		for item in obj:
			res = find_url(item)
			if res:
				return res
	return None

jar_url = find_url(data.get('downloads', data))
if not jar_url:
	raise SystemExit(f'Could not determine PaperMC download URL for {mc_version} build {paper_build}')

dest = '/data/server.jar'
print('Jar URL:', jar_url)
req = urllib.request.Request(jar_url, headers={
	'User-Agent': 'curl/7.85.0',
	'Accept': '*/*'
})
try:
	with urllib.request.urlopen(req, timeout=60) as resp, open(dest, 'wb') as out:
		import shutil
		shutil.copyfileobj(resp, out)
except Exception as e:
	raise SystemExit(f'Download failed: {e}')
print('Saved:', dest)
PY
}

if [[ ! -f /data/server.jar ]]; then
	download_server_jar
fi

JAVA_MEM_ARGS=("-Xms${MEMORY}" "-Xmx${MEMORY}")

echo "Starting Minecraft..."
exec java "${JAVA_MEM_ARGS[@]}" ${JAVA_EXTRA_ARGS} -jar /data/server.jar nogui
