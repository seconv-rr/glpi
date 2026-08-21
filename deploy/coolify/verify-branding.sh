#!/usr/bin/env bash
# Fail loudly when the SECONV-RR branding has quietly stopped working.
#
# Two failures never announce themselves:
#
#   1. GLPI adds or renames a logo. public/pics/logos/ ends up holding a name dist/ has no
#      counterpart for, the Dockerfile's COPY leaves it alone, and a GLPI-branded image is
#      served from whichever screen reads it.
#   2. Upstream moves what plugins/appname stands on — the $CFG_GLPI['app_name'] default, the
#      boot hook that overrides it, the template that prints it, or the supported version
#      range. The plugin keeps loading, and the tab title silently says "GLPI" again.
#
# Neither one raises an error by itself, so this script is the error. It only reads.
#
#   ./deploy/coolify/verify-branding.sh                                    the checkout
#   ./deploy/coolify/verify-branding.sh --glpi-root /var/www/glpi --dist /tmp/dist
#
# Exit 0: every check passed. Exit 1: at least one failed — all of them are reported, the
# script does not stop at the first.
#
# What it cannot see: whether the plugin is actually installed and active in an instance.
# That lives in the database, not in the image — smoke.sh checks it against a running site.

set -uo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

glpi_root=""
dist=""

usage() {
    awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$0"
    exit "${1:-1}"
}

while [ $# -gt 0 ]; do
    case "$1" in
    --glpi-root)
        glpi_root="${2:-}"
        shift 2
        ;;
    --dist)
        dist="${2:-}"
        shift 2
        ;;
    -h | --help)
        usage 0
        ;;
    *)
        echo "error: unknown argument '$1'" >&2
        usage 1
        ;;
    esac
done

# Defaults assume the checkout: this script lives in deploy/coolify, the branding two levels up.
: "${glpi_root:=$(cd "$here/../.." && pwd)}"
: "${dist:=$glpi_root/branding/seconv-rr/dist}"

failures=0

fail() {
    printf 'FAIL  %s\n' "$1" >&2
    failures=$((failures + 1))
}

pass() {
    printf 'ok    %s\n' "$1"
}

# A check that cannot even read what it needs is a failure, not a skip: it means the layout
# moved, which is exactly the kind of silent drift this script exists to catch.
readable() {
    if [ -r "$1" ]; then
        return 0
    fi
    fail "$2: $1 is missing or unreadable"
    return 1
}

for dir in "$glpi_root" "$dist"; do
    if [ ! -d "$dir" ]; then
        echo "error: $dir is not a directory" >&2
        exit 1
    fi
done

pics="$glpi_root/public/pics"
scss="$glpi_root/css/includes/_base.scss"
head_twig="$glpi_root/templates/layout/parts/head.html.twig"
cfg_glpi="$glpi_root/src/autoload/CFG_GLPI.php"
plugin_php="$glpi_root/src/Plugin.php"
constants="$glpi_root/src/autoload/constants.php"
appname_setup="$glpi_root/plugins/appname/setup.php"

in_dist() {
    [ -f "$dist/$1" ]
}

# 1. Every logo GLPI ships has a SECONV-RR replacement.
#
# After the COPY (or apply.sh) every name dist/ knows about has been overwritten, so a file
# still carrying GLPI's artwork can only be one dist/ has no counterpart for.
check_logo_coverage() {
    local uncovered=() served name
    for served in "$pics"/logos/logo-*.png; do
        [ -e "$served" ] || continue
        name="$(basename "$served")"
        in_dist "$name" || uncovered+=("$name")
    done

    if [ "${#uncovered[@]}" -gt 0 ]; then
        fail "GLPI ships logos dist/ does not replace: ${uncovered[*]} — regenerate them in branding/seconv-rr/generate-assets.py"
        return
    fi
    pass "every logo in public/pics/logos has a dist/ counterpart"
}

# 2. Every logo the stylesheet asks for is one of ours.
#
# Coverage alone misses a rename: GLPI can point --glpi-logo at a brand new file, which the
# loop above never sees because upstream deleted the old name it replaced.
check_logo_references() {
    readable "$scss" "stylesheet check" || return

    local missing=() referenced name
    mapfile -t referenced < <(grep -oE 'pics/logos/[A-Za-z0-9._/-]+\.(png|svg|ico)' "$scss" | sed 's#.*/##' | sort -u)

    if [ "${#referenced[@]}" -eq 0 ]; then
        fail "stylesheet check: no logo URL found in ${scss#"$glpi_root"/} — GLPI stopped wiring the logos there"
        return
    fi

    for name in "${referenced[@]}"; do
        in_dist "$name" || missing+=("$name")
    done

    if [ "${#missing[@]}" -gt 0 ]; then
        fail "the stylesheet serves logos dist/ does not provide: ${missing[*]}"
        return
    fi
    pass "the ${#referenced[@]} logos ${scss#"$glpi_root"/} references all come from dist/"
}

# 3. The files really landed. Catches a COPY that silently matched nothing, an apply.sh that
#    was never run, and a plugin that wrote its own images over public/pics at activation.
check_applied() {
    local stale=() source target name
    for source in "$dist"/logo-*.png "$dist/favicon.ico"; do
        [ -e "$source" ] || continue
        name="$(basename "$source")"
        case "$name" in
        favicon.ico) target="$pics/favicon.ico" ;;
        *) target="$pics/logos/$name" ;;
        esac

        if [ ! -f "$target" ]; then
            stale+=("$name (absent)")
            continue
        fi
        if [ "$(sha256sum <"$source" | cut -d' ' -f1)" != "$(sha256sum <"$target" | cut -d' ' -f1)" ]; then
            stale+=("$name")
        fi
    done

    if [ "${#stale[@]}" -gt 0 ]; then
        fail "served files differ from dist/: ${stale[*]} — run branding/seconv-rr/apply.sh, or check what overwrote them"
        return
    fi
    pass "the served logos and favicon match dist/ byte for byte"
}

check_favicon_reference() {
    readable "$head_twig" "favicon check" || return

    if ! grep -q "asset_path('/pics/favicon.ico')" "$head_twig"; then
        fail "favicon check: ${head_twig#"$glpi_root"/} no longer points at /pics/favicon.ico"
        return
    fi
    pass "the layout still serves the favicon from public/pics/favicon.ico"
}

# 4. The three things plugins/appname stands on.
check_app_name_anchor() {
    readable "$cfg_glpi" "app_name check" || return

    if ! grep -qE "^\\\$CFG_GLPI\['app_name'\] *=" "$cfg_glpi"; then
        fail "app_name check: \$CFG_GLPI['app_name'] is no longer assigned in ${cfg_glpi#"$glpi_root"/} — plugins/appname overrides a key that stopped existing"
        return
    fi
    pass "\$CFG_GLPI['app_name'] still exists for plugins/appname to override"
}

check_boot_hook() {
    readable "$plugin_php" "boot hook check" || return

    if ! grep -q "plugin_%s_boot" "$plugin_php"; then
        fail "boot hook check: ${plugin_php#"$glpi_root"/} no longer calls plugin_<key>_boot() — plugin_appname_boot() is never reached"
        return
    fi
    pass "GLPI still calls plugin_<key>_boot(), which is where appname sets the name"
}

check_title_consumer() {
    readable "$head_twig" "tab title check" || return

    if ! grep -q "config('app_name')" "$head_twig"; then
        fail "tab title check: ${head_twig#"$glpi_root"/} stopped reading config('app_name') — the browser tab no longer follows the plugin"
        return
    fi
    pass "the browser tab title still reads config('app_name')"
}

# 5. GLPI's version is inside the range the plugin declares.
#
# Outside it GLPI refuses to activate the plugin, and an instance that is merely redeployed
# keeps running with the name back to "GLPI" and nothing in the interface saying why.
check_version_range() {
    readable "$constants" "version check" || return
    readable "$appname_setup" "version check" || return

    local version base min max
    version="$(sed -nE "s/^define\('GLPI_VERSION', *'([^']+)'\).*/\1/p" "$constants" | head -1)"
    min="$(sed -nE "s/.*'min' *=> *'([^']+)'.*/\1/p" "$appname_setup" | head -1)"
    max="$(sed -nE "s/.*'max' *=> *'([^']+)'.*/\1/p" "$appname_setup" | head -1)"

    if [ -z "$version" ] || [ -z "$min" ] || [ -z "$max" ]; then
        fail "version check: could not read GLPI_VERSION ('$version') or the appname range ('$min'..'$max')"
        return
    fi

    # 11.0.9-dev compares as 11.0.9: the suffix says "not released yet", not "older".
    base="${version%%-*}"

    if [ "$(printf '%s\n%s\n' "$min" "$base" | sort -V | head -1)" != "$min" ]; then
        fail "version check: GLPI $version is below the 'min' => '$min' plugins/appname declares — GLPI will not activate it"
        return
    fi

    # GLPI treats 'max' as excluded, so equality is already out of range.
    if [ "$base" = "$max" ] || [ "$(printf '%s\n%s\n' "$base" "$max" | sort -V | head -1)" = "$max" ]; then
        fail "version check: GLPI $version has reached the 'max' => '$max' plugins/appname declares — GLPI will not activate it, and the name falls back to \"GLPI\". Review the plugin against the new release before widening the range"
        return
    fi

    pass "GLPI $version is inside the $min..<$max range plugins/appname declares"
}

echo "verifying the SECONV-RR branding in $glpi_root"
echo "against $dist"
echo

check_logo_coverage
check_logo_references
check_applied
check_favicon_reference
check_app_name_anchor
check_boot_hook
check_title_consumer
check_version_range

echo
if [ "$failures" -gt 0 ]; then
    echo "$failures check(s) failed — the branding is not intact." >&2
    exit 1
fi

echo "branding intact."
