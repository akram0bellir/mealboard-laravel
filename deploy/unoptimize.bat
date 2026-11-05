@echo off
rem Back to development: forget the cached config / routes / views and the strict class map.
cd /d "%~dp0.."
C:\xampp\php\php.exe artisan optimize:clear
call C:\xampp\php\php.exe C:\tools\composer\composer.phar dump-autoload --no-interaction
