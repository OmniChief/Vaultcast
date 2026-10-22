; =============================================================================
; Vaultcast Windows installer (NSIS 3, Unicode, MUI2)
;
; Self-contained: the application, PHP, Node.js (with the multiplayer relay's
; node_modules), nginx, MariaDB, FFmpeg, WinSW, the CA bundle, the Visual C++
; runtime and Transmission are all packed in at build time by build.ps1.
; Nothing is downloaded and no PowerShell is run during install or uninstall.
;
; Build:  .\build.ps1            (recommended; stages files then runs makensis)
;   or:   makensis /INPUTCHARSET UTF8 /DSTAGE_DIR=<stage> Vaultcast.nsi
;
; Silent: Setup.exe /S [/D=C:\Path]   (/D must be last, unquoted)
;   Options: /PORT=9999 /PHPPORT=9000 /PHPWORKERS=4 /RELAYPORT=3100 /LISTEN=0.0.0.0
;            /EXTERNALDB /NODESKTOP /NOSTARTMENU /NOFFMPEG /NOTRANSMISSION
; =============================================================================

Unicode true
ManifestDPIAware true
SetCompressor /SOLID lzma
SetCompressorDictSize 64
RequestExecutionLevel admin   ; services, firewall and HKLM need elevation


!include "MUI2.nsh"
!include "nsDialogs.nsh"
!include "LogicLib.nsh"
!include "x64.nsh"
!include "FileFunc.nsh"
!include "WordFunc.nsh"
!include "TextFunc.nsh"
!include "Sections.nsh"

!include "include\Config.nsh"

; Solid LZMA is one stream: reading a file that sits behind the current position
; restarts decompression from the beginning. .onInit needs setup-helper.php, so
; reserve it at the front instead of making launch decompress ~800 MB to find it.
ReserveFile "support\setup-helper.php"
ReserveFile /plugin "nsDialogs.dll"
ReserveFile /plugin "System.dll"

; ---- General ----
Name "${PRODUCT_NAME}"
OutFile "${OUTPUT_FILE}"
; No InstallDir: .onInit picks ProgramData\Vaultcast, /D=..., or the previous install.
BrandingText "${PRODUCT_NAME} ${APP_VERSION} - ${PRODUCT_TAGLINE}"
ShowInstDetails show
ShowUninstDetails show

; ---- Version information (Explorer > Properties > Details) ----
VIProductVersion "${VER_MAJOR}.${VER_MINOR}.${VER_PATCH}.0"
VIFileVersion    "${VER_MAJOR}.${VER_MINOR}.${VER_PATCH}.0"
VIAddVersionKey "ProductName"     "${PRODUCT_NAME}"
VIAddVersionKey "ProductVersion"  "${APP_VERSION}"
VIAddVersionKey "CompanyName"     "${PRODUCT_PUBLISHER}"
VIAddVersionKey "FileDescription" "${PRODUCT_NAME} Setup"
VIAddVersionKey "FileVersion"     "${APP_VERSION}"
VIAddVersionKey "LegalCopyright"  "${PRODUCT_PUBLISHER}"

; ---- Variables ----
Var PreviousRoot      ; install root from an earlier installation, if any
Var PreviousVersion
Var Operation         ; "install" or "reset"
Var Advanced
Var External
Var WebPort
Var PhpPort
Var PhpWorkers   ; number of php-cgi services (1..PHP_WORKERS_MAX)
Var PhpPorts     ; their ports, space separated, first = $PhpPort
Var SvcPath      ; PATH for services (InstallAppService uses $0-$9)
Var RelayPort
Var DbPort
Var ListenAddress
Var ReservedPorts     ; "|9999|9000|" style list of ports already taken by us
Var Runtime           ; $INSTDIR\data\runtime
Var Php               ; bundled php.exe
Var AppUrl
Var DbDepend
Var ResetUser
Var ResetPassword
; Transmission service settings
Var TrPort
Var TrPeerPort
Var TrUser
Var TrPassword
Var TrDownloads
; WinSW service parameters
Var SvcId
Var SvcName
Var SvcExe
Var SvcArgs
Var SvcDir
Var SvcExtra
Var SvcStopArgs
; dialog handles
Var hExpress
Var hAdvanced
Var hWebPort
Var hPhpPort
Var hPhpWorkers
Var hRelayPort
Var hListen
Var hExternal
Var hOpInstall
Var hOpReset
Var hResetUser
Var hResetPass
Var hResetConfirm

!include "include\Helpers.nsh"
!insertmacro DEFINE_SERVICE_FUNCTIONS ""
!insertmacro DEFINE_SERVICE_FUNCTIONS "un."

; ---- Interface ----
!define MUI_ABORTWARNING
!define MUI_ICON   "branding\vaultcast.ico"
!define MUI_UNICON "branding\vaultcast.ico"
!define MUI_HEADERIMAGE
!define MUI_HEADERIMAGE_RIGHT
!define MUI_HEADERIMAGE_BITMAP   "branding\header.bmp"
!define MUI_HEADERIMAGE_UNBITMAP "branding\header.bmp"
!define MUI_WELCOMEFINISHPAGE_BITMAP   "branding\wizard.bmp"
!define MUI_UNWELCOMEFINISHPAGE_BITMAP "branding\wizard.bmp"
!define MUI_COMPONENTSPAGE_SMALLDESC

; ---- Installer pages ----
!define MUI_WELCOMEPAGE_TITLE "Welcome to ${PRODUCT_NAME} ${APP_VERSION}"
!define MUI_WELCOMEPAGE_TEXT "${PRODUCT_NAME} turns this PC into a private home for your films, shows, music and games, available on every screen in your home.$\r$\n$\r$\nSetup installs everything it needs and runs it as Windows services, so your library is ready whenever this PC is on. No internet connection is required.$\r$\n$\r$\nClick Next to continue."
!insertmacro MUI_PAGE_WELCOME
; Vaultcast's own licence. Bundled programs keep their own licences (NOTICE, licenses\).
!define MUI_PAGE_CUSTOMFUNCTION_PRE SkipIfReset
!define MUI_LICENSEPAGE_TEXT_TOP "${PRODUCT_NAME}'s own code is licensed under these terms."
!define MUI_LICENSEPAGE_TEXT_BOTTOM "This licence covers ${PRODUCT_NAME}'s own code only. The bundled programs (PHP, Node.js, MariaDB, nginx, FFmpeg, Transmission and others) keep their own licences, which are installed with NOTICE in the licenses folder. Click I Agree to continue."
!insertmacro MUI_PAGE_LICENSE "${STAGE_DIR}\LICENSE"
Page custom OptionsPageCreate OptionsPageLeave
Page custom ResetPageCreate ResetPageLeave
!define MUI_PAGE_CUSTOMFUNCTION_PRE SkipIfReset
!insertmacro MUI_PAGE_COMPONENTS
!define MUI_PAGE_CUSTOMFUNCTION_PRE DirectoryPre
!define MUI_PAGE_CUSTOMFUNCTION_LEAVE DirectoryLeave
!define MUI_DIRECTORYPAGE_TEXT_TOP "Vaultcast keeps its programs and library database in this folder. Your media can live anywhere."
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES
!define MUI_PAGE_CUSTOMFUNCTION_PRE FinishPre
!define MUI_FINISHPAGE_TITLE "${PRODUCT_NAME} is ready"
!define MUI_FINISHPAGE_TEXT "Your library starts automatically with Windows, even before anyone signs in.$\r$\n$\r$\nOpen ${PRODUCT_NAME} to name your library, create the administrator account and add your media."
!define MUI_FINISHPAGE_RUN
!define MUI_FINISHPAGE_RUN_TEXT "Open ${PRODUCT_NAME} now"
!define MUI_FINISHPAGE_RUN_FUNCTION LaunchApp
!define MUI_FINISHPAGE_NOREBOOTSUPPORT
!insertmacro MUI_PAGE_FINISH

; ---- Uninstaller pages ----
!insertmacro MUI_UNPAGE_WELCOME
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_UNPAGE_FINISH

!insertmacro MUI_LANGUAGE "English"

!include "include\Pages.nsh"
!include "include\Configure.nsh"

; =============================================================================
; Sections
; =============================================================================

Section "${PRODUCT_NAME}" SEC_CORE
  SectionIn RO
  ${If} $Operation == "reset"
    Return
  ${EndIf}
  ; Validate command-line ports before stopping services or replacing any files.
  StrCpy $R0 $WebPort
  Call ValidatePort
  ${If} $0 != ""
    !insertmacro Fail "$0"
  ${EndIf}
  StrCpy $R0 $PhpPort
  Call ValidatePort
  ${If} $0 != ""
    !insertmacro Fail "$0"
  ${EndIf}
  StrCpy $R0 $RelayPort
  Call ValidatePort
  ${If} $0 != ""
    !insertmacro Fail "$0"
  ${EndIf}
  StrCpy $Runtime "$INSTDIR\data\runtime"
  StrCpy $Php "$Runtime\${RT_PHP}\php.exe"

  ; Upgrade: stop the services of this installation before replacing files.
  DetailPrint "Preparing ${PRODUCT_NAME} ${APP_VERSION}..."
  StrCpy $R8 $INSTDIR
  Call StopOwnedServices

  ; Run first: its data sits at the start of the solid archive, so this keeps
  ; decompression strictly forward (see ReserveFile note at the top).
  Call InstallVCRuntime

  DetailPrint "Copying ${PRODUCT_NAME} application files..."

  ; Application files. Updates never replace data\ or configuration.
  SetOutPath "$INSTDIR"
  File /r /x "data" /x "config.php" /x ".install-*" "${STAGE_DIR}\app\*.*"

  ; Licences: Vaultcast's LICENSE and NOTICE, and each bundled component's licence
  ; texts plus the GPL source information (licenses\SOURCE.md). Replaced on update.
  File "${STAGE_DIR}\LICENSE"
  File "${STAGE_DIR}\NOTICE"
  RMDir /r "$INSTDIR\licenses"
  SetOutPath "$INSTDIR\licenses"
  File /r "${STAGE_DIR}\licenses\*.*"
  SetOutPath "$INSTDIR"

  ; Bundled runtimes.
  DetailPrint "Unpacking PHP, Node.js, nginx and MariaDB..."
  CreateDirectory "$Runtime\services"
  CreateDirectory "$Runtime\logs"
  CreateDirectory "$INSTDIR\data\sessions"
  CreateDirectory "$INSTDIR\data\tmp"
  SetOutPath "$Runtime\${RT_PHP}"
  File /r "${STAGE_DIR}\runtime\php\*.*"
  SetOutPath "$Runtime\${RT_NODE}"
  File /r "${STAGE_DIR}\runtime\node\*.*"
  SetOutPath "$Runtime\${RT_NGINX}"
  File /r "${STAGE_DIR}\runtime\nginx\*.*"
  SetOutPath "$Runtime\${RT_MARIADB}"
  File /r "${STAGE_DIR}\runtime\mariadb\*.*"
  SetOutPath "$Runtime"
  File "${STAGE_DIR}\runtime\WinSW.exe"
  File "${STAGE_DIR}\runtime\cacert.pem"
  File "/oname=vaultcast.ico" "branding\vaultcast.ico"
  FileOpen $9 "$Runtime\setup-owner.txt" w
  FileWrite $9 "Vaultcast ${APP_VERSION}"
  FileClose $9

  ; Leftovers of the previous PowerShell-based installer.
  Delete "$Runtime\bootstrap.ps1"
  Delete "$INSTDIR\Uninstall.exe"
SectionEnd

Section "FFmpeg media tools" SEC_FFMPEG
  ${If} $Operation == "reset"
    Return
  ${EndIf}
  DetailPrint "Unpacking FFmpeg..."
  SetOutPath "$INSTDIR\data\runtime\${RT_FFMPEG}"
  File /r "${STAGE_DIR}\runtime\ffmpeg\*.*"
SectionEnd

; Vaultcast's own headless Transmission daemon (not the desktop app). It is
; configured and connected to Vaultcast automatically in -Configure.
Section "Transmission downloads" SEC_TRANSMISSION
  ${If} $Operation == "reset"
    Return
  ${EndIf}
  DetailPrint "Unpacking the Transmission download service..."
  SetOutPath "$INSTDIR\data\runtime\${RT_TRANSMISSION}"
  File /r "${STAGE_DIR}\runtime\transmission\*.*"
SectionEnd

; Configuration and services (hidden; runs after the files above).
Section "-Configure" SEC_CONFIGURE
  ${If} $Operation == "reset"
    Call ResetAdministrator
    Return
  ${EndIf}

  ; Folder permissions: SYSTEM/Administrators full, LocalService read; the
  ; services may write data, but not the executables they run.
  DetailPrint "Setting folder permissions..."
  !insertmacro RunChecked '"$SYSDIR\icacls.exe" "$INSTDIR" /inheritance:r /grant:r ${SID_SYSTEM}:(OI)(CI)F ${SID_ADMINS}:(OI)(CI)F ${SID_LOCALSERVICE}:(OI)(CI)RX /Q' \
    "Could not set permissions on $INSTDIR."
  !insertmacro GrantServiceWrite "$INSTDIR\data"
  !insertmacro RunChecked '"$SYSDIR\icacls.exe" "$Runtime" /inheritance:r /grant:r ${SID_SYSTEM}:(OI)(CI)F ${SID_ADMINS}:(OI)(CI)F ${SID_LOCALSERVICE}:(OI)(CI)RX /Q' \
    "Could not set permissions on $Runtime."
  !insertmacro GrantServiceWrite "$Runtime\logs"

  ; Older releases kept config.php in the install root.
  ${If} ${FileExists} "$INSTDIR\config.php"
    ${If} ${FileExists} "$INSTDIR\data\config.php"
      !insertmacro Fail "This folder has two database configurations. Keep the one for your library and rerun setup; neither was changed."
    ${EndIf}
    Rename "$INSTDIR\config.php" "$INSTDIR\data\config.php"
  ${EndIf}
  WriteRegStr HKLM "${PRODUCT_REGKEY}" "InstallPath" "$INSTDIR"

  ; Ports. The library port must be exactly what was asked for; private ports
  ; move up to the next free one in Express mode.
  StrCpy $ReservedPorts "|"
  ReadINIStr $0 "$Runtime\database.ini" "database" "port"
  ${If} $0 != ""
    StrCpy $ReservedPorts "|$0|"
  ${EndIf}
  ; Kept Transmission ports (upgrade) stay reserved too.
  ReadINIStr $0 "$Runtime\transmission.ini" "transmission" "port"
  ReadINIStr $1 "$Runtime\transmission.ini" "transmission" "peer_port"
  ${If} $0 != ""
    StrCpy $ReservedPorts "$ReservedPorts$0|$1|"
  ${EndIf}
  !insertmacro PhpHelper 'ipv4 $ListenAddress'
  ${If} $0 != 0
    !insertmacro Fail "Enter an IPv4 listen address such as 0.0.0.0 or 127.0.0.1."
  ${EndIf}
  StrCpy $R0 $WebPort
  StrCpy $R1 1
  StrCpy $R2 $ListenAddress
  Call ChoosePort
  StrCpy $WebPort $R0
  StrCpy $R1 $Advanced
  StrCpy $R2 "127.0.0.1"
  StrCpy $R0 $PhpPort
  Call ChoosePort
  StrCpy $PhpPort $R0
  ; Further php-cgi workers take the next free loopback ports after the first.
  StrCpy $PhpPorts $PhpPort
  ${ForEach} $3 2 $PhpWorkers + 1
    IntOp $R0 $R0 + 1
    Call ChoosePort
    StrCpy $PhpPorts "$PhpPorts $R0"
  ${Next}
  StrCpy $R0 $RelayPort
  Call ChoosePort
  StrCpy $RelayPort $R0

  Call WritePhpIni
  Call PrepareDatabase
  Call WriteNginxConf
  ; Add the rule before nginx starts listening on the network.
  Call ConfigureFirewall
  !insertmacro GrantServiceWrite "$Runtime\${RT_NGINX}\logs"

  ; PATH for services: bundled runtimes first, then the machine PATH.
  ReadRegStr $1 HKLM "SYSTEM\CurrentControlSet\Control\Session Manager\Environment" "Path"
  StrCpy $2 "$Runtime\${RT_PHP};$Runtime\${RT_NODE}"
  ${If} ${SectionIsSelected} ${SEC_FFMPEG}
    StrCpy $2 "$2;$Runtime\${RT_FFMPEG}\bin"
  ${EndIf}
  StrCpy $2 "$2;$1"
  StrCpy $SvcPath $2

  ; php-cgi pool: one single-process service per port; nginx balances across them
  ; (upstream vaultcast_php), so one long request no longer blocks the others.
  ${ForEach} $R5 1 $PhpWorkers + 1
    ${WordFind} "$PhpPorts" " " "+$R5" $R4
    ${If} $R5 == 1
      StrCpy $SvcId "VaultcastPHP"
      StrCpy $SvcName "Vaultcast PHP"
    ${Else}
      StrCpy $SvcId "VaultcastPHP$R5"
      StrCpy $SvcName "Vaultcast PHP $R5"
    ${EndIf}
    StrCpy $SvcExe "$Runtime\${RT_PHP}\php-cgi.exe"
    StrCpy $SvcArgs '-c "$Runtime\php.ini" -b 127.0.0.1:$R4'
    StrCpy $SvcDir $INSTDIR
    StrCpy $SvcStopArgs ""
    StrCpy $SvcExtra $DbDepend
    !insertmacro SvcEnv "PATH" $SvcPath
    !insertmacro SvcEnv "PHP_FCGI_MAX_REQUESTS" "0"
    Call InstallAppService
  ${Next}
  ; Fewer workers than before (Update/repair): remove the surplus services.
  Call RemoveSurplusPhpWorkers

  StrCpy $SvcId "VaultcastNetplay"
  StrCpy $SvcName "Vaultcast Multiplayer"
  StrCpy $SvcExe "$Runtime\${RT_NODE}\node.exe"
  StrCpy $SvcArgs '"$INSTDIR\netplay\server.js"'
  StrCpy $SvcExtra ""
  !insertmacro SvcEnv "PATH" $SvcPath
  !insertmacro SvcEnv "PORT" $RelayPort
  !insertmacro SvcEnv "HOST" "127.0.0.1"
  !insertmacro SvcEnv "NETPLAY_LOG" "$INSTDIR\data\netplay.log"
  Call InstallAppService

  StrCpy $3 "$Runtime\${RT_NGINX}"
  !insertmacro ForwardSlashes $3
  StrCpy $SvcId "VaultcastWeb"
  StrCpy $SvcName "Vaultcast Web"
  StrCpy $SvcExe "$Runtime\${RT_NGINX}\nginx.exe"
  StrCpy $SvcArgs '-p "$3/" -c conf/vaultcast.conf'
  StrCpy $SvcStopArgs '-p "$3/" -c conf/vaultcast.conf -s quit'
  StrCpy $SvcDir "$Runtime\${RT_NGINX}"
  StrCpy $SvcExtra '  <depend>VaultcastPHP</depend>$\r$\n  <depend>VaultcastNetplay</depend>$\r$\n'
  Call InstallAppService

  ${If} ${SectionIsSelected} ${SEC_TRANSMISSION}
    Call ConfigureTransmission
  ${EndIf}

  Call WriteSetupJson
  Call WaitForServices

  ; Internet shortcut used by the Start menu / desktop entries and the finish page.
  ${If} $ListenAddress == "0.0.0.0"
  ${OrIf} $ListenAddress == "127.0.0.1"
    StrCpy $AppUrl "http://localhost:$WebPort/"
  ${Else}
    StrCpy $AppUrl "http://$ListenAddress:$WebPort/"
  ${EndIf}
  WriteINIStr "$Runtime\${PRODUCT_NAME}.url" "InternetShortcut" "URL" "$AppUrl"
  WriteINIStr "$Runtime\${PRODUCT_NAME}.url" "InternetShortcut" "IconFile" "$Runtime\vaultcast.ico"
  WriteINIStr "$Runtime\${PRODUCT_NAME}.url" "InternetShortcut" "IconIndex" "0"
  DetailPrint "${PRODUCT_NAME} is running at $AppUrl"
SectionEnd

Section "Start menu shortcut" SEC_STARTMENU
  ${If} $Operation == "reset"
    Return
  ${EndIf}
  CopyFiles /SILENT "$Runtime\${PRODUCT_NAME}.url" "$SMPROGRAMS\${PRODUCT_NAME}.url"
SectionEnd

Section "Desktop shortcut" SEC_DESKTOP
  ${If} $Operation == "reset"
    Return
  ${EndIf}
  CopyFiles /SILENT "$Runtime\${PRODUCT_NAME}.url" "$DESKTOP\${PRODUCT_NAME}.url"
SectionEnd

; Uninstaller and Add/Remove Programs entry (hidden).
Section "-Register"
  ${If} $Operation == "reset"
    Return
  ${EndIf}
  ; A deselected shortcut on update means "remove it".
  ${IfNot} ${SectionIsSelected} ${SEC_STARTMENU}
    Delete "$SMPROGRAMS\${PRODUCT_NAME}.url"
  ${EndIf}
  ${IfNot} ${SectionIsSelected} ${SEC_DESKTOP}
    Delete "$DESKTOP\${PRODUCT_NAME}.url"
  ${EndIf}

  WriteUninstaller "$INSTDIR\Uninstall.exe"
  !define ARP "${UNINSTALL_REGKEY}"
  WriteRegStr   HKLM "${ARP}" "DisplayName"          "${PRODUCT_NAME}"
  WriteRegStr   HKLM "${ARP}" "DisplayIcon"          "$Runtime\vaultcast.ico"
  WriteRegStr   HKLM "${ARP}" "DisplayVersion"       "${APP_VERSION}"
  WriteRegStr   HKLM "${ARP}" "Publisher"            "${PRODUCT_PUBLISHER}"
  WriteRegStr   HKLM "${ARP}" "InstallLocation"      "$INSTDIR"
  WriteRegStr   HKLM "${ARP}" "UninstallString"      '"$INSTDIR\Uninstall.exe"'
  WriteRegStr   HKLM "${ARP}" "QuietUninstallString" '"$INSTDIR\Uninstall.exe" /S'
  WriteRegStr   HKLM "${ARP}" "URLInfoAbout"         "$AppUrl"
  WriteRegDWORD HKLM "${ARP}" "VersionMajor"         ${VER_MAJOR}
  WriteRegDWORD HKLM "${ARP}" "VersionMinor"         ${VER_MINOR}
  WriteRegDWORD HKLM "${ARP}" "NoModify"             1
  WriteRegDWORD HKLM "${ARP}" "NoRepair"             1
  ; Size of programs and runtimes, excluding the library database.
  ${GetSize} "$INSTDIR" "/S=0K /G=1" $0 $1 $2
  ${GetSize} "$INSTDIR\data\database" "/S=0K /G=1" $3 $1 $2
  IntOp $0 $0 - $3
  WriteRegDWORD HKLM "${ARP}" "EstimatedSize" $0
  !undef ARP
SectionEnd

; ---- Component descriptions ----
!insertmacro MUI_FUNCTION_DESCRIPTION_BEGIN
  !insertmacro MUI_DESCRIPTION_TEXT ${SEC_CORE}         "The ${PRODUCT_NAME} application with its bundled PHP, Node.js, nginx and MariaDB, running as Windows services."
  !insertmacro MUI_DESCRIPTION_TEXT ${SEC_FFMPEG}       "Media analysis and offline conversion. Recommended."
  !insertmacro MUI_DESCRIPTION_TEXT ${SEC_TRANSMISSION} "A private Transmission service, set up and connected to ${PRODUCT_NAME} automatically for the optional Downloads feature."
  !insertmacro MUI_DESCRIPTION_TEXT ${SEC_STARTMENU}    "Add ${PRODUCT_NAME} to the Start menu."
  !insertmacro MUI_DESCRIPTION_TEXT ${SEC_DESKTOP}      "Add a ${PRODUCT_NAME} shortcut to the desktop."
!insertmacro MUI_FUNCTION_DESCRIPTION_END

; =============================================================================
; Initialisation
; =============================================================================

Function .onInit
  ${IfNot} ${RunningX64}
    MessageBox MB_ICONSTOP "${PRODUCT_NAME} requires 64-bit Windows." /SD IDOK
    Abort
  ${EndIf}
  ${If} ${IsNativeARM64}
    MessageBox MB_ICONSTOP "${PRODUCT_NAME} requires x64 Windows; ARM64 is not supported." /SD IDOK
    Abort
  ${EndIf}
  SetRegView 64
  SetShellVarContext all
  ${DisableX64FSRedirection}

  ; Only one setup at a time.
  System::Call 'kernel32::CreateMutex(p 0, i 0, t "VaultcastSetupMutex") p .r1 ?e'
  Pop $0
  ${If} $0 == 183
    MessageBox MB_ICONEXCLAMATION "${PRODUCT_NAME} setup is already running." /SD IDOK
    Abort
  ${EndIf}

  ; Defaults (Express).
  StrCpy $Operation "install"
  StrCpy $Advanced 0
  StrCpy $External 0
  StrCpy $WebPort 9999
  StrCpy $PhpPort 9000
  StrCpy $PhpWorkers ${PHP_WORKERS_DEFAULT}
  StrCpy $RelayPort 3100
  StrCpy $ListenAddress "0.0.0.0"

  ; Default folder: C:\ProgramData\Vaultcast ($APPDATA in all-users context), unless /D= was given.
  ${If} $INSTDIR == ""
    StrCpy $INSTDIR "$APPDATA\${PRODUCT_NAME}"
  ${EndIf}

  ; Upgrade detection: reuse the previous folder and say what will happen.
  ReadRegStr $PreviousRoot HKLM "${PRODUCT_REGKEY}" "InstallPath"
  ReadRegStr $PreviousVersion HKLM "${UNINSTALL_REGKEY}" "DisplayVersion"
  ${If} $PreviousRoot != ""
    StrCpy $INSTDIR $PreviousRoot
    ${If} $PreviousVersion != ""
      ${VersionCompare} "$PreviousVersion" "${APP_VERSION}" $0
      ${If} $0 == 1
        MessageBox MB_ICONQUESTION|MB_YESNO "A newer ${PRODUCT_NAME} ($PreviousVersion) is installed. Replace it with ${APP_VERSION}?" /SD IDYES IDYES +2
        Abort
      ${EndIf}
    ${EndIf}
  ${EndIf}

  ; Command-line options (mainly for /S).
  ${GetParameters} $R0
  ClearErrors
  ${GetOptions} $R0 "/PORT=" $0
  ${IfNot} ${Errors}
    StrCpy $WebPort $0
    StrCpy $Advanced 1
  ${EndIf}
  ClearErrors
  ${GetOptions} $R0 "/PHPPORT=" $0
  ${IfNot} ${Errors}
    StrCpy $PhpPort $0
    StrCpy $Advanced 1
  ${EndIf}
  ClearErrors
  ClearErrors
  ${GetOptions} $R0 "/PHPWORKERS=" $0
  ${IfNot} ${Errors}
    ${If} $0 >= 1
    ${AndIf} $0 <= ${PHP_WORKERS_MAX}
      StrCpy $PhpWorkers $0
      StrCpy $Advanced 1
    ${EndIf}
  ${EndIf}
  ${GetOptions} $R0 "/RELAYPORT=" $0
  ${IfNot} ${Errors}
    StrCpy $RelayPort $0
    StrCpy $Advanced 1
  ${EndIf}
  ClearErrors
  ${GetOptions} $R0 "/LISTEN=" $0
  ${IfNot} ${Errors}
    StrCpy $ListenAddress $0
  ${EndIf}
  ClearErrors
  ${GetOptions} $R0 "/EXTERNALDB" $0
  ${IfNot} ${Errors}
    StrCpy $External 1
  ${EndIf}
  ClearErrors
  ${GetOptions} $R0 "/NODESKTOP" $0
  ${IfNot} ${Errors}
    !insertmacro UnselectSection ${SEC_DESKTOP}
  ${EndIf}
  ClearErrors
  ${GetOptions} $R0 "/NOSTARTMENU" $0
  ${IfNot} ${Errors}
    !insertmacro UnselectSection ${SEC_STARTMENU}
  ${EndIf}
  ClearErrors
  ${GetOptions} $R0 "/NOFFMPEG" $0
  ${IfNot} ${Errors}
    !insertmacro UnselectSection ${SEC_FFMPEG}
  ${EndIf}
  ClearErrors
  ${GetOptions} $R0 "/NOTRANSMISSION" $0
  ${IfNot} ${Errors}
    !insertmacro UnselectSection ${SEC_TRANSMISSION}
  ${EndIf}
  ; /DESKTOP and /TRANSMISSION are still accepted; both are now the default.

  ; Silent installs validate the folder here, since no page runs.
  ${If} ${Silent}
    Call ValidateInstallDir
    ${If} $0 != ""
      SetErrorLevel 2
      Abort
    ${EndIf}
  ${EndIf}

  InitPluginsDir
  File "/oname=$PLUGINSDIR\setup-helper.php" "support\setup-helper.php"
FunctionEnd

Function .onInstFailed
  IfSilent +2
  MessageBox MB_ICONSTOP "Setup did not finish. Nothing in your library was removed. Review the details above and run setup again to resume."
FunctionEnd

!include "include\Uninstall.nsh"
