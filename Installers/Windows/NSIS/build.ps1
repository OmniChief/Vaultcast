<#
  Builds the self-contained Vaultcast Windows installer.

  This is a developer build script only. It runs on the build PC, never on the
  PC where Vaultcast is installed; the installer itself contains no PowerShell.

  Steps:
    1. Download (and cache) every native package, checking publisher checksums.
    2. Unpack them into build\stage\runtime and build\stage\redist.
    3. Export the committed Application\ folder into build\stage\app and run
       `npm ci` for the multiplayer relay, so no npm runs at install time.
    4. Collect licence texts into build\stage\licenses: from each downloaded package
       when it ships them (exact version), else the committed copies in licenses\.
       Record the exact GPL versions and source URLs in licenses\SOURCE.md.
    5. Compile Vaultcast.nsi with makensis.

  Usage:  ./Installers/Windows/NSIS/build.ps1 [-OutputDirectory <dir>] [-Compiler <makensis.exe>]
#>
param(
    [string]$OutputDirectory=(Join-Path $(if ($env:USERPROFILE) { $env:USERPROFILE } else { $HOME }) 'Downloads'),
    [string]$Compiler='',
    [string]$SigningScript=$env:VAULTCAST_SIGNING_SCRIPT,
    [switch]$UnsignedDevelopmentBuild
)
$ErrorActionPreference='Stop'
# Windows PowerShell 5.1 has no $IsWindows; PowerShell 7 on Linux/macOS can also build.
$onWindows=($PSVersionTable.PSEdition -ne 'Core') -or $IsWindows
$curl=if ($onWindows) { 'curl.exe' } else { 'curl' }
if (!$UnsignedDevelopmentBuild) {
    if (!$onWindows) { throw 'Signed release builds require Windows Authenticode verification.' }
    if (!$SigningScript -or !(Test-Path -LiteralPath $SigningScript -PathType Leaf)) {
        throw 'Release installers require a trusted signing provider. Pass -SigningScript <provider.ps1> (accepting -FilePath), or explicitly use -UnsignedDevelopmentBuild for local development only. Unsigned EXEs may be blocked by Application Control.'
    }
    $SigningScript=(Resolve-Path -LiteralPath $SigningScript).Path
}
[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12
$repo=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../..'))
$build=Join-Path $PSScriptRoot 'build'
$cache=Join-Path $build 'downloads'
$stage=Join-Path $build 'stage'
New-Item -ItemType Directory -Path $build,$cache,$OutputDirectory -Force | Out-Null
if (!$Compiler) {
    $found=Get-Command makensis.exe,makensis -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($found) { $Compiler=$found.Source } elseif (${env:ProgramFiles(x86)}) { $Compiler=Join-Path ${env:ProgramFiles(x86)} 'NSIS/makensis.exe' }
}
if (!$Compiler -or !(Test-Path -LiteralPath $Compiler)) { throw 'Install NSIS 3 or pass -Compiler with the path to makensis.exe.' }

# ---- Download cache (keyed by URL, verified by SHA-256) ----
$manifest=@{}
$manifestFile=Join-Path $cache 'manifest.json'
if (Test-Path -LiteralPath $manifestFile) {
    $saved=Get-Content -LiteralPath $manifestFile -Raw | ConvertFrom-Json
    foreach ($property in $saved.PSObject.Properties) { $manifest[$property.Name]=$property.Value }
}
function Save-Package([string]$Url,[string]$Expected='',[string]$Mirror='') {
    if ($Url -notmatch '^https://') { throw 'Build downloads must use HTTPS.' }
    $hasher=[Security.Cryptography.SHA256]::Create()
    try { $key=([BitConverter]::ToString($hasher.ComputeHash([Text.Encoding]::UTF8.GetBytes($Url)))).Replace('-','').ToLowerInvariant() } finally { $hasher.Dispose() }
    $file=Join-Path $cache ($key+'.bin')
    $entry=$manifest[$Url]
    if ($entry -and (Test-Path -LiteralPath $file) -and (Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash -eq $entry.sha256) {
        if ($Expected -and $entry.sha256 -ne $Expected) { throw "Cached publisher checksum mismatch: $Url" }
        return $file
    }
    Write-Host "Downloading $Url"
    $downloadUrl=if ($Mirror) { $Mirror } else { $Url }
    for ($attempt=1;$attempt -le 3;$attempt++) {
        try {
            & $curl --fail --location --progress-bar --connect-timeout 20 --max-time 1200 --output ($file+'.part') $downloadUrl
            if ($LASTEXITCODE -ne 0) { throw "Download failed: $Url" }
            $hash=(Get-FileHash -LiteralPath ($file+'.part') -Algorithm SHA256).Hash
            if ($Expected -and $hash -ne $Expected) { throw 'Publisher checksum mismatch.' }
            Move-Item -LiteralPath ($file+'.part') -Destination $file -Force
            break
        } catch { if ($attempt -eq 3) { throw }; Write-Host 'Retrying download...' }
    }
    $manifest[$Url]=@{file=($key+'.bin');sha256=$hash}
    [IO.File]::WriteAllText($manifestFile,($manifest | ConvertTo-Json -Depth 6),[Text.UTF8Encoding]::new($false))
    return $file
}
function Read-Catalogue([string]$Url) { return Get-Content -LiteralPath (Save-Package $Url) -Raw | ConvertFrom-Json }

# Unpack a ZIP into $Destination; a single top-level folder is flattened away.
function Expand-Flat([string]$Archive,[string]$Destination) {
    $temp=Join-Path $build ('unpack-'+[IO.Path]::GetRandomFileName())
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [IO.Compression.ZipFile]::ExtractToDirectory($Archive,$temp)
    $items=@(Get-ChildItem -LiteralPath $temp -Force)
    $source=if ($items.Count -eq 1 -and $items[0].PSIsContainer) { $items[0].FullName } else { $temp }
    if (Test-Path -LiteralPath $Destination) { Remove-Item -LiteralPath $Destination -Recurse -Force }
    Move-Item -LiteralPath $source -Destination $Destination
    if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp -Recurse -Force }
}

if (Test-Path -LiteralPath $stage) { Remove-Item -LiteralPath $stage -Recurse -Force }
$runtime=Join-Path $stage 'runtime'
$redist=Join-Path $stage 'redist'
New-Item -ItemType Directory -Path $runtime,$redist -Force | Out-Null
$bundled=[ordered]@{}   # component -> @{version; url; source} for NOTICE/SOURCE.md
$trLicences=Join-Path $build 'transmission-licences'

# ---- PHP 8.3 NTS x64 + Visual C++ runtime ----
$php=Read-Catalogue 'https://downloads.php.net/~windows/releases/releases.json'
$package=$php.'8.3'.'nts-vs16-x64'.zip
if (!$package.path -or !$package.sha256) { throw 'PHP x64 package was not found.' }
Expand-Flat (Save-Package ('https://downloads.php.net/~windows/releases/'+$package.path) $package.sha256) (Join-Path $runtime 'php')
$bundled['PHP']=@{version=$php.'8.3'.version; url='https://downloads.php.net/~windows/releases/'+$package.path}
Copy-Item -LiteralPath (Save-Package 'https://aka.ms/vs/17/release/vc_redist.x64.exe') -Destination (Join-Path $redist 'vc_redist.x64.exe')

# ---- Node.js LTS ----
$nodes=Read-Catalogue 'https://nodejs.org/dist/index.json'
$node=$nodes | Where-Object { $_.lts -and $_.files -contains 'win-x64-zip' } | Select-Object -First 1
$nodeName="node-$($node.version)-win-x64.zip"
$base="https://nodejs.org/dist/$($node.version)/"
$sums=Get-Content -LiteralPath (Save-Package ($base+'SHASUMS256.txt')) -Raw
$match=[regex]::Match($sums,'(?m)^([a-fA-F0-9]{64})\s+'+[regex]::Escape($nodeName)+'\s*$')
if (!$match.Success) { throw 'Node.js publisher checksum was not found.' }
Expand-Flat (Save-Package ($base+$nodeName) $match.Groups[1].Value) (Join-Path $runtime 'node')
$bundled['Node.js']=@{version=$node.version.TrimStart('v'); url=$base+$nodeName}

# ---- MariaDB 11.4 ----
$maria=Read-Catalogue 'https://downloads.mariadb.org/rest-api/mariadb/11.4/'
$release=$maria.releases.PSObject.Properties | Where-Object Name -match '^11\.4\.\d+$' | Sort-Object { [version]$_.Name } -Descending | Select-Object -First 1
$package=$release.Value.files | Where-Object file_name -match '^mariadb-[0-9.]+-winx64\.zip$' | Select-Object -First 1
if (!$package.checksum.sha256sum) { throw 'MariaDB publisher checksum was not found.' }
Expand-Flat (Save-Package ("https://downloads.mariadb.org/rest-api/mariadb/$($release.Name)/$($package.file_name)") $package.checksum.sha256sum) (Join-Path $runtime 'mariadb')
$bundled['MariaDB']=@{version=$release.Name; url="https://downloads.mariadb.org/rest-api/mariadb/$($release.Name)/$($package.file_name)"
    source="https://archive.mariadb.org/mariadb-$($release.Name)/source/mariadb-$($release.Name).tar.gz"; tag="https://github.com/MariaDB/server/tree/mariadb-$($release.Name)"}

# ---- nginx ----
$nginx=Get-Content -LiteralPath (Save-Package 'https://nginx.org/en/download.html') -Raw
$match=[regex]::Match($nginx,'href="(/download/nginx-[0-9.]+\.zip)"')
if (!$match.Success) { throw 'nginx Windows package was not found.' }
Expand-Flat (Save-Package ('https://nginx.org'+$match.Groups[1].Value)) (Join-Path $runtime 'nginx')
$bundled['nginx']=@{version=([regex]::Match($match.Groups[1].Value,'nginx-([0-9.]+)\.zip').Groups[1].Value); url='https://nginx.org'+$match.Groups[1].Value}

# ---- FFmpeg essentials ----
$ffmpeg='https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip'
$sum=(Get-Content -LiteralPath (Save-Package ($ffmpeg+'.sha256')) -Raw).Trim().Split(' ')[0]
$ffmpegRelease=Read-Catalogue 'https://api.github.com/repos/GyanD/codexffmpeg/releases/latest'
$ffmpegAsset=$ffmpegRelease.assets | Where-Object name -match '^ffmpeg-[0-9.]+-essentials_build\.zip$' | Select-Object -First 1
if (!$ffmpegAsset) { throw 'The FFmpeg publisher mirror has no essentials ZIP.' }
Expand-Flat (Save-Package $ffmpeg $sum $ffmpegAsset.browser_download_url) (Join-Path $runtime 'ffmpeg')
$ffVersion=[regex]::Match($ffmpegAsset.name,'^ffmpeg-([0-9.]+)-essentials').Groups[1].Value
$bundled['FFmpeg']=@{version=$ffVersion; url=$ffmpegAsset.browser_download_url
    source="https://ffmpeg.org/releases/ffmpeg-$ffVersion.tar.xz"; tag="https://git.ffmpeg.org/gitweb/ffmpeg.git/tag/n$ffVersion"
    build="https://github.com/GyanD/codexffmpeg/releases/tag/$($ffmpegRelease.tag_name) (build notes: licenses/FFmpeg/README.txt)"}

# ---- WinSW, CA bundle, Transmission ----
Copy-Item -LiteralPath (Save-Package 'https://github.com/winsw/winsw/releases/download/v2.12.0/WinSW.NET4.exe') -Destination (Join-Path $runtime 'WinSW.exe')
$bundled['WinSW']=@{version='2.12.0'; url='https://github.com/winsw/winsw/releases/download/v2.12.0/WinSW.NET4.exe'}
Copy-Item -LiteralPath (Save-Package 'https://curl.se/ca/cacert.pem') -Destination (Join-Path $runtime 'cacert.pem')
$bundled['curl CA bundle']=@{version=('downloaded '+(Get-Date -Format 'yyyy-MM-dd')); url='https://curl.se/ca/cacert.pem'}
$bundled['Visual C++ Redistributable']=@{version='14.x (latest at build)'; url='https://aka.ms/vs/17/release/vc_redist.x64.exe'}
$transmission=Read-Catalogue 'https://api.github.com/repos/transmission/transmission/releases/latest'
$package=$transmission.assets | Where-Object { $_.name -match '^transmission-.*-x64\.msi$' } | Select-Object -First 1
if (!$package) { throw 'Transmission x64 package was not found.' }
$sum=if ($package.digest -match '^sha256:([a-fA-F0-9]{64})$') { $Matches[1] } else { '' }
$msi=Save-Package $package.browser_download_url $sum
$trTag=$transmission.tag_name
$trSource=$transmission.assets | Where-Object { $_.name -match '^transmission-.*\.tar\.xz$' } | Select-Object -First 1
$bundled['Transmission']=@{version=$trTag; url=$package.browser_download_url
    source=$(if ($trSource) { $trSource.browser_download_url } else { "https://github.com/transmission/transmission/archive/refs/tags/$trTag.tar.gz" })
    tag="https://github.com/transmission/transmission/tree/$trTag"}
# Only the headless daemon is bundled (Vaultcast runs it as its own service); take it
# and its libraries out of the official MSI without installing anything.
$msiOut=Join-Path $build ('msi-'+[IO.Path]::GetRandomFileName())
New-Item -ItemType Directory -Path $msiOut -Force | Out-Null
if ($onWindows) {
    $process=Start-Process msiexec.exe -ArgumentList @('/a',('"'+$msi+'"'),'/qn',('TARGETDIR="'+$msiOut+'"')) -Wait -PassThru
    if ($process.ExitCode -ne 0) { throw "Could not unpack the Transmission MSI ($($process.ExitCode))." }
} else {
    Copy-Item -LiteralPath $msi -Destination (Join-Path $msiOut 'transmission.msi')
    & msiextract -C $msiOut (Join-Path $msiOut 'transmission.msi') | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'msiextract (msitools) is needed to unpack the Transmission MSI on this platform.' }
}
$daemon=Get-ChildItem -LiteralPath $msiOut -Recurse -Filter 'transmission-daemon.exe' | Select-Object -First 1
if (!$daemon) { throw 'The Transmission MSI has no transmission-daemon.exe.' }
$trStage=Join-Path $runtime 'transmission'
New-Item -ItemType Directory -Path $trStage -Force | Out-Null
foreach ($name in @('transmission-daemon.exe','libcurl.dll','libssl-3-x64.dll','libcrypto-3-x64.dll','zlib.dll')) {
    Copy-Item -LiteralPath (Join-Path $daemon.DirectoryName $name) -Destination $trStage
}
# Keep the licence files the MSI ships (exact version) for build\stage\licenses.
if (Test-Path -LiteralPath $trLicences) { Remove-Item -LiteralPath $trLicences -Recurse -Force }
New-Item -ItemType Directory -Path $trLicences -Force | Out-Null
Get-ChildItem -LiteralPath $msiOut -Recurse -File | Where-Object { $_.Name -match '^(COPYING|LICEN[CS]E|NOTICE)' } |
    ForEach-Object { Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $trLicences $_.Name) -Force }
$web=Join-Path $daemon.DirectoryName 'public_html'
if (Test-Path -LiteralPath $web) { Copy-Item -LiteralPath $web -Destination $trStage -Recurse }
Remove-Item -LiteralPath $msiOut -Recurse -Force

# ---- Application (committed files only) + multiplayer node_modules ----
# Git's tracked snapshot excludes runtime data, credentials and ignored files.
$archive=Join-Path $build 'application.zip'
& git -C $repo archive --format=zip "--output=$archive" HEAD:Application
if ($LASTEXITCODE -ne 0) { throw 'Could not archive the committed application source.' }
Add-Type -AssemblyName System.IO.Compression.FileSystem
[IO.Compression.ZipFile]::ExtractToDirectory($archive,(Join-Path $stage 'app'))
$nodeDir=Join-Path $runtime 'node'
Push-Location (Join-Path $stage 'app/netplay')
$previousPath=$env:Path
try {
    # The relay's dependencies are plain JavaScript, so a non-Windows build uses the local npm.
    $npm=if ($onWindows) { $env:Path=$nodeDir+';'+$env:Path; Join-Path $nodeDir 'npm.cmd' } else { 'npm' }
    Write-Host 'Installing multiplayer relay dependencies (no lifecycle scripts)...'
    & $npm ci --omit=dev --ignore-scripts --no-audit --no-fund --cache (Join-Path $build 'npm-cache')
    if ($LASTEXITCODE -ne 0) { throw 'npm ci failed for the multiplayer relay.' }
} finally { Pop-Location; $env:Path=$previousPath }

# ---- Licences (LICENSE, NOTICE, licenses\<component>\, SOURCE.md) ----
$licences=Join-Path $stage 'licenses'
$fallback=Join-Path $PSScriptRoot 'licenses'
New-Item -ItemType Directory -Path $licences -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $repo 'LICENSE'),(Join-Path $repo 'NOTICE') -Destination $stage
$index=New-Object System.Collections.Generic.List[string]
# Copy licence files found in a package (exact version); otherwise use the committed copies.
# $Always are committed full texts that are always added (e.g. the GPL text a package only refers to).
function Add-Licence([string]$Name,[string]$Root,[string]$Pattern,[string[]]$Committed,[string[]]$Always=@()) {
    $target=Join-Path $licences $Name
    New-Item -ItemType Directory -Path $target -Force | Out-Null
    $found=@()
    if ($Root -and $Pattern -and (Test-Path -LiteralPath $Root)) {
        $found=@(Get-ChildItem -LiteralPath $Root -Recurse -File -Depth 2 | Where-Object { $_.Name -match $Pattern })
    }
    foreach ($file in $found) { Copy-Item -LiteralPath $file.FullName -Destination (Join-Path $target $file.Name) -Force }
    $origin='from the bundled package'
    if (!$found) { $Always=@($Committed)+$Always; $origin='committed copy (the package ships no licence file)' }
    foreach ($file in $Always) { Copy-Item -LiteralPath (Join-Path $fallback $file) -Destination $target -Force }
    $version=if ($bundled.Contains($Name)) { $bundled[$Name].version } else { '' }
    $index.Add(('{0,-28} {1,-22} {2}' -f $Name,$version,$origin))
}
Add-Licence 'PHP' (Join-Path $runtime 'php') '^license\.txt$' @('PHP-LICENSE-3.01.txt')
Add-Licence 'Node.js' (Join-Path $runtime 'node') '^LICENSE$' @('Node.js-LICENSE.txt')
Add-Licence 'MariaDB' (Join-Path $runtime 'mariadb') '^(COPYING.*|THIRDPARTY|README-wsrep)$' @('MariaDB-COPYING.txt','MariaDB-THIRDPARTY.txt') @('GPL-2.0.txt')
Add-Licence 'nginx' (Join-Path $runtime 'nginx/docs') 'LICEN[CS]E' @('nginx-LICENSE.txt')
Add-Licence 'FFmpeg' (Join-Path $runtime 'ffmpeg') '^(LICENSE.*|README\.txt)$' @('FFmpeg-LICENSE.md') @('GPL-3.0.txt','LGPL-2.1.txt')
Add-Licence 'Transmission' $trLicences '.' @('Transmission-COPYING.txt') @('GPL-2.0.txt','GPL-3.0.txt','OpenSSL-LICENSE.txt','curl-COPYING.txt','zlib-LICENSE.txt')
Add-Licence 'WinSW' '' '' @('WinSW-LICENSE.txt')
Add-Licence 'curl CA bundle' '' '' @('cacert-NOTICE.txt','MPL-2.0.txt')
Add-Licence 'Visual C++ Redistributable' '' '' @('VC-Redist-TERMS.txt')
Add-Licence 'NSIS' '' '' @('NSIS-COPYING.txt')
# Multiplayer relay npm packages: each package's own licence file(s), plus a list with SPDX ids.
$npmTarget=Join-Path $licences 'netplay-npm'
New-Item -ItemType Directory -Path $npmTarget -Force | Out-Null
$npmList=New-Object System.Collections.Generic.List[string]
foreach ($manifestPath in Get-ChildItem -LiteralPath (Join-Path $stage 'app/netplay/node_modules') -Recurse -Filter package.json -File) {
    $packageDir=$manifestPath.Directory
    if ($packageDir.Parent.Name -ne 'node_modules' -and $packageDir.Parent.Parent.Name -ne 'node_modules') { continue }
    $meta=Get-Content -LiteralPath $manifestPath.FullName -Raw | ConvertFrom-Json
    if (!$meta.name -or !$meta.version) { continue }
    $licence=if ($meta.license -is [string]) { $meta.license } elseif ($meta.license.type) { $meta.license.type } else { 'see package' }
    $files=@(Get-ChildItem -LiteralPath $packageDir.FullName -File | Where-Object { $_.Name -match '^(LICEN[CS]E|COPYING|NOTICE)' })
    $dest=Join-Path $npmTarget ($meta.name -replace '/','__')
    if ($files) { New-Item -ItemType Directory -Path $dest -Force | Out-Null; $files | ForEach-Object { Copy-Item -LiteralPath $_.FullName -Destination $dest -Force } }
    $npmList.Add(('{0}@{1}  {2}{3}' -f $meta.name,$meta.version,$licence,$(if ($files) { '' } else { '  (no licence file in package; licence per package.json)' })))
}
$npmList.Sort()
Set-Content -LiteralPath (Join-Path $npmTarget 'PACKAGES.txt') -Encoding UTF8 -Value (@('Multiplayer relay npm packages bundled in this installer (name@version  licence):','')+$npmList)
$index.Add(('{0,-28} {1,-22} {2}' -f 'netplay-npm',"$($npmList.Count) packages",'each package''s own licence file (see PACKAGES.txt)'))
Set-Content -LiteralPath (Join-Path $licences 'INDEX.txt') -Encoding UTF8 -Value (@(
    "Third-party components bundled in Vaultcast $((Get-Content -LiteralPath (Join-Path $repo 'Application/VERSION') -Raw).Trim()) for Windows (built $(Get-Date -Format 'yyyy-MM-dd')).",
    'Each folder holds that component''s full licence text. Vaultcast''s own licence (LICENSE) does not apply to them; see NOTICE.','',
    ('{0,-28} {1,-22} {2}' -f 'Component','Version','Licence text')) + $index)
# Exact GPL versions and their corresponding source, recorded at build time.
$gpl=@(
    @{name='MariaDB'; licence='GPL-2.0'},
    @{name='FFmpeg'; licence='GPL-3.0 (GPL build; includes GPL libraries such as libx264/libx265)'},
    @{name='Transmission'; licence='GPL-2.0 or GPL-3.0'}
)
$sourceLines=New-Object System.Collections.Generic.List[string]
$sourceLines.Add('# Corresponding source for GPL components in this installer'); $sourceLines.Add('')
$sourceLines.Add("Recorded by build.ps1 on $(Get-Date -Format 'yyyy-MM-dd') for Vaultcast $((Get-Content -LiteralPath (Join-Path $repo 'Application/VERSION') -Raw).Trim()). These programs are unmodified upstream builds.")
$sourceLines.Add('')
foreach ($component in $gpl) {
    $entry=$bundled[$component.name]
    $sourceLines.Add("## $($component.name) $($entry.version)"); $sourceLines.Add('')
    $sourceLines.Add("- Licence: $($component.licence)")
    $sourceLines.Add("- Bundled binary: $($entry.url)")
    $sourceLines.Add("- Source: $($entry.source)")
    if ($entry.tag) { $sourceLines.Add("- Source tag: $($entry.tag)") }
    if ($entry.build) { $sourceLines.Add("- Build: $($entry.build)") }
    $sourceLines.Add('')
}
$sourceLines.Add((Get-Content -LiteralPath (Join-Path $fallback 'SOURCE-OFFER.txt') -Raw).TrimEnd())
Set-Content -LiteralPath (Join-Path $licences 'SOURCE.md') -Encoding UTF8 -Value $sourceLines
Remove-Item -LiteralPath $trLicences -Recurse -Force -ErrorAction SilentlyContinue

# ---- Compile ----
$version=(Get-Content -LiteralPath (Join-Path $repo 'Application/VERSION') -Raw).Trim()
$outputName=if ($UnsignedDevelopmentBuild) { "Vaultcast-$version-UNSIGNED-Development-x64.exe" } else { "Vaultcast-$version-Setup-x64.exe" }
$output=Join-Path ([IO.Path]::GetFullPath($OutputDirectory)) $outputName
$previousSigner=$env:VAULTCAST_SIGNING_SCRIPT
$env:VAULTCAST_SIGNING_SCRIPT=$SigningScript
$signHost=if ($onWindows) { Join-Path $env:SystemRoot 'System32/WindowsPowerShell/v1.0/powershell.exe' } else { '' }
$signWrapper=Join-Path $PSScriptRoot 'support/sign-file.ps1'
if (!$UnsignedDevelopmentBuild) {
    # Plugins execute from the installer's temporary folder and also need trusted signatures.
    $plugins=Join-Path $stage 'nsis-plugins'
    New-Item -ItemType Directory -Path $plugins -Force | Out-Null
    $compilerRoot=Split-Path $Compiler -Parent
    if ((Split-Path $compilerRoot -Leaf) -eq 'Bin') { $compilerRoot=Split-Path $compilerRoot -Parent }
    foreach ($name in @('System.dll','nsDialogs.dll','nsExec.dll')) {
        $plugin=Join-Path $compilerRoot "Plugins/x86-unicode/$name"
        if (!(Test-Path -LiteralPath $plugin)) { throw "NSIS Unicode plugin not found: $plugin. Use the actual NSIS installation's makensis.exe, not a launcher shim." }
        Copy-Item -LiteralPath $plugin -Destination $plugins
    }
    foreach ($file in Get-ChildItem -LiteralPath $stage -Recurse -File | Where-Object { $_.Extension -in '.exe','.dll','.pyd','.node' }) {
        & $signHost -NoProfile -NonInteractive -File $signWrapper -FilePath $file.FullName
        if ($LASTEXITCODE -ne 0) { throw "Release signing/verification failed: $($file.FullName)" }
    }
    $signCommand='"'+$signHost+'" -NoProfile -NonInteractive -File "'+$signWrapper+'" -FilePath "%1"'
    $directives="!uninstfinalize '$signCommand' = 0`n!finalize '$signCommand' = 0`n"
    [IO.File]::WriteAllText((Join-Path $stage 'signing.nsh'),$directives,[Text.UTF8Encoding]::new($false))
}
Push-Location $PSScriptRoot
try {
    # '-' switches work for makensis on every platform ('/' is a path on Linux).
    $buildFlags=@('-INPUTCHARSET','UTF8','-V3','-WX',"-DAPP_VERSION=$version","-DSTAGE_DIR=$stage","-DOUTPUT_FILE=$output")
    if ($UnsignedDevelopmentBuild) { $buildFlags+='-DALLOW_UNSIGNED_DEVELOPMENT_BUILD' }
    & $Compiler @buildFlags Vaultcast.nsi
    if ($LASTEXITCODE -ne 0) { throw 'NSIS compilation failed.' }
} finally { Pop-Location; $env:VAULTCAST_SIGNING_SCRIPT=$previousSigner }
if (!$UnsignedDevelopmentBuild) {
    $signature=Get-AuthenticodeSignature -LiteralPath $output
    if ($signature.Status -ne 'Valid') { throw 'The compiled installer does not have a valid signature.' }
} else { Write-Warning 'UNSIGNED DEVELOPMENT BUILD: not a release artifact; Application Control may block it.' }
Write-Host "Created $output"
