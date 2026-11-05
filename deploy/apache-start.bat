@echo off
rem Starts the Mealboard Apache (http://127.0.0.1:8088) next to XAMPP's own Apache, in a minimized window.
rem Close that window or run apache-stop.bat to stop it.
cd /d "%~dp0.."
"C:\xampp\apache\bin\httpd.exe" -t -f "%~dp0apache-mealboard.conf" || (echo The Apache configuration has an error. & pause & exit /b 1)
start "Mealboard Apache" /min "C:\xampp\apache\bin\httpd.exe" -f "%~dp0apache-mealboard.conf"
echo Mealboard is on http://127.0.0.1:8088
