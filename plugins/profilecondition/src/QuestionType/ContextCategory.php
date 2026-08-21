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

namespace GlpiPlugin\Profilecondition\QuestionType;

use Glpi\Form\QuestionType\QuestionTypeCategoryInterface;
use Override;

/**
 * Form builder category for question types that expose session context to the
 * condition engine instead of asking the user anything.
 *
 * The category key is the class name (QuestionTypesManager::getCategoryKey()),
 * so every instance of this class refers to the same category.
 */
final class ContextCategory implements QuestionTypeCategoryInterface
{
    #[Override]
    public function getLabel(): string
    {
        return __('Context', 'profilecondition');
    }

    #[Override]
    public function getIcon(): string
    {
        return 'ti ti-user-cog';
    }

    #[Override]
    public function getWeight(): int
    {
        // Core categories end at 110 (QuestionTypeCategory::getWeight(), ITEM)
        // and Advanced Forms' own category weighs 1000: 200 lands after every
        // core entry without sitting at the same weight as that plugin's.
        return 200;
    }
}
