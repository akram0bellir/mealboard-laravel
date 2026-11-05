@echo off
rem Makes (or refreshes) the database the automated tests run on: mealboard_test, a clone of mealboard_laravel.
rem Every test rolls back what it writes, so run this only when the real copy has new data you want in the tests.
set MY=C:\mysql8\mysql-8.0.40-winx64\bin
"%MY%\mysql.exe" -h127.0.0.1 -P3307 -uroot -e "DROP DATABASE IF EXISTS mealboard_test; CREATE DATABASE mealboard_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"%MY%\mysqldump.exe" -h127.0.0.1 -P3307 -uroot --no-tablespaces mealboard_laravel | "%MY%\mysql.exe" -h127.0.0.1 -P3307 -uroot mealboard_test
echo Done. Run the tests with:  C:\xampp\php\php.exe artisan test
