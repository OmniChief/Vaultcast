# Windows GUI installer

`Installers/Windows/NSIS` builds a single, self-contained x64 installer. The
application, PHP, Node.js (with the multiplayer relay's packages), nginx,
MariaDB, FFmpeg, the WinSW service wrapper, the CA bundle, the Microsoft Visual
C++ runtime and a Transmission download service are all inside the executable. Installation does
not download anything and does not run PowerShell. Windows x64 is required.

The script installer (`Installers/Windows/x86-64/install.cmd`) remains available
and installs the same layout.

## Build

Install NSIS 3 and Git. From the repository root, on Windows:

```powershell
./Installers/Windows/NSIS/build.ps1
```

The executable is written to your Downloads folder (`-OutputDirectory` changes
this, `-Compiler` selects makensis). The build script downloads native packages
into the ignored `NSIS/build/` folder, checks publisher checksums where supplied,
reuses verified cached downloads, unpacks everything into `build/stage`, exports
the committed `Application/` folder (commit changes first) and runs `npm ci
--ignore-scripts` for the relay. It then compiles `Vaultcast.nsi`. It never runs
the installer.

Direct compilation against an existing stage folder:

```text
makensis /INPUTCHARSET UTF8 /DSTAGE_DIR=C:\path\to\stage Vaultcast.nsi
```

Source layout:

| File | Purpose |
| --- | --- |
| `Vaultcast.nsi` | Pages, sections, initialisation, Add/Remove Programs entry |
| `include/Config.nsh` | Product names, version (from `Application/VERSION`), paths |
| `include/Helpers.nsh` | Shared macros: command execution, escaping, service ownership |
| `include/Pages.nsh` | Setup options and password-reset pages |
| `include/Configure.nsh` | php.ini, MariaDB, nginx, WinSW services, firewall |
| `include/Uninstall.nsh` | Uninstaller |
| `support/setup-helper.php` | Port probes, secrets and calls into the app's PHP setup code, run with the bundled PHP |
| `branding/` | Icon and wizard artwork (`make-branding.sh` regenerates them from `mark.svg`) |

## Install, update and repair

Choose **Express** for port 9999 and a generated local database, or **Advanced**
for the library, PHP and multiplayer ports, the listen address and an existing
database server. Optional components, all on by default: FFmpeg, Transmission,
Start menu and desktop shortcuts. The Visual C++ runtime is skipped when the same
or a newer version is already installed.

**Downloads need no setup.** The Transmission component runs Vaultcast's own
`transmission-daemon` as the `VaultcastTransmission` service with a private config
folder (`data\transmission`), so a desktop Transmission and its settings are left
alone. RPC listens on 127.0.0.1 only, on a free port, with a generated username
and password (stored in `data\runtime\transmission.ini`, readable by SYSTEM and
Administrators only, kept on update). Downloads go to `C:\Users\Public\Downloads\Vaultcast`.
A firewall rule for the daemon is added up front so Windows never asks. Vaultcast
reads the connection automatically for Settings > Downloads. The finish page can open Vaultcast.

Running the installer on a PC that already has Vaultcast updates it in place:
the services of that installation are stopped, program files and runtimes are
replaced, and the database, settings and media are kept.

**PHP workers and media streaming.**
- **Worker pool:** PHP runs as a small pool of `php-cgi.exe` services, 4 by default and 1–16 under Advanced or `/PHPWORKERS=`. They are `Vaultcast PHP`, then `Vaultcast PHP 2`… on the next free loopback ports after the PHP port, and nginx balances requests across them (`upstream vaultcast_php`, `least_conn`).
- **Media streaming:** playback is served by nginx itself through X-Accel-Redirect. PHP checks the playback token and the library folder, then hands `/_vaultcast_media/C:/…` to an internal nginx location. Byte ranges come from nginx, and no PHP worker is held while a film plays.
- **What isn't covered:** media on UNC network paths (`\\server\share`) is still streamed by PHP.
- **The setting:** `data\runtime\setup.json` records `"mediaAccel": true`, and the app then turns `streaming.nginx_accel` on.

**Existing installations** need to run this installer once and choose **Update or repair**. That regenerates `vaultcast.conf` and registers the extra PHP services. The in-app updater only replaces application files; it doesn't change Windows services or the nginx configuration. Until then, an install keeps its previous behaviour: one PHP worker, and PHP-served streams.

Silent install: `Vaultcast-x.y.z-Setup-x64.exe /S` with optional `/PORT=`,
`/PHPPORT=`, `/PHPWORKERS=`, `/RELAYPORT=`, `/LISTEN=`, `/EXTERNALDB`, `/NODESKTOP`, `/NOSTARTMENU`,
`/NOFFMPEG`, `/NOTRANSMISSION` and `/D=C:\Folder` (last).

When Vaultcast is installed, the options page also offers **Reset administrator
password**. The new password is passed to the application through a temporary
file read by PHP, never on a command line, and old sessions for that account are
revoked.

## Remove

Use Windows Installed Apps or `Uninstall.exe` (`/S` for silent). Removal stops
and unregisters only this installation's services, removes its firewall rule,
shortcuts, application files and bundled runtimes, and keeps `data/` (database,
settings, logs) for recovery. Media directories are never deleted.
