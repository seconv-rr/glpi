#!/usr/bin/env bash
# Push the generated SECONV-RR assets into the `mod` (UI Branding) plugin and into the
# files GLPI actually serves.
#
# Run it AFTER `bin/console plugin:install mod`, never before: the plugin's install()
# copies the untouched GLPI logos to files/_plugins/mod/backups, and that backup is the
# only way `Restore` and the uninstall routine can give the originals back.

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
dist="$here/dist"
glpi_root="$(cd "$here/../.." && pwd)"

plugin_files="$glpi_root/files/_plugins/mod"
plugin_images="$plugin_files/images"
pics="$glpi_root/public/pics"

if [ ! -d "$plugin_files/backups" ]; then
    echo "error: $plugin_files/backups is missing — install the plugin first:" >&2
    echo "  bin/console plugin:install mod && bin/console plugin:activate mod" >&2
    exit 1
fi

if [ ! -d "$dist" ]; then
    echo "error: $dist is missing — run: python3 $here/generate-assets.py" >&2
    exit 1
fi

# The plugin only reads per-variant logo files while theme_logos is on; with it off it
# fans a single image out to all three variants and the header text would be unreadable
# on one of the two backgrounds.
printf 'title="SECONV-RR"\nlogin="0"\ntheme_logos="1"\n' >"$plugin_files/modifiers.ini"

for variant in black grey white; do
    for base in logo-G-100 logo-GLPI-100 logo-GLPI-250; do
        cp "$dist/$base-$variant.png" "$plugin_images/$base-$variant.png"
        cp "$dist/$base-$variant.png" "$pics/logos/$base-$variant.png"
    done
done

cp "$dist/favicon.ico" "$plugin_images/favicon.ico"
cp "$dist/favicon.ico" "$pics/favicon.ico"

echo "SECONV-RR branding applied. Hard-reload the browser (Ctrl+Shift+R) to drop the cached logos."
