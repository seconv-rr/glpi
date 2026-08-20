<?php

/**
 * -------------------------------------------------------------------------
 * Flat Dropdown plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Adds a form question type that behaves exactly like the core "Dropdown"
 * question, minus the entity group header.
 *
 * Dropdown::getDropdownValue() groups its results by entity whenever the
 * itemtype is recursive (src/Dropdown.php:3103), which ITILCategory always is.
 * The grouping is unconditional — there is no setting, no parameter and no hook
 * to turn it off — so every category dropdown is rendered under a
 * "Raiz > TI"-style optgroup even on a single-entity instance, where the header
 * carries no information at all.
 *
 * Rather than patch the core dropdown (which would affect every recursive
 * dropdown in GLPI: locations, groups, categories...), this plugin registers a
 * *second* question type. Forms keep using the native one until an author picks
 * this one, and uninstalling the plugin cannot break a form built on the native
 * field.
 *
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later
 * version.
 * -------------------------------------------------------------------------
 */

use Glpi\Form\QuestionType\QuestionTypesManager;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Flatdropdown\Form\QuestionTypeFlatItemDropdown;

const PLUGIN_FLATDROPDOWN_VERSION = '1.0.0';

function plugin_init_flatdropdown(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['flatdropdown'] = true;

    // A question type registered while the plugin is inactive would be offered in
    // the form editor but its class would not be loadable on the next request.
    if (!(new Plugin())->isActivated('flatdropdown')) {
        return;
    }

    // Plugin types are appended to the ones the manager discovers by scanning
    // src/Glpi/Form/QuestionType/ (QuestionTypesManager::loadCoreQuestionsTypes(),
    // src/Glpi/Form/QuestionType/QuestionTypesManager.php:299).
    QuestionTypesManager::getInstance()->registerPluginQuestionType(
        new QuestionTypeFlatItemDropdown()
    );
}

function plugin_version_flatdropdown(): array
{
    return [
        'name'         => 'Flat Dropdown',
        'version'      => PLUGIN_FLATDROPDOWN_VERSION,
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
