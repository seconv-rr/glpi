<?php

/**
 * -------------------------------------------------------------------------
 * Flat Dropdown plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Drop-in replacement for /ajax/getDropdownValue.php that returns the same
 * payload without the per-entity optgroups.
 *
 * The whole query — rights, entity restriction, IDOR validation, search,
 * pagination, tree indentation — is still done by the core method. Only the
 * grouping is undone afterwards, so nothing here can widen what a user is
 * allowed to see.
 *
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later
 * version.
 * -------------------------------------------------------------------------
 */

use function Safe\json_encode;

header('Content-Type: application/json; charset=UTF-8');
Html::header_nocache();

// Returns false when Session::validateIDOR() rejects the request
// (src/Dropdown.php:2882), same contract as the core endpoint.
$response = Dropdown::getDropdownValue($_POST, json: false);

if (!is_array($response)) {
    echo json_encode($response);
    return;
}

/**
 * Replace every entity group by its own items.
 *
 * Groups are built one level deep only: Dropdown::getDropdownValue() emits
 * `['text' => <entity name>, 'children' => [...], 'itemtype' => 'Entity']`
 * entries (src/Dropdown.php:3238 and 3386) and nothing nests below them. The
 * ancestor rows a tree dropdown adds for indentation live *inside* `children`
 * and are kept untouched, so the hierarchy stays readable.
 */
$flatten = static function (array $results): array {
    $flat = [];

    foreach ($results as $entry) {
        if (!isset($entry['children']) || !is_array($entry['children'])) {
            $flat[] = $entry;
            continue;
        }

        foreach ($entry['children'] as $child) {
            $flat[] = $child;
        }
    }

    return $flat;
};

$response['results'] = $flatten($response['results'] ?? []);

echo json_encode($response);
