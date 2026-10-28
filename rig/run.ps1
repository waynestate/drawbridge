# Run the DRAWBRIDGE container rig on Windows: build the gateway image, start the mock OpenID Connect provider and echo app,
# run the end-to-end tests, tear everything down. Nothing here talks to Entra. (Linux/macOS: rig/run.sh.)
#
# Drawbridge goes up, the tests go in, the drawbridge goes back down.
$ErrorActionPreference = 'Continue'

$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $root

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error 'run.ps1 needs Docker Desktop (with the compose plugin).'
    exit 1
}

docker build -q -f docker/gateway/Dockerfile -t drawbridge-gateway:rig .
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

# The tests run in a small PHP container that mounts this folder, so the dev dependencies must exist here.
if (-not (Test-Path (Join-Path $root 'vendor/bin/phpunit'))) {
    docker run --rm -v "${root}:/app" -w /app composer:2.10.3 composer install --no-interaction --no-progress
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Set-Location (Join-Path $root 'rig')
docker compose --profile test run --rm tester
$status = $LASTEXITCODE
docker compose --profile test down -v 2>&1 | Out-Null
exit $status
