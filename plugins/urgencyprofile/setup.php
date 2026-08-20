<?php

/**
 * -------------------------------------------------------------------------
 * Urgency by Profile plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Adds an "Urgency (restricted by profile)" question type to the native form
 * builder. The question behaves exactly like the core urgency question for the
 * profiles listed in PLUGIN_URGENCYPROFILE_ALLOWED_PROFILES and is hidden — and
 * forced to its default value — for everybody else.
 *
 * Why a new question type instead of hiding the core one:
 * the form builder can only condition a question on the answer of another
 * question (Glpi\Form\Condition\Type only has QUESTION, SECTION and COMMENT, and
 * Glpi\Form\Condition\EngineInput only carries answers), so the current profile
 * can never be a visibility criteria. The renderer, on the other hand, asks the
 * question *type* whether its input is hidden
 * (templates/pages/form_renderer.html.twig:154), which is a decision a plugin
 * type can take.
 *
 * Because Glpi\Form\Destination\CommonITILField\UrgencyField matches urgency
 * questions by exact class name (UrgencyField.php:189 and
 * UrgencyFieldStrategy.php:98 both compare against QuestionTypeUrgency), a
 * plugin question type is invisible to the core urgency destination field. This
 * plugin therefore ships its own destination field so the answer still reaches
 * the ticket/change/problem.
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

use Glpi\Form\Destination\AbstractCommonITILFormDestination;
use Glpi\Form\Destination\FormDestinationManager;
use Glpi\Form\QuestionType\QuestionTypesManager;
use GlpiPlugin\Urgencyprofile\Destination\UrgencyByProfileField;
use GlpiPlugin\Urgencyprofile\QuestionType\UrgencyByProfile;

const PLUGIN_URGENCYPROFILE_VERSION = '1.0.0';

/**
 * Profiles allowed to see and answer the question.
 *
 * Entries are either a profile name, matched against glpi_profiles.name, or a
 * numeric profile id. Names are resolved once per request by
 * GlpiPlugin\Urgencyprofile\ProfileGate.
 *
 * Anybody whose active profile is not listed here gets the question hidden and
 * its answer forced to the default value configured on the question. An empty
 * list therefore hides the question from everyone.
 *
 * @var list<int|string>
 */
const PLUGIN_URGENCYPROFILE_ALLOWED_PROFILES = [
    'Super-Admin',
    'Admin',
    'Supervisor',
    'Technician',
];

function plugin_init_urgencyprofile(): void
{
    plugin_urgencyprofile_register_form_extensions();
}

/**
 * Register the question type and the destination field that reads its answers.
 *
 * Both managers are singletons that only ever append to a list, so a second call
 * would show the question type twice in the form builder. Plugin::load() already
 * guards against calling plugin_init_urgencyprofile() twice within a request
 * (src/Plugin.php:466), but the static below makes that guarantee local.
 */
function plugin_urgencyprofile_register_form_extensions(): void
{
    static $registered = false;

    if ($registered) {
        return;
    }
    $registered = true;

    QuestionTypesManager::getInstance()->registerPluginQuestionType(
        new UrgencyByProfile()
    );

    // Registering on the abstract parent covers Ticket, Change and Problem at
    // once: getPluginCommonITILConfigFields() matches with is_a()
    // (src/Glpi/Form/Destination/FormDestinationManager.php:180).
    FormDestinationManager::getInstance()->registerPluginCommonITILConfigField(
        AbstractCommonITILFormDestination::class,
        new UrgencyByProfileField()
    );
}

/**
 * @return array<string, mixed>
 */
function plugin_version_urgencyprofile(): array
{
    return [
        'name'         => 'Urgency by Profile',
        'version'      => PLUGIN_URGENCYPROFILE_VERSION,
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
