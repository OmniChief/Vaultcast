#!/bin/bash
# Vaultcast installer for TerraMaster TOS 7 (tested on F2-212, ARM64).
# Runs Vaultcast as a separate app without changing any TOS defaults:
#  * PHP: the App Center "PHP74" app's php-fpm7.4 binary, started as a NEW service with its own
#    php.ini (-n, nothing read from /etc/php), its own pool user and its own socket.
#    TOS's own PHP-FPM (PHP74.service, /etc/nginx/php-fpm.conf) is never started, stopped or edited.
#  * Web: a separate nginx instance (own config, own pid, own logs) on its own port. TOS's nginx.conf
#    has no http-level include directory, so a separate instance avoids editing any TOS file.
#  * Database: a new MariaDB database and user in the App Center MySQL app. The installer asks for
#    admin details (or reads VAULTCAST_DB_ADMIN_CNF, a client option file).
# Why not apt? TOS pins php-common, php7.4-* and nginx to priority -1
# (/etc/apt/preferences.d/lock_system_packages.pref); overriding that would change NAS defaults.
#  * Multiplayer: official Node.js 20 prebuilt under $APP/node + vaultcast-netplay.service (loopback 3100).
#  * Downloads: connects the App Center Transmission (auto-detects RPC port/auth; never edits it).
# Safe to rerun: an existing installation only gets missing optional features.
# Usage (as root, from this folder of a Vaultcast checkout):
#   VAULTCAST_PORT=9999 VAULTCAST_DIR=/Volume1/vaultcast ./install.sh
set -euo pipefail
cd "$(dirname "$0")/../../Application"
APP="${VAULTCAST_DIR:-/Volume1/vaultcast}"
PORT="${VAULTCAST_PORT:-9999}"
U="${VAULTCAST_USER:-vaultcast}"
DB="${VAULTCAST_DB:-vaultcast}"
PHPROOT=/Volume1/@apps/PHP74/depends
PHPBIN=$PHPROOT/bin/php7.4
FPM=$PHPROOT/bin/php-fpm7.4
EXT=/usr/lib/php/20190902
SOCK=/run/vaultcast-php/php-fpm.sock
UNITS=/etc/systemd/system
export LD_LIBRARY_PATH=$PHPROOT/lib
# TOS ships its own /usr/sbin/install tool, so coreutils "install" is not used here.

[ "$(id -u)" = 0 ] || { echo 'Run as root.'; exit 1; }
[ -x "$FPM" ] && [ -x "$PHPBIN" ] || { echo 'Install the PHP74 app from TOS App Center first.'; exit 1; }
[[ "$APP" =~ ^/[a-zA-Z0-9_./-]+$ ]] || { echo 'Use an absolute path without spaces.'; exit 1; }
[[ "$PORT" =~ ^[0-9]+$ && "$PORT" -ge 1024 ]] || { echo 'Choose a port from 1024 to 65535.'; exit 1; }
# First run creates everything fresh and refuses to reuse anything that already exists.
# A rerun on an existing installation only completes the optional features below.
if [ -e "$APP/etc/php/php-fpm.conf" ] && [ -e $UNITS/vaultcast-nginx.service ] && getent passwd "$U" >/dev/null; then
    echo "Existing installation found at $APP; only optional features are completed."
    RERUN=1
else
RERUN=0
for p in "$APP" $UNITS/vaultcast-php-fpm.service $UNITS/vaultcast-nginx.service $UNITS/vaultcast-netplay.service /run/vaultcast-php /run/vaultcast-nginx; do
    [ ! -e "$p" ] || { echo "$p already exists; remove it or choose another location."; exit 1; }
done
! getent passwd "$U" >/dev/null || { echo "User $U already exists."; exit 1; }
! ss -ltn | awk '{print $4}' | grep -q ":$PORT\$" || { echo "Port $PORT is in use."; exit 1; }
useradd -r -U -M -d "$APP" -s /usr/sbin/nologin "$U"
mkdir -p "$APP"/{app/data,sessions,logs,etc/php,etc/nginx}
for e in index.php manifest.json sw.js VERSION assets src deploy docs; do cp -R "$e" "$APP/app/"; done
cp ../LICENSE ../NOTICE "$APP/app/" 2>/dev/null || true

{
    echo "extension_dir=$EXT"
    for x in mysqlnd pdo xml mbstring curl gd ctype dom fileinfo iconv json mysqli pdo_mysql posix simplexml tokenizer xmlreader xmlwriter zip exif sockets; do echo "extension=$x.so"; done
    echo 'zend_extension=opcache.so'
    echo 'memory_limit=256M'; echo 'upload_max_filesize=16M'; echo 'post_max_size=16M'
    echo 'date.timezone=UTC'; echo 'expose_php=Off'
    echo "session.save_path=$APP/sessions"
} > "$APP/etc/php/php.ini"

cat > "$APP/etc/php/php-fpm.conf" <<C
[global]
pid = /run/vaultcast-php/php-fpm.pid
error_log = $APP/logs/php-fpm.log
daemonize = no
[vaultcast]
user = $U
group = $U
listen = $SOCK
listen.owner = $U
listen.group = $U
listen.mode = 0660
pm = ondemand
pm.max_children = 6
pm.process_idle_timeout = 30s
env[PATH] = $APP/bin:$APP/node/bin:/usr/local/bin:/usr/bin:/bin
php_admin_value[error_log] = $APP/logs/php-error.log
php_admin_flag[log_errors] = on
C

cp /etc/nginx/fastcgi_params.default "$APP/etc/nginx/fastcgi_params"
sed -e "s|root /srv/vaultcast;|root $APP/app;|" -e "s|listen 80;|listen 0.0.0.0:$PORT;|" \
    -e "s|unix:/run/php/php7.4-fpm.sock|unix:$SOCK|" -e 's|server_name vaultcast.local;|server_name _;|' \
    deploy/nginx.conf.example | grep -v '^#' > "$APP/etc/nginx/vaultcast-site.conf"
cat > "$APP/etc/nginx/nginx.conf" <<C
user $U;
worker_processes 2;
pid /run/vaultcast-nginx/nginx.pid;
error_log $APP/logs/nginx-error.log warn;
events { worker_connections 512; }
http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    access_log $APP/logs/nginx-access.log;
    client_body_temp_path /run/vaultcast-nginx/body;
    fastcgi_temp_path /run/vaultcast-nginx/fastcgi;
    proxy_temp_path /run/vaultcast-nginx/proxy;
    uwsgi_temp_path /run/vaultcast-nginx/uwsgi;
    scgi_temp_path /run/vaultcast-nginx/scgi;
    include $APP/etc/nginx/vaultcast-site.conf;
}
C

cat > $UNITS/vaultcast-php-fpm.service <<C
[Unit]
Description=Vaultcast PHP-FPM 7.4 (separate pool)
After=network.target tnas-mntdata.service
[Service]
Type=simple
RuntimeDirectory=vaultcast-php
Environment=LD_LIBRARY_PATH=$PHPROOT/lib
ExecStart=$FPM -n -c $APP/etc/php/php.ini --nodaemonize --fpm-config $APP/etc/php/php-fpm.conf
Restart=on-failure
[Install]
WantedBy=multi-user.target
C
cat > $UNITS/vaultcast-nginx.service <<C
[Unit]
Description=Vaultcast web server (separate nginx instance, port $PORT)
After=network.target vaultcast-php-fpm.service
Requires=vaultcast-php-fpm.service
[Service]
Type=forking
RuntimeDirectory=vaultcast-nginx
PIDFile=/run/vaultcast-nginx/nginx.pid
ExecStartPre=/usr/sbin/nginx -t -q -c $APP/etc/nginx/nginx.conf
ExecStart=/usr/sbin/nginx -c $APP/etc/nginx/nginx.conf
ExecReload=/usr/sbin/nginx -c $APP/etc/nginx/nginx.conf -s reload
ExecStop=/usr/sbin/nginx -c $APP/etc/nginx/nginx.conf -s quit
[Install]
WantedBy=multi-user.target
C

# Same ownership as Installers/Linux/setup.sh: the app directory and data/ belong to the pool user.
chown -R root:root "$APP/app" "$APP/etc"; chmod -R go-w "$APP/app"
chown "$U:$U" "$APP/app" "$APP/app/data" "$APP/logs" "$APP/sessions"
chmod 755 "$APP/app"; chmod 770 "$APP/app/data"; chmod 750 "$APP/logs"; chmod 700 "$APP/sessions"

fail=0
while IFS= read -r f; do "$PHPBIN" -n -c "$APP/etc/php/php.ini" -l "$f" >/dev/null || fail=1; done < <(find "$APP/app" -name '*.php')
[ "$fail" = 0 ] || { echo 'PHP syntax check failed.'; exit 1; }

systemctl daemon-reload
systemctl enable --now vaultcast-php-fpm.service
systemctl enable --now vaultcast-nginx.service

fi

# Database: use the TOS App Center MySQL/MariaDB app. Nothing is installed here, so TOS stays untouched.
MYSQL=/Volume1/@apps/mysql/mysql/bin/mysql
[ -x "$MYSQL" ] || MYSQL="$(command -v mysql || command -v mariadb || true)"
DB_SOCKET="${VAULTCAST_DB_SOCKET:-/var/lib/mysql/mysql.sock}"
if [ -e "$APP/app/data/setup-database.php" ]; then
    echo 'Database connection already configured.'
elif [ "${VAULTCAST_CREATE_DB:-yes}" != yes ]; then
    echo 'Database step skipped; enter database details in the web installer.'
elif [ -z "$MYSQL" ] || ! { [ -S "$DB_SOCKET" ] || ss -ltn | grep -q ':3306 '; }; then
    echo 'MariaDB was not found. Install "MySQL" from TOS App Center, then rerun with an empty'
    echo "$APP or enter database details in the web installer."
else
    if [ -n "${VAULTCAST_DB_ADMIN_CNF:-}" ]; then
        admin=(--defaults-extra-file="$VAULTCAST_DB_ADMIN_CNF")
    else
        read -r -p 'MariaDB admin user [root]: ' admin_user; admin_user="${admin_user:-root}"
        read -r -s -p "MariaDB password for $admin_user: " admin_pw; echo
        admin_cnf="$(mktemp)"; chmod 600 "$admin_cnf"
        printf '[client]\nuser=%s\npassword=%s\nsocket=%s\n' "$admin_user" "$admin_pw" "$DB_SOCKET" > "$admin_cnf"
        unset admin_pw; trap 'rm -f "$admin_cnf"' EXIT
        admin=(--defaults-extra-file="$admin_cnf")
    fi
    if ! "$MYSQL" "${admin[@]}" -e 'SELECT 1' >/dev/null 2>&1; then
        echo 'Could not log in to MariaDB; enter database details in the web installer instead.'
    elif [ -n "$("$MYSQL" "${admin[@]}" -N -B -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$DB'")" ]; then
        echo "Database $DB already exists; it was not reused. Rerun with VAULTCAST_DB=<new name>."
    else
        db_user="vc_$(od -An -N6 -tx1 /dev/urandom | tr -d ' \n')"
        db_password="$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')"
        "$MYSQL" "${admin[@]}" <<SQL
CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$db_user'@'localhost' IDENTIFIED BY '$db_password';
CREATE USER '$db_user'@'127.0.0.1' IDENTIFIED BY '$db_password';
GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$db_user'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$db_user'@'127.0.0.1';
SQL
        ( umask 077
          printf '%s\n%s\n%s\n' "$DB" "$db_user" "$db_password" | "$PHPBIN" -n -r '$v=explode("\n",trim(stream_get_contents(STDIN))); $c=["host"=>"127.0.0.1","port"=>3306,"name"=>$v[0],"user"=>$v[1],"password"=>$v[2]]; file_put_contents($argv[1],"<?php return ".var_export($c,true).";\n");' "$APP/app/data/setup-database.php" )
        chown "$U:$U" "$APP/app/data/setup-database.php"; chmod 600 "$APP/app/data/setup-database.php"
        echo "Database $DB created; the web installer will use it automatically."
    fi
fi

# ---- Multiplayer relay: official Node.js 20 prebuilt (checksum verified) under $APP/node ----
if [[ "${VAULTCAST_SKIP_MULTIPLAYER:-}" =~ ^(1|y|yes|true)$ ]]; then
    echo 'Multiplayer skipped (VAULTCAST_SKIP_MULTIPLAYER).'
else
    case "$(uname -m)" in aarch64|arm64) node_arch=arm64 ;; x86_64) node_arch=x64 ;; *) node_arch= ;; esac
    if [ -z "$node_arch" ]; then echo 'No Node.js build for this CPU; multiplayer skipped.'
    else
        if [ ! -x "$APP/node/bin/node" ]; then
            tmp="$(mktemp -d)"; base=https://nodejs.org/dist/latest-v20.x
            curl -fsSL "$base/SHASUMS256.txt" -o "$tmp/SHASUMS256.txt"
            file="$(grep -o "node-v[0-9.]*-linux-$node_arch.tar.gz" "$tmp/SHASUMS256.txt" | head -n 1)"
            curl -fsSL "$base/$file" -o "$tmp/$file"
            (cd "$tmp" && grep " $file\$" SHASUMS256.txt | sha256sum -c - >/dev/null) || { echo 'Node.js checksum mismatch.'; exit 1; }
            mkdir -p "$APP/node" && tar xzf "$tmp/$file" -C "$APP/node" --strip-components=1
            chown -R root:root "$APP/node"; rm -rf "$tmp"
        fi
        if [ ! -d "$APP/app/netplay/node_modules" ]; then
            ss -ltn | grep -q '127.0.0.1:3100 \|:3100 ' && { echo 'Port 3100 is in use; free it and rerun.'; exit 1; }
            mkdir -p "$APP/app/netplay"
            cp netplay/server.js netplay/package.json netplay/package-lock.json "$APP/app/netplay/"
            npm_cache="$(mktemp -d)"
            (cd "$APP/app/netplay" && PATH="$APP/node/bin:$PATH" npm ci --omit=dev --no-audit --no-fund --cache "$npm_cache" >/dev/null)
            rm -rf "$npm_cache"; chown -R root:root "$APP/app/netplay"
        fi
        if [ ! -e $UNITS/vaultcast-netplay.service ]; then
            cat > $UNITS/vaultcast-netplay.service <<C
[Unit]
Description=Vaultcast multiplayer relay (loopback 3100)
After=network.target
[Service]
Type=simple
User=$U
Group=$U
WorkingDirectory=$APP/app/netplay
ExecStart=$APP/node/bin/node server.js
Environment=NODE_ENV=production
Environment=NETPLAY_LOG=$APP/logs/netplay.log
Restart=on-failure
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
[Install]
WantedBy=multi-user.target
C
            systemctl daemon-reload
        fi
        grep -q '^env\[PATH\]' "$APP/etc/php/php-fpm.conf" || echo "env[PATH] = $APP/bin:$APP/node/bin:/usr/local/bin:/usr/bin:/bin" >> "$APP/etc/php/php-fpm.conf"
        systemctl enable --now vaultcast-netplay.service
        echo 'Multiplayer relay running on 127.0.0.1:3100 (proxied at /netplay/).'
    fi
fi

# ---- Downloads: connect the App Center Transmission without changing its settings ----
TCONF="${VAULTCAST_TRANSMISSION_CONFIG:-/Volume1/@apps/transmission/config/settings.json}"
TINI="$APP/app/data/runtime/transmission.ini"
if [ -e "$TINI" ]; then
    echo 'Transmission connection already configured.'
elif [ ! -r "$TCONF" ]; then
    echo 'Transmission not found. Install it from TOS App Center and rerun this installer to connect it.'
else
    tport="$("$PHPBIN" -n -r '$c=json_decode((string)file_get_contents($argv[1]),true); echo (int)($c["rpc-port"] ?? 9091);' "$TCONF")"
    tdir="$("$PHPBIN" -n -r '$c=json_decode((string)file_get_contents($argv[1]),true); echo $c["download-dir"] ?? "";' "$TCONF")"
    tauth="$("$PHPBIN" -n -r '$c=json_decode((string)file_get_contents($argv[1]),true); echo empty($c["rpc-authentication-required"]) ? "no" : "yes";' "$TCONF")"
    tuser=vaultcast; tpass=
    if [ "$tauth" = yes ]; then
        tuser="${VAULTCAST_TRANSMISSION_USER:-}"; tpass="${VAULTCAST_TRANSMISSION_PASSWORD:-}"
        if [ -z "$tuser" ] && [ -t 0 ]; then
            read -r -p 'Transmission RPC username: ' tuser
            read -r -s -p 'Transmission RPC password: ' tpass; echo
        fi
    fi
    code="$(curl -s -o /dev/null -w '%{http_code}' -X POST -d '{}' -u "$tuser:$tpass" "http://127.0.0.1:$tport/transmission/rpc" || true)"
    if [ -n "$tuser" ] && [ "$code" = 409 ]; then
        mkdir -p "$APP/app/data/runtime"
        ( umask 077; printf '[transmission]\nport=%s\nusername=%s\npassword=%s\ndownload_dir=%s\n' "$tport" "$tuser" "$tpass" "$tdir" > "$TINI" )
        chown -R "$U:$U" "$APP/app/data/runtime"; chmod 750 "$APP/app/data/runtime"; chmod 600 "$TINI"
        # Let Vaultcast read and move finished downloads (Transmission's group owns them).
        tgroup="$(stat -c %G "$tdir" 2>/dev/null || true)"
        [ -n "$tgroup" ] && [ "$tgroup" != root ] && getent group "$tgroup" >/dev/null && usermod -aG "$tgroup" "$U"
        echo "Transmission connected (RPC 127.0.0.1:$tport)."
    else
        echo "Transmission RPC did not accept the connection (HTTP $code). Set VAULTCAST_TRANSMISSION_USER/PASSWORD"
        echo 'and rerun, or enter it in Settings > Downloads. Transmission settings were not changed.'
    fi
fi
# ---- PHP CLI for background jobs (library scans, maintenance): a wrapper on the service PATH ----
if [ ! -x "$APP/bin/php" ]; then
    mkdir -p "$APP/bin"
    printf '#!/bin/sh\n# Vaultcast PHP CLI: App Center PHP 7.4 with Vaultcast own php.ini\nexport LD_LIBRARY_PATH=%s/lib\nexec %s -n -c %s/etc/php/php.ini "$@"\n' "$PHPROOT" "$PHPBIN" "$APP" > "$APP/bin/php"
    chown -R root:root "$APP/bin"; chmod 755 "$APP/bin" "$APP/bin/php"
fi
grep -q "^env\[PATH\] = $APP/bin:" "$APP/etc/php/php-fpm.conf" || sed -i "s|^env\[PATH\] = |env[PATH] = $APP/bin:|" "$APP/etc/php/php-fpm.conf"

# ---- Media access: TOS enforces share permissions through its own ACL system (tmacl), so POSIX
# setfacl grants don't take effect. Adding the service user to the TOS "admin" group gives it
# read/write on every share that grants admin. This is BROAD access (all admin-granted shares).
# Opt out with VAULTCAST_ADMIN_GROUP=no and grant shares in TOS Control Panel instead.
if [ "${VAULTCAST_ADMIN_GROUP:-yes}" = yes ] && getent group admin >/dev/null; then
    if id -nG "$U" | tr ' ' '\n' | grep -qx admin; then echo "$U is already in group admin."
    else
        cp -a /etc/group "$APP/etc/group.backup-$(date +%Y%m%d-%H%M%S)"
        usermod -aG admin "$U" && echo "Added $U to group admin (read/write on admin-granted shares)."
        systemctl restart vaultcast-netplay.service 2>/dev/null || true
    fi
fi
systemctl restart vaultcast-php-fpm.service
echo "Open http://<your-nas>:$PORT to finish setup."
