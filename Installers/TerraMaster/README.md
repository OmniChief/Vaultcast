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
| Logs, sessions | `$VAULTCAST_DIR/logs`, `$VAULTCAST_DIR/sessions` |
| System user | `vaultcast` (no login) |
| Services | `vaultcast-php-fpm.service`, `vaultcast-nginx.service` |
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

Multiplayer (Node.js relay) isn't set up here.

## Uninstall
```sh
systemctl disable --now vaultcast-nginx vaultcast-php-fpm
rm /etc/systemd/system/vaultcast-{nginx,php-fpm}.service && systemctl daemon-reload
rm -rf /Volume1/vaultcast && userdel vaultcast
```
