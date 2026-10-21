param(
    [switch]$Elevated, [switch]$Unattended,
    [ValidateSet('auto','x64')][string]$Architecture='auto',
    [string]$InstallDirectory='', [int]$LibraryPort=9999,
    [int]$FastCgiPort=9000, [int]$RelayPort=3100,
    [switch]$ExternalDatabase,
    [string]$BundleDirectory='',
    [ValidateSet('','PHP','Node','FFmpeg','nginx','MariaDB','Transmission')][string]$Dependency=''
)
trap {
    Write-Host ("Setup stopped: " + $_.Exception.Message) -ForegroundColor Red
    if ($Elevated -and !$Unattended) { Read-Host 'Press Enter to close setup' | Out-Null }
    exit 1
}
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
$sourceRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../Application'))
$administrator = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (!$administrator) {
    if ($Elevated) { throw 'Administrator permission is required. Setup cannot continue without it.' }
    $elevationArgs = @('-NoProfile','-ExecutionPolicy','Bypass','-File',('"'+$PSCommandPath+'"'),'-Elevated','-Architecture',$Architecture)
    if ($Unattended) { $elevationArgs += '-Unattended' }
    if ($ExternalDatabase) { $elevationArgs += '-ExternalDatabase' }
    if ($BundleDirectory) { $elevationArgs += @('-BundleDirectory',('"'+$BundleDirectory+'"')) }
    if ($Dependency) { $elevationArgs += @('-Dependency',$Dependency) }
    if ($InstallDirectory) { $elevationArgs += @('-InstallDirectory',('"'+$InstallDirectory+'"')) }
    $elevationArgs += @('-LibraryPort',"$LibraryPort",'-FastCgiPort',"$FastCgiPort",'-RelayPort',"$RelayPort")
    $process = Start-Process powershell.exe -Verb RunAs -ArgumentList $elevationArgs -Wait -PassThru
    exit $process.ExitCode
}
function Invoke-Native([string]$File,[string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        & $File @Arguments 2>&1 | ForEach-Object { Write-Host $_ }
        return $LASTEXITCODE
    } finally { $ErrorActionPreference = $previous }
}
function Confirm-Setup([string]$Message) {
    if ($basic) { return $true }
    return (Read-Host "$Message [y/N]") -match '^(y|yes)$'
}
function Show-SetupStage([int]$Number,[string]$Label) {
    Write-Host "`n[$Number/8] $Label" -ForegroundColor Cyan
}
function Save-Text([string]$Path,[string]$Text) { [IO.File]::WriteAllText($Path,$Text,[Text.UTF8Encoding]::new($false)) }
function Random-Secret {
    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [BitConverter]::ToString($bytes).Replace('-','').ToLowerInvariant()
}
function Port-Free([int]$Port) {
    if ($reservedPorts -contains $Port) { return $false }
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Any,$Port)
    try { $listener.Start(); return $true } catch { return $false } finally { $listener.Stop() }
}
function Choose-Port([string]$Label,[int]$Default,[switch]$Public) {
    $port = $Default
    if (!$basic) {
        $answer = Read-Host "$Label [$Default]"
        if ($answer) {
            if ($answer -notmatch '^\d{1,5}$') { throw 'Enter a port number between 1024 and 65535.' }
            $port = [int]$answer
        }
    }
    if ($port -lt 1024 -or $port -gt 65535) { throw 'Choose a port between 1024 and 65535.' }
    if ($basic -and !$Public) { while ($port -le 65535 -and !(Port-Free $port)) { $port++ } }
    if ($port -gt 65535 -or !(Port-Free $port)) { throw "$Label $port is already in use. Close the conflicting application or choose another port in Advanced setup." }
    $script:reservedPorts += $port
    return $port
}
function Set-InstallPermissions {
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true,$false)
    foreach ($entry in @(@('S-1-5-18','FullControl'),@('S-1-5-32-544','FullControl'),@('S-1-5-19','ReadAndExecute'))) {
        $sid = [Security.Principal.SecurityIdentifier]::new($entry[0])
        $rule = [Security.AccessControl.FileSystemAccessRule]::new($sid,$entry[1],'ContainerInherit,ObjectInherit','None','Allow')
        $acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $installRoot -AclObject $acl
    $dataAcl = Get-Acl -LiteralPath (Join-Path $installRoot 'data')
    $sid = [Security.Principal.SecurityIdentifier]::new('S-1-5-19')
    $dataAcl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($sid,'Modify','ContainerInherit,ObjectInherit','None','Allow'))
    Set-Acl -LiteralPath (Join-Path $installRoot 'data') -AclObject $dataAcl
    # The web process may write application data, but not executable runtimes.
    Set-Acl -LiteralPath $runtime -AclObject $acl
    Grant-ServiceWrite (Join-Path $runtime 'logs')
}
function Grant-ServiceWrite([string]$Folder) {
    New-Item -ItemType Directory -Path $Folder -Force | Out-Null
    $acl = Get-Acl -LiteralPath $Folder
    $sid = [Security.Principal.SecurityIdentifier]::new('S-1-5-19')
    $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($sid,'Modify','ContainerInherit,ObjectInherit','None','Allow'))
    Set-Acl -LiteralPath $Folder -AclObject $acl
}
function Xml([string]$Value) { return [Security.SecurityElement]::Escape($Value) }
function Wait-Listening([string]$Address,[int]$Port) {
    for ($attempt=0; $attempt -lt 40; $attempt++) {
        $client = New-Object Net.Sockets.TcpClient
        try {
            $connection = $client.ConnectAsync($Address,$Port)
            if ($connection.Wait(500) -and $client.Connected) { return }
        } catch {} finally { $client.Dispose() }
        Start-Sleep -Milliseconds 250
    }
    throw "A Vaultcast service did not start on port $Port. Details are in $runtime\logs. Rerun setup to resume."
}
function Install-AppService([string]$Id,[string]$Label,[string]$Executable,[string]$Arguments,[string]$Directory,[hashtable]$Environment=@{},[string[]]$Depends=@(),[string]$StopArguments='') {
    Write-Host "Registering and starting $Label..."
    $wrapper = Join-Path $services "$Id.exe"
    if (!(Test-Path -LiteralPath $wrapper)) { Copy-Item -LiteralPath $serviceWrapper -Destination $wrapper }
    $extra = ''
    foreach ($key in $Environment.Keys) { $extra += '<env name="'+(Xml $key)+'" value="'+(Xml $Environment[$key])+'"/>' }
    foreach ($dependency in $Depends) { $extra += '<depend>'+(Xml $dependency)+'</depend>' }
    $argumentsTag = if ($StopArguments) { 'startarguments' } else { 'arguments' }
    if ($StopArguments) { $extra += '<stoparguments>'+(Xml $StopArguments)+'</stoparguments>' }
    $accountXml = if ($Id -match '^VaultcastPHP(?:[2-9]|1[0-6])?$') { '<serviceaccount><user>LocalSystem</user></serviceaccount>' } else { '<serviceaccount><domain>NT AUTHORITY</domain><user>LocalService</user></serviceaccount>' }
    $xml = '<service><id>'+ $Id +'</id><name>'+ (Xml $Label) +'</name><description>Vaultcast native application service</description><executable>'+ (Xml $Executable) +'</executable><'+$argumentsTag+'>'+ (Xml $Arguments) +'</'+$argumentsTag+'><workingdirectory>'+ (Xml $Directory) +'</workingdirectory><startmode>Automatic</startmode><onfailure action="restart" delay="5 sec"/><stoptimeout>30 sec</stoptimeout><logpath>'+ (Xml (Join-Path $runtime 'logs')) +'</logpath><log mode="roll"/>'+ $accountXml + $extra +'</service>'
    Save-Text (Join-Path $services "$Id.xml") $xml
    $operation = if (Get-Service -Name $Id -ErrorAction SilentlyContinue) { 'refresh' } else { 'install' }
    if ((Invoke-Native $wrapper @($operation)) -ne 0) { throw "Could not register/update the $Label service." }
    # Explicitly migrate the account when updating an existing installation.
    if ($Id -match '^VaultcastPHP(?:[2-9]|1[0-6])?$') {
        if ((Invoke-Native (Join-Path $env:SystemRoot 'System32/sc.exe') @('config',$Id,'obj=','LocalSystem')) -ne 0) { throw 'Could not enable administrator access for the PHP service.' }
    }
    Start-Service -Name $Id
    Write-Host "$Label started."
}
function Get-BundledFile([string]$Url) {
    if (!$BundleDirectory) { return $null }
    if (!$script:bundleManifest) { $script:bundleManifest = Get-Content -LiteralPath (Join-Path $BundleDirectory 'manifest.json') -Raw | ConvertFrom-Json }
    $entry = $script:bundleManifest.PSObject.Properties[$Url].Value
    if (!$entry -or $entry.file -notmatch '^[a-f0-9]{64}\.bin$') { throw "The offline bundle is missing $Url. Rebuild the full installer." }
    $file = Join-Path $BundleDirectory $entry.file
    if (!(Test-Path -LiteralPath $file) -or (Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash -ne $entry.sha256) { throw 'An offline package failed checksum verification.' }
    return $file
}
function Get-ReleaseInfo([string]$Url) {
    if ($BundleDirectory) {
        $text = [IO.File]::ReadAllText((Get-BundledFile $Url))
        try { return ConvertFrom-Json -InputObject $text -ErrorAction Stop } catch { return $text }
    }
    for ($attempt = 1; $attempt -le 3; $attempt++) {
        try { return Invoke-RestMethod -Uri $Url -TimeoutSec 60 }
        catch { if ($attempt -eq 3) { throw "Could not read $Url. Check the internet connection and rerun setup." }; Start-Sleep -Seconds 2 }
    }
}
function Get-Download([string]$Url,[string]$Destination,[string]$Sha256 = '') {
    if ($BundleDirectory) {
        $file = Get-BundledFile $Url
        if ($Sha256 -and (Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash -ne $Sha256) { throw 'Bundled package does not match its publisher checksum.' }
        Write-Host "Preparing bundled $([IO.Path]::GetFileName($Destination))..."
        Copy-Item -LiteralPath $file -Destination $Destination -Force
        return
    }
    if ($Sha256 -and $Sha256 -notmatch '^[a-fA-F0-9]{64}$') { throw 'The publisher did not supply a valid SHA-256 checksum.' }
    if ($Sha256 -and (Test-Path -LiteralPath $Destination) -and (Get-FileHash -LiteralPath $Destination -Algorithm SHA256).Hash -eq $Sha256) {
        Write-Host "Using verified download: $([IO.Path]::GetFileName($Destination))"
        return
    }
    for ($attempt = 1; $attempt -le 3; $attempt++) {
        try {
            Write-Host "Downloading $([IO.Path]::GetFileName($Destination)) (attempt $attempt of 3)..."
            $previousProgress = $ProgressPreference
            try {
                $ProgressPreference = 'Continue'
                Invoke-WebRequest -Uri $Url -OutFile "$Destination.part" -UseBasicParsing -TimeoutSec 600
            } finally { $ProgressPreference = $previousProgress }
            Write-Host 'Download complete.'
            if ($Sha256) { Write-Host 'Verifying package checksum...' }
            if ($Sha256 -and (Get-FileHash -LiteralPath "$Destination.part" -Algorithm SHA256).Hash -ne $Sha256) { throw 'Download checksum mismatch.' }
            Move-Item -LiteralPath "$Destination.part" -Destination $Destination -Force
            return
        } catch {
            if (Test-Path -LiteralPath "$Destination.part") { Remove-Item -LiteralPath "$Destination.part" }
            if ($attempt -eq 3) { throw "Download failed: $Url. $($_.Exception.Message) Rerun setup to resume." }
            Write-Host 'Download interrupted or verification failed. Retrying in 2 seconds...' -ForegroundColor Yellow
            Start-Sleep -Seconds 2
        }
    }
}
function Find-Program([string]$Name) {
    $found = Get-Command $Name -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if (!$found) { return $null }
    $file = Get-Item -LiteralPath $found.Source
    if ($file.Target) { return [string](@($file.Target)[0]) }
    return $file.FullName
}
function Expand-Package([string]$Archive,[string]$Destination) {
    $label = 'Extracting ' + [IO.Path]::GetFileName($Archive)
    Write-Host "$label..."
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $root = [IO.Path]::GetFullPath($Destination).TrimEnd('\') + '\'
    [IO.Directory]::CreateDirectory($root) | Out-Null
    $zip = [IO.Compression.ZipFile]::OpenRead($Archive)
    $previousProgress = $ProgressPreference
    $ProgressPreference = 'Continue'
    $extracted = 0
    $clock = [Diagnostics.Stopwatch]::StartNew()
    $lastUpdate = -250
    try {
        foreach ($entry in $zip.Entries) {
            if ($clock.ElapsedMilliseconds - $lastUpdate -ge 250) {
                Write-Progress -Id 1 -Activity $label -Status "$extracted of $($zip.Entries.Count) entries extracted" -PercentComplete ([int](100 * $extracted / [Math]::Max(1,$zip.Entries.Count)))
                $lastUpdate = $clock.ElapsedMilliseconds
            }
            $target = [IO.Path]::GetFullPath((Join-Path $root $entry.FullName))
            if (!$target.StartsWith($root,[StringComparison]::OrdinalIgnoreCase)) { throw 'The downloaded archive contains an invalid path.' }
            if (!$entry.Name) { [IO.Directory]::CreateDirectory($target) | Out-Null; $extracted++; continue }
            [IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($target)) | Out-Null
            [IO.Compression.ZipFileExtensions]::ExtractToFile($entry,$target,$true)
            $extracted++
        }
        Write-Host "$label complete ($extracted entries)."
    } finally {
        $zip.Dispose()
        Write-Progress -Id 1 -Activity $label -Completed
        $ProgressPreference = $previousProgress
    }
}
function Install-VCRuntime {
    $vc = Get-ItemProperty ('HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\'+$Architecture) -ErrorAction SilentlyContinue
    if ($vc -and $vc.Installed -eq 1 -and [version]($vc.Version -replace '^v','') -ge [version]'14.38') { return }
    if (!(Confirm-Setup 'PHP needs the Microsoft Visual C++ runtime. Download and install it from Microsoft?')) { throw 'The Visual C++ runtime is required to start PHP.' }
    $file = Join-Path $runtime ('vc_redist.'+$Architecture+'.exe')
    Get-Download ('https://aka.ms/vs/17/release/vc_redist.'+$Architecture+'.exe') $file
    $signature = Get-AuthenticodeSignature -LiteralPath $file
    if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch 'O=Microsoft Corporation[, ]') { throw 'The Microsoft runtime signature could not be verified.' }
    Write-Host 'Installing Microsoft Visual C++ runtime...'
    $process = Start-Process -FilePath $file -ArgumentList '/install /quiet /norestart' -WindowStyle Hidden -PassThru
    $elapsed = [Diagnostics.Stopwatch]::StartNew()
    $previousProgress = $ProgressPreference
    try {
        $ProgressPreference = 'Continue'
        while (!$process.WaitForExit(1000)) {
            Write-Progress -Id 1 -Activity 'Installing Microsoft Visual C++ runtime' -Status ("Elapsed: {0:mm\:ss}; waiting for the Microsoft installer" -f $elapsed.Elapsed) -PercentComplete -1
        }
        $process.WaitForExit()
    } finally {
        Write-Progress -Id 1 -Activity 'Installing Microsoft Visual C++ runtime' -Completed
        $ProgressPreference = $previousProgress
    }
    if ($process.ExitCode -notin @(0,3010,1638)) { throw "Visual C++ runtime installation failed (code $($process.ExitCode))." }
    if ($process.ExitCode -eq 3010) { Write-Host 'Windows reports that a restart is required for the runtime.' }
}
function Get-PHP {
    $local = Join-Path $runtime ('php-'+$Architecture+'/php.exe')
    $candidate = if (Test-Path -LiteralPath $local) { $local } else { Find-Program 'php.exe' }
    if ($candidate -and (Test-Path -LiteralPath (Join-Path (Split-Path $candidate -Parent) 'php-cgi.exe'))) {
        try {
            $version = & $candidate -n -r 'if (PHP_INT_SIZE !== 8) exit(1); echo PHP_VERSION;'
            if ($LASTEXITCODE -eq 0 -and [version]$version -ge [version]'7.4') { return $candidate }
        } catch { Write-Host 'The existing PHP installation could not run. Setup can download a separate copy.' }
    }
    if (!(Confirm-Setup 'Download PHP for this application from php.net?')) { throw 'PHP is required.' }
    Install-VCRuntime
    $releases = Get-ReleaseInfo 'https://downloads.php.net/~windows/releases/releases.json'
    $package = $releases.'8.3'.('nts-vs16-'+$Architecture).zip
    if (!$package -or $package.path -notmatch ('^php-[0-9.]+-nts-Win32-vs16-'+$Architecture+'\.zip$')) { throw 'The PHP download catalogue did not contain the expected Windows package.' }
    $zip = Join-Path $runtime $package.path
    Get-Download ('https://downloads.php.net/~windows/releases/' + $package.path) $zip $package.sha256
    Expand-Package $zip (Join-Path $runtime ('php-'+$Architecture))
    & $local -n -r 'exit(PHP_VERSION_ID < 70400 ? 1 : 0);'
    if ($LASTEXITCODE -ne 0) { throw 'PHP could not start. Restart Windows if the runtime installer requested it, then rerun setup.' }
    return $local
}
function Get-Node {
    $local = Get-ChildItem -Path "$runtime/node-v*-win-$Architecture/node.exe" -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName
    $candidate = if ($local) { $local } else { Find-Program 'node.exe' }
    if ($candidate -and (Test-Path -LiteralPath (Join-Path (Split-Path $candidate -Parent) 'npm.cmd'))) {
        try {
            $major = & $candidate -p 'parseInt(process.versions.node)'
            if ($LASTEXITCODE -eq 0 -and [int]$major -ge 18) { return $candidate }
        } catch { Write-Host 'The existing Node.js installation could not run. Setup can download a separate copy.' }
    }
    if (!(Confirm-Setup 'Download Node.js LTS and npm for multiplayer from nodejs.org?')) { throw 'Node.js and npm are required by the multiplayer relay.' }
    $catalogue = Get-ReleaseInfo 'https://nodejs.org/dist/index.json'
    $release = $catalogue | Where-Object { $_.lts -and $_.files -contains ('win-'+$Architecture+'-zip') } | Select-Object -First 1
    if (!$release -or $release.version -isnot [string] -or $release.version -notmatch '^v\d+\.\d+\.\d+$') { throw 'No Windows LTS release was found in the Node.js catalogue.' }
    $name = "node-$($release.version)-win-$Architecture.zip"
    $base = "https://nodejs.org/dist/$($release.version)/"
    $sums = [string](Get-ReleaseInfo ($base + 'SHASUMS256.txt'))
    $match = [regex]::Match($sums, '(?m)^([a-fA-F0-9]{64})\s+' + [regex]::Escape($name) + '\s*$')
    if (!$match.Success) { throw 'Node.js checksum was not found.' }
    Get-Download ($base + $name) (Join-Path $runtime $name) $match.Groups[1].Value
    Expand-Package (Join-Path $runtime $name) $runtime
    return Join-Path $runtime "node-$($release.version)-win-$Architecture/node.exe"
}
function Get-MariaDBBin {
    $bin = Get-ChildItem -Path "$runtime/mariadb-*-winx64/bin/mariadbd.exe" -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty DirectoryName
    if (!$bin) {
        $existing = Find-Program 'mariadbd.exe'
        if ($existing) {
            $candidate = Split-Path $existing -Parent
            if ((Test-Path -LiteralPath (Join-Path $candidate 'mariadb-install-db.exe')) -or (Test-Path -LiteralPath (Join-Path $candidate 'mysql_install_db.exe'))) {
                Write-Host 'Using the existing MariaDB binaries with a separate Vaultcast database directory.'
                return $candidate
            }
        }
    }
    if (!$bin) {
        $info = Get-ReleaseInfo 'https://downloads.mariadb.org/rest-api/mariadb/11.4/'
        $release = $info.releases.PSObject.Properties | Where-Object Name -match '^11\.4\.\d+$' | Sort-Object { [version]$_.Name } -Descending | Select-Object -First 1
        $package = $release.Value.files | Where-Object file_name -match '^mariadb-[0-9.]+-winx64\.zip$' | Select-Object -First 1
        if (!$package -or !$package.checksum.sha256sum) { throw 'The MariaDB catalogue did not supply the Windows ZIP checksum.' }
        $archive = Join-Path $runtime $package.file_name
        Get-Download ("https://downloads.mariadb.org/rest-api/mariadb/$($release.Name)/$($package.file_name)") $archive $package.checksum.sha256sum
        Expand-Package $archive $runtime
        $bin = Get-ChildItem -Path "$runtime/mariadb-*-winx64/bin/mariadbd.exe" | Select-Object -First 1 -ExpandProperty DirectoryName
    }
    return $bin
}
function Prepare-Database {
    $script:databaseDependency = @()
    if ((Test-Path -LiteralPath (Join-Path $installRoot 'config.php')) -or (Test-Path -LiteralPath (Join-Path $installRoot 'data/config.php'))) {
        if (Get-Service VaultcastDatabase -ErrorAction SilentlyContinue) {
            Start-Service VaultcastDatabase
            $script:databaseDependency = @('VaultcastDatabase')
            $managedIni = Join-Path $runtime 'database.ini'
            $managedSecret = Join-Path $runtime 'database-secret.txt'
            if (Test-Path -LiteralPath $managedIni) {
                & $phpExe -c $ini (Join-Path $PSScriptRoot 'NSIS/support/setup-helper.php') provision $installRoot $managedIni
                if ($LASTEXITCODE -ne 0) { throw 'The managed database permissions could not be repaired.' }
            } elseif (Test-Path -LiteralPath $managedSecret) {
                $secret = Get-Content -LiteralPath $managedSecret -Raw | ConvertTo-SecureString
                $managed = [Net.NetworkCredential]::new('', $secret).Password
                $provision = 'require $argv[1]; require $argv[2]; Vaultcast\Installer::prepareWindowsDatabase(json_decode(stream_get_contents(STDIN),true));'
                $managed | & $phpExe -c $ini -r $provision (Join-Path $installRoot 'src/Database.php') (Join-Path $installRoot 'src/Installer.php')
                if ($LASTEXITCODE -ne 0) { throw 'The managed database permissions could not be repaired.' }
            }
        }
        return
    }
    if ($ExternalDatabase -or (!$basic -and (Read-Host 'Database: 1 = automatic local database, 2 = enter an existing connection in the browser [1]') -eq '2')) {
        $pending = Join-Path $installRoot 'data/setup-database.php'
        if (Test-Path -LiteralPath $pending) { Remove-Item -LiteralPath $pending }
        if (Get-Service VaultcastDatabase -ErrorAction SilentlyContinue) { Set-Service VaultcastDatabase -StartupType Manual }
        return
    }
    $stateFile = Join-Path $runtime 'database-secret.txt'
    if (Test-Path -LiteralPath $stateFile) {
        $secure = Get-Content -LiteralPath $stateFile -Raw | ConvertTo-SecureString
        $plain = [Net.NetworkCredential]::new('', $secure).Password
        $db = $plain | ConvertFrom-Json
    } else {
        $db = [pscustomobject]@{port=(Choose-Port 'Private database port' 3306);rootPassword=(Random-Secret);user=('rk_'+(Random-Secret).Substring(0,12));password=(Random-Secret);name='vaultcast'}
        $encrypted = ConvertTo-SecureString ($db | ConvertTo-Json -Compress) -AsPlainText -Force | ConvertFrom-SecureString
        Save-Text $stateFile $encrypted
    }
    $bin = Get-MariaDBBin
    $databaseDir = Join-Path $installRoot 'data/database'
    if (!(Test-Path -LiteralPath (Join-Path $databaseDir 'mysql'))) {
        $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,[int]$db.port)
        try { $listener.Start() } catch { throw 'The saved database port is occupied by another application.' } finally { $listener.Stop() }
        $initializer = Join-Path $bin 'mariadb-install-db.exe'
        if (!(Test-Path -LiteralPath $initializer)) { $initializer = Join-Path $bin 'mysql_install_db.exe' }
        Write-Host 'Initializing MariaDB system tables. This can take a few minutes...'
        # Do not log the initialization command or its password.
        $logFile = Join-Path $runtime 'logs/mariadb-install.log'
        $process = New-Object Diagnostics.Process
        $process.StartInfo.FileName = $initializer
        $process.StartInfo.WorkingDirectory = $bin
        $process.StartInfo.UseShellExecute = $false
        $process.StartInfo.CreateNoWindow = $true
        $process.StartInfo.RedirectStandardOutput = $true
        $process.StartInfo.RedirectStandardError = $true
        $process.StartInfo.Arguments = '"--datadir='+$databaseDir+'" --password='+$db.rootPassword+' --port='+$db.port+' --verbose-bootstrap'
        $previousProgress = $ProgressPreference
        try {
            if (!$process.Start()) { throw 'Could not start the MariaDB initializer.' }
            # Drain both streams concurrently so a full pipe cannot block installation.
            $stdout = $process.StandardOutput.ReadToEndAsync()
            $stderr = $process.StandardError.ReadToEndAsync()
            $elapsed = [Diagnostics.Stopwatch]::StartNew()
            $ProgressPreference = 'Continue'
            while (!$process.WaitForExit(1000)) {
                Write-Progress -Id 1 -Activity 'Initializing MariaDB' -Status ("Elapsed: {0:mm\:ss}; creating system tables" -f $elapsed.Elapsed) -PercentComplete -1
            }
            $process.WaitForExit()
            $result = $process.ExitCode
            $details = $stdout.GetAwaiter().GetResult() + "`r`n" + $stderr.GetAwaiter().GetResult()
            foreach ($secret in @($db.rootPassword,$db.password)) {
                if ($secret) { $details = $details.Replace([string]$secret,'[redacted]') }
            }
            Save-Text $logFile ("MariaDB initialization exit code: $result`r`n" + $details)
        } finally {
            Write-Progress -Id 1 -Activity 'Initializing MariaDB' -Completed
            $ProgressPreference = $previousProgress
            $process.Dispose()
        }
        if ($result -ne 0) {
            Write-Host (($details -split '\r?\n' | Where-Object { $_.Trim() } | Select-Object -Last 20) -join "`n") -ForegroundColor Yellow
            throw "MariaDB initialization failed (exit code $result). Details: $logFile. The database directory has been left intact."
        }
        Write-Host 'MariaDB system tables created.'
    }
    $dbIni = Join-Path $databaseDir 'my.ini'
    # MariaDB replaces the initial directory ACL while creating system tables.
    Grant-ServiceWrite $databaseDir
    $dbText = [IO.File]::ReadAllText($dbIni)
    if ($dbText -notmatch '(?m)^bind-address\s*=') { Save-Text $dbIni ($dbText+"`n[mysqld]`nbind-address=127.0.0.1`n") }
    Install-AppService 'VaultcastDatabase' 'Vaultcast Database' (Join-Path $bin 'mariadbd.exe') ('--defaults-file="'+$dbIni+'" --console') $bin
    $script:databaseDependency = @('VaultcastDatabase')
    $provision = 'require $argv[1]; require $argv[2]; Vaultcast\Installer::prepareWindowsDatabase(json_decode(stream_get_contents(STDIN),true));'
    Write-Host 'Creating the private library database and account...'
    $db | ConvertTo-Json -Compress | & $phpExe -c $ini -r $provision (Join-Path $installRoot 'src/Database.php') (Join-Path $installRoot 'src/Installer.php')
    if ($LASTEXITCODE -ne 0) { throw 'The database could not be prepared. Run setup again to resume; saved credentials will be reused.' }
}
function Get-Nginx {
$nginxExe = Get-ChildItem -Path "$runtime/nginx-*/nginx.exe" -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName
if (!$nginxExe) {
    $existing = Find-Program 'nginx.exe'
    if ($existing) {
        $mime = Join-Path (Split-Path $existing -Parent) 'conf/mime.types'
        if (Test-Path -LiteralPath $mime) {
            $private = Join-Path $runtime 'nginx-local'
            New-Item -ItemType Directory -Path (Join-Path $private 'conf'),(Join-Path $private 'logs') -Force | Out-Null
            Copy-Item -LiteralPath $existing -Destination (Join-Path $private 'nginx.exe')
            Copy-Item -LiteralPath $mime -Destination (Join-Path $private 'conf/mime.types')
            Write-Host 'Using the existing nginx executable with separate Vaultcast configuration.'
            return Join-Path $private 'nginx.exe'
        }
    }
}
if (!$nginxExe) {
    $page = [string](Get-ReleaseInfo 'https://nginx.org/en/download.html')
    $match = [regex]::Match($page,'href="(/download/nginx-[0-9.]+\.zip)"')
    if (!$match.Success) { throw 'The nginx Windows download could not be located.' }
    $zip=Join-Path $runtime 'nginx.zip'
    Get-Download ('https://nginx.org'+$match.Groups[1].Value) $zip
    Expand-Package $zip $runtime
    $nginxExe=Get-ChildItem -Path "$runtime/nginx-*/nginx.exe" | Select-Object -First 1 -ExpandProperty FullName
}
    return $nginxExe
}
function Get-FFmpegDirectory {
    $existing = Find-Program 'ffmpeg.exe'
    if ($existing) { return Split-Path $existing -Parent }
    $local = Get-ChildItem -Path "$runtime/ffmpeg-*/bin/ffmpeg.exe" -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName
    if ($local) { return Split-Path $local -Parent }
    if (!(Confirm-Setup 'Download optional FFmpeg from gyan.dev for media analysis and offline conversion?')) { return $null }
    $url = 'https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip'
    $checksum = ([string](Get-ReleaseInfo ($url + '.sha256'))).Trim().Split(' ')[0]
    $zip = Join-Path $runtime 'ffmpeg.zip'
    $downloadUrl = $url
    if (!$BundleDirectory) {
        try {
            $release = Get-ReleaseInfo 'https://api.github.com/repos/GyanD/codexffmpeg/releases/latest'
            $asset = $release.assets | Where-Object name -match '^ffmpeg-[0-9.]+-essentials_build\.zip$' | Select-Object -First 1
            if ($asset) { $downloadUrl = $asset.browser_download_url }
        } catch { Write-Host 'Using the primary FFmpeg download server.' }
    }
    Get-Download $downloadUrl $zip $checksum
    Expand-Package $zip $runtime
    $exe = Get-ChildItem -Path "$runtime/ffmpeg-*/bin/ffmpeg.exe" | Select-Object -First 1 -ExpandProperty FullName
    if (!$exe) { throw 'The FFmpeg archive did not contain ffmpeg.exe.' }
    return Split-Path $exe -Parent
}
function Install-Transmission {
    $existing = Find-Program 'transmission-qt.exe'
    if (!$existing) { $existing = Join-Path $env:ProgramFiles 'Transmission/transmission-qt.exe' }
    if (Test-Path -LiteralPath $existing) { Write-Host 'Transmission is already installed. Configure Remote access in its Preferences.'; return }
    $release = Get-ReleaseInfo 'https://api.github.com/repos/transmission/transmission/releases/latest'
    $asset = $release.assets | Where-Object { $_.name -match '^transmission-.*-x64\.msi$' } | Select-Object -First 1
    if (!$asset) { throw 'No official Transmission x64 installer was found. Download it from https://transmissionbt.com/download.' }
    $package = Join-Path $runtime $asset.name
    $hash = if ($asset.digest -match '^sha256:([a-fA-F0-9]{64})$') { $Matches[1] } else { '' }
    Get-Download $asset.browser_download_url $package $hash
    if ((Get-AuthenticodeSignature -LiteralPath $package).Status -ne 'Valid') { throw 'Transmission installer signature could not be verified.' }
    Write-Host 'Installing optional Transmission. Its installer displays progress...'
    $process = Start-Process msiexec.exe -ArgumentList @('/i',('"'+$package+'"'),'/passive','/norestart') -Wait -PassThru
    if ($process.ExitCode -notin @(0,3010)) { throw "Transmission installation failed ($($process.ExitCode))." }
    Write-Host 'Open Transmission, then Preferences > Remote. Enable remote access and authentication. Enter those credentials in Vaultcast Settings > Downloads.'
}
Write-Host 'Vaultcast setup'
Write-Host '1. Basic - install everything automatically and share the library on port 9999.'
Write-Host '2. Advanced - choose ports, installation folder and database connection.'
$choice = if ($Unattended) { '1' } else { Read-Host 'Choose setup [1]' }
if ($choice -and $choice -notin @('1','2')) { throw 'Choose Basic (1) or Advanced (2).' }
$basic = $choice -ne '2'
if (!$Unattended -and (Read-Host 'Install/update Vaultcast, its native dependencies and automatic Windows services? [Y/n]') -match '^(n|no)$') { exit }
if ($env:PROCESSOR_ARCHITECTURE -eq 'ARM64' -or $env:PROCESSOR_ARCHITEW6432 -eq 'ARM64') { throw 'Vaultcast requires x64 Windows.' }
if ($Architecture -eq 'auto') { $Architecture = 'x64' }
if ($Architecture -eq 'x64' -and ![Environment]::Is64BitOperatingSystem) { throw 'Vaultcast requires 64-bit Windows.' }
$registration = Get-ItemProperty 'HKLM:\SOFTWARE\Vaultcast' -ErrorAction SilentlyContinue
$installRoot = if ($registration.InstallPath) { $registration.InstallPath } elseif ($InstallDirectory) { $InstallDirectory } else { Join-Path $env:ProgramData 'Vaultcast' }
if (!$basic -and !$registration.InstallPath) {
    $answer = Read-Host "Installation folder [$installRoot]"
    if ($answer) { $installRoot = $answer }
}
if (![IO.Path]::IsPathRooted($installRoot)) { throw 'Choose an absolute installation folder.' }
$installRoot = [IO.Path]::GetFullPath($installRoot).TrimEnd('\')
if ($installRoot.StartsWith('\\')) { throw 'Install the Windows services on a local disk. Media can be kept on a network share.' }
if ($installRoot -match '["\r\n$%]' -or $installRoot -eq [IO.Path]::GetPathRoot($installRoot).TrimEnd('\')) { throw 'Choose a dedicated installation folder without quotes, percent signs or dollar signs.' }
$runtime = Join-Path $installRoot 'data/runtime'
$services = Join-Path $runtime 'services'
$ownership = Join-Path $runtime 'setup-owner.txt'
if (!$registration.InstallPath -and (Test-Path -LiteralPath $installRoot) -and (Get-ChildItem -LiteralPath $installRoot -Force | Select-Object -First 1) -and !(Test-Path -LiteralPath (Join-Path $installRoot 'src/Database.php')) -and !(Test-Path -LiteralPath $ownership)) {
    throw 'Choose an empty installation folder or an existing Vaultcast installation.'
}
if ($Dependency) {
    foreach ($folder in @($runtime,$services,(Join-Path $runtime 'logs'))) { New-Item -ItemType Directory -Path $folder -Force | Out-Null }
    Save-Text $ownership 'Vaultcast dependency staging'
    switch ($Dependency) {
        'PHP' { Get-PHP | Out-Null }
        'Node' { Get-Node | Out-Null }
        'FFmpeg' { Get-FFmpegDirectory | Out-Null }
        'nginx' { Get-Nginx | Out-Null }
        'MariaDB' { Get-MariaDBBin | Out-Null }
        'Transmission' { Install-Transmission }
    }
    Write-Host "$Dependency preparation complete."
    exit 0
}
Show-SetupStage 1 'Preparing the application and choosing ports'
# Stop only services belonging to this installation, never another server.
$ownedServiceIds = @('VaultcastWeb') + @(16..2 | ForEach-Object { 'VaultcastPHP'+$_ }) + @('VaultcastPHP','VaultcastNetplay','VaultcastDatabase')
foreach ($id in $ownedServiceIds) {
    $existing = Get-CimInstance Win32_Service -Filter "Name='$id'" -ErrorAction SilentlyContinue
    if ($existing) {
        $expected = Join-Path $services "$id.exe"
        if ($existing.PathName.Trim('"') -ne $expected) { throw "The service $id belongs to another folder. Use that installation's setup." }
        Stop-Service $id -Force
        (Get-Service $id).WaitForStatus('Stopped',[TimeSpan]::FromSeconds(30))
    }
}
New-Item -ItemType Directory -Path $installRoot -Force | Out-Null
# Copy application files only. Updates never replace saved data or configuration.
if ($sourceRoot.TrimEnd('\') -ne $installRoot) {
    foreach ($entry in @('index.php','router.php','.htaccess','manifest.json','sw.js','VERSION','assets','src','deploy')) {
        Copy-Item -LiteralPath (Join-Path $sourceRoot $entry) -Destination $installRoot -Recurse -Force
    }
    New-Item -ItemType Directory -Path (Join-Path $installRoot 'netplay') -Force | Out-Null
    foreach ($entry in @('server.js','package.json','package-lock.json')) { Copy-Item -LiteralPath (Join-Path $sourceRoot "netplay/$entry") -Destination (Join-Path $installRoot 'netplay') -Force }
    if (!(Test-Path -LiteralPath (Join-Path $installRoot 'data')) -and ((Test-Path -LiteralPath (Join-Path $sourceRoot 'config.php')) -or (Test-Path -LiteralPath (Join-Path $sourceRoot 'data/config.php')))) {
        Copy-Item -LiteralPath (Join-Path $sourceRoot 'data') -Destination $installRoot -Recurse
        if (Test-Path -LiteralPath (Join-Path $sourceRoot 'config.php')) { Copy-Item -LiteralPath (Join-Path $sourceRoot 'config.php') -Destination $installRoot }
    }
}
foreach ($legalFile in @('LICENSE','NOTICE')) {
    $legalSource = Join-Path (Split-Path $sourceRoot -Parent) $legalFile
    if (Test-Path -LiteralPath $legalSource) { Copy-Item -LiteralPath $legalSource -Destination (Join-Path $installRoot $legalFile) -Force }
}
foreach ($folder in @($runtime,$services,(Join-Path $runtime 'logs'),(Join-Path $installRoot 'data/sessions'),(Join-Path $installRoot 'data/tmp'))) { New-Item -ItemType Directory -Path $folder -Force | Out-Null }
Set-InstallPermissions
$legacyConfig = Join-Path $installRoot 'config.php'
$storedConfig = Join-Path $installRoot 'data/config.php'
if (Test-Path -LiteralPath $legacyConfig) {
    if (Test-Path -LiteralPath $storedConfig) { throw 'This folder has two database configurations. Keep the configuration for your library before rerunning setup; neither has been overwritten.' }
    Move-Item -LiteralPath $legacyConfig -Destination $storedConfig
}
New-Item 'HKLM:\SOFTWARE\Vaultcast' -Force | Out-Null
Set-ItemProperty 'HKLM:\SOFTWARE\Vaultcast' InstallPath $installRoot
Set-Location -LiteralPath $installRoot
$savedPath = Join-Path $runtime 'setup.json'
$saved = if (Test-Path -LiteralPath $savedPath) { Get-Content -LiteralPath $savedPath -Raw | ConvertFrom-Json } else { $null }
$script:reservedPorts = @()
$databaseState = Join-Path $runtime 'database-secret.txt'
if (Test-Path -LiteralPath $databaseState) {
    $secret = Get-Content -LiteralPath $databaseState -Raw | ConvertTo-SecureString
    $databaseSettings = [Net.NetworkCredential]::new('', $secret).Password | ConvertFrom-Json
    $script:reservedPorts += [int]$databaseSettings.port
}
$webDefault = if ($saved.webPort) { [int]$saved.webPort } else { $LibraryPort }
$webPort = Choose-Port 'Library port' $webDefault -Public
$phpPort = Choose-Port 'Private PHP port' $(if ($saved.phpPort) { [int]$saved.phpPort } else { $FastCgiPort })
$phpWorkers = if ($saved.phpWorkers) { [Math]::Max(1,[Math]::Min(16,[int]$saved.phpWorkers)) } else { 4 }
$phpPorts = @($phpPort)
for ($worker=2; $worker -le $phpWorkers; $worker++) { $phpPorts += Choose-Port "Private PHP worker $worker port" ($phpPorts[-1]+1) }
$netplayPort = Choose-Port 'Private multiplayer port' $(if ($saved.netplayPort) { [int]$saved.netplayPort } else { $RelayPort })
$listenAddress = if ($saved.listenAddress) { $saved.listenAddress } else { '0.0.0.0' }
if (!$basic) { $answer=Read-Host "Listen address [$listenAddress; use 127.0.0.1 for this PC only]"; if ($answer) { $listenAddress=$answer } }
$address=$null
if (![Net.IPAddress]::TryParse($listenAddress,[ref]$address) -or $address.AddressFamily -ne [Net.Sockets.AddressFamily]::InterNetwork) { throw 'Enter an IPv4 listen address.' }
Show-SetupStage 2 'Preparing PHP and its Microsoft runtime'
$phpExe = Get-PHP
$phpDir = Split-Path $phpExe -Parent
Show-SetupStage 3 'Preparing Node.js'
$nodeExe = Get-Node
$npm = Join-Path (Split-Path $nodeExe -Parent) 'npm.cmd'
Show-SetupStage 4 'Preparing media tools'
$ffmpegDir = Get-FFmpegDirectory
if ($BundleDirectory -or (!$Unattended -and (Read-Host 'Install optional Transmission for downloads? Existing media does not need it [y/N]') -match '^(y|yes)$')) { Install-Transmission }
$runtimePaths = @($phpDir,(Split-Path $nodeExe -Parent))
if ($ffmpegDir) { $runtimePaths += $ffmpegDir }
$servicePath = ($runtimePaths -join ';') + ';' + [Environment]::GetEnvironmentVariable('Path','Machine')
$env:Path = $servicePath
Show-SetupStage 5 'Preparing nginx and the Windows service wrapper'
$nginxExe = Get-Nginx
$serviceWrapper=Join-Path $services 'WinSW.exe'
if (!(Test-Path -LiteralPath $serviceWrapper)) { Get-Download 'https://github.com/winsw/winsw/releases/download/v2.12.0/WinSW.NET4.exe' $serviceWrapper }
$caFile=Join-Path $runtime 'cacert.pem'
if (!(Test-Path -LiteralPath $caFile)) { Get-Download 'https://curl.se/ca/cacert.pem' $caFile }
$ini=Join-Path $runtime 'php.ini'
$phpExt=(Join-Path $phpDir 'ext').Replace('\','/')
$caPath=$caFile.Replace('\','/')
$errorLog=(Join-Path $runtime 'logs/php-errors.log').Replace('\','/')
$sessionPath=(Join-Path $installRoot 'data/sessions').Replace('\','/')
$tempPath=(Join-Path $installRoot 'data/tmp').Replace('\','/')
Save-Text $ini @"
extension_dir="$phpExt"
extension=pdo_mysql
extension=mbstring
extension=fileinfo
extension=curl
extension=openssl
extension=gd
extension=zip
zend_extension=opcache
opcache.enable=1
opcache.validate_timestamps=1
opcache.revalidate_freq=2
cgi.fix_pathinfo=0
display_errors=Off
log_errors=On
error_log="$errorLog"
session.save_path="$sessionPath"
upload_tmp_dir="$tempPath"
sys_temp_dir="$tempPath"
curl.cainfo="$caPath"
openssl.cafile="$caPath"
upload_max_filesize=16M
post_max_size=16M
"@
Show-SetupStage 6 'Preparing MariaDB'
Prepare-Database
$nginxDir=Split-Path $nginxExe -Parent
Grant-ServiceWrite (Join-Path $nginxDir 'logs')
$rootForNginx=$installRoot.Replace('\','/')
$config=Get-Content -LiteralPath 'deploy/nginx.conf.example' -Raw
$config=$config.Replace('listen 80;',"listen $($listenAddress):$webPort;").Replace('server_name vaultcast.local;','server_name _;').Replace('root /srv/vaultcast;',"root ""$rootForNginx"";").Replace('unix:/run/php/php7.4-fpm.sock','vaultcast_php').Replace('127.0.0.1:3100',"127.0.0.1:$netplayPort")
$mediaLocation = '    location ~ "^/_vaultcast_media/([A-Za-z]):/(.*)$" {' + "`n" + '        internal; alias $1:/$2; sendfile on;' + "`n    }"
$config=[regex]::Replace($config,'(?s)    location \^~ /_vaultcast_media/ \{.*?\n    \}',[Text.RegularExpressions.MatchEvaluator]{ param($match) $mediaLocation })
$upstream = "upstream vaultcast_php {`n    least_conn;`n" + (($phpPorts | ForEach-Object { "    server 127.0.0.1:$_;" }) -join "`n") + "`n}`n"
$config = $upstream + $config
$temporaryPaths = ''
foreach ($kind in @('client_body','proxy','fastcgi','uwsgi','scgi')) {
    $temporaryPaths += "$($kind)_temp_path ""$tempPath/$kind"";`n"
}
Save-Text (Join-Path $nginxDir 'conf/vaultcast.conf') "daemon off;`nevents { worker_connections 512; }`nhttp { include mime.types; default_type application/octet-stream;`n$temporaryPaths`n$config`n}`n"
Show-SetupStage 7 'Installing multiplayer dependencies and Windows services'
Push-Location netplay
try {
    $npmArguments = @('ci','--omit=dev','--ignore-scripts','--no-audit','--no-fund','--progress=true','--loglevel=http')
    if ($BundleDirectory) { $npmArguments += @('--offline','--cache',(Join-Path $BundleDirectory 'npm-cache')) }
    if ((Invoke-Native $npm $npmArguments) -ne 0) { throw 'Multiplayer dependencies could not be installed. Rerun setup to resume.' }
} finally { Pop-Location }
$phpServiceIds = @()
for ($worker=0; $worker -lt $phpPorts.Count; $worker++) {
    $serviceId = if ($worker -eq 0) { 'VaultcastPHP' } else { 'VaultcastPHP'+($worker+1) }
    $phpServiceIds += $serviceId
    Install-AppService $serviceId ("Vaultcast PHP worker "+($worker+1)) (Join-Path $phpDir 'php-cgi.exe') ('-c "'+$ini+'" -b 127.0.0.1:'+$phpPorts[$worker]) $installRoot @{PATH=$servicePath;PHP_FCGI_MAX_REQUESTS='0'} $databaseDependency
}
Install-AppService 'VaultcastNetplay' 'Vaultcast Multiplayer' $nodeExe ('"'+(Join-Path $installRoot 'netplay/server.js')+'"') $installRoot @{PATH=$servicePath;PORT="$netplayPort";HOST='127.0.0.1';NETPLAY_LOG=(Join-Path $installRoot 'data/netplay.log')}
$nginxArguments='-p "'+$nginxDir.Replace('\','/')+'/" -c conf/vaultcast.conf'
Install-AppService 'VaultcastWeb' 'Vaultcast Web' $nginxExe $nginxArguments $nginxDir @{} ($phpServiceIds + @('VaultcastNetplay')) ($nginxArguments+' -s quit')
Save-Text $savedPath ([pscustomobject]@{webPort=$webPort;phpPort=$phpPort;phpWorkers=$phpWorkers;mediaAccel=$true;netplayPort=$netplayPort;listenAddress=$listenAddress;installRoot=$installRoot} | ConvertTo-Json)
Show-SetupStage 8 'Configuring network access and waiting for startup'
Get-NetFirewallRule -Name VaultcastWeb -ErrorAction SilentlyContinue | Remove-NetFirewallRule
if ($listenAddress -ne '127.0.0.1') { New-NetFirewallRule -Name VaultcastWeb -DisplayName 'Vaultcast library' -Direction Inbound -Action Allow -Protocol TCP -LocalPort $webPort -Program $nginxExe -Profile Private,Domain -RemoteAddress LocalSubnet | Out-Null }
$urlHost = if ($listenAddress -in @('0.0.0.0','127.0.0.1')) { 'localhost' } else { $listenAddress }
$url="http://$($urlHost):$webPort/"
foreach ($workerPort in $phpPorts) { Wait-Listening '127.0.0.1' $workerPort }
Wait-Listening '127.0.0.1' $netplayPort
Wait-Listening $(if ($listenAddress -eq '0.0.0.0') { '127.0.0.1' } else { $listenAddress }) $webPort
$shortcut=Join-Path ([Environment]::GetFolderPath('CommonPrograms')) 'Vaultcast.url'
Save-Text $shortcut "[InternetShortcut]`r`nURL=$url`r`n"
Write-Host 'All 8 setup stages complete.' -ForegroundColor Green
Write-Host "Vaultcast is installed at $installRoot."
Write-Host "Open $url to name the library, create its administrator and match your media."
Write-Host 'Windows starts Vaultcast automatically, including before anyone signs in. Use Vaultcast in the Start menu to open it.'
Start-Process $url
