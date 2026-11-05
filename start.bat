@echo off
rem Starts Mealboard (Laravel) on this PC: MySQL 8 (port 3307), the scheduler (cron every 5 min, backups every 6 h)
rem in a minimized window, and the website at http://127.0.0.1:%PORT%.
rem The door app's "Server" setting is this address (it adds /api by itself).
cd /d "%~dp0"
set PORT=8080
start "Mealboard MySQL" "C:\mysql8\mysql-8.0.40-winx64\bin\mysqld.exe" --defaults-file=C:\mysql8\my.ini --console
timeout /t 5 >nul
rem Not needed when the Windows tasks from install-tasks.bat are installed.
start "Mealboard Scheduler" /min cmd /c ""C:\xampp\php\php.exe" artisan schedule:work"
start "" http://127.0.0.1:%PORT%
"C:\xampp\php\php.exe" artisan serve --host=127.0.0.1 --port=%PORT%
