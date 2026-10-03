@echo off
rem ============================================================
rem  TasteWise - database backup (one click)
rem
rem  ============================================================
rem  THIS FILE IS INTENTIONALLY ASCII-ONLY. Do NOT add non-ASCII
rem  text (Chinese, accents, box-drawing characters, ...) to it.
rem
rem  Why: cmd.exe mis-parses batch files that mix multi-byte
rem  characters with chcp. It counts file positions in characters
rem  but reads in bytes, so after the code page changes the two
rem  disagree -- the result is duplicated characters ("AB" printed
rem  as "AABB") and lines cut in half that then get executed as
rem  commands ("... is not recognized as an internal or external
rem  command"). Whether it blows up depends on byte offsets, so a
rem  tiny edit can flip a working file into a broken one.
rem
rem  Fix: keep every .bat pure ASCII, and print all Chinese text
rem  from PHP (backend\tools\backup_db.php) instead.
rem  ============================================================
rem
rem  chcp 65001 switches this console to UTF-8 so PHP's output shows
rem  correctly. The "<nul" part is required: without it chcp swallows
rem  redirected stdin, and the child PHP process then reads nothing
rem  (that bug broke the password tool's piped input).
rem
rem  The "locate php.exe" block below is also present in the other
rem  two launchers in this folder. Keep them in sync.
rem ============================================================

chcp 65001 <nul >nul 2>&1
setlocal
cd /d "%~dp0"

set "PHP="

rem ---- 1. php.exe on PATH ----
for %%P in (php.exe) do if not "%%~$PATH:P"=="" set "PHP=%%~$PATH:P"

rem ---- 2. phpstudy ----
if not defined PHP (
  for %%D in (C D E F G H) do (
    if not defined PHP if exist "%%D:\phpstudy_pro\Extensions\php" (
      for /d %%V in ("%%D:\phpstudy_pro\Extensions\php\php*") do (
        if not defined PHP if exist "%%~fV\php.exe" set "PHP=%%~fV\php.exe"
      )
    )
  )
)

rem ---- 3. xampp / wamp ----
if not defined PHP (
  for %%D in (C D E F G H) do (
    if not defined PHP if exist "%%D:\xampp\php\php.exe" set "PHP=%%D:\xampp\php\php.exe"
    if not defined PHP if exist "%%D:\wamp64\bin\php" (
      for /d %%V in ("%%D:\wamp64\bin\php\php*") do (
        if not defined PHP if exist "%%~fV\php.exe" set "PHP=%%~fV\php.exe"
      )
    )
  )
)

if not defined PHP goto no_php

"%PHP%" "backend\tools\backup_db.php" %*
set "EXITCODE=%ERRORLEVEL%"
echo.
pause >nul
exit /b %EXITCODE%

rem ============================================================
rem  php.exe not found: fall back to mysqldump on PATH so a backup
rem  is still possible without PHP.
rem ============================================================
:no_php
echo.
echo   [X] php.exe was not found, so the friendly output is unavailable.
echo       Trying mysqldump directly instead.
echo.
where mysqldump >nul 2>&1
if errorlevel 1 goto no_dump

if not exist "backup" mkdir "backup"

rem Deliberately NOT parsing %DATE%/%TIME%: their format depends on the
rem Windows locale, so it happily produces names like "backup\wechat_order_2026.sql"
rem or fails outright. A simple counter is locale-proof and never overwrites.
set "N=1"
:find_name
if exist "backup\wechat_order_manual_%N%.sql" (
  set /a N+=1
  goto find_name
)
set "OUT=backup\wechat_order_manual_%N%.sql"

echo   Writing: %OUT%
mysqldump -uroot -p --default-character-set=utf8mb4 --single-transaction --skip-comments wechat_order > "%OUT%"
if errorlevel 1 goto dump_failed

echo   Done. Check the file size before trusting it.
echo   Restore: mysql -uroot -p wechat_order ^< "%OUT%"
pause >nul
exit /b 0

:no_dump
echo   [X] mysqldump.exe was not found either.
echo.
echo       It usually lives in:
echo         ...\phpstudy_pro\Extensions\MySQL5.7.26\bin\mysqldump.exe
echo.
pause >nul
exit /b 1

:dump_failed
echo   [X] mysqldump failed. The file is probably empty or missing --
echo       do not trust it. Common causes: wrong MySQL password, or the
echo       MySQL service is not running.
pause >nul
exit /b 1
