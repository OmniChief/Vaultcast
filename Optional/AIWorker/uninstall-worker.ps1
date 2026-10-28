param([switch]$Preview,[switch]$RemoveEngine)
$ErrorActionPreference='Stop'

function Full-Path([string]$Path) { return [IO.Path]::GetFullPath($Path).TrimEnd('\') }
function Within([string]$Path,[string]$Root) {
    $candidate=Full-Path $Path; $parent=Full-Path $Root
    return $candidate.Equals($parent,[StringComparison]::OrdinalIgnoreCase) -or $candidate.StartsWith($parent+'\',[StringComparison]::OrdinalIgnoreCase)
}
function Remove-OwnedTree([string]$Path,[string]$Root) {
    $full=Full-Path $Path
    if (!(Within $full $Root)) { throw "Cleanup path escaped its recorded root: $full" }
    $item=Get-Item -LiteralPath $full -Force -ErrorAction SilentlyContinue
    if (!$item) { return }
    # Never traverse a junction or symlink into another install or user's files.
    if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) {
        if ($item.PSIsContainer) { [IO.Directory]::Delete($full) } else { [IO.File]::Delete($full) }
        return
    }
    if ($item.PSIsContainer) {
        foreach ($child in Get-ChildItem -LiteralPath $full -Force) { Remove-OwnedTree $child.FullName $Root }
        [IO.Directory]::Delete($full)
    } else {
        [IO.File]::SetAttributes($full,[IO.FileAttributes]::Normal)
        [IO.File]::Delete($full)
    }
}

try {
    $localRoots=@((Join-Path $env:LOCALAPPDATA 'VaultcastAIWorker'))
    $roamingRoots=@((Join-Path $env:APPDATA 'VaultcastAIWorker'))
    $packages=Join-Path $env:LOCALAPPDATA 'Packages'
    if (Test-Path -LiteralPath $packages) {
        foreach ($package in Get-ChildItem -LiteralPath $packages -Directory -Filter 'PythonSoftwareFoundation.Python.*') {
            $localRoots+=Join-Path $package.FullName 'LocalCache\Local\VaultcastAIWorker'
            $roamingRoots+=Join-Path $package.FullName 'LocalCache\Roaming\VaultcastAIWorker'
        }
    }
    $nativeRoot=Join-Path $env:USERPROFILE '.vaultcast'
    $roots=@($localRoots+$roamingRoots+@($nativeRoot) | Select-Object -Unique)
    $engines=@()
    foreach ($root in $localRoots) {
        $state=Join-Path $root 'engine.json'
        if (Test-Path -LiteralPath $state) {
            try {
                $record=Get-Content -LiteralPath $state -Raw | ConvertFrom-Json
                if ($record.root) { $engines+=Full-Path ([string]$record.root) }
                foreach ($owned in $record.owned_roots) { if ($owned) { $engines+=Full-Path ([string]$owned) } }
            } catch { Write-Host "Could not read $state; unidentified custom engine files will be kept." }
        }
        $default=Join-Path $root 'InvokeAI'
        if (Test-Path -LiteralPath (Join-Path $default '.vaultcast-managed')) { $engines+=Full-Path $default }
    }
    $engines=@($engines | Select-Object -Unique | Where-Object { Test-Path -LiteralPath (Join-Path $_ '.vaultcast-managed') })
    $protected=@($env:USERPROFILE,$env:SystemRoot,[IO.Path]::GetPathRoot($env:USERPROFILE),$env:LOCALAPPDATA,$env:APPDATA,(Join-Path $env:USERPROFILE 'Downloads'),(Join-Path $env:USERPROFILE 'Documents'),(Join-Path $env:USERPROFILE 'Desktop'))
    $protected+=@([Environment]::GetFolderPath('MyDocuments'),[Environment]::GetFolderPath('Desktop'))
    foreach ($engine in $engines) {
        foreach ($path in $protected+$roots) { if ($path -and (Full-Path $path) -eq $engine) { throw "Refusing broad engine root: $engine" } }
        if ((Get-Item -LiteralPath $engine -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw "Engine root is a link; remove it manually: $engine" }
    }
    if ($engines.Count) {
        Write-Host 'Dedicated contributor InvokeAI installations:'
        $engines | ForEach-Object { Write-Host "  $_" }
        if (!$Preview -and !$RemoveEngine) { $RemoveEngine=(Read-Host 'Also remove these dedicated engines, their models and generated files? [y/N]') -match '^(y|yes)$' }
    }
    $targets=@()
    $names=@('worker-venv','ffmpeg','tmp','downloads','conversions','worker.log','setup.log','invokeai.log','worker.json','managed-engine-process.json')
    foreach ($root in $roots) {
        foreach ($name in $names) {
            $path=Join-Path $root $name
            if (Test-Path -LiteralPath $path) { $targets+=Full-Path $path }
        }
        if (Test-Path -LiteralPath $root) {
            foreach ($backup in Get-ChildItem -LiteralPath $root -Directory -Filter 'worker-venv.broken-*') {
                if ($backup.Name -match '^worker-venv\.broken-[a-f0-9]{32}$') { $targets+=$backup.FullName }
            }
        }
        if ($RemoveEngine) {
            $state=Join-Path $root 'engine.json'
            if (Test-Path -LiteralPath $state) { $targets+=Full-Path $state }
        }
    }
    if ($RemoveEngine) { $targets+=$engines }
    $targets=@($targets | Select-Object -Unique)
    foreach ($engine in $engines) {
        foreach ($target in $targets) {
            if ($target -ne $engine -and (Within $engine $target)) { throw "Cleanup target contains a protected engine: $target" }
        }
    }
    Write-Host 'Worker cleanup will remove:'
    $targets | ForEach-Object { Write-Host "  $_" }
    if (!$RemoveEngine -and $engines.Count) { Write-Host 'Dedicated engine files and their discovery records will be kept.' }
    Write-Host 'Downloaded sources and unconfirmed conversion copies in these folders will be removed.'
    Write-Host 'Shared Python, the InvokeAI Launcher, and separately installed InvokeAI are kept.'
    if ($Preview) { Write-Host 'Preview only. No processes stopped, credentials revoked, or files removed.'; return }
    if ((Read-Host 'Type UNINSTALL to stop this contributor and remove the listed files') -cne 'UNINSTALL') { Write-Host 'Uninstall canceled.'; return }

    # Match this contributor's command line/environment or the marked dedicated engine, never all Python.
    $processes=@(Get-CimInstance Win32_Process)
    $ownScript=Full-Path (Join-Path $PSScriptRoot 'worker.py')
    $processRoots=@($engines+@($targets | Where-Object { [IO.Path]::GetFileName($_) -eq 'worker-venv' }))
    $sharedIds=@()
    foreach ($process in $processes) {
        $command=[string]$process.CommandLine
        if ($command -notmatch 'invokeai-web') { continue }
        $managed=$false
        foreach ($engine in $engines) {
            if (($process.ExecutablePath -and (Within ([string]$process.ExecutablePath) $engine)) -or $command.IndexOf($engine+'\',[StringComparison]::OrdinalIgnoreCase) -ge 0 -or $command.IndexOf($engine+'/',[StringComparison]::OrdinalIgnoreCase) -ge 0) { $managed=$true }
        }
        if (!$managed) { $sharedIds+=[int]$process.ProcessId }
    }
    do {
        $before=$sharedIds.Count
        foreach ($process in $processes) { if ($sharedIds -contains [int]$process.ParentProcessId -and $sharedIds -notcontains [int]$process.ProcessId) { $sharedIds+=[int]$process.ProcessId } }
    } while ($sharedIds.Count -gt $before)
    $ids=@()
    foreach ($process in $processes) {
        if ($process.ProcessId -eq $PID -or $sharedIds -contains [int]$process.ProcessId) { continue }
        $command=[string]$process.CommandLine
        $matched=$command.IndexOf($ownScript,[StringComparison]::OrdinalIgnoreCase) -ge 0
        foreach ($root in $processRoots) {
            if ($process.ExecutablePath -and (Within ([string]$process.ExecutablePath) $root)) { $matched=$true }
            if ($command.IndexOf($root+'\',[StringComparison]::OrdinalIgnoreCase) -ge 0 -or $command.IndexOf($root+'/',[StringComparison]::OrdinalIgnoreCase) -ge 0) { $matched=$true }
        }
        if ($matched) { $ids+=[int]$process.ProcessId }
    }
    do {
        $before=$ids.Count
        foreach ($process in $processes) { if ($process.ProcessId -ne $PID -and $ids -contains [int]$process.ParentProcessId -and $ids -notcontains [int]$process.ProcessId -and $sharedIds -notcontains [int]$process.ProcessId) { $ids+=[int]$process.ProcessId } }
    } while ($ids.Count -gt $before)
    foreach ($processId in ($ids | Sort-Object -Descending -Unique)) {
        Write-Host "Stopping contributor process $processId"
        Stop-Process -Id $processId -Force -ErrorAction SilentlyContinue
    }
    foreach ($root in $roamingRoots) {
        $file=Join-Path $root 'worker.json'
        if (!(Test-Path -LiteralPath $file)) { continue }
        try {
            $config=Get-Content -LiteralPath $file -Raw | ConvertFrom-Json
            if ($config.server -and $config.worker_token) {
                Invoke-RestMethod -Uri ($config.server.TrimEnd('/')+'/api/ai/worker/unregister') -Method Post -Headers @{'X-Vaultcast-Worker'=[string]$config.worker_token} -ContentType 'application/json' -Body '{}' -TimeoutSec 5 | Out-Null
                Write-Host 'Contributor unregistered from Vaultcast.'
            }
        } catch { Write-Host 'Server unregister unavailable; local cleanup will continue.' }
    }
    $failures=@()
    foreach ($target in $targets) {
        try { Write-Host "Removing $target"; Remove-OwnedTree $target $target }
        catch { $failures+="$target : $($_.Exception.Message)" }
    }
    foreach ($root in $roots) {
        if ((Test-Path -LiteralPath $root) -and !(Get-ChildItem -LiteralPath $root -Force | Select-Object -First 1)) { [IO.Directory]::Delete((Full-Path $root)) }
    }
    if ($failures.Count) { $failures | ForEach-Object { Write-Host $_ }; throw 'Some files could not be removed. Close contributor windows and retry uninstall.' }
    Write-Host 'Worker uninstall complete. You can now delete the extracted worker software folder.'
} catch {
    Write-Host "Uninstall failed: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
