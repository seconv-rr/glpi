#!/usr/bin/env bash
# Check a running instance actually wears the SECONV-RR branding.
#
# verify-branding.sh proves the image is built right; it cannot prove the instance is. The
# name comes from a plugin, and a plugin only takes effect once it is installed and active in
# the database — state that lives in the /var/glpi volume, not in the image. So a deploy where
# nobody ran `plugin:activate appname`, or where someone deactivated it, serves a site titled
# "GLPI" with no error anywhere. Same for the logos if a plugin wrote its own over
# public/pics (i-Vertix's `mod` does exactly that on activation).
#
#   ./deploy/coolify/smoke.sh https://helpdesk.example
#   ./deploy/coolify/smoke.sh http://localhost:8080 --dist /path/to/dist
#
# Anonymous requests only — no credentials needed, the login page carries everything checked.
# Exit 0: the site is branded. Exit 1: at least one check failed.

set -uo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$here/../.." && pwd)"

url=""
dist="$repo_root/branding/seconv-rr/dist"

usage() {
    awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$0"
    exit "${1:-1}"
}

while [ $# -gt 0 ]; do
    case "$1" in
    --dist)
        dist="${2:-}"
        shift 2
        ;;
    -h | --help)
        usage 0
        ;;
    -*)
        echo "error: unknown option '$1'" >&2
        usage 1
        ;;
    *)
        url="$1"
        shift
        ;;
    esac
done

if [ -z "$url" ]; then
    echo "error: no URL given" >&2
    usage 1
fi

url="${url%/}"

if [ ! -d "$dist" ]; then
    echo "error: $dist is not a directory" >&2
    exit 1
fi

failures=0

fail() {
    printf 'FAIL  %s\n' "$1" >&2
    failures=$((failures + 1))
}

pass() {
    printf 'ok    %s\n' "$1"
}

setup_php="$repo_root/plugins/appname/setup.php"
scss="$repo_root/css/includes/_base.scss"

# The name the plugin is supposed to install, read from the plugin itself so renaming the
# instance never means remembering to edit this script too.
expected_name="$(sed -nE "s/^const PLUGIN_APPNAME_APP_NAME *= *'([^']+)'.*/\1/p" "$setup_php" | head -1)"

if [ -z "$expected_name" ]; then
    echo "error: could not read PLUGIN_APPNAME_APP_NAME from ${setup_php#"$repo_root"/}" >&2
    exit 1
fi

# The tab title is the cheapest proof the plugin booted: it is the same $CFG_GLPI['app_name']
# the notification footers and the MFA issuer label read.
check_title() {
    local body title
    if ! body="$(curl -fsSL --max-time 20 "$url/")"; then
        fail "GET $url/ failed — nothing else could be checked"
        return 1
    fi

    title="$(printf '%s' "$body" | tr -d '\n' | sed -nE 's#.*<title>(.*)</title>.*#\1#p')"

    if [ -z "$title" ]; then
        fail "no <title> in the page served at $url/"
        return
    fi

    case "$title" in
    *"$expected_name"*)
        pass "the tab title says \"$title\" — plugins/appname is installed and active"
        ;;
    *)
        fail "the tab title says \"$title\", not \"$expected_name\" — appname is not active on this instance: php bin/console plugin:install --username=glpi appname && php bin/console plugin:activate appname"
        ;;
    esac
}

# Every logo the stylesheet asks for, fetched the way a browser would and compared with the
# file the image was built from.
check_assets() {
    local names=() name path served_sum local_sum wrong=()

    mapfile -t names < <(grep -oE 'pics/logos/[A-Za-z0-9._/-]+\.(png|svg|ico)' "$scss" | sed 's#.*/##' | sort -u)
    names+=("favicon.ico")

    for name in "${names[@]}"; do
        case "$name" in
        favicon.ico) path="/pics/favicon.ico" ;;
        *) path="/pics/logos/$name" ;;
        esac

        if [ ! -f "$dist/$name" ]; then
            wrong+=("$name (not in dist/)")
            continue
        fi

        # No -S here: the loop reports the failure itself, curl's own message would only
        # repeat it once per asset.
        if ! served_sum="$(curl -fsL --max-time 20 "$url$path" | sha256sum | cut -d' ' -f1)"; then
            wrong+=("$name (GET $path failed)")
            continue
        fi

        local_sum="$(sha256sum <"$dist/$name" | cut -d' ' -f1)"
        [ "$served_sum" = "$local_sum" ] || wrong+=("$name")
    done

    if [ "${#wrong[@]}" -gt 0 ]; then
        fail "served assets do not match dist/: ${wrong[*]} — something wrote over public/pics, or the image predates the current dist/"
        return
    fi
    pass "the ${#names[@]} branded assets the site serves match dist/"
}

echo "smoke-testing the SECONV-RR branding at $url"
echo

check_title
check_assets

echo
if [ "$failures" -gt 0 ]; then
    echo "$failures check(s) failed — the instance is not fully branded." >&2
    exit 1
fi

echo "instance branded."
