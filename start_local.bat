@echo off
chcp 65001 >nul
rem Local start with XAMPP PHP. Start MySQL in XAMPP Control Panel first.
rem   start_local.bat        : create tables if missing, then start
rem   start_local.bat test   : also load test data (sql/03) when creating tables
rem   start_local.bat reset  : drop and recreate the DB (with test data)
cd /d %~dp0
set PHP=C:\xampp\php\php.exe
if not exist %PHP% (
  echo XAMPP の PHP が見つかりません: %PHP%
  pause
  exit /b 1
)
if "%1"=="reset" (
  %PHP% tools\db_init.php --reset --test
) else if "%1"=="test" (
  %PHP% tools\db_init.php --test
) else (
  %PHP% tools\db_init.php
)
echo.
echo http://localhost:8000/ を開きます。止めるときはこの画面で Ctrl+C
start http://localhost:8000/
%PHP% -S localhost:8000 -t .
