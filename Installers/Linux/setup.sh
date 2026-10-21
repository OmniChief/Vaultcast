#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../Application"
as_root() {
    if (( EUID == 0 )); then "$@";
    elif command -v sudo >/dev/null; then sudo "$@";
    else echo 'Administrator access is required. Run this setup as root or install sudo.' >&2; return 1;
    fi
}
assume_yes=false
[[ "${VAULTCAST_ASSUME_YES:-}" =~ ^(1|y|yes|true)$ ]] && assume_yes=true
for argument in "$@"; do
    case "$argument" in
        -y|--yes) assume_yes=true ;;
        -h|--help) echo 'Usage: setup.sh [--yes]'
            echo '  --yes (or VAULTCAST_ASSUME_YES=1) answers yes to every question and uses the defaults,'
            echo '  overridable with VAULTCAST_DIR, VAULTCAST_PORT, VAULTCAST_LISTEN and VAULTCAST_DB.'; exit 0 ;;
        *) echo "Unknown option: $argument (use --help)"; exit 1 ;;
    esac
done
confirm() {
    local reply
    if $assume_yes; then echo "$1 [y/N] y"; return 0; fi
    read -r -p "$1 [y/N] " reply; [[ "$reply" =~ ^([yY]|[yY][eE][sS])$ ]]
}
# ask VARIABLE PROMPT DEFAULT: an empty answer (or --yes) takes the default.
ask() {
    local reply=''
    if $assume_yes; then echo "$2 [$3]: $3"; else read -r -p "$2 [$3]: " reply; fi
    printf -v "$1" '%s' "${reply:-$3}"
}
node_major() { command -v node >/dev/null && node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0; }
node_ready() { [[ "$(node_major)" -ge 18 ]] && command -v npm >/dev/null; }
apt_updated=false
# Multiplayer needs Node.js 18+. Use the distribution's package when it is new enough,
# otherwise install Node.js 22 from NodeSource (amd64 and arm64).
ensure_node() {
    node_ready && return 0
    $apt_updated || { as_root apt-get update && apt_updated=true; }
    local candidate
    candidate="$(apt-cache policy nodejs 2>/dev/null | awk '/Candidate:/ {print $2}')"
    candidate="${candidate#*:}"; candidate="${candidate%%.*}"
    if [[ "$candidate" =~ ^[0-9]+$ ]] && (( candidate >= 18 )); then
        as_root apt-get install -y nodejs npm && node_ready && return 0
    fi
    echo 'The distribution Node.js is older than the multiplayer relay needs; installing Node.js 22 from NodeSource.'
    local script; script="$(mktemp)"
    if { ! dpkg -s npm >/dev/null 2>&1 || as_root apt-get remove -y npm; } &&   # Ubuntu's npm conflicts with NodeSource nodejs
       as_root apt-get install -y curl ca-certificates gnupg &&
       curl -fsSL https://deb.nodesource.com/setup_22.x -o "$script" &&
       as_root bash "$script" && as_root apt-get install -y nodejs && node_ready; then
        rm -f "$script"; return 0
    fi
    rm -f "$script"
    echo 'Node.js 22 could not be installed from NodeSource (https://deb.nodesource.com), so multiplayer cannot be set up.'
    echo 'Check the network, then rerun setup. To install without multiplayer, rerun with VAULTCAST_SKIP_MULTIPLAYER=1.'
    exit 1
}
# Connect the distribution's transmission-daemon to Vaultcast with no manual steps, like the
# Windows installer: RPC on 127.0.0.1 only, random credentials, whitelist loopback, a download
# folder shared with PHP (setgid group www-data, umask 002). The connection is written to
# data/runtime/transmission.ini, which Vaultcast reads to fill Settings > Downloads.
# Every run ends with a verified connection: a stored connection the daemon no longer accepts
# (or a missing one, e.g. after reinstalling) is recreated. Only a daemon with someone else's
# RPC account asks first (--yes accepts).
transmission_rpc_ok() {
    local code='' attempt
    for attempt in 1 2 3 4 5 6 7 8 9 10; do
        code="$(curl -s -o /dev/null -w '%{http_code}' -u "$1:$2" -X POST -d '{}' http://127.0.0.1:9091/transmission/rpc || true)"
        [[ "$code" == 409 || "$code" == 200 ]] && return 0
        [[ "$code" == 401 || "$code" == 403 ]] && return 1
        sleep 1
    done
    return 1
}
configure_transmission() {
    local dir="$1" conf=/etc/transmission-daemon/settings.json
    local ini="$dir/data/runtime/transmission.ini" downloads=/var/lib/vaultcast/downloads
    command -v transmission-daemon >/dev/null && as_root test -f "$conf" || return 0
    command -v curl >/dev/null || as_root apt-get install -y curl
    if as_root test -f "$ini"; then
        as_root systemctl enable --now transmission-daemon >/dev/null 2>&1 || true
        if transmission_rpc_ok "$(as_root sed -n 's/^username=//p' "$ini")" "$(as_root sed -n 's/^password=//p' "$ini")"; then
            echo 'Transmission is already connected to Vaultcast.'; return 0
        fi
        echo 'The stored Transmission connection is not accepted by transmission-daemon; reconnecting it.'
    fi
    local current
    current="$(as_root php -r '$c=json_decode((string)file_get_contents($argv[1]),true); echo $c["rpc-username"] ?? "";' "$conf")"
    if [[ "$current" != transmission && "$current" != vaultcast ]] &&
       ! confirm "transmission-daemon uses its own RPC account ($current). Reconfigure it for Vaultcast (new RPC credentials, loopback only)?"; then
        echo 'Transmission left unchanged. Enter its RPC URL and credentials in Settings > Downloads.'
        return 0
    fi
    local user="vaultcast" password
    password="$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')"
    as_root mkdir -p "$downloads"
    as_root chown debian-transmission:www-data "$downloads"
    as_root chmod 2775 "$downloads"
    as_root systemctl stop transmission-daemon   # the daemon rewrites settings.json when it stops
    as_root php -r '$f=$argv[1]; $c=json_decode((string)file_get_contents($f),true) ?: [];
        $c=array_replace($c,["rpc-enabled"=>true,"rpc-bind-address"=>"127.0.0.1","rpc-port"=>9091,
        "rpc-authentication-required"=>true,"rpc-username"=>$argv[2],"rpc-password"=>$argv[3],
        "rpc-whitelist-enabled"=>true,"rpc-whitelist"=>"127.0.0.1,::1","download-dir"=>$argv[4],
        "incomplete-dir-enabled"=>false,"umask"=>2]);
        file_put_contents($f,json_encode($c,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");' "$conf" "$user" "$password" "$downloads"
    as_root systemctl enable --now transmission-daemon
    as_root mkdir -p "$dir/data/runtime"
    printf '[transmission]\nport=9091\nusername=%s\npassword=%s\ndownload_dir=%s\n' "$user" "$password" "$downloads" | as_root tee "$ini" >/dev/null
    as_root chown -R www-data:www-data "$dir/data/runtime"
    as_root chmod 750 "$dir/data/runtime"
    as_root chmod 600 "$ini"
    if transmission_rpc_ok "$user" "$password"; then echo "Transmission connected to Vaultcast (RPC 127.0.0.1:9091, downloads in $downloads)."
    else echo 'Transmission was configured but its RPC did not answer. Check: systemctl status transmission-daemon, then rerun setup.'; fi
}
# Install the relay's packages and the vaultcast-netplay service (loopback port 3100). Safe to rerun.
setup_relay() {
    local dir="$1"
    [[ -f "$dir/netplay/package.json" ]] || { as_root mkdir -p "$dir/netplay"; as_root cp netplay/server.js netplay/package.json netplay/package-lock.json "$dir/netplay/"; as_root chown -R www-data:www-data "$dir/netplay"; }
    [[ -d "$dir/netplay/node_modules" ]] || (cd "$dir/netplay" && as_root npm ci --omit=dev --ignore-scripts --no-audit --no-fund)
    if [[ ! -f /etc/systemd/system/vaultcast-netplay.service ]]; then
        sed "s|/srv/vaultcast|$dir|g" deploy/vaultcast-netplay.service.example | as_root tee /etc/systemd/system/vaultcast-netplay.service >/dev/null
        as_root systemctl daemon-reload
    fi
    as_root systemctl enable --now vaultcast-netplay
}
echo 'Vaultcast — native Linux setup for Ubuntu 20.04+ (x86-64, arm64) and Debian 11+. Packages are installed only with your approval.'
. /etc/os-release
if [[ "${ID:-}" != ubuntu && "${ID:-}" != debian ]]; then
    echo 'Automatic installation supports Debian and Ubuntu. For other Linux systems, install the dependencies using your package manager and configure Application/deploy/nginx.conf.example for Application/.'
    exit 1
fi
architecture="$(dpkg --print-architecture)"
case "$architecture" in
    amd64) launcher=x86-64 ;;
    arm64) launcher=ARM64 ;;
    i386) launcher=x86-32 ;;
    *) echo "Unsupported architecture: $architecture. Use amd64, arm64 or i386."; exit 1 ;;
esac
[[ -z "${VAULTCAST_ARCH:-}" || "$VAULTCAST_ARCH" == "$launcher" ]] || { echo "Use the $launcher launcher for this system."; exit 1; }
# Distribution packages must meet the application's minimum runtime versions.
if [[ "$ID" == ubuntu ]] && dpkg --compare-versions "$VERSION_ID" lt 20.04; then
    echo "Ubuntu $VERSION_ID is not supported: this installer needs Ubuntu 20.04 or newer (PHP 7.4+)."
    echo 'Use Ubuntu 20.04 or newer, or Debian 11+ (Debian 12 also supports 32-bit x86).'
    echo 'An existing PHP 7.4+/MariaDB server can use Application directly; this launcher will not upgrade your operating system.'
    exit 1
fi
if [[ "$ID" == debian ]] && dpkg --compare-versions "$VERSION_ID" lt 11; then
    echo 'Use Debian 11 or newer for native dependency installation.'; exit 1
fi
if confirm 'Install missing nginx, PHP-FPM/CLI/extensions, and MariaDB? (Node.js 22 for multiplayer is set up automatically.)'; then
    if as_root apt-get update; then apt_updated=true; else
        echo 'Package indexes could not be downloaded. Check the network and configured APT repositories.'; exit 1
    fi
    if ! apt-cache show nginx php-fpm mariadb-server >/dev/null 2>&1; then
        echo 'The configured APT repositories do not supply the required packages.'
        if [[ "$ID" == ubuntu ]] && confirm 'Enable the official Ubuntu main and universe package sources?'; then
            case "$VERSION_CODENAME" in focal|jammy|noble) ;; *) echo 'Configure official repositories for this Ubuntu release first.'; exit 1 ;; esac
            mirror=http://archive.ubuntu.com/ubuntu
            security=http://security.ubuntu.com/ubuntu
            if [[ "$architecture" == arm64 ]]; then mirror=http://ports.ubuntu.com/ubuntu-ports; security="$mirror"; fi
            printf 'deb %s %s main universe\ndeb %s %s-updates main universe\ndeb %s %s-security main universe\n' "$mirror" "$VERSION_CODENAME" "$mirror" "$VERSION_CODENAME" "$security" "$VERSION_CODENAME" | as_root tee /etc/apt/sources.list.d/vaultcast-dependencies.list >/dev/null
            as_root apt-get update
        else
            echo 'Enable the official distribution repositories in Software & Updates, then rerun setup.'; exit 1
        fi
    fi
    as_root apt-get install -y --no-upgrade nginx php-fpm php-cli php-mysql php-mbstring php-curl php-xml php-gd php-zip php-opcache mariadb-server
fi
if ! command -v transmission-daemon >/dev/null && confirm 'Install optional Transmission for download integration? (Not needed for existing media)'; then
    as_root apt-get install -y transmission-daemon curl
fi
if ! command -v ffmpeg >/dev/null && confirm 'Install optional FFmpeg for analysis and offline conversion?'; then as_root apt-get install -y ffmpeg; fi
for program in nginx php mariadb; do command -v "$program" >/dev/null || { echo "$program is missing. Rerun setup to install dependencies."; exit 1; }; done
php -r 'exit(PHP_VERSION_ID >= 70400 ? 0 : 1);' || { echo 'PHP 7.4 or newer is required.'; exit 1; }
multiplayer=true
if [[ "${VAULTCAST_SKIP_MULTIPLAYER:-}" =~ ^(1|y|yes|true)$ ]]; then multiplayer=false; else ensure_node; fi
# Rerun on an existing installation: only complete the multiplayer relay and the Transmission
# connection; never touch the database, site or application files.
existing_site="$(ls /etc/nginx/sites-available/vaultcast-* 2>/dev/null | head -n 1 || true)"
if [[ -n "$existing_site" ]]; then
    existing_root="$(awk '$1 == "root" {gsub(";", "", $2); print $2; exit}' "$existing_site")"
    echo "Existing installation found at $existing_root ($existing_site); first-time setup is skipped."
    if $multiplayer && [[ -d "$existing_root" ]]; then setup_relay "$existing_root"; echo 'Multiplayer relay is installed and running on 127.0.0.1:3100.'; fi
    [[ -d "$existing_root" ]] && as_root cp ../LICENSE ../NOTICE "$existing_root/"
    [[ -d "$existing_root" ]] && configure_transmission "$existing_root"
    exit 0
fi
ask destination 'New application directory (absolute path)' "${VAULTCAST_DIR:-/srv/vaultcast}"
[[ "$destination" =~ ^/[a-zA-Z0-9_./-]+$ && "$destination" != / ]] || { echo 'Use an absolute path without spaces or shell characters.'; exit 1; }
ask web_port 'Web port' "${VAULTCAST_PORT:-9999}"
[[ "$web_port" =~ ^[0-9]+$ && "$web_port" -ge 1024 && "$web_port" -le 65535 ]] || { echo 'Choose a port from 1024 to 65535.'; exit 1; }
ask listen_address 'Listen address (0.0.0.0 for your network)' "${VAULTCAST_LISTEN:-127.0.0.1}"
[[ "$listen_address" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo 'Enter an IPv4 address.'; exit 1; }
php -r '$address=$argv[1]; $port=(int)$argv[2]; if (!filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) || $port===3100) exit(1); $s=@stream_socket_server("tcp://".$address.":".$port,$e,$m); if (!$s) {fwrite(STDERR,"Web port is unavailable.\n"); exit(1);} fclose($s);' "$listen_address" "$web_port" || exit 1
if $multiplayer; then
    php -r '$s=@stream_socket_server("tcp://127.0.0.1:3100",$e,$m); if (!$s) {fwrite(STDERR,"Multiplayer port 3100 is already in use.\n"); exit(1);} fclose($s);' || exit 1
fi
ask db_name 'New empty database name' "${VAULTCAST_DB:-vaultcast}"
[[ "$db_name" =~ ^[a-zA-Z0-9_]{1,32}$ ]] || { echo 'Use letters, digits and underscores.'; exit 1; }
confirm 'Create the application installation, database and a separate nginx site?' || exit 0
[[ ! -e "$destination" ]] || { echo 'Choose a new directory; existing files are never replaced.'; exit 1; }
site="/etc/nginx/sites-available/vaultcast-$web_port"
[[ ! -e "$site" && ! -e /etc/systemd/system/vaultcast-netplay.service ]] || { echo 'An installation already exists. Use its configuration instead of first-time setup.'; exit 1; }
as_root systemctl enable --now mariadb nginx
[[ -z "$(as_root mariadb -N -B -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$db_name'")" ]] || { echo 'That database already exists; choose another name.'; exit 1; }
as_root mkdir -p "$destination"
for entry in index.php router.php .htaccess manifest.json sw.js VERSION assets src deploy; do as_root cp -R -- "$entry" "$destination/"; done
# Vaultcast's licence and third-party notice. Distribution packages bring their own
# licences (/usr/share/doc/<package>/copyright); the relay's npm packages keep theirs in node_modules.
as_root cp ../LICENSE ../NOTICE "$destination/"
as_root mkdir "$destination/netplay"
as_root cp netplay/server.js netplay/package.json netplay/package-lock.json "$destination/netplay/"
as_root mkdir -p "$destination/data"
# The in-app updater replaces allowlisted files (index.php, assets/, src/, deploy/, netplay/, ...)
# and stages/backs up under data/updates, so the app tree belongs to the PHP-FPM user (www-data).
as_root chown -R www-data:www-data "$destination"
as_root chmod 755 "$destination"
as_root chmod 770 "$destination/data"
php_version="$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
as_root systemctl enable --now "php$php_version-fpm"
[[ -S "/run/php/php$php_version-fpm.sock" ]] || { echo 'PHP-FPM socket unavailable. Check its service configuration.'; exit 1; }
db_password="$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')"
db_user="rk_$(od -An -N6 -tx1 /dev/urandom | tr -d ' \n')"
as_root mariadb <<SQL
CREATE DATABASE \`$db_name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$db_user'@'localhost' IDENTIFIED BY '$db_password';
GRANT ALL PRIVILEGES ON \`$db_name\`.* TO '$db_user'@'localhost';
SQL
printf '%s\n%s\n%s\n' "$db_name" "$db_user" "$db_password" | as_root php -r '$v=explode("\n",trim(stream_get_contents(STDIN))); $c=["host"=>"127.0.0.1","port"=>3306,"name"=>$v[0],"user"=>$v[1],"password"=>$v[2]]; file_put_contents($argv[1],"<?php return ".var_export($c,true).";\n");' "$destination/data/setup-database.php"
as_root chown www-data:www-data "$destination/data/setup-database.php"
as_root chmod 600 "$destination/data/setup-database.php"
sed -e "s|root /srv/vaultcast;|root $destination;|" -e "s|listen 80;|listen $listen_address:$web_port;|" -e "s|php7.4-fpm.sock|php$php_version-fpm.sock|" deploy/nginx.conf.example | as_root tee "$site" >/dev/null
as_root ln -s "$site" "/etc/nginx/sites-enabled/vaultcast-$web_port"
as_root nginx -t
as_root systemctl reload nginx
if $multiplayer; then setup_relay "$destination"; fi
configure_transmission "$destination"
url_host="$listen_address"
[[ "$url_host" != 0.0.0.0 ]] || url_host=localhost
echo "Open http://$url_host:$web_port to name the site, create the administrator and choose media folders."
$multiplayer || echo 'Multiplayer was skipped (VAULTCAST_SKIP_MULTIPLAYER). Rerun setup without it to add the relay.'
echo 'The installer can use the database created by setup. Keep data writable; keep the application directory writable by PHP so Settings can install updates.'
