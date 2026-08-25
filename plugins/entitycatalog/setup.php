<?php

/**
 * -------------------------------------------------------------------------
 * Entity service catalog plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Adds a helpdesk tile that opens the service catalog of a chosen entity,
 * instead of the one the visitor happens to be sitting in.
 *
 * Why a plugin is needed:
 * the catalog has no entity parameter. Both of its controllers read the
 * session and nothing else — Glpi\Controller\ServiceCatalog\IndexController
 * loads Entity::getById($session->getCurrentEntityId())
 * (src/Glpi/Controller/ServiceCatalog/IndexController.php:80) and
 * FormProvider filters the forms with the session's active entities
 * (src/Glpi/Form/ServiceCatalog/Provider/FormProvider.php:102). The native
 * "GLPI page" tile can therefore only ever point at the current entity's
 * catalog (src/Glpi/Helpdesk/Tile/GlpiPageTile.php:158).
 *
 * So the tile shipped here does not link to the catalog directly: it links to
 * a route of its own that switches the session to the target entity and then
 * hands over to the native catalog, which sees exactly what it expects.
 *
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later
 * version.
 * -------------------------------------------------------------------------
 */

use Glpi\Helpdesk\Tile\TilesManager;
use GlpiPlugin\Entitycatalog\EntityCatalogTile;

const PLUGIN_ENTITYCATALOG_VERSION = '1.0.0';

function plugin_init_entitycatalog(): void
{
    plugin_entitycatalog_register_tile_type();
}

/**
 * Register the tile type in the helpdesk tiles manager.
 *
 * The manager is a singleton that only appends to a list
 * (src/Glpi/Helpdesk/Tile/TilesManager.php:91), so a second call would show
 * the type twice in the "Add tile" dropdown. Plugin::load() already guards
 * against calling plugin_init_entitycatalog() twice within a request
 * (src/Plugin.php:466), but the static below makes that guarantee local.
 */
function plugin_entitycatalog_register_tile_type(): void
{
    static $registered = false;

    if ($registered) {
        return;
    }
    $registered = true;

    TilesManager::getInstance()->registerPluginTileType(new EntityCatalogTile());
}

/**
 * @return array<string, mixed>
 */
function plugin_version_entitycatalog(): array
{
    return [
        'name'         => 'Entity service catalog',
        'version'      => PLUGIN_ENTITYCATALOG_VERSION,
        'author'       => 'SECONV-RR',
        'license'      => 'GPLv3',
        'requirements' => [
            'glpi' => [
                'min' => '11.0',
                'max' => '12.0',
            ],
        ],
    ];
}
