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
# Multiplayer (Node.js relay) is not set up by this installer.
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
# Everything is created fresh: refuse to reuse anything that already exists.
for p in "$APP" $UNITS/vaultcast-php-fpm.service $UNITS/vaultcast-nginx.service /run/vaultcast-php /run/vaultcast-nginx; do
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

# Database: use the TOS App Center MySQL/MariaDB app. Nothing is installed here, so TOS stays untouched.
MYSQL=/Volume1/@apps/mysql/mysql/bin/mysql
[ -x "$MYSQL" ] || MYSQL="$(command -v mysql || command -v mariadb || true)"
DB_SOCKET="${VAULTCAST_DB_SOCKET:-/var/lib/mysql/mysql.sock}"
if [ "${VAULTCAST_CREATE_DB:-yes}" != yes ]; then
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
echo "Open http://<your-nas>:$PORT to finish setup."
