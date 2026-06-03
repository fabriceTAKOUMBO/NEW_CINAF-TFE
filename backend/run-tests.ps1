# ============================================================
# CINAF v2 - Script de lancement des tests fonctionnels PHPUnit
# Environnement : Windows / PowerShell
# Usage : .\run-tests.ps1
# Usage (classe unique) : .\run-tests.ps1 -Filter FilmControllerTest
# ============================================================

param(
    [string]$Filter = "",
    [switch]$SkipDbReset = $false
)

$ErrorActionPreference = "Stop"
$Root = $PSScriptRoot

Write-Host ""
Write-Host "======================================" -ForegroundColor Cyan
Write-Host "  CINAF v2 - Tests fonctionnels backend" -ForegroundColor Cyan
Write-Host "======================================" -ForegroundColor Cyan
Write-Host ""

# 1. Reset de la BDD de test
if (-not $SkipDbReset) {
    Write-Host "[1/4] Suppression de la BDD de test..." -ForegroundColor Yellow
    php "$Root\bin\console" --env=test doctrine:database:drop --force --if-exists
    if ($LASTEXITCODE -ne 0) { Write-Error "Echec de la suppression de la BDD" }

    Write-Host "[2/4] Creation de la BDD de test..." -ForegroundColor Yellow
    php "$Root\bin\console" --env=test doctrine:database:create
    if ($LASTEXITCODE -ne 0) { Write-Error "Echec de la creation de la BDD" }

    Write-Host "[3/4] Migration..." -ForegroundColor Yellow
    php "$Root\bin\console" --env=test doctrine:migrations:migrate --no-interaction
    if ($LASTEXITCODE -ne 0) { Write-Error "Echec des migrations" }
} else {
    Write-Host "[1-3/4] Reset BDD ignore (--SkipDbReset)" -ForegroundColor DarkGray
}

# 2. Lancement de PHPUnit
Write-Host "[4/4] Lancement de PHPUnit..." -ForegroundColor Yellow
Write-Host ""

if ($Filter -ne "") {
    php "$Root\bin\phpunit" --filter $Filter --colors=always
} else {
    php "$Root\bin\phpunit" --colors=always
}

$exitCode = $LASTEXITCODE

Write-Host ""
if ($exitCode -eq 0) {
    Write-Host "Tous les tests sont passes !" -ForegroundColor Green
} else {
    Write-Host "Des tests ont echoue (code $exitCode)." -ForegroundColor Red
}

exit $exitCode
