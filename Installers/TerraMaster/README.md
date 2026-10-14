# Vaultcast on TerraMaster TOS 7 (F2-212, ARM64)

This installer runs Vaultcast as a separate app and changes no TOS defaults. It edits no existing TOS files and doesn't restart TOS services.

## Requirements
- TOS 7, with the **PHP74** app installed from App Center. Only its PHP 7.4 binaries are used; TOS's own PHP-FPM service is left alone.
- The TOS App Center **MySQL** (MariaDB) app. If it's missing, install it from App Center first. The installer asks for MariaDB admin details, or reads them from a client option file passed as `VAULTCAST_DB_ADMIN_CNF`. You can also skip that step (`VAULTCAST_CREATE_DB=no`) and enter database details in the web installer.
- SSH as root.

## Install
```sh
cd Installers/TerraMaster
VAULTCAST_PORT=9999 VAULTCAST_DIR=/Volume1/vaultcast ./install.sh
```
Then open `http://<your-nas>:9999`.

## What it creates
| Item | Path / name |
|---|---|
| App | `$VAULTCAST_DIR/app` (`data/` inside, as the updater expects) |
| PHP config | `$VAULTCAST_DIR/etc/php/php.ini`, `php-fpm.conf` (php-fpm runs with `-n`, so nothing is read from `/etc/php`) |
| nginx config | `$VAULTCAST_DIR/etc/nginx/` (a separate nginx instance) |
| PHP CLI | `$VAULTCAST_DIR/bin/php` (a wrapper on the service PATH, used for library scans and maintenance jobs) |
| Logs, sessions | `$VAULTCAST_DIR/logs`, `$VAULTCAST_DIR/sessions` |
| System user | `vaultcast` (no login) |
| Services | `vaultcast-php-fpm.service`, `vaultcast-nginx.service`, `vaultcast-netplay.service` |
| Socket | `/run/vaultcast-php/php-fpm.sock` |

## Forgotten MariaDB root password
The App Center app doesn't show its root password. Follow MariaDB's documented reset:
1. Stop `mysql.service`.
2. Start `mysqld_safe` once with `--skip-grant-tables --skip-networking`.
3. Run `FLUSH PRIVILEGES; ALTER USER 'root'@'localhost' IDENTIFIED BY '...';`.
4. Shut it down and start `mysql.service` again.

Keep the new password in a root-only (mode 600) client option file.

## Why not apt or a TOS nginx server block?
- TOS pins `php-common`, `php7.4-*` and `nginx*` to priority -1 in `/etc/apt/preferences.d/lock_system_packages.pref`, so apt can't install PHP without overriding TOS.
- TOS's `nginx.conf` only includes `conf.d/*.conf` inside its own server blocks, so a new site would mean editing a TOS file.

## Optional features (set up automatically)
- **Multiplayer:** the official Node.js 20 prebuilt (checksum-verified) goes in `$VAULTCAST_DIR/node`, and the relay runs as `vaultcast-netplay.service` on loopback port 3100. It's served at `/netplay/` by Vaultcast's own nginx. Set `VAULTCAST_SKIP_MULTIPLAYER=1` to skip it.
- **Downloads:** the App Center Transmission is auto-detected (RPC port, download folder, whether login is required) and connected through `data/runtime/transmission.ini`. Transmission's settings are never changed. If RPC login is required, the installer prompts for it or reads `VAULTCAST_TRANSMISSION_USER` / `VAULTCAST_TRANSMISSION_PASSWORD`. The `vaultcast` user joins the group that owns the download folder so it can import finished files.
- **FFmpeg / ffprobe:** TOS already provides both, and Vaultcast uses them as they are.
- Vaultcast needs no cron jobs or background workers.

## Media folder access (broad)
TOS enforces shared-folder permissions through its own ACL system (`tmacl`), so standard `setfacl` grants don't work. By default the installer adds the `vaultcast` user to the TOS **admin** group, after backing up `/etc/group` to `$VAULTCAST_DIR/etc/`. That gives Vaultcast read/write access to **every share that grants the admin group**, which is broad access. Folders that don't grant admin stay inaccessible.

To limit access, set `VAULTCAST_ADMIN_GROUP=no`. Then grant individual shares to a TOS user in Control Panel > Shared Folders and run the services as that user.

To undo: `gpasswd -d vaultcast admin`, then `systemctl restart vaultcast-php-fpm vaultcast-netplay`.

Rerunning the installer on an existing installation only adds missing optional features.

## Uninstall
```sh
systemctl disable --now vaultcast-netplay vaultcast-nginx vaultcast-php-fpm
rm /etc/systemd/system/vaultcast-{netplay,nginx,php-fpm}.service && systemctl daemon-reload
rm -rf /Volume1/vaultcast && userdel vaultcast
```
