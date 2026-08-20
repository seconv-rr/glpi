<?php

/**
 * -------------------------------------------------------------------------
 * Flat Dropdown plugin for GLPI
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
 * The plugin owns no table and no configuration: the question type is registered
 * in memory on every request and the answers it stores use the same columns as
 * the native dropdown question.
 */
function plugin_flatdropdown_install(array $params = []): bool
{
    return true;
}

/**
 * Plugin uninstall process.
 *
 * Questions already created with this type keep their rows but their class is no
 * longer registered, so the form editor will report them as an unknown type until
 * the plugin is installed again or the question is replaced by a native dropdown.
 */
function plugin_flatdropdown_uninstall(): bool
{
    return true;
}
