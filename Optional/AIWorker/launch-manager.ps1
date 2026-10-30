$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
$form = New-Object Windows.Forms.Form
$form.Text = 'Vaultcast Worker Manager setup'
$form.Size = New-Object Drawing.Size(670,430)
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
$details = New-Object Windows.Forms.TextBox
$details.Multiline = $true
$details.ReadOnly = $true
$details.ScrollBars = 'Both'
$details.WordWrap = $false
$details.Location = New-Object Drawing.Point(20,140)
$details.Size = New-Object Drawing.Size(610,200)
$form.Controls.Add($details)
$openLog = New-Object Windows.Forms.Button
$openLog.Text = 'Open setup log'
$openLog.Location = New-Object Drawing.Point(20,350)
$openLog.Size = New-Object Drawing.Size(150,30)
$openLog.Add_Click({ Start-Process -FilePath 'notepad.exe' -ArgumentList @(('"' + $taskLog + '"')) })
$form.Controls.Add($openLog)
$script:setupJob = $null
$script:complete = $false
$form.Add_Shown({
    $script:setupJob = Start-Job -ArgumentList $PSScriptRoot,$taskRoot,$taskLog -ScriptBlock {
        param($source,$root,$log)
        $ErrorActionPreference='Stop'
        function Invoke-Python($exe,$arguments,$timeoutSeconds=30) {
            # Windows PowerShell 5.1 turns native stderr into ErrorRecords. Capture it
            # through Process instead so an expected missing-package probe can fail
            # normally and setup can install the missing dependencies.
            $quoted = foreach ($argument in $arguments) {
                $value = [string]$argument
                '"' + (($value -replace '(\\*)"','$1$1\"') -replace '(\\+)$','$1$1') + '"'
            }
            $info = New-Object Diagnostics.ProcessStartInfo
            $info.FileName = $exe
            $info.Arguments = $quoted -join ' '
            $info.UseShellExecute = $false
            $info.CreateNoWindow = $true
            $info.RedirectStandardOutput = $true
            $info.RedirectStandardError = $true
            $info.RedirectStandardInput = $true
            $info.EnvironmentVariables['PYTHONUNBUFFERED'] = '1'
            [IO.File]::AppendAllText($log,"`r`nRunning Python: " + ($arguments -join ' ') + "`r`n",[Text.Encoding]::UTF8)
            $process = New-Object Diagnostics.Process
            $process.StartInfo = $info
            try {
                [void]$process.Start()
                $process.StandardInput.Close()
                $stdout = $process.StandardOutput.ReadToEndAsync()
                $stderr = $process.StandardError.ReadToEndAsync()
                $timedOut = !$process.WaitForExit($timeoutSeconds * 1000)
                if ($timedOut) { $process.Kill() }
                $process.WaitForExit()
                [IO.File]::AppendAllText($log,$stdout.Result + $stderr.Result,[Text.Encoding]::UTF8)
                if ($timedOut) {
                    [IO.File]::AppendAllText($log,"Python operation timed out after $timeoutSeconds seconds.`r`n",[Text.Encoding]::UTF8)
                    return 124
                }
                return $process.ExitCode
            } finally { $process.Dispose() }
        }
        function Run-Checked($exe,$arguments,$timeoutSeconds=1800) {
            $code = Invoke-Python $exe $arguments $timeoutSeconds
            if ($code -ne 0) { throw "Setup failed (exit $code). Full details: $log" }
        }
        $environment = Join-Path $root 'worker-venv'
        $environmentPython = Join-Path $environment 'Scripts\python.exe'
        if (Test-Path -LiteralPath $environmentPython) {
            $code = Invoke-Python $environmentPython @('-I','-c','import sys,tkinter; sys.exit(0 if sys.version_info >= (3,10) and sys.maxsize > 2**32 else 1)')
            if ($code -ne 0) { throw "The existing worker Python environment could not start. Full details: $log. Your configuration and models have not been changed." }
        } else {
            $python = $null
            $candidates = @((Join-Path $env:LOCALAPPDATA 'Programs\Python\Python312\python.exe'),(Join-Path $env:LOCALAPPDATA 'Programs\Python\Python313\python.exe'))
            $command = Get-Command python.exe -ErrorAction SilentlyContinue
            if ($command -and $command.Source -notmatch 'WindowsApps') { $candidates += $command.Source }
            foreach ($candidate in $candidates) {
                if (Test-Path -LiteralPath $candidate) {
                    $code = Invoke-Python $candidate @('-I','-c','import sys,tkinter; sys.exit(0 if sys.version_info >= (3,10) and sys.maxsize > 2**32 else 1)')
                    if ($code -eq 0) { $python=$candidate; break }
                }
            }
            if (!$python) { throw "No usable 64-bit Python with Tcl/Tk was found. Install Python 3.12 or 3.13 from python.org. Probe details: $log" }
            Run-Checked $python @('-I','-m','venv',$environment) 120
        }
        $code = Invoke-Python $environmentPython @('-I','-c','import tkinter,socketio,uv,PIL,truststore')
        if ($code -ne 0) {
            $pipCode = Invoke-Python $environmentPython @('-I','-m','pip','--version')
            if ($pipCode -ne 0) { Run-Checked $environmentPython @('-I','-m','ensurepip','--upgrade') }
            Run-Checked $environmentPython @('-I','-m','pip','install','python-socketio[client]>=5.11,<6','uv==0.13.6','Pillow>=10,<13','truststore>=0.10,<1')
        }
        $code = Invoke-Python $environmentPython @('-I','-c','import tkinter,socketio,uv,PIL,truststore')
        if ($code -ne 0) { throw "Worker dependencies still could not load after installation. Full details: $log" }
        $windowPython = Join-Path $environment 'Scripts\pythonw.exe'
        Start-Process -FilePath $windowPython -ArgumentList @(('"' + (Join-Path $source 'worker_manager.py') + '"')) -WorkingDirectory $source -WindowStyle Hidden
        return 'ready'
    }
})
$timer = New-Object Windows.Forms.Timer
$timer.Interval = 300
$timer.Add_Tick({
    if ($script:setupJob -and $script:setupJob.State -eq 'Running') {
        try {
            $text = [IO.File]::ReadAllText($taskLog)
            if ($details.Text -ne $text) {
                $details.Text=$text
                $details.SelectionStart=$details.TextLength
                $details.ScrollToCaret()
            }
        } catch { } # The writer can briefly hold the log while appending.
    }
    if ($script:setupJob -and $script:setupJob.State -in @('Completed','Failed','Stopped')) {
        $timer.Stop()
        try {
            Receive-Job $script:setupJob -ErrorAction Stop | Out-Null
            if ($script:setupJob.State -ne 'Completed') { throw "Setup stopped. Open $taskLog for details." }
            $script:complete=$true
            $form.Close()
        } catch {
            $bar.Style='Blocks'
            $label.Text="Setup could not finish. See the complete error below or open the setup log."
            $failure = ($_ | Out-String)
            [IO.File]::AppendAllText($taskLog,"`r`n" + $failure,[Text.Encoding]::UTF8)
            $details.Text=[IO.File]::ReadAllText($taskLog)
            $details.SelectionStart=$details.TextLength
            $details.ScrollToCaret()
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
