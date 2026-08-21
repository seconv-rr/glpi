#!/usr/bin/env bash
# Copy the generated SECONV-RR assets over the logos and favicon GLPI serves.
#
# Development stack only. The production image gets the same files at build time
# (deploy/coolify/Dockerfile), because public/pics lives in an image layer and anything
# written there at runtime is lost on the next redeploy.
#
# This overwrites ten files that git tracks; see git-guard.sh for living with that.

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
dist="$here/dist"
glpi_root="$(cd "$here/../.." && pwd)"

pics="$glpi_root/public/pics"

if [ ! -d "$dist" ]; then
    echo "error: $dist is missing — run: python3 $here/generate-assets.py" >&2
    exit 1
fi

if [ ! -d "$pics/logos" ]; then
    echo "error: $pics/logos is missing — is $glpi_root a GLPI checkout?" >&2
    exit 1
fi

# Whatever generate-assets.py produced, rather than a second hardcoded list of the nine names.
for source in "$dist"/logo-*.png; do
    cp "$source" "$pics/logos/$(basename "$source")"
done

cp "$dist/favicon.ico" "$pics/favicon.ico"

echo "SECONV-RR branding applied. Hard-reload the browser (Ctrl+Shift+R) to drop the cached logos."
