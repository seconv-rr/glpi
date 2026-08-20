<?php

/**
 * -------------------------------------------------------------------------
 * Urgency by Profile plugin for GLPI
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
 * registered in memory on every request and the profiles allowed to see it are
 * read from the PLUGIN_URGENCYPROFILE_ALLOWED_PROFILES constant in setup.php.
 *
 * @param array<string, mixed> $params
 */
function plugin_urgencyprofile_install(array $params = []): bool
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
 * plugin brings those questions back untouched, which is why nothing is deleted
 * here.
 */
function plugin_urgencyprofile_uninstall(): bool
{
    return true;
}
