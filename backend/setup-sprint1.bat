@echo off
echo =========================================================
echo  CINAF v2 - Sprint 1 Backend Setup
echo =========================================================
cd /d "%~dp0"

echo.
echo [ETAPE 1] Permissions sur var/
icacls var /grant Everyone:F /T 2>nul

echo.
echo [ETAPE 1] Installation des packages Composer...
composer require lexik/jwt-authentication-bundle gesdinet/jwt-refresh-token-bundle symfony/uid symfony/mailer symfony/security-bundle --no-interaction
if %ERRORLEVEL% neq 0 (
    echo ERREUR lors de l'installation des packages!
    pause
    exit /b 1
)

echo.
echo [ETAPE 2] Generation des cles JWT...
php bin/console lexik:jwt:generate-keypair
if %ERRORLEVEL% neq 0 (
    echo Tentative manuelle de generation des cles JWT...
    if not exist "config\jwt" mkdir config\jwt
    openssl genrsa -out config/jwt/private.pem 4096
    openssl rsa -pubout -in config/jwt/private.pem -out config/jwt/public.pem
)

echo.
echo [ETAPE 3] Creation de la base de donnees...
php bin/console doctrine:database:create --if-not-exists

echo.
echo [ETAPE 4] Generation de la migration...
php bin/console doctrine:migrations:diff

echo.
echo [ETAPE 5] Execution de la migration...
php bin/console doctrine:migrations:migrate --no-interaction

echo.
echo [ETAPE 6] Vidage du cache...
php bin/console cache:clear

echo.
echo [ETAPE 7] Demarrage du serveur Symfony sur le port 8001...
symfony server:stop 2>nul
symfony server:start --port=8001 --no-tls -d

echo.
echo [ETAPE 8] Test de l'API - Inscription...
curl -s -X POST http://127.0.0.1:8001/api/auth/register ^
  -H "Content-Type: application/json" ^
  -d "{\"email\":\"test@cinaf.com\",\"password\":\"Test1234!\",\"firstName\":\"Test\",\"lastName\":\"User\",\"consentRgpd\":true}"

echo.
echo [ETAPE 9] Test de l'API - Connexion...
curl -s -X POST http://127.0.0.1:8001/api/auth/login ^
  -H "Content-Type: application/json" ^
  -d "{\"email\":\"test@cinaf.com\",\"password\":\"Test1234!\"}"

echo.
echo =========================================================
echo  Setup termine !
echo =========================================================
pause
