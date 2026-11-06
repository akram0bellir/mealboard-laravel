@echo off
rem Starts the Mealboard Apache (http://127.0.0.1:8088) next to XAMPP's own Apache, in a minimized window.
rem It uses its own php.ini: XAMPP's, plus deploy\php-overrides.ini (its own code cache, production settings).
rem Run apache-stop.bat to stop it.
cd /d "%~dp0.."
if not exist "%~dp0php" mkdir "%~dp0php"
copy /y "C:\xampp\php\php.ini" "%~dp0php\php.ini" >nul
type "%~dp0php-overrides.ini" >> "%~dp0php\php.ini"
"C:\xampp\apache\bin\httpd.exe" -t -f "%~dp0apache-mealboard.conf" || (echo The Apache configuration has an error. & pause & exit /b 1)
start "Mealboard Apache" /min "C:\xampp\apache\bin\httpd.exe" -f "%~dp0apache-mealboard.conf"
echo Mealboard is on http://127.0.0.1:8088
