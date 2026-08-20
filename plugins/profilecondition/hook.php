<?php

/**
 * -------------------------------------------------------------------------
 * Conditions by profile plugin for GLPI
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
 * The plugin owns no table and no configuration row: the question type is
 * registered in memory on every request, and which profiles show or hide what
 * is configured per form, in the native conditions editor.
 *
 * @param array<string, mixed> $params
 */
function plugin_profilecondition_install(array $params = []): bool
{
    return true;
}

/**
 * Plugin uninstall process.
 *
 * Questions already created with this plugin's type keep their rows in
 * glpi_forms_questions. GLPI degrades gracefully on its own: once the class is
 * no longer registered, Question::getQuestionType() returns null
 * (src/Glpi/Form/Question.php:225) and Section::getQuestions() drops the
 * question from the form (src/Glpi/Form/Section.php:312). Reinstalling the
 * plugin brings those questions back untouched, which is why nothing is
 * deleted here.
 *
 * Conditions that reference the question are the exception — remove them
 * before uninstalling. See the README's Uninstall section.
 */
function plugin_profilecondition_uninstall(): bool
{
    return true;
}
