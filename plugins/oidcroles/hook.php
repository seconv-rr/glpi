<?php

/**
 * -------------------------------------------------------------------------
 * OIDC Roles plugin for GLPI
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
 * The plugin keeps no state of its own: the role map is a constant in setup.php and
 * the profiles it grants are ordinary rows of glpi_profiles_users. GLPI still needs
 * the function to exist to move the plugin to the "installed" state.
 *
 * @param array<string, mixed> $params
 */
function plugin_oidcroles_install(array $params = []): bool
{
    return true;
}

/**
 * Plugin uninstall process.
 *
 * The dynamic profiles already granted are left where they are. GLPI drops them by
 * itself at each user's next single sign-on login, when no rule grants them anymore
 * (User::applyRightRules(), src/User.php:1707).
 */
function plugin_oidcroles_uninstall(): bool
{
    return true;
}
