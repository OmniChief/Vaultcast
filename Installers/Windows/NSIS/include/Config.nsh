; =============================================================================
; Config.nsh - product identity, versioning and build-time inputs
; =============================================================================

!define PRODUCT_NAME      "Vaultcast"
!define PRODUCT_PUBLISHER "Vaultcast"
!define PRODUCT_TAGLINE   "Your collection, your space"
!define PRODUCT_REGKEY    "SOFTWARE\Vaultcast"
!define UNINSTALL_REGKEY  "Software\Microsoft\Windows\CurrentVersion\Uninstall\Vaultcast"
!define FIREWALL_RULE     "Vaultcast library"

; Version comes from Application/VERSION unless the build passes /DAPP_VERSION=x.y.z.
!ifndef APP_VERSION
  !searchparse /file "..\..\..\Application\VERSION" "" APP_VERSION
!endif
!searchparse /noerrors "${APP_VERSION}" "" VER_MAJOR "." VER_MINOR "." VER_PATCH
!ifndef VER_PATCH
  !error "APP_VERSION must look like 1.2.3 (got '${APP_VERSION}')."
!endif

; STAGE_DIR is the folder build.ps1 prepares: app\ (application with netplay\node_modules),
; runtime\ (php, node, nginx, mariadb, ffmpeg, WinSW.exe, cacert.pem) and
; redist\ (vc_redist.x64.exe); runtime\transmission holds transmission-daemon. Every file is unpacked by NSIS;
; nothing is downloaded at install time.
!ifndef STAGE_DIR
  !define STAGE_DIR "build\stage"
!endif

; Bundled redistributable versions, so already-installed copies can be skipped.
!getdllversion "${STAGE_DIR}\redist\vc_redist.x64.exe" VCREDIST_

!ifndef OUTPUT_FILE
  !define OUTPUT_FILE "${PRODUCT_NAME}-${APP_VERSION}-Setup-x64.exe"
!endif

; Bundled runtime folder names under <install>\data\runtime.
!define RT_PHP     "php-x64"
!define RT_NODE    "node"
!define RT_NGINX   "nginx"
!define RT_MARIADB "mariadb"
!define RT_FFMPEG  "ffmpeg"
!define RT_TRANSMISSION "transmission"

; Windows services, in start order. Each is a copy of WinSW named after its id.
; php-cgi pool: VaultcastPHP (first port) plus VaultcastPHP2..N on the next free loopback ports.
!define PHP_WORKERS_DEFAULT 4
!define PHP_WORKERS_MAX 16
!define SERVICE_IDS "VaultcastDatabase VaultcastPHP VaultcastNetplay VaultcastWeb VaultcastTransmission"
!define FIREWALL_RULE_DOWNLOADS "Vaultcast downloads"

; Well-known SIDs used with icacls.
!define SID_SYSTEM        "*S-1-5-18"
!define SID_ADMINS        "*S-1-5-32-544"
!define SID_LOCALSERVICE  "*S-1-5-19"
