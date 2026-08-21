<?php

/**
 * -------------------------------------------------------------------------
 * Conditions by profile plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Adds a "Current user profile" question type to the native form builder. The
 * question is never shown to whoever fills the form: it answers itself with
 * the profile active in the session, so that any other item of the form —
 * questions, comments, sections, validation rules, destinations — can use the
 * current profile as a criterion in its native conditions.
 *
 * Why the profile has to become a question:
 * the form builder can only condition an item on the answer of another
 * question (Glpi\Form\Condition\Type only has QUESTION, SECTION and COMMENT,
 * and Glpi\Form\Condition\EngineInput only carries answers), so the current
 * profile can never be a visibility criterion natively. A question whose
 * answer *is* the current profile turns it into one.
 *
 * The condition handlers shipped by this plugin never compare against the
 * answer they are given: they read the profile straight from the session.
 * Every evaluation site therefore sees the same truth — the initial render,
 * which feeds stored default values to the engine
 * (src/Glpi/Controller/Form/RendererController.php:98), the live
 * re-evaluation, which feeds DOM values
 * (src/Glpi/Controller/Form/Condition/EngineController.php:72), and the
 * validation and answer filtering on submit
 * (src/Glpi/Form/AnswersHandler/AnswersHandler.php:103). Editing the hidden
 * input in the DOM changes nothing server side.
 *
 * This plugin only uses GLPI core APIs. It shares no class, table, hook key or
 * configuration with the Advanced Forms plugin and can be installed alongside
 * it.
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
use GlpiPlugin\Profilecondition\QuestionType\ContextCategory;
use GlpiPlugin\Profilecondition\QuestionType\UserProfile;

const PLUGIN_PROFILECONDITION_VERSION = '1.0.0';

function plugin_init_profilecondition(): void
{
    plugin_profilecondition_register_form_extensions();
}

/**
 * Register the question category and the question type.
 *
 * The manager is a singleton that only ever appends to a list, so a second
 * call would show the category and the question type twice in the form
 * builder. Plugin::load() already guards against calling
 * plugin_init_profilecondition() twice within a request (src/Plugin.php:466),
 * but the static below makes that guarantee local.
 */
function plugin_profilecondition_register_form_extensions(): void
{
    static $registered = false;

    if ($registered) {
        return;
    }
    $registered = true;

    $manager = QuestionTypesManager::getInstance();
    $manager->registerPluginCategory(new ContextCategory());
    $manager->registerPluginQuestionType(new UserProfile());
}

/**
 * @return array<string, mixed>
 */
function plugin_version_profilecondition(): array
{
    return [
        'name'         => 'Conditions by profile',
        'version'      => PLUGIN_PROFILECONDITION_VERSION,
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
