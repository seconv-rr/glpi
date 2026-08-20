# Production image for Coolify: the official GLPI image with the SECONV-RR branding baked in.
#
# The branding has to live in the image, not in a volume: the official image keeps
# /var/www/glpi in an image layer and only persists /var/glpi (config, files, logs,
# marketplace). Logos written into public/pics at runtime would be lost on every redeploy.
#
# Build context is the repository root, so Coolify needs:
#   Base Directory     /
#   Dockerfile         /deploy/coolify/Dockerfile
#
# Pin the patch version deliberately. The GLPI project rebuilds each tag periodically to
# pick up Debian/PHP security fixes without changing application code, so `11.0.8` keeps
# receiving those while never moving to 11.0.9 on its own.
FROM glpi/glpi:11.0.8

# Inherited from the base image, repeated so scanners can see it without resolving the base.
USER www-data

# All nine variants belong in the same directory; GLPI 11 picks the one the palette asks for.
COPY --chown=www-data:www-data branding/seconv-rr/dist/logo-*.png /var/www/glpi/public/pics/logos/
COPY --chown=www-data:www-data branding/seconv-rr/dist/favicon.ico /var/www/glpi/public/pics/favicon.ico

# The logos cover everything visual, but the "GLPI" in the browser tab comes from
# $CFG_GLPI['app_name'] (src/autoload/CFG_GLPI.php:47), which only a plugin can override.
# The same value is used in notification e-mail footers and as the MFA/TOTP issuer label.
ADD --chown=www-data:www-data \
    https://github.com/i-Vertix/glpi-modifications/releases/download/11.0.5/glpi-mod-11.0.5.tar.gz \
    /tmp/glpi-mod.tar.gz
RUN tar --extract --gzip --file=/tmp/glpi-mod.tar.gz --directory=/var/www/glpi/plugins \
    && rm /tmp/glpi-mod.tar.gz

# Portuguese, always and only. The "Default language" setting alone loses to the browser's
# Accept-Language header on every anonymous page (Session::getPreferredLanguage(),
# src/Session.php:971) and to each user's own preference; this plugin removes every other
# locale from $CFG_GLPI['languages'], which is what all of those read. See its README.
COPY --chown=www-data:www-data plugins/defaultlang /var/www/glpi/plugins/defaultlang

# Category dropdowns in the form editor are always grouped under their entity's complete name
# ("Raiz > TI"), because Dropdown::getDropdownValue() forces the grouping on for every recursive
# itemtype (src/Dropdown.php:3103) with no setting to turn it off. This plugin adds a second
# question type that renders the same dropdown without that header. See its README.
COPY --chown=www-data:www-data plugins/flatdropdown /var/www/glpi/plugins/flatdropdown
