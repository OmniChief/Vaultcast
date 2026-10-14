; =============================================================================
; Uninstall.nsh - uninstaller. Removes services, firewall rule, shortcuts,
; application files and bundled runtimes. The data folder (database, settings,
; logs) is kept for recovery and media folders are never touched.
; =============================================================================

Function un.onInit
  SetRegView 64
  SetShellVarContext all
  ${DisableX64FSRedirection}
  ; Prefer the registered install root over the uninstaller's own folder.
  ReadRegStr $0 HKLM "${PRODUCT_REGKEY}" "InstallPath"
  ${If} $0 != ""
  ${AndIf} ${FileExists} "$0\*.*"
    StrCpy $INSTDIR $0
  ${EndIf}
FunctionEnd

Section "Uninstall"
  SetRegView 64
  SetShellVarContext all
  StrCpy $R8 $INSTDIR

  ; 1. Stop and unregister our services (never someone else's).
  Call un.StopOwnedServices
  ${ForEachServiceId} un.UnregisterService

  ; 2. Firewall rule and shortcuts.
  nsExec::ExecToLog '"$SYSDIR\netsh.exe" advfirewall firewall delete rule name="${FIREWALL_RULE}"'
  Pop $0
  nsExec::ExecToLog '"$SYSDIR\netsh.exe" advfirewall firewall delete rule name="${FIREWALL_RULE_DOWNLOADS}"'
  Pop $0
  Delete "$SMPROGRAMS\${PRODUCT_NAME}.url"
  Delete "$DESKTOP\${PRODUCT_NAME}.url"

  ; 3. Application files (named entries only).
  RMDir /r "$INSTDIR\assets"
  RMDir /r "$INSTDIR\src"
  RMDir /r "$INSTDIR\deploy"
  RMDir /r "$INSTDIR\docs"
  RMDir /r "$INSTDIR\netplay"
  Delete "$INSTDIR\index.php"
  Delete "$INSTDIR\manifest.json"
  Delete "$INSTDIR\sw.js"
  Delete "$INSTDIR\VERSION"
  Delete "$INSTDIR\LICENSE"
  Delete "$INSTDIR\NOTICE"
  RMDir /r "$INSTDIR\licenses"

  ; 4. Bundled runtimes and generated runtime files. Logs, database.ini, transmission.ini,
  ;    setup.json and data\database stay so the library can be recovered.
  StrCpy $1 "$INSTDIR\data\runtime"
  RMDir /r "$1\${RT_PHP}"
  RMDir /r "$1\${RT_NODE}"
  RMDir /r "$1\${RT_NGINX}"
  RMDir /r "$1\${RT_MARIADB}"
  RMDir /r "$1\${RT_FFMPEG}"
  RMDir /r "$1\${RT_TRANSMISSION}"
  RMDir /r "$1\services"
  Delete "$1\WinSW.exe"
  Delete "$1\cacert.pem"
  Delete "$1\php.ini"
  Delete "$1\vaultcast.ico"
  Delete "$1\${PRODUCT_NAME}.url"

  ; 5. Registry. HKLM\SOFTWARE\Vaultcast\InstallPath is kept so a later install
  ;    finds the retained data folder.
  DeleteRegKey HKLM "${UNINSTALL_REGKEY}"
  Delete "$INSTDIR\Uninstall.exe"
  RMDir "$INSTDIR" ; only if empty

  IfSilent +2
  MessageBox MB_ICONINFORMATION "Vaultcast was removed.$\r$\n$\r$\nYour database and settings remain in $INSTDIR\data for recovery. Your media folders were not changed."
SectionEnd

; In: $R9 service id, $R8 install root.
Function un.UnregisterService
  Call un.ServiceOwnership
  ${If} $R7 == "ours"
    DetailPrint "Removing service $R9..."
    nsExec::ExecToLog '"$R8\data\runtime\services\$R9.exe" uninstall'
    Pop $0
    ${If} $0 != 0
      ; Fall back to the Windows service manager directly.
      nsExec::ExecToLog '"$SYSDIR\sc.exe" delete $R9'
      Pop $0
    ${EndIf}
  ${EndIf}
FunctionEnd
