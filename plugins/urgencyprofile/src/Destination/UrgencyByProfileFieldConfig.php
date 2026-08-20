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

namespace GlpiPlugin\Urgencyprofile\Destination;

use Glpi\DBAL\JsonFieldInterface;
use Override;

/**
 * Which "Urgency (restricted by profile)" answer feeds the created ITIL object.
 */
final class UrgencyByProfileFieldConfig implements JsonFieldInterface
{
    // Serialized key name, also used to build the form input name.
    public const QUESTION_ID = 'question_id';

    /** Do not touch the urgency, leave it to the core urgency field. */
    public const DISABLED = 0;

    /** Use the last answered question of this plugin's type. */
    public const LAST_ANSWER = -1;

    public function __construct(
        private int $question_id = self::LAST_ANSWER,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[Override]
    public static function jsonDeserialize(array $data): self
    {
        $question_id = $data[self::QUESTION_ID] ?? self::LAST_ANSWER;

        return new self(
            is_numeric($question_id) ? (int) $question_id : self::LAST_ANSWER
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            self::QUESTION_ID => $this->question_id,
        ];
    }

    public function getQuestionId(): int
    {
        return $this->question_id;
    }
}
