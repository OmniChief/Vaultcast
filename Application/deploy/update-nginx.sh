#!/usr/bin/env bash
# Install the Xtream compatibility route in an existing Vaultcast nginx site.
set -euo pipefail

usage() {
    echo "Usage: sudo bash ${0##*/} [--site FILE | --terramaster INSTALL_DIR]"
    echo 'Without arguments, select the installed TerraMaster instance or the enabled Debian/Ubuntu Vaultcast site.'
}
die() { echo "Vaultcast nginx: $*" >&2; exit 1; }
site=''
nas=''
case "${1:-}" in
    -h|--help) usage; exit 0 ;;
    --site) [[ $# == 2 && -n $2 ]] || { usage >&2; exit 2; }; site=$2 ;;
    --terramaster) [[ $# == 2 && -n $2 ]] || { usage >&2; exit 2; }; nas=$2 ;;
    '') [[ $# == 0 ]] || { usage >&2; exit 2; } ;;
    *) usage >&2; exit 2 ;;
esac
(( EUID == 0 )) || die 'Run this script with sudo or as root.'
if [[ -z $site && -z $nas ]]; then
    # From an installed app/deploy/ directory this also finds custom NAS volumes.
    nearby=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)
    shopt -s nullglob
    sites=(/etc/nginx/sites-enabled/vaultcast-*)
    for base in "$nearby" "${VAULTCAST_DIR:-/Volume1/vaultcast}"; do
        if [[ -f $base/etc/nginx/vaultcast-site.conf && -f $base/etc/nginx/nginx.conf ]]; then
            nas=$base
            break
        fi
    done
    if [[ -n $nas && ${#sites[@]} -ne 0 ]]; then die 'Both nginx instances exist. Select --site or --terramaster.'; fi
    if [[ -z $nas ]]; then
        (( ${#sites[@]} == 1 )) || die 'Could not select one enabled Vaultcast site. Use --site FILE or --terramaster INSTALL_DIR.'
        site=${sites[0]}
    fi
fi

nginx=(/usr/sbin/nginx)
if [[ -n $nas ]]; then
    main=$(realpath -e -- "$nas/etc/nginx/nginx.conf") || die 'TerraMaster nginx configuration was not found.'
    nginx+=(-c "$main")
    site="$nas/etc/nginx/vaultcast-site.conf"
fi
site=$(realpath -e -- "$site") || die 'Site configuration was not found.'
[[ -f $site ]] || die 'Expected a regular site configuration file.'
directory=$(dirname -- "$site")
# Serialize separate invocations even when the site was selected through a different symlink.
exec 9<"$directory"
flock -x 9

# Confirm the running installation's configuration includes this file. Do not print the configuration dump.
includes=$("${nginx[@]}" -T | awk '/^# configuration file .*:$/ { sub(/^# configuration file /,""); sub(/:$/,""); print }')
included=false
while IFS= read -r file; do
    if [[ -n $file && $file -ef $site ]]; then included=true; break; fi
done <<< "$includes"
$included || die "The selected nginx configuration does not include $site."

candidate=$(mktemp "$directory/.vaultcast-nginx-new.XXXXXXXX")
backup=''
keep_backup=false
restore=false
reload_attempted=false
cleanup() {
    local code=$?
    trap - EXIT HUP INT TERM
    if $restore; then
        if mv -fT -- "$backup" "$site"; then
            echo "Restored $site." >&2
            if $reload_attempted; then "${nginx[@]}" -t && "${nginx[@]}" -s reload || echo 'Reload of the restored configuration failed.' >&2; fi
        else
            keep_backup=true
            echo "Restore failed. Original configuration is in $backup." >&2
        fi
    fi
    rm -f -- "$candidate"
    if [[ -n $backup ]] && ! $keep_backup; then rm -f -- "$backup"; fi
    exit "$code"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
backup=$(mktemp "$directory/.vaultcast-nginx-original.XXXXXXXX")
cp --preserve=all -- "$site" "$backup"
cp --preserve=all -- "$backup" "$candidate"

# Recognize the installer layout. Preserve custom settings and refuse ambiguous or conflicting routes.
awk '
    {
        line=$0
        sub(/[[:space:]]*#.*/, "", line)
        gsub(/[[:space:]]+/, " ", line)
        sub(/^ /, "", line); sub(/ $/, "", line)
        if (line ~ /^server *[{]/) servers++
        if (line == "location ^~ /netplay/ {") { relay++; in_relay=1 }
        if (in_relay && line ~ /^proxy_pass http[s]?:\/\/[^ ;]+\/;$/) proxied++
        if (line == "}") in_relay=0
        if (line ~ /^location .*xtream_/) {
            if (line != "location ^~ /api/live/xtream_ {") conflict=1
            existing++; in_xtream=1
        } else if (in_xtream && line != "") {
            if (line == "}") in_xtream=0
            else if (line == "rewrite ^/api/live/(xtream_[a-z_]+)/?$ /netplay/application/live/$1 last;" && step==0) step=1
            else if (line == "return 404;" && step==1) step=2
            else conflict=1
        }
        if (line == "location ^~ /api/ {") { api++; anchor=NR }
        lines[NR]=$0
    }
    END {
        if (servers!=1 || api!=1 || relay!=1 || proxied!=1 || conflict || existing>1 || (existing && step!=2)) {
            print "Unrecognized Vaultcast site layout or conflicting Xtream route. Site was left intact." > "/dev/stderr"
            exit 1
        }
        for (i=1; i<=NR; i++) {
            if (!existing && i==anchor) {
                match(lines[i], /^[[:blank:]]*/); indent=substr(lines[i],1,RLENGTH)
                print indent "location ^~ /api/live/xtream_ {"
                print indent "    rewrite ^/api/live/(xtream_[a-z_]+)/?$ /netplay/application/live/$1 last;"
                print indent "    return 404;"
                print indent "}"
            }
            print lines[i]
        }
    }
' "$backup" > "$candidate"

cmp -s -- "$site" "$backup" || die 'Site changed during preparation. Run the repair again.'
if ! cmp -s -- "$backup" "$candidate"; then
    restore=true
    mv -fT -- "$candidate" "$site"
    keep_backup=true
fi
"${nginx[@]}" -t
reload_attempted=true
"${nginx[@]}" -s reload
restore=false
echo "Validated $site and requested an nginx reload."
if $keep_backup; then echo "Original configuration: $backup"; fi
