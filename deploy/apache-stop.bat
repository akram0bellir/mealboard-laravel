@echo off
rem Stops only the Mealboard Apache (the one started by apache-start.bat), not XAMPP's.
powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"Name='httpd.exe'\" | Where-Object { $_.CommandLine -like '*apache-mealboard.conf*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }"
echo Stopped.
