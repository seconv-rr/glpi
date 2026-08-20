<?php

/**
 * -------------------------------------------------------------------------
 * Split Category plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Splits the ITIL category question of a form into two chained dropdowns:
 * a "Categoria" one listing the top level categories, and a "Subcategoria" one
 * listing what lives under the category that was just picked.
 *
 * The plugin deliberately does *not* register a new question type. GLPI matches
 * question types by exact class name — `Form::getQuestionsByTypes()`
 * (src/Glpi/Form/Form.php:727) and `AnswersSet::getAnswersByType()`
 * (src/Glpi/Form/AnswersSet.php:133) both compare strings, never `is_a()` — so a
 * question type registered by a plugin is invisible to the "ITIL category" field
 * of a form destination (src/Glpi/Form/Destination/CommonITILField/ITILCategoryFieldStrategy.php:97)
 * and the created ticket would end up with no category at all.
 *
 * Enhancing the *native* dropdown question from the browser keeps the question,
 * its stored answer and its type untouched, so destinations, conditions, exports
 * and translations all keep working, and uninstalling the plugin simply brings
 * back the single tree dropdown.
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

const PLUGIN_SPLITCATEGORY_VERSION = '1.0.0';

function plugin_init_splitcategory(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['splitcategory'] = true;

    // The form renderer page is served by the same layout for logged in and
    // anonymous visitors (`Html::footer()` reads ADD_JAVASCRIPT_MODULE,
    // src/Html.php:6193), but public forms rendered through the "not logged"
    // card layout read the anonymous hook instead
    // (templates/layout/page_card_notlogged.html.twig:64). Both are registered so
    // the split works wherever a form can be answered.
    //
    // The path is relative to the plugin URI, and only `/ajax`, `/front` and
    // `/report` PHP scripts are served from the plugin root: everything else is
    // looked up under `<plugin>/public/`
    // (src/Glpi/Http/RequestRouterTrait.php:86-97), which is where the file lives.
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT_MODULE]['splitcategory'][]                = 'js/split_category.js';
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT_MODULE_ANONYMOUS_PAGE]['splitcategory'][] = 'js/split_category.js';
}

function plugin_version_splitcategory(): array
{
    return [
        'name'         => 'Split Category',
        'version'      => PLUGIN_SPLITCATEGORY_VERSION,
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
