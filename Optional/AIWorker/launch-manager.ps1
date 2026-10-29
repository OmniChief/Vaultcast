$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
$form = New-Object Windows.Forms.Form
$form.Text = 'Vaultcast Worker Manager setup'
$form.Size = New-Object Drawing.Size(570,220)
$form.StartPosition = 'CenterScreen'
$label = New-Object Windows.Forms.Label
$label.Text = 'Preparing the worker manager. Python and packages stay in your user account.'
$label.Location = New-Object Drawing.Point(20,20)
$label.Size = New-Object Drawing.Size(510,65)
$form.Controls.Add($label)
$bar = New-Object Windows.Forms.ProgressBar
$bar.Style = 'Marquee'
$bar.Location = New-Object Drawing.Point(20,95)
$bar.Size = New-Object Drawing.Size(510,22)
$form.Controls.Add($bar)
$taskRoot = Join-Path $env:USERPROFILE '.vaultcast'
New-Item -ItemType Directory -Force -Path $taskRoot | Out-Null
$taskLog = Join-Path $taskRoot 'manager-setup.log'
$script:setupJob = $null
$script:complete = $false
$form.Add_Shown({
    $script:setupJob = Start-Job -ArgumentList $PSScriptRoot,$taskRoot,$taskLog -ScriptBlock {
        param($source,$root,$log)
        $ErrorActionPreference='Stop'
        function Run-Checked($exe,$arguments) {
            & $exe @arguments >> $log 2>&1
            if ($LASTEXITCODE -ne 0) { throw "Setup failed. Details: $log" }
        }
        $python = $null
        $candidates = @((Join-Path $env:LOCALAPPDATA 'Programs\Python\Python312\python.exe'),(Join-Path $env:LOCALAPPDATA 'Programs\Python\Python313\python.exe'))
        $command = Get-Command python.exe -ErrorAction SilentlyContinue
        if ($command -and $command.Source -notmatch 'WindowsApps') { $candidates += $command.Source }
        foreach ($candidate in $candidates) {
            if (Test-Path -LiteralPath $candidate) {
                & $candidate -c 'import sys,tkinter; sys.exit(0 if sys.version_info >= (3,10) and sys.maxsize > 2**32 else 1)' >> $log 2>&1
                if ($LASTEXITCODE -eq 0) { $python=$candidate; break }
            }
        }
        if (!$python) { throw 'Install 64-bit Python 3.12 or 3.13 from python.org with Tcl/Tk enabled, then open start-worker.cmd again. https://www.python.org/downloads/windows/' }
        $environment = Join-Path $root 'worker-venv'
        $environmentPython = Join-Path $environment 'Scripts\python.exe'
        if (!(Test-Path -LiteralPath $environmentPython)) { Run-Checked $python @('-m','venv',$environment) }
        & $environmentPython -c 'import tkinter,socketio,uv,PIL,truststore' >> $log 2>&1
        if ($LASTEXITCODE -ne 0) {
            Run-Checked $environmentPython @('-m','pip','install','python-socketio[client]>=5.11,<6','uv==0.13.6','Pillow>=10,<13','truststore>=0.10,<1')
        }
        $windowPython = Join-Path $environment 'Scripts\pythonw.exe'
        Start-Process -FilePath $windowPython -ArgumentList @(('"' + (Join-Path $source 'worker_manager.py') + '"')) -WorkingDirectory $source -WindowStyle Hidden
        return 'ready'
    }
})
$timer = New-Object Windows.Forms.Timer
$timer.Interval = 300
$timer.Add_Tick({
    if ($script:setupJob -and $script:setupJob.State -in @('Completed','Failed','Stopped')) {
        $timer.Stop()
        try {
            Receive-Job $script:setupJob -ErrorAction Stop | Out-Null
            if ($script:setupJob.State -ne 'Completed') { throw "Setup stopped. Open $taskLog for details." }
            $script:complete=$true
            $form.Close()
        } catch {
            $bar.Style='Blocks'
            $label.Text=$_.Exception.Message
            [Windows.Forms.MessageBox]::Show($label.Text,'Vaultcast setup') | Out-Null
            $script:complete=$true
        } finally { Remove-Job $script:setupJob -Force; $script:setupJob=$null }
    }
})
$form.Add_FormClosing({param($sender,$event)
    if (!$script:complete -and $script:setupJob) {
        $event.Cancel=$true
        $label.Text='Setup is still preparing Python packages. Please wait; this window closes when the manager is ready.'
    }
})
$timer.Start()
[void]$form.ShowDialog()
$timer.Dispose()
$form.Dispose()
