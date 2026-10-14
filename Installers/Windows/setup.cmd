@echo off

set "setup_powershell=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"

if exist "%SystemRoot%\Sysnative\WindowsPowerShell\v1.0\powershell.exe" set "setup_powershell=%SystemRoot%\Sysnative\WindowsPowerShell\v1.0\powershell.exe"

"%setup_powershell%" -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup.ps1" %*

set "setup_result=%errorlevel%"

pause

exit /b %setup_result%

