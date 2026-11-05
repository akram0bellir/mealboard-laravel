@echo off
rem Creates the Windows scheduled task Mealboard needs on this PC: every minute it runs `php artisan schedule:run`,
rem which starts the cron (every 5 minutes: automatic no-shows, daily order list, e-mails) and the backup (every 6 hours).
rem MySQL must be running (start.bat) for it to work. Use this OR the "Mealboard Scheduler" window of start.bat, not both.
cd /d "%~dp0"
schtasks /Create /F /TN "Mealboard Scheduler" /SC MINUTE /MO 1 /TR "wscript.exe \"%~dp0tools\run-hidden.vbs\""
echo.
echo To remove it:  schtasks /Delete /F /TN "Mealboard Scheduler"
echo To check it:   php artisan schedule:list   (and the System page in the admin)
pause
