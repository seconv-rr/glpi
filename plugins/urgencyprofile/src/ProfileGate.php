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

namespace GlpiPlugin\Urgencyprofile;

use DBmysql;
use Profile;

/**
 * Answers the only question this plugin really asks: may the profile currently
 * active in the session see the urgency question?
 */
final class ProfileGate
{
    /**
     * Profile ids resolved from PLUGIN_URGENCYPROFILE_ALLOWED_PROFILES.
     *
     * @var int[]|null
     */
    private static ?array $allowed_ids = null;

    /**
     * An unauthenticated visitor (public form accessed through a direct access
     * token) has no profile at all, and is treated as a regular user.
     */
    public static function isCurrentProfileAllowed(): bool
    {
        $profile_id = $_SESSION['glpiactiveprofile']['id'] ?? null;
        if (!is_numeric($profile_id)) {
            return false;
        }

        return in_array((int) $profile_id, self::getAllowedProfileIds(), true);
    }

    /**
     * Resolve the configured profiles to ids, once per request.
     *
     * Names are accepted next to ids because ids are instance specific: a name
     * written in setup.php keeps working when the form is exported to another
     * GLPI, an id does not.
     *
     * @return int[]
     */
    public static function getAllowedProfileIds(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        if (self::$allowed_ids !== null) {
            return self::$allowed_ids;
        }

        /** @var list<int|string> $configured */
        $configured = PLUGIN_URGENCYPROFILE_ALLOWED_PROFILES;

        $ids = [];
        $names = [];
        foreach ($configured as $entry) {
            if (is_int($entry)) {
                $ids[] = $entry;
                continue;
            }

            if (ctype_digit($entry)) {
                $ids[] = (int) $entry;
                continue;
            }

            if ($entry !== '') {
                $names[] = $entry;
            }
        }

        if ($names !== []) {
            $rows = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => Profile::getTable(),
                'WHERE'  => ['name' => $names],
            ]);
            foreach ($rows as $row) {
                $ids[] = (int) $row['id'];
            }
        }

        self::$allowed_ids = array_values(array_unique($ids));

        return self::$allowed_ids;
    }

    /**
     * Names of the configured profiles, for display in the form builder.
     *
     * Reads back from the database so a name that matches nothing is visibly
     * missing from the hint instead of silently doing nothing at runtime.
     *
     * @return string[]
     */
    public static function getAllowedProfileNames(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $ids = self::getAllowedProfileIds();
        if ($ids === []) {
            return [];
        }

        $names = [];
        $rows = $DB->request([
            'SELECT' => ['name'],
            'FROM'   => Profile::getTable(),
            'WHERE'  => ['id' => $ids],
            'ORDER'  => 'name',
        ]);
        foreach ($rows as $row) {
            $names[] = (string) $row['name'];
        }

        return $names;
    }
}
