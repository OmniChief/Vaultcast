; =============================================================================
; Pages.nsh - custom wizard pages (nsDialogs) and page callbacks
; =============================================================================

; ---- Setup options: Express / Advanced, ports, database, repair vs reset ----
Function OptionsPageCreate
  !insertmacro MUI_HEADER_TEXT "Setup options" "Express uses sensible defaults. Advanced lets you pick ports and the database."
  nsDialogs::Create 1018
  Pop $0

  ${NSD_CreateRadioButton} 0 0 100% 12u "&Express (recommended): automatic database, library on port 9999"
  Pop $hExpress
  ${NSD_CreateRadioButton} 0 14u 100% 12u "&Advanced: choose ports, listen address and database"
  Pop $hAdvanced
  ${NSD_OnClick} $hExpress OptionsToggle
  ${NSD_OnClick} $hAdvanced OptionsToggle

  ${NSD_CreateLabel} 12u 32u 30% 10u "Library port"
  Pop $0
  ${NSD_CreateLabel} 40% 32u 28% 10u "Private PHP port"
  Pop $0
  ${NSD_CreateLabel} 70% 32u 30% 10u "Multiplayer port"
  Pop $0
  ${NSD_CreateNumber} 12u 43u 25% 12u "$WebPort"
  Pop $hWebPort
  ${NSD_CreateNumber} 40% 43u 25% 12u "$PhpPort"
  Pop $hPhpPort
  ${NSD_CreateNumber} 70% 43u 25% 12u "$RelayPort"
  Pop $hRelayPort
  ${NSD_CreateLabel} 12u 60u 60% 10u "Listen address (127.0.0.1 = this PC only)"
  Pop $0
  ${NSD_CreateText} 12u 71u 40% 12u "$ListenAddress"
  Pop $hListen
  ${NSD_CreateLabel} 70% 60u 30% 10u "PHP workers (1-${PHP_WORKERS_MAX})"
  Pop $0
  ${NSD_CreateNumber} 70% 71u 25% 12u "$PhpWorkers"
  Pop $hPhpWorkers
  ${NSD_CreateCheckbox} 12u 88u 100% 12u "Use an existing MariaDB/MySQL server (enter it in the browser afterwards)"
  Pop $hExternal

  ; Only offered when Vaultcast is already installed.
  ${If} $PreviousRoot != ""
    ${NSD_CreateGroupBox} 0 106u 100% 34u "Existing installation found"
    Pop $0
    ${NSD_CreateRadioButton} 8u 117u 45% 10u "&Update or repair"
    Pop $hOpInstall
    ${NSD_CreateRadioButton} 52% 117u 46% 10u "&Reset administrator password"
    Pop $hOpReset
    ${NSD_CreateLabel} 8u 128u 95% 10u "Your library, settings and media are always kept."
    Pop $0
    ${If} $Operation == "reset"
      ${NSD_Check} $hOpReset
    ${Else}
      ${NSD_Check} $hOpInstall
    ${EndIf}
  ${EndIf}

  ${If} $Advanced == 1
    ${NSD_Check} $hAdvanced
  ${Else}
    ${NSD_Check} $hExpress
  ${EndIf}
  ${If} $External == 1
    ${NSD_Check} $hExternal
  ${EndIf}
  Call OptionsToggle
  nsDialogs::Show
FunctionEnd

; Enable the advanced fields only when Advanced is selected.
Function OptionsToggle
  ${NSD_GetState} $hAdvanced $0
  ${If} $0 == ${BST_CHECKED}
    StrCpy $1 1
  ${Else}
    StrCpy $1 0
  ${EndIf}
  EnableWindow $hWebPort $1
  EnableWindow $hPhpPort $1
  EnableWindow $hRelayPort $1
  EnableWindow $hListen $1
  EnableWindow $hPhpWorkers $1
  EnableWindow $hExternal $1
FunctionEnd

Function OptionsPageLeave
  StrCpy $Operation "install"
  ${If} $PreviousRoot != ""
    ${NSD_GetState} $hOpReset $0
    ${If} $0 == ${BST_CHECKED}
      StrCpy $Operation "reset"
      Return
    ${EndIf}
  ${EndIf}

  ${NSD_GetState} $hAdvanced $0
  ${If} $0 == ${BST_CHECKED}
    StrCpy $Advanced 1
    ${NSD_GetText} $hWebPort $WebPort
    ${NSD_GetText} $hPhpPort $PhpPort
    ${NSD_GetText} $hRelayPort $RelayPort
    ${NSD_GetText} $hListen $ListenAddress
    ${NSD_GetText} $hPhpWorkers $PhpWorkers
    ${If} $PhpWorkers < 1
    ${OrIf} $PhpWorkers > ${PHP_WORKERS_MAX}
      MessageBox MB_ICONEXCLAMATION "PHP workers must be between 1 and ${PHP_WORKERS_MAX}."
      Abort
    ${EndIf}
    ${NSD_GetState} $hExternal $0
    ${If} $0 == ${BST_CHECKED}
      StrCpy $External 1
    ${Else}
      StrCpy $External 0
    ${EndIf}
    ; Basic range check; availability is checked during installation.
    ${ForEach} $1 0 2 + 1
      ${If} $1 == 0
        StrCpy $2 $WebPort
      ${ElseIf} $1 == 1
        StrCpy $2 $PhpPort
      ${Else}
        StrCpy $2 $RelayPort
      ${EndIf}
      ${If} $2 < 1024
      ${OrIf} $2 > 65535
        MessageBox MB_ICONEXCLAMATION "Ports must be between 1024 and 65535."
        Abort
      ${EndIf}
    ${Next}
    ${If} $WebPort == $PhpPort
    ${OrIf} $WebPort == $RelayPort
    ${OrIf} $PhpPort == $RelayPort
      MessageBox MB_ICONEXCLAMATION "Each port must be different."
      Abort
    ${EndIf}
  ${Else}
    StrCpy $Advanced 0
    StrCpy $External 0
    StrCpy $WebPort 9999
    StrCpy $PhpPort 9000
    StrCpy $PhpWorkers ${PHP_WORKERS_DEFAULT}
    StrCpy $RelayPort 3100
    StrCpy $ListenAddress "0.0.0.0"
  ${EndIf}
FunctionEnd

; ---- Reset administrator password (replaces the old PowerShell WinForms dialog) ----
Function ResetPageCreate
  ${If} $Operation != "reset"
    Abort ; skip page
  ${EndIf}
  !insertmacro MUI_HEADER_TEXT "Reset administrator password" "Signs the account out everywhere. Nothing else changes."
  nsDialogs::Create 1018
  Pop $0
  ${NSD_CreateLabel} 0 0 100% 10u "Administrator username"
  Pop $0
  ${NSD_CreateText} 0 11u 100% 12u ""
  Pop $hResetUser
  ${NSD_CreateLabel} 0 30u 100% 10u "New password (12 to 72 bytes)"
  Pop $0
  ${NSD_CreatePassword} 0 41u 100% 12u ""
  Pop $hResetPass
  ${NSD_CreateLabel} 0 60u 100% 10u "Confirm new password"
  Pop $0
  ${NSD_CreatePassword} 0 71u 100% 12u ""
  Pop $hResetConfirm
  ${NSD_SetFocus} $hResetUser
  nsDialogs::Show
FunctionEnd

Function ResetPageLeave
  ${NSD_GetText} $hResetUser $ResetUser
  ${NSD_GetText} $hResetPass $ResetPassword
  ${NSD_GetText} $hResetConfirm $0
  ${If} $ResetUser == ""
    MessageBox MB_ICONEXCLAMATION "Enter the administrator username."
    Abort
  ${EndIf}
  ${If} $ResetPassword != $0
    MessageBox MB_ICONEXCLAMATION "The passwords do not match."
    Abort
  ${EndIf}
  ; StrCmp is case-insensitive, so compare exactly as well.
  ${IfNot} $ResetPassword S== $0
    MessageBox MB_ICONEXCLAMATION "The passwords do not match."
    Abort
  ${EndIf}
  StrLen $1 $ResetPassword
  ${If} $1 < 12
  ${OrIf} $1 > 72
    MessageBox MB_ICONEXCLAMATION "Use a password of 12 to 72 characters."
    Abort
  ${EndIf}
FunctionEnd

; ---- Standard pages that are skipped in some modes ----
Function SkipIfReset
  ${If} $Operation == "reset"
    Abort
  ${EndIf}
FunctionEnd

; Existing installations are always updated in place, so the folder is fixed.
Function DirectoryPre
  Call SkipIfReset
  ${If} $PreviousRoot != ""
    Abort
  ${EndIf}
FunctionEnd

Function DirectoryLeave
  Call ValidateInstallDir
  ${If} $0 != ""
    MessageBox MB_ICONEXCLAMATION "$0"
    Abort
  ${EndIf}
FunctionEnd

Function FinishPre
  ${If} $Operation == "reset"
    Abort
  ${EndIf}
FunctionEnd

Function LaunchApp
  ; Open through Explorer so the browser runs as the signed-in user, not elevated.
  Exec '"$WINDIR\explorer.exe" "$AppUrl"'
FunctionEnd
