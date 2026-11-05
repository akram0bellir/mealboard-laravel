@echo off
rem Mealboard: set a new password for one account (and log it out everywhere).
cd /d "%~dp0"
set /p LOGIN=E-mail or username of the account:
set /p NEWPASS=New password (at least 8 characters):
"C:\xampp\php\php.exe" artisan mealboard:set-password "%LOGIN%" "%NEWPASS%"
pause
