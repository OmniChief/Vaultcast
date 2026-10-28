param([Parameter(ValueFromRemainingArguments=$true)][string[]]$WorkerArguments)
$ErrorActionPreference = 'Stop'
$workerRoot = Join-Path $env:LOCALAPPDATA 'VaultcastAIWorker'
$legacyEnvironmentPath = Join-Path $workerRoot 'worker-venv'
# Store Python virtualizes AppData. The user-profile folder is shared with ordinary PowerShell.
$environmentRoot = Join-Path $env:USERPROFILE '.vaultcast'
$environmentPath = Join-Path $environmentRoot 'worker-venv'
$logPath = Join-Path $workerRoot 'setup.log'
$step = 'starting contributor setup'
$transcribing = $false

function Find-WorkingPython {
    $candidates = @([pscustomobject]@{Path=(Join-Path $environmentPath 'Scripts\python.exe'); Arguments=@()})
    foreach ($name in @('py','python','python3')) {
        $command = Get-Command $name -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($command) { $candidates += [pscustomobject]@{Path=$command.Source; Arguments=$(if($name -eq 'py'){@('-3')}else{@()})} }
    }
    $pythonHome = Join-Path $env:LOCALAPPDATA 'Programs\Python'
    if (Test-Path -LiteralPath $pythonHome) {
        Get-ChildItem -LiteralPath $pythonHome -Directory -Filter 'Python3*' | Sort-Object Name -Descending | ForEach-Object {
            $candidates += [pscustomobject]@{Path=(Join-Path $_.FullName 'python.exe'); Arguments=@()}
        }
    }
    foreach ($candidate in $candidates) {
        if (!(Test-Path -LiteralPath $candidate.Path)) { continue }
        try {
            $candidateArguments = @($candidate.Arguments)
            $result = @(& $candidate.Path @candidateArguments -c 'import sys; sys.exit(1) if sys.version_info < (3,10) or sys.maxsize <= 2**32 else print(sys.executable)' 2>$null)
            if ($LASTEXITCODE -eq 0 -and $result.Count -gt 0) {
                $actual = ([string]$result[-1]).Trim()
                if (Test-Path -LiteralPath $actual -PathType Leaf) { return $actual }
            }
        } catch { }
    }
    return $null
}

function Run-Checked([string]$Executable,[string[]]$Arguments) {
    if (!(Test-Path -LiteralPath $Executable -PathType Leaf)) { throw "Executable is missing: $Executable" }
    & $Executable @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Command failed with exit code ${LASTEXITCODE}: $Executable $($Arguments -join ' ')" }
}

try {
    New-Item -ItemType Directory -Force -Path $workerRoot | Out-Null
    try { Start-Transcript -Path $logPath -Append | Out-Null; $transcribing=$true } catch { }
    Set-Location -LiteralPath $PSScriptRoot
    foreach ($file in @('worker.py','contributor_setup.py','encoding_worker.py')) {
        if (!(Test-Path -LiteralPath (Join-Path $PSScriptRoot $file))) { throw "Missing $file. Extract the entire Optional/AIWorker folder; do not run it inside the ZIP." }
    }
    # Keep existing working non-redirected environments; new/repaired installs use the shared path.
    $legacyPython = Join-Path $legacyEnvironmentPath 'Scripts\python.exe'
    if (Test-Path -LiteralPath $legacyPython) {
        try {
            & $legacyPython -c 'import sys; sys.exit(0 if sys.version_info >= (3,10) and sys.maxsize > 2**32 else 1)' 2>$null
            if ($LASTEXITCODE -eq 0) { $environmentPath=$legacyEnvironmentPath; $environmentRoot=$workerRoot }
        } catch { }
    }
    $step = 'finding a working 64-bit Python 3.10 or newer'
    $python = Find-WorkingPython
    if (!$python) {
        if (![Environment]::Is64BitOperatingSystem) { throw 'This is 32-bit Windows. The current contributor installer requires 64-bit Windows and Python.' }
        $winget = Get-Command winget -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
        if (!$winget) { throw 'Python was not found and WinGet is unavailable. Install 64-bit Python from https://www.python.org/downloads/windows/ and rerun this file.' }
        if ((Read-Host 'Install Python 3.12 (64-bit) for your Windows user with WinGet? [Y/N]') -notmatch '^(y|yes)$') { return }
        $step = 'installing Python with WinGet'
        Run-Checked $winget.Source @('install','--id','Python.Python.3.12','--exact','--scope','user','--architecture','x64')
        $python = Find-WorkingPython
        if (!$python) { throw 'Python installation finished but no working interpreter was found. Restart Windows and rerun setup.' }
    }
    Write-Host "Using Python: $python"
    $environmentPython = Join-Path $environmentPath 'Scripts\python.exe'
    $healthy = $false
    if (Test-Path -LiteralPath $environmentPython) {
        try {
            & $environmentPython -c 'import sys,venv; sys.exit(0 if sys.version_info >= (3,10) and sys.maxsize > 2**32 else 1)' 2>$null
            $healthy = $LASTEXITCODE -eq 0
        } catch { }
    }
    if (!$healthy) {
        $step = 'rebuilding the contributor Python environment'
        New-Item -ItemType Directory -Force -Path $environmentRoot | Out-Null
        if (Test-Path -LiteralPath $environmentPath) {
            $resolvedEnvironment = [IO.Path]::GetFullPath($environmentPath)
            if ([IO.Path]::GetDirectoryName($resolvedEnvironment) -ne [IO.Path]::GetFullPath($environmentRoot)) { throw 'Unexpected environment path; refusing to move it.' }
            $backupName = 'worker-venv.broken-' + [Guid]::NewGuid().ToString('N')
            Rename-Item -LiteralPath $environmentPath -NewName $backupName
            Write-Host "Broken environment kept as $backupName. Pairing, models and retained conversions are preserved."
        }
        Run-Checked $python @('-m','venv',$environmentPath)
        Run-Checked $environmentPython @('-c',"import sys; print('Environment ready:',sys.executable)")
    }
    $step = 'checking pip'
    $hasPip = $false
    try { & $environmentPython -m pip --version 2>$null; $hasPip=$LASTEXITCODE -eq 0 } catch { }
    if (!$hasPip) { Run-Checked $environmentPython @('-m','ensurepip','--upgrade') }
    $step = 'checking contributor dependencies'
    $hasPackages = $false
    try { & $environmentPython -c 'import socketio,uv' 2>$null; $hasPackages=$LASTEXITCODE -eq 0 } catch { }
    if (!$hasPackages) {
        Write-Host "Dependencies are installed only in $environmentPath"
        if ((Read-Host 'Install the contributor packages now? [Y/N]') -notmatch '^(y|yes)$') { return }
        $step = 'installing contributor dependencies'
        Run-Checked $environmentPython @('-m','pip','install','python-socketio[client]>=5.11,<6','uv==0.13.6')
    }
    $step = 'starting the contributor'
    Write-Host "Python setup complete. Contributor diagnostics: $(Join-Path $env:USERPROFILE '.vaultcast\worker.log')"
    if ($transcribing) { Stop-Transcript | Out-Null; $transcribing=$false }
    & $environmentPython (Join-Path $PSScriptRoot 'worker.py') @WorkerArguments
    if ($LASTEXITCODE -ne 0) { throw "Contributor exited with code $LASTEXITCODE" }
} catch {
    Write-Host "`nContributor setup failed while $step." -ForegroundColor Red
    Write-Host $_.Exception.Message
    Write-Host "Setup log: $logPath"
    exit 1
} finally {
    if ($transcribing) { Stop-Transcript | Out-Null }
}
