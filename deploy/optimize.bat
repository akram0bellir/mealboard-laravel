@echo off
rem Production speed-ups. Run after every code or .env change on the server, then restart Apache.
rem   - class map (no file search for classes), cached config / routes / events / views
rem   - APP_DEBUG=false and APP_ENV=production are baked into the cached config
rem To develop again (php artisan serve, tests): deploy\unoptimize.bat
cd /d "%~dp0.."
set APP_ENV=production
set APP_DEBUG=false
set VIEW_CHECK_CACHE_TIMESTAMPS=false
call C:\xampp\php\php.exe C:\tools\composer\composer.phar dump-autoload --optimize --classmap-authoritative --no-interaction
C:\xampp\php\php.exe artisan optimize
echo.
echo Done. Restart the Mealboard Apache (deploy\apache-stop.bat then deploy\apache-start.bat).
