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

namespace GlpiPlugin\Profilecondition\Condition;

use Glpi\Form\Condition\ConditionData;
use Glpi\Form\Condition\ConditionHandler\ConditionHandlerInterface;
use Glpi\Form\Condition\ValueOperator;
use GlpiPlugin\Profilecondition\SessionProfile;
use Override;

/**
 * "Current profile is / is not" condition.
 *
 * The answer carried by the engine input ($a) is deliberately ignored: on the
 * initial render it is a stored default value (EngineInput::fromForm(),
 * src/Glpi/Form/Condition/EngineInput.php:55) and on live re-evaluation it is
 * a DOM-editable value posted by the browser
 * (src/Glpi/Controller/Form/Condition/EngineController.php:72). Reading the
 * session instead gives every evaluation site the same, unforgeable truth.
 */
final class ProfileEqualsConditionHandler implements ConditionHandlerInterface
{
    #[Override]
    public function getSupportedValueOperators(): array
    {
        return [
            ValueOperator::EQUALS,
            ValueOperator::NOT_EQUALS,
        ];
    }

    #[Override]
    public function getTemplate(): string
    {
        return '/pages/admin/form/condition_handler_templates/dropdown.html.twig';
    }

    #[Override]
    public function getTemplateParameters(ConditionData $condition): array
    {
        return ['values' => SessionProfile::getProfilesDropdown()];
    }

    #[Override]
    public function applyValueOperator(
        mixed $a,
        ValueOperator $operator,
        mixed $b,
    ): bool {
        if (is_array($b)) {
            $b = array_pop($b);
        }

        $profile_id = SessionProfile::getActiveProfileId();
        $matches = $profile_id !== null
            && is_numeric($b)
            && $profile_id === (int) $b;

        return match ($operator) {
            ValueOperator::EQUALS     => $matches,
            ValueOperator::NOT_EQUALS => !$matches,

            // Unsupported operators
            default => false,
        };
    }
}
