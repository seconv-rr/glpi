<?php

/**
 * -------------------------------------------------------------------------
 * Application Name plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later
 * version.
 * -------------------------------------------------------------------------
 */

/**
 * Plugin install process.
 *
 * The plugin keeps no state of its own — the name is a constant in setup.php and
 * is applied on every boot — so there is nothing to create here. GLPI still needs
 * the function to exist to move the plugin to the "installed" state.
 */
function plugin_appname_install(array $params = []): bool
{
    return true;
}

/**
 * Plugin uninstall process.
 *
 * Nothing was stored, so nothing has to be undone: $CFG_GLPI['app_name'] falls
 * back to the "GLPI" of src/autoload/CFG_GLPI.php:47 as soon as the plugin stops
 * booting.
 */
function plugin_appname_uninstall(): bool
{
    return true;
}
