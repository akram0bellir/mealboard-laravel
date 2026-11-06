#!/bin/bash
# Rebuild the production caches, restart the Mealboard Apache, pin it to one core. (Git Bash helper for load tests.)
cd "$(dirname "$0")/.."
APP_ENV=production APP_DEBUG=false VIEW_CHECK_CACHE_TIMESTAMPS=false /c/xampp/php/php.exe artisan optimize > /dev/null 2>&1
/c/xampp/php/php.exe -r '@mkdir("deploy/php"); file_put_contents("deploy/php/php.ini", file_get_contents("C:/xampp/php/php.ini") . "
" . file_get_contents("deploy/php-overrides.ini"));'
powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"Name='httpd.exe'\" | Where-Object { \$_.CommandLine -like '*apache-mealboard.conf*' } | ForEach-Object { Stop-Process -Id \$_.ProcessId -Force }"
sleep 1
(/c/xampp/apache/bin/httpd.exe -f "$(pwd -W)/deploy/apache-mealboard.conf" > /dev/null 2>&1 &)
sleep 3
powershell -NoProfile -ExecutionPolicy Bypass -File tools/pin-one-core.ps1 > /dev/null
curl -s -o /dev/null http://127.0.0.1:8088/up
