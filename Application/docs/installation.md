# Install Vaultcast

## Download integrations

Downloads are optional. They're on by default only when an installer has connected Transmission. The supported client is **Transmission
on every platform**, including Windows x64 and Linux ARM64/x86. qBittorrent is not
supported. PHP must have cURL enabled; RSS feeds also need SimpleXML.

**Windows GUI installer:** nothing to configure. It runs its own Transmission
service (RPC on 127.0.0.1 with generated credentials) and Vaultcast picks the
connection up automatically; just turn on Download integrations in Settings when
you want them.

**Linux setup (Debian/Ubuntu):** nothing to configure either. When you accept the
optional Transmission (or `--yes`), setup installs `transmission-daemon` and
configures it with RPC on `127.0.0.1:9091`, random credentials, a loopback
whitelist and the download folder `/var/lib/vaultcast/downloads` (group `www-data`,
setgid, umask 002). It records the connection in `data/runtime/transmission.ini`, so
Vaultcast fills **Settings → Downloads** and enables downloads automatically. Every setup run (including reruns) ends with a verified connection: a missing
connection or one the daemon no longer accepts is recreated automatically. If the
daemon uses someone else's RPC account, setup asks before reconfiguring it (`--yes`
accepts). If you decline, enter that daemon's details in Settings. Saved Settings
that point at the setup-managed address always use the current setup credentials.

The steps below are only for the Windows script launcher or for using a
Transmission you run yourself. The Windows script launcher asks whether to install
Transmission from its official signed MSI. That doesn't configure the connection.

### Windows or a desktop Transmission client

1. Open Transmission, then **Edit → Preferences → Remote**.
2. Enable remote access and authentication. Choose a username and password. Allow
   `127.0.0.1` when Vaultcast runs on the same machine. Keep Transmission running
   while downloading; the desktop client does not run as a Vaultcast service.
3. Choose a download folder that both programs can read and write.
4. In Vaultcast, enable **Download integrations** in Settings, then open the download
   source settings. Enter `http://127.0.0.1:9091/transmission/rpc` (adjust the host
   or port if configured differently), the credentials and the download folder.
5. Click **Check Transmission connection**, then Save. Configure your RSS feeds
   before requesting downloads. A successful check verifies authenticated RPC.

Transmission's [remote-access guide](https://github.com/transmission/transmission/blob/main/docs/Web-Interface.md)
shows the client controls.

### Linux daemon

Only needed for a daemon you manage yourself. For a Debian/Ubuntu daemon, stop `transmission-daemon` before
editing `/etc/transmission-daemon/settings.json`; otherwise it can overwrite your
changes when exiting. Set `rpc-enabled` and `rpc-authentication-required` to true,
`rpc-bind-address` to `127.0.0.1`, `rpc-whitelist` to `127.0.0.1`, and choose
`rpc-username`, `rpc-password` and `download-dir`. The daemon hashes the password
when it saves settings. Enable and start the service again, then enter the same
RPC connection in Vaultcast as above. Use the actual port if 9091 is occupied.

The daemon account (`debian-transmission` on Debian/Ubuntu) and PHP account
(`www-data`) both need access to the chosen download folder. Give those accounts
shared group access; do not make the folder world-writable. Existing Transmission
servers retain their configuration: use their administrator's connection details.
See the [Transmission configuration reference](https://github.com/transmission/transmission/blob/main/docs/Editing-Configuration-Files.md)
for version-specific settings (newer releases also document underscore names).

Download transfers appear in the admin download queue and can be cancelled there.
Importing finished files is an admin maintenance action; Vaultcast creates no cron
schedule. Transmission itself continues explicitly requested transfers while its
client/service is running. Removing Vaultcast does not uninstall a separately
installed download client or delete its downloads.

## Requirements

- nginx and PHP 7.4 or newer: PHP-FPM on Linux, PHP-CGI on Windows.
- MariaDB 10.2 or newer.
- PHP extensions: PDO MySQL, JSON, mbstring, fileinfo and sessions.
- Read permission for your media folders.
- Write permission for the application directory during installation and `data/` during use.

FFmpeg and ffprobe enable media analysis and conversion. cURL supports metadata
and IPTV requests; XMLReader/SimpleXML support XMLTV guides; GD supports artwork
optimization. Multiplayer lobbies need Node.js 18 or newer, npm and the included
relay service. Choose a currently supported Node.js release for a new installation.
Tomcat is not required.

## Guided native installation

Extract the full download, keeping Application and Installers together. Package installations are
optional and explicitly confirmed. The launchers require administrator rights
when installing system packages; the browser installer never requests your
operating-system password.

### Windows

Double-click `Installers/Windows/x86-64/install.cmd` on x64 Windows 10/11 and approve the Windows administrator
prompt. Choose **Basic** or **Advanced**, then confirm installation.

**Basic** installs in `%ProgramData%\Vaultcast`, downloads native PHP, nginx,
MariaDB, Node.js/npm, FFmpeg and WinSW (the Windows service wrapper), and generates
secure database credentials. MariaDB installs silently. No Microsoft Store,
package manager, database password entry or terminal commands are required.

Open **http://localhost:9999** on this PC or **http://YOUR-PC:9999** on your local
network. Setup adds a firewall rule for the local subnet on Private and Domain
networks. PHP, MariaDB and multiplayer stay on loopback. Their standard ports are
used when free, with alternatives selected automatically when occupied. Setup
never takes over another server. If 9999 is occupied, close the conflicting
application or select a different library port in Advanced setup.

**Advanced** offers a custom folder on first installation, ports and listen
address. Choose an automatic database or enter an existing MariaDB connection in
the browser. Installed libraries retain their saved database connection.

Vaultcast Web, Vaultcast PHP, Vaultcast Multiplayer and the managed Vaultcast Database
run as automatic Windows services. PHP runs as LocalSystem for local library
access; the other services use Local Service. They start before login
and restart failed processes. Open **Vaultcast** from the Start menu to visit the
library. Use Windows **Services** to stop or restart the services if needed.

The browser opens after installation. Choose the site's name, create its
administrator, select folders, then scan and review title/episode matches. A
failed or postponed scan does not repeat account setup.

Windows setup requires administrator approval and stops if it is denied. PHP
retains system-level local access after reboot, so ordinary local media folders
need no separate permission setup. This also gives the PHP application broad
local privileges. Encryption and explicit access restrictions still apply.
Network shares enforce their own credentials and permissions; use a UNC path
rather than a desktop mapped drive, which is not available to Windows services.
Library errors keep the browser on the folder step with the entered details intact.

To update, extract the new download and double-click `Installers/Windows/x86-64/install.cmd`. Setup locates
the permanent installation and reuses its services, configuration and data.
Keep that installation folder in place. Rerun setup to resume interrupted
downloads. Bootstrap database credentials are encrypted for the administrator
who performed setup and are never displayed in the browser.

### Debian / Ubuntu

Run `bash Installers/Linux/setup.sh` from the extracted repository root. Setup offers missing packages,
asks for a new application directory and web address, then creates a MariaDB
database, restricted application account and separate nginx site. The relay and
PHP run as native systemd services and restart on boot.

The web installer reads the generated database credentials from a protected
temporary file and removes that file after installation. Once setup is complete,
make the application directory owned by your deployment account again; PHP needs
write access only to `data/`, and read access to your media.

Supported: Ubuntu 20.04 or newer (x86-64 and arm64; tested on 20.04.6 for both)
and Debian 11 or newer.

Multiplayer is set up automatically. If the system Node.js is missing or older than
18 (for example Ubuntu 20.04's Node.js 10), setup installs Node.js 20 from
NodeSource. It removes Ubuntu's conflicting `npm` package first. Setup then installs
the relay packages (`npm ci --omit=dev` in `netplay/`) and enables the
`vaultcast-netplay` systemd service on loopback port 3100. The generated nginx site
forwards `/netplay/` to it. If NodeSource can't be reached, setup stops before
changing your installation. Fix the network and rerun it, or set
`VAULTCAST_SKIP_MULTIPLAYER=1` to install without multiplayer.

Rerunning setup on a machine with an existing installation skips first-time setup.
It only completes or repairs the multiplayer relay (Node.js, packages, service). A
second installation on the same machine isn't supported.

Unattended install: `bash Installers/Linux/setup.sh --yes`, or
`VAULTCAST_ASSUME_YES=1`. This answers yes to every question, including the optional
Transmission and FFmpeg, and uses the defaults. Override the defaults with
`VAULTCAST_DIR` (`/srv/vaultcast`), `VAULTCAST_PORT` (`9999`), `VAULTCAST_LISTEN`
(`127.0.0.1`; use `0.0.0.0` for your network) and `VAULTCAST_DB` (`vaultcast`).

Troubleshooting multiplayer: run `systemctl status vaultcast-netplay` and
`ss -ltn | grep 3100`. Rerunning setup repairs a missing service or missing relay packages.

Other Linux distributions use the manual installation below with their native
package manager and service configuration.

## Set up the server

1. Extract the release into the application's directory, such as `/srv/vaultcast`.
2. Adapt `deploy/nginx.conf.example` to your hostname, directory and PHP-FPM socket.
   Keep its restrictions protecting configuration, source and data directories.
3. Give the PHP service account the required permissions.
4. Create an empty MariaDB database and a database user. During setup the user
   needs SELECT, INSERT, UPDATE, DELETE, CREATE and INDEX permissions.
5. Visit `index.php` and follow the installer. Choose the site's display name,
   enter database details, create an administrator and optionally select folders.
6. Select **Scan my folders**, then **Open my library**.

For a local MariaDB server, the web installer can create a new database and
application account when selected. Supply a database administrator with CREATE
DATABASE, CREATE USER and GRANT permissions. Only the generated application
credentials are saved. For a remote database, create the database/account there
and enter its connection details. The browser needs a running PHP server;
install system dependencies first using a launcher or your package manager.

Setup creates `data/config.php` containing the database connection and installation
URL. Library settings, accounts and preferences are stored in MariaDB. Keep
`data/config.php` private and readable by PHP. After setup, remove PHP's write access
to the application directory; only `data/` needs to remain writable.
Use HTTPS for access outside a trusted LAN.

The nginx example mounts Vaultcast at `/`. For a subdirectory, adjust the location
prefixes and FastCGI SCRIPT_NAME values to match. The internal
`/_vaultcast_media/` location keeps its name. Setup records the application base URL.
Both page and API requests run through the root `index.php`; `/api/` is a URL
prefix, not a directory. Use the supplied nginx routing when updating an installation.

## Clean URLs and deep links

Pages use readable path URLs, for example `/movies/runner-2026`,
`/tvshows/the-office/season-2/episode-5`, `/live/<channel>`, `/games`, `/library/<section>`,
`/collections`, `/settings/users` and `/search?q=…`. Old `#/…` links are redirected to the new form.
Slugs come from the title (plus the year for films). Each one is assigned once and kept, and
collisions get `-2`, `-3`…. Raw item ids also work in these URLs.

Opening such a link directly needs the web server to send every path that isn't a real file to
`index.php`:

| Server | What it needs |
|---|---|
| nginx (Linux and Windows installers) | `location / { try_files $uri $uri/ /index.php; }`. Already in `deploy/nginx.conf.example` and in the Windows installer's generated config. Nothing to change. |
| Apache | Use the bundled `.htaccess` (needs `mod_rewrite` and `AllowOverride FileInfo`). It also denies the private folders. |
| PHP built-in server (development only) | `php -S 127.0.0.1:8080 router.php` from the `Application` folder. |
| Subdirectory installs | Keep the fallback inside the subdirectory's location. The page sets `<base href>` to the recorded base URL, so assets load on any deep link. |

**Share** buttons on movie, show, season, episode and live channel pages copy the absolute link.
That link uses the address you are browsing on (protocol, host and port), or the *Public base URL*
from Settings > General if one is set, for example when the server is reached through a reverse proxy.

## Media delivery

The internal nginx media location handles streaming and byte ranges. If your server
does not allow that configuration, set `streaming.nginx_accel` to `false` in
advanced configuration to stream through PHP instead.

Media folders stay where they are. MariaDB holds catalogue records, accounts,
sessions, viewing progress, playback tokens and browse/probe caches; `data/` holds
artwork, downloaded player files and runtime working files.

By default, EmulatorJS loads from `https://cdn.emulatorjs.org/4.2.1/data/` and
HLS.js loads from jsDelivr. **Settings → Playback → Player files** can download
both to `data/players/` and serve them through Vaultcast instead. No nginx changes
are needed beyond the supplied configuration. Browser caching applies in either
mode. Custom Content-Security-Policy headers must permit the selected source and
the WebAssembly/blob workers used by EmulatorJS.

## Multiplayer service

Run these commands in `netplay`:

```sh
npm ci --omit=dev
node server.js
```

Use `deploy/vaultcast-netplay.service.example` as a service-manager template. The
nginx example forwards `/netplay/` to the service on loopback port 3100.
All players need the same application and emulator versions.
When updating the application, restart the relay service and refresh each player's
browser so the frontend and relay use the same multiplayer protocol.

## Scheduled maintenance

Run commands from the Vaultcast directory with your PHP CLI executable:

```sh
php src/Console.php scan
php src/Console.php collections
php src/Console.php downloads
php src/Console.php durations
```

`scan` indexes your folders. `collections` matches collection entries to library
items and accepts an optional collection ID. `downloads` imports completed
Transmission downloads when the integration is enabled. `durations` fills missing
Live media durations. Prefer Settings → Maintenance → Background tasks for tracked, cancellable work.
If you create an OS schedule, choose a low-use time; remove or disable it in the
operating system scheduler to prevent future runs.

For large libraries, use the CLI scan to avoid browser or proxy timeouts.
If the PHP CLI is not on PATH, configure `tools.php_cli` with its absolute path
for automatic Live duration processing.

## Backups

Back up MariaDB, `data/` and your media folders. Older installations using a root
`config.php` must also back up that file; it remains supported.
Keep credentials and user data separate from application source. When replacing
application files, preserve `data/` and any existing root `config.php`.
