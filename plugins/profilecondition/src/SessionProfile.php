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

namespace GlpiPlugin\Profilecondition;

use DBmysql;
use Profile;

/**
 * Reads the profile active in the current session, and the list of existing
 * profiles offered by the condition editor.
 */
final class SessionProfile
{
    /**
     * Existing profiles, id => name, resolved once per request.
     *
     * @var array<int, string>|null
     */
    private static ?array $profiles = null;

    /**
     * An unauthenticated visitor (public form accessed through a direct access
     * token) has no active profile and gets null: EQUALS conditions never
     * match for them, and EMPTY conditions always do.
     */
    public static function getActiveProfileId(): ?int
    {
        $profile_id = $_SESSION['glpiactiveprofile']['id'] ?? null;

        return is_numeric($profile_id) ? (int) $profile_id : null;
    }

    /**
     * Every profile of the instance, for the condition editor's value dropdown
     * and for displaying stored answers.
     *
     * @return array<int, string>
     */
    public static function getProfilesDropdown(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        if (self::$profiles !== null) {
            return self::$profiles;
        }

        self::$profiles = [];
        $rows = $DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => Profile::getTable(),
            'ORDER'  => 'name',
        ]);
        foreach ($rows as $row) {
            self::$profiles[(int) $row['id']] = (string) $row['name'];
        }

        return self::$profiles;
    }
}
