@echo off
setlocal
cd /d "%~dp0"
title Vaultcast AI Contributor
set "PYRUN="
where py >nul 2>nul
if not errorlevel 1 (
  py -3 -c "import sys; raise SystemExit(0 if sys.version_info >= (3,10) and sys.maxsize > 2**32 else 1)" >nul 2>nul
  if not errorlevel 1 set "PYRUN=py -3"
)
if not defined PYRUN (
  where python >nul 2>nul
  if not errorlevel 1 (
    python -c "import sys; raise SystemExit(0 if sys.version_info >= (3,10) and sys.maxsize > 2**32 else 1)" >nul 2>nul
    if not errorlevel 1 set "PYRUN=python"
  )
)
if defined PYRUN goto prepare_worker

echo Python 3.10 or newer ^(64-bit^) is needed for contributor setup.
where winget >nul 2>nul
if errorlevel 1 goto manual_python
set /p "INSTALLPY=Install Python 3.12 for your Windows user with WinGet? [Y/N]: "
if /I not "%INSTALLPY%"=="Y" goto manual_python
winget install --id Python.Python.3.12 --exact --scope user --architecture x64
if errorlevel 1 goto manual_python
if exist "%LOCALAPPDATA%\Programs\Python\Python312\python.exe" (
  set "PYRUN="%LOCALAPPDATA%\Programs\Python\Python312\python.exe""
  goto prepare_worker
)
echo Python was installed. Close this window and run start-worker.cmd again.
goto finish

:prepare_worker
set "WORKERVENV=%LOCALAPPDATA%\VaultcastAIWorker\worker-venv"
if exist "%WORKERVENV%\Scripts\python.exe" goto check_packages
echo Creating an isolated contributor environment in %WORKERVENV%
%PYRUN% -m venv "%WORKERVENV%"
if errorlevel 1 goto environment_failed

:check_packages
"%WORKERVENV%\Scripts\python.exe" -c "import socketio, uv" >nul 2>nul
if not errorlevel 1 goto run_worker
echo Contributor setup uses python-socketio for progress and uv to install an optional InvokeAI engine.
echo These packages are installed only inside the contributor environment above.
set /p "INSTALLDEPS=Install the contributor packages now? [Y/N]: "
if /I not "%INSTALLDEPS%"=="Y" goto finish
"%WORKERVENV%\Scripts\python.exe" -m pip install "python-socketio[client]>=5.11,<6" "uv==0.13.6"
if errorlevel 1 goto environment_failed

:run_worker
"%WORKERVENV%\Scripts\python.exe" worker.py %*
goto finish

:manual_python
echo Install 64-bit Python 3.10 or newer from https://www.python.org/downloads/windows/
echo Then run start-worker.cmd again.
goto finish

:environment_failed
echo Contributor environment setup failed. Check the error above and rerun this file to retry.

:finish
echo.
pause
