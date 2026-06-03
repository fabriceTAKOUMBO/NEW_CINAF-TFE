#!/usr/bin/env bash
# ============================================================
# CINAF v2 — Script de lancement des tests fonctionnels PHPUnit
# Environnement : bash (Linux / WSL / Git Bash)
# Usage : bash bin/run-tests.sh
# Usage (classe unique) : bash bin/run-tests.sh FilmControllerTest
# ============================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FILTER="${1:-}"

echo ""
echo "======================================"
echo "  CINAF v2 — Tests fonctionnels backend"
echo "======================================"
echo ""

# 1. Reset de la BDD de test
echo "[1/4] Suppression de la BDD de test..."
php "$SCRIPT_DIR/bin/console" --env=test doctrine:database:drop --force --if-exists

echo "[2/4] Creation de la BDD de test..."
php "$SCRIPT_DIR/bin/console" --env=test doctrine:database:create

echo "[3/4] Migration..."
php "$SCRIPT_DIR/bin/console" --env=test doctrine:migrations:migrate --no-interaction

# 2. Lancement de PHPUnit
echo "[4/4] Lancement de PHPUnit..."
echo ""

if [ -n "$FILTER" ]; then
    php "$SCRIPT_DIR/bin/phpunit" --filter "$FILTER" --colors=always
else
    php "$SCRIPT_DIR/bin/phpunit" --colors=always
fi

echo ""
echo "Tests termines."
