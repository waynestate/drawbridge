#!/bin/sh
# Run the DRAWBRIDGE container rig on Linux or macOS: build the gateway image, start the mock OpenID Connect provider and echo app,
# run the end-to-end tests, tear everything down. Nothing here talks to Entra. (Windows: rig/run.ps1.)
#
# Drawbridge goes up, the tests go in, the drawbridge goes back down. Raise it again and nobody has to knock.
set -eu

root=$(cd "$(dirname "$0")/.." && pwd)
cd "$root"

if ! command -v docker >/dev/null 2>&1; then
    echo "run.sh needs Docker (with the compose plugin)." >&2
    exit 1
fi

docker build -q -f docker/gateway/Dockerfile -t drawbridge-gateway:rig .

# The tests run in a small PHP container that mounts this folder, so the dev dependencies must exist here.
if [ ! -f vendor/bin/phpunit ]; then
    docker run --rm -v "$root:/app" -w /app composer:2.10.3 composer install --no-interaction --no-progress
fi

cd rig
status=0
docker compose --profile test run --rm tester || status=$?
docker compose --profile test down -v >/dev/null 2>&1 || true
exit "$status"
