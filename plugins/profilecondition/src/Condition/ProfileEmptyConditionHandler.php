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
 * "Current profile is empty / is not empty" condition — an authentication
 * check: only a visitor filling a public form without logging in has no
 * profile.
 *
 * Same principle as ProfileEqualsConditionHandler: the answer carried by the
 * engine input is ignored, the session is the truth.
 */
final class ProfileEmptyConditionHandler implements ConditionHandlerInterface
{
    #[Override]
    public function getSupportedValueOperators(): array
    {
        return [
            ValueOperator::EMPTY,
            ValueOperator::NOT_EMPTY,
        ];
    }

    #[Override]
    public function getTemplate(): null
    {
        // No input field needed for empty conditions
        return null;
    }

    #[Override]
    public function getTemplateParameters(ConditionData $condition): array
    {
        return [];
    }

    #[Override]
    public function applyValueOperator(
        mixed $a,
        ValueOperator $operator,
        mixed $b,
    ): bool {
        $profile_id = SessionProfile::getActiveProfileId();

        return match ($operator) {
            ValueOperator::EMPTY     => $profile_id === null,
            ValueOperator::NOT_EMPTY => $profile_id !== null,

            // Unsupported operators
            default => false,
        };
    }
}
