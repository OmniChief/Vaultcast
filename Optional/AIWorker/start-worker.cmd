@echo off
setlocal
cd /d "%~dp0"
title Vaultcast Contributor
if /I "%~1"=="--uninstall" goto uninstall
echo To uninstall this contributor, run uninstall-worker.cmd or start-worker.cmd --uninstall
if not exist "%~dp0setup-worker.ps1" (
  echo Missing setup-worker.ps1. Extract the entire Optional\AIWorker folder before running setup.
  goto finish
)
"%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup-worker.ps1" %*
goto finish
:uninstall
"%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "%~dp0uninstall-worker.ps1"
:finish
echo.
pause
