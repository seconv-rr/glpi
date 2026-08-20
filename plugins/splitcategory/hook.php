<?php

/**
 * -------------------------------------------------------------------------
 * Split Category plugin for GLPI
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
 * The plugin owns no table and no configuration: it only reshapes, in the
 * browser, a question that GLPI already stores on its own.
 */
function plugin_splitcategory_install(array $params = []): bool
{
    return true;
}

/**
 * Plugin uninstall process.
 *
 * Nothing to clean up. Existing forms and existing answers are native, so they
 * keep working with the single tree dropdown GLPI renders by default.
 */
function plugin_splitcategory_uninstall(): bool
{
    return true;
}
