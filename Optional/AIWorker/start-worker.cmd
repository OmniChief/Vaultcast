@echo off
setlocal
cd /d "%~dp0"
title Vaultcast Contributor
if not exist "%~dp0setup-worker.ps1" (
  echo Missing setup-worker.ps1. Extract the entire Optional\AIWorker folder before running setup.
  goto finish
)
"%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup-worker.ps1" %*
:finish
echo.
pause
