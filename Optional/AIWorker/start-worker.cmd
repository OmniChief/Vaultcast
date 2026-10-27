@echo off
title Vaultcast AI Contributor
where py >nul 2>nul
if %errorlevel%==0 (
  py -3 worker.py
) else (
  python worker.py
)
pause
