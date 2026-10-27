@echo off
setlocal
title Vaultcast AI Contributor
set "PYRUN="

where py >nul 2>nul
if not errorlevel 1 (
  py -3 -c "import sys; raise SystemExit(0 if sys.version_info >= (3,10) else 1)" >nul 2>nul
  if not errorlevel 1 set "PYRUN=py -3"
)

if not defined PYRUN (
  where python >nul 2>nul
  if not errorlevel 1 (
    python -c "import sys; raise SystemExit(0 if sys.version_info >= (3,10) else 1)" >nul 2>nul
    if not errorlevel 1 set "PYRUN=python"
  )
)

if defined PYRUN goto check_socketio

echo Python 3.10 or newer was not found.
where winget >nul 2>nul
if errorlevel 1 goto manual_install
echo.
set /p "INSTALLPY=Install Python 3.12 for your Windows user with WinGet now? [Y/N]: "
if /I not "%INSTALLPY%"=="Y" goto manual_install
echo.
winget install --id Python.Python.3.12 --exact --scope user
if errorlevel 1 goto install_failed
echo.
echo Python installation finished. Close this window and run start-worker.cmd again.
goto finish

:manual_install
echo.
echo Install Python 3.10 or newer from https://www.python.org/downloads/windows/
echo Then run start-worker.cmd again.
goto finish

:install_failed
echo.
echo Python could not be installed automatically. Install it from https://www.python.org/downloads/windows/
echo Then run start-worker.cmd again.
goto finish

:run_worker
%PYRUN% worker.py
goto finish

:check_socketio
%PYRUN% -c "import socketio" >nul 2>nul
if not errorlevel 1 goto run_worker
echo.
echo Live progress updates need the small python-socketio client package.
set /p "INSTALLSOCKETIO=Install it for this Python user now? [Y/N]: "
if /I not "%INSTALLSOCKETIO%"=="Y" goto run_without_socketio
echo.
%PYRUN% -m pip install "python-socketio[client]"
if errorlevel 1 goto socketio_failed
goto run_worker

:socketio_failed
echo.
echo The progress package could not be installed. The worker can still generate images, but progress will stay indeterminate.
goto run_worker

:run_without_socketio
echo.
echo Continuing without live progress updates. The worker can still generate and upload images.
goto run_worker

:finish
echo.
pause
