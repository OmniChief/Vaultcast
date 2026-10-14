; =============================================================================
; Helpers.nsh - small reusable macros and functions (no PowerShell anywhere)
; =============================================================================

; Run a command, write its output to the details log and abort on a non-zero exit.
; Usage: !insertmacro RunChecked '"$SYSDIR\sc.exe" ...' "Message shown on failure"
!macro RunChecked COMMAND FAILMSG
  nsExec::ExecToLog `${COMMAND}`
  Pop $0
  ${If} $0 != 0
    DetailPrint "Exit code: $0"
    !insertmacro Fail "${FAILMSG}"
  ${EndIf}
!macroend

; Run a command quietly and leave its exit code in $0 (output is discarded,
; which is what we want for anything carrying a password).
!macro RunQuiet COMMAND
  nsExec::Exec `${COMMAND}`
  Pop $0
!macroend

; Stop installation with a clear message (no dialog in silent mode).
!macro Fail MESSAGE
  DetailPrint "Setup stopped: ${MESSAGE}"
  MessageBox MB_ICONSTOP|MB_OK "${MESSAGE}" /SD IDOK
  SetErrorLevel 2
  Abort
!macroend

; Run the PHP setup helper; exit code in $0, printed output in $1.
; Usage: !insertmacro PhpHelper 'secret'
!macro PhpHelper ARGS
  nsExec::ExecToStack `"$Php" -n "$PLUGINSDIR\setup-helper.php" ${ARGS}`
  Pop $0
  Pop $1
!macroend

; Escape a string for use inside XML text or attribute values.
!macro XmlEscape VAR
  ${WordReplace} "${VAR}" "&" "&amp;" "+" ${VAR}
  ${WordReplace} "${VAR}" "<" "&lt;" "+" ${VAR}
  ${WordReplace} "${VAR}" ">" "&gt;" "+" ${VAR}
  ${WordReplace} "${VAR}" '"' "&quot;" "+" ${VAR}
!macroend

; Turn back slashes into forward slashes (php.ini, nginx.conf).
!macro ForwardSlashes VAR
  ${WordReplace} "${VAR}" "\" "/" "+" ${VAR}
!macroend

; Grant LocalService modify rights on a folder (creating it first).
!macro GrantServiceWrite FOLDER
  CreateDirectory "${FOLDER}"
  nsExec::ExecToLog '"$SYSDIR\icacls.exe" "${FOLDER}" /grant ${SID_LOCALSERVICE}:(OI)(CI)M /Q'
  Pop $0
!macroend

; -----------------------------------------------------------------------------
; Service ownership / stop helpers, shared by installer and uninstaller.
; A Vaultcast service is only touched when its executable is the WinSW copy inside
; this installation, so another Vaultcast folder or unrelated software is safe.
; -----------------------------------------------------------------------------
!macro DEFINE_SERVICE_FUNCTIONS PREFIX
; In: $R9 service id, $R8 install root. Out: $R7 = "none" | "ours" | "foreign".
Function ${PREFIX}ServiceOwnership
  ClearErrors
  ReadRegStr $R7 HKLM "SYSTEM\CurrentControlSet\Services\$R9" "ImagePath"
  ${If} ${Errors}
  ${OrIf} $R7 == ""
    StrCpy $R7 "none"
    Return
  ${EndIf}
  ${WordReplace} $R7 '"' "" "+" $R7
  ${TrimNewLines} $R7 $R7
  ${If} $R7 == "$R8\data\runtime\services\$R9.exe"   ; StrCmp is case-insensitive
    StrCpy $R7 "ours"
  ${Else}
    StrCpy $R7 "foreign"
  ${EndIf}
FunctionEnd

; Stop every Vaultcast service owned by $R8; abort if one belongs elsewhere.
Function ${PREFIX}StopOwnedServices
  ${ForEachServiceId} ${PREFIX}StopOneService
FunctionEnd

Function ${PREFIX}StopOneService
  Call ${PREFIX}ServiceOwnership
  ${If} $R7 == "foreign"
    !insertmacro Fail "The Windows service $R9 belongs to another Vaultcast folder. Use that installation's setup or uninstaller."
  ${ElseIf} $R7 == "ours"
    DetailPrint "Stopping $R9..."
    ; net.exe waits until the service has actually stopped. Exit 2 = not running.
    nsExec::ExecToLog '"$SYSDIR\net.exe" stop $R9'
    Pop $0
  ${EndIf}
FunctionEnd
!macroend

; Call FUNC once per service id with the id in $R9 (reverse start order: web first).
!define ForEachServiceId "!insertmacro ForEachServiceId"
!macro ForEachServiceId FUNC
  StrCpy $R9 "VaultcastTransmission"
  Call ${FUNC}
  StrCpy $R9 "VaultcastWeb"
  Call ${FUNC}
  StrCpy $R9 "VaultcastNetplay"
  Call ${FUNC}
  ; Extra php-cgi pool workers (any that exist; "none" is skipped by the callers).
  ${ForEach} $R6 ${PHP_WORKERS_MAX} 2 - 1
    StrCpy $R9 "VaultcastPHP$R6"
    Call ${FUNC}
  ${Next}
  StrCpy $R9 "VaultcastPHP"
  Call ${FUNC}
  StrCpy $R9 "VaultcastDatabase"
  Call ${FUNC}
!macroend

; Path validation for the chosen folder. Out: $0 = "" when valid, otherwise a message.
Function ValidateInstallDir
  StrCpy $0 ""
  StrCpy $1 $INSTDIR 2
  StrLen $2 $INSTDIR
  ${If} $1 == "\\"
    StrCpy $0 "Install the Windows services on a local disk. Media can still live on a network share."
  ${ElseIf} $2 <= 3
    StrCpy $0 "Choose a dedicated folder rather than the root of a drive."
  ${Else}
    ; Characters that break service command lines or configuration files.
    StrCpy $3 0
    ${Do}
      StrCpy $4 $INSTDIR 1 $3
      ${If} $4 == ""
        ${Break}
      ${EndIf}
      ${If} $4 == '"'
      ${OrIf} $4 == "%"
      ${OrIf} $4 == "$$"
        StrCpy $0 'Choose a folder without quotes, percent signs or dollar signs.'
        ${Break}
      ${EndIf}
      IntOp $3 $3 + 1
    ${Loop}
  ${EndIf}
FunctionEnd
