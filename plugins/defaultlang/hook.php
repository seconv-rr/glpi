<?php

/**
 * -------------------------------------------------------------------------
 * Default Language plugin for GLPI
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
 * Sets the instance default language and clears the per-user language of every
 * account that already exists, so they inherit the new default instead of the
 * en_GB that GLPI's installer wrote into the seed accounts
 * (install/empty_data.php:9451).
 */
function plugin_defaultlang_install(array $params = []): bool
{
    /** @var DBmysql $DB */
    global $DB, $CFG_GLPI;

    // Remembered so the uninstall can put the instance back where it was. Only on the first
    // install: a reinstall would otherwise record the language this plugin itself forced,
    // since plugin_defaultlang_boot() already overwrote $CFG_GLPI['language'] in memory.
    $recorded = Config::getConfigurationValues('plugin:defaultlang', ['previous_language']);
    if (!isset($recorded['previous_language'])) {
        Config::setConfigurationValues(
            'plugin:defaultlang',
            ['previous_language' => $CFG_GLPI['language'] ?? 'en_GB']
        );
    }

    Config::setConfigurationValues('core', ['language' => PLUGIN_DEFAULTLANG_LANGUAGE]);
    $CFG_GLPI['language'] = PLUGIN_DEFAULTLANG_LANGUAGE;

    // NULL means "inherit the instance default" for every user preference
    // (User::computePreferences(), src/User.php:301).
    $DB->update('glpi_users', ['language' => null], ['NOT' => ['language' => null]]);

    return true;
}

/**
 * Plugin uninstall process.
 *
 * Restores the default language recorded at install time. The per-user languages
 * cleared by the install are not restored — they were dropped, not saved.
 */
function plugin_defaultlang_uninstall(): bool
{
    $config = Config::getConfigurationValues('plugin:defaultlang', ['previous_language']);
    $previous = $config['previous_language'] ?? null;

    if (is_string($previous) && $previous !== '') {
        Config::setConfigurationValues('core', ['language' => $previous]);
    }

    Config::deleteConfigurationValues('plugin:defaultlang', ['previous_language']);

    return true;
}
