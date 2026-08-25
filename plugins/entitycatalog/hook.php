<?php

/**
 * -------------------------------------------------------------------------
 * Entity service catalog plugin for GLPI
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

use GlpiPlugin\Entitycatalog\EntityCatalogTile;

/**
 * Plugin install process.
 *
 * The tile is a CommonDBTM, so it needs a table of its own. Its columns are
 * the ones the native tiles use (src/Glpi/Helpdesk/Tile/GlpiPageTile.php and
 * install/mysql/glpi-11.0.4-empty.sql:3202) plus the target entity.
 *
 * @param array<string, mixed> $params
 */
function plugin_entitycatalog_install(array $params = []): bool
{
    /** @var DBmysql $DB */
    global $DB;

    $table = EntityCatalogTile::getTable();

    if ($DB->tableExists($table)) {
        return true;
    }

    $query = <<<SQL
        CREATE TABLE `$table` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `title` varchar(255) DEFAULT NULL,
            `description` text DEFAULT NULL,
            `illustration` varchar(255) DEFAULT NULL,
            `target_entities_id` int unsigned NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `target_entities_id` (`target_entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        SQL;

    $DB->doQueryOrDie($query, $DB->error());

    return true;
}

/**
 * Plugin uninstall process.
 *
 * Dropping the table would silently delete every tile an administrator
 * configured, and the rows linking them to their entity or profile
 * (glpi_helpdesks_tiles_items_tiles) would be left dangling anyway. GLPI
 * degrades gracefully on its own instead: once the class is no longer
 * registered, TilesManager::getTilesForItem() skips the row because the
 * itemtype no longer resolves to a TileInterface
 * (src/Glpi/Helpdesk/Tile/TilesManager.php:152), so the tile just stops being
 * displayed and comes back untouched on reinstall.
 *
 * To remove the data for good, delete the tiles in the UI *before*
 * uninstalling — that path also cleans up the link rows
 * (EntityCatalogTile::cleanDBonPurge()).
 */
function plugin_entitycatalog_uninstall(): bool
{
    return true;
}
