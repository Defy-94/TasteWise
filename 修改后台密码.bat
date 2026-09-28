@echo off
rem ============================================================
rem  TasteWise Admin - change the administrator password
rem  (double-click to run)
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
rem  from PHP (backend\tools\admin_passwd.php) instead.
rem  ============================================================
rem
rem  chcp 65001 switches this console to UTF-8 so PHP's output shows
rem  correctly. The "<nul" part is required: without it chcp swallows
rem  redirected stdin, and the child PHP process then reads nothing.
rem
rem  The "locate php.exe" block below is also present in the other
rem  launcher in this folder. Keep the two in sync.
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

"%PHP%" "backend\tools\admin_passwd.php" %*
set "EXITCODE=%ERRORLEVEL%"
echo.
pause >nul
exit /b %EXITCODE%

rem ============================================================
rem  php.exe not found. Both auto-detection and the manual path
rem  question are ASCII-only; the rest of the UI comes from PHP.
rem ============================================================
:no_php
echo.
echo   [X] php.exe was not found automatically.
echo.
echo       Changing the password needs PHP, so please paste the full
echo       path to php.exe below. phpstudy usually keeps it under
echo       ...\phpstudy_pro\Extensions\php\VERSION\php.exe
echo       (open phpstudy's install folder and look for Extensions\php).
echo.
echo       Press Enter with no input to cancel.
echo.
set /p "MANUAL=   php.exe full path: "

if not defined MANUAL goto cancelled
if not exist "%MANUAL%" (
  echo.
  echo   That path does not exist: %MANUAL%
  goto cancelled
)

"%MANUAL%" "backend\tools\admin_passwd.php"
echo.
pause >nul
exit /b 0

:cancelled
echo.
echo   Cancelled. The password was NOT changed.
pause >nul
exit /b 1
