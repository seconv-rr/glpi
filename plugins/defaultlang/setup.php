<?php

/**
 * -------------------------------------------------------------------------
 * Default Language plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Locks the whole instance to a single language.
 *
 * Out of the box the "Default language" setting is only a fallback:
 * Session::getPreferredLanguage() (src/Session.php:971) matches the visitor's
 * `Accept-Language` header against $CFG_GLPI['languages'] first and only reads
 * $CFG_GLPI['language'] when nothing matched, and any user can pick another
 * language in their preferences. A workstation with an en-US browser therefore
 * gets the login page, the anonymous helpdesk and the password-reset pages in
 * English no matter what the administrator configured.
 *
 * This plugin removes every other locale from $CFG_GLPI['languages'], which is
 * the array every one of those code paths reads. Nothing else can be negotiated,
 * selected or stored.
 *
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later
 * version.
 * -------------------------------------------------------------------------
 */

use Glpi\Plugin\Hooks;

const PLUGIN_DEFAULTLANG_VERSION = '1.0.0';

/**
 * The one language the instance is allowed to use. Must be a key of
 * $CFG_GLPI['languages'] (src/autoload/CFG_GLPI.php:105).
 */
const PLUGIN_DEFAULTLANG_LANGUAGE = 'pt_BR';

/**
 * Reduce the instance to a single locale.
 *
 * Runs from the plugin boot function on purpose: the kernel fires it at priority 140,
 * before SessionStart (130) and LoadLanguage (120), so the very first language
 * resolution of the request already sees the restricted list
 * (src/Glpi/Kernel/ListenersPriority.php:64).
 */
function plugin_defaultlang_boot(): void
{
    global $CFG_GLPI;

    $language = PLUGIN_DEFAULTLANG_LANGUAGE;

    // A locale GLPI does not ship would leave the array empty and break every page
    // that reads $CFG_GLPI['languages'][...] — better to stay out of the way.
    if (!isset($CFG_GLPI['languages'][$language])) {
        trigger_error(
            sprintf('Plugin defaultlang: unknown language "%s", leaving GLPI untouched.', $language),
            E_USER_WARNING
        );
        return;
    }

    // Every language consumer reads this array: the Accept-Language negotiation
    // (src/Session.php:984), the language dropdowns (Dropdown::getLanguages(),
    // src/Dropdown.php:1568) and the per-user fallback (User::computePreferences(),
    // src/User.php:318), which now resets any other stored language on its own.
    $CFG_GLPI['languages'] = [$language => $CFG_GLPI['languages'][$language]];

    // The stored default is what the fallbacks land on, so keep it in sync even if
    // the database still holds an older value.
    $CFG_GLPI['language'] = $language;
}

function plugin_init_defaultlang(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['defaultlang'] = true;

    // Sessions opened before the plugin was activated still carry their old language,
    // and Session::loadLanguage() already ran (priority 120) by the time plugins are
    // initialized (110). POST_INIT is the first point where that can be corrected.
    $PLUGIN_HOOKS[Hooks::POST_INIT]['defaultlang'] = 'plugin_defaultlang_force_session_language';
}

/**
 * Drop a language left over in the session by an older request.
 */
function plugin_defaultlang_force_session_language(): void
{
    global $CFG_GLPI;

    if (!isset($_SESSION) || !isset($CFG_GLPI['languages'][PLUGIN_DEFAULTLANG_LANGUAGE])) {
        return;
    }

    if (($_SESSION['glpilanguage'] ?? null) === PLUGIN_DEFAULTLANG_LANGUAGE) {
        return; // The common case: nothing to reload.
    }

    $_SESSION['glpilanguage'] = PLUGIN_DEFAULTLANG_LANGUAGE;

    // Also refreshes glpipluralnumber and glpiisrtl.
    Session::loadLanguage();
}

function plugin_version_defaultlang(): array
{
    return [
        'name'         => 'Default Language',
        'version'      => PLUGIN_DEFAULTLANG_VERSION,
        'author'       => 'SECONV-RR',
        'license'      => 'GPLv3',
        'requirements' => [
            'glpi' => [
                'min' => '11.0',
                'max' => '12.0',
            ],
        ],
    ];
}
