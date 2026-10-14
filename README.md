# Vaultcast

A self-hosted library for films, television, music and games, with one interface
for desktop, phone and TV browsers.

**Version:** see [`Application/VERSION`](Application/VERSION) · nginx · PHP 7.4+ · MariaDB 10.2+ · Node.js for multiplayer

- Choose the media types and folders you want to share.
- Browse your collection, resume playback and manage artwork.
- Prepare MP4/H.264/AAC copies for broad playback compatibility, using low-CPU
  stream copying where possible and single-thread conversion when needed.
- Play ROMs through EmulatorJS and host multiplayer lobbies.
- Create Live channels or add IPTV playlists.
- Manage accounts, library folders and optional integrations in Settings.

## Install

Download **Code → Download ZIP** and extract it.

- **Windows:** double-click `Installers/Windows/x86-64/install.cmd`. Basic setup installs native Windows services
  and opens your library on port **9999**. Advanced setup exposes custom options.
- **Debian / Ubuntu:** run `bash Installers/Linux/setup.sh` to install native packages and create a
  separate nginx site. Each dependency installation requires your agreement.
- **Existing server:** configure nginx with `Application/deploy/nginx.conf.example`, then
  serve the contents of `Application/` and visit `index.php`.

The web installer checks PHP dependencies and lets you choose the site's name,
create your administrator account and connect MariaDB. Add your library folders
now or later in Settings. No accounts, media folders or integration credentials
are supplied with the application.

[Installation guide](Application/docs/installation.md) · [User manual](Application/docs/user-guide.md)

Choose CDN delivery or download EmulatorJS and HLS.js to your server under
**Settings → Playback → Player files**. Media, BIOS files, database credentials
and server runtimes are supplied separately.

## Download layout

- `Application/` contains the deployable web app and its user manuals.
- `Installers/Windows/` contains the native x64 launcher and `NSIS/` GUI installer source.
- `Installers/Linux/` contains one shared installer and ARM64, x86-64 and x86-32 launchers.

Windows requires x64 Windows 10/11. The Linux installer supports **Ubuntu 20.04 onwards**
(x86-64 and arm64, tested on 20.04.6 for both) and Debian 11 or newer, with working
official package repositories. Debian 12 supports 32-bit x86. Ubuntu 18.04 is not
supported: its PHP 7.2 is below the app's PHP 7.4 minimum, and the launcher stops
before changing the machine.
Multiplayer requires Node.js 18+. Linux setup installs Node.js 20 from NodeSource
automatically when the system's Node.js is missing or older (for example Ubuntu
20.04's Node.js 10), then enables the relay service. For an unattended install, run
`bash Installers/Linux/setup.sh --yes`
(see the [installation guide](Application/docs/installation.md#debian--ubuntu)).

The [Windows installer guide](Application/docs/windows-installer.md) describes
building the Windows installer, its dependencies, repair and removal. The
installer is self-contained: it bundles all native dependencies, including
Transmission and the multiplayer npm packages, so nothing is downloaded at install time.
Executables and downloaded packages are excluded from this source repository.

Administrators can start, inspect and cancel queued scans, collection matching,
imports and Live-duration maintenance in **Settings → Maintenance → Background tasks**.
No recurring maintenance schedule is installed by default.

Download integration optionally uses **Transmission on Windows and Linux**, plus PHP cURL.
The Windows GUI installer sets it up and connects it automatically; with the script
installers, remote access and its credentials are configured in Settings. qBittorrent is not supported. Existing media needs no download client.
See [Download setup](Application/docs/installation.md#download-integrations).

## Licence

Vaultcast's own code is source-available under the
[PolyForm Noncommercial License 1.0.0](LICENSE)
(Required Notice: Copyright (c) 2026 OmniChief). Personal, hobby,
research, educational and other noncommercial use is permitted. Commercial use
needs separate permission.

Third-party components keep their own licences. This includes the programs that
the Windows installer bundles (PHP, MariaDB, nginx, FFmpeg, Transmission,
Node.js and others) and the player files downloaded at runtime (EmulatorJS,
hls.js). See [NOTICE](NOTICE). Contributors should read
[CONTRIBUTING.md](CONTRIBUTING.md). None of this is legal advice.

## Updating

Administrators can update from **Settings → Maintenance → Check for updates**.
The updater compares the installed files with the `main` branch on GitHub and
downloads only what has changed — there is no release package to build. See the
[user manual](Application/docs/user-guide.md#updating-vaultcast) and
[docs/releasing.md](Application/docs/releasing.md) (publishing is just pushing
to `main`).
